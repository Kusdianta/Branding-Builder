# Keep the branding-builder queue worker alive.
#
# Runs `php artisan queue:work` in a restart loop so a crash, a fatal job,
# or a worker self-exit (memory / --max-time) is recovered within seconds.
# Launched at logon (no admin) by the Startup-folder nema-queue-worker.vbs
# (Task Scheduler registration requires elevation here). See scripts/README.md.
# --timeout=600 matches FetchGMapsReviewsJob (BB130 full-corpus scrape).
#
# ASCII only on purpose: PowerShell 5.1 reads a no-BOM .ps1 as ANSI, so a
# non-ASCII char (e.g. an em-dash) inside a string literal corrupts parsing.
#
# Verify it's alive - NOTE: PowerShell 5.1 Get-Process does NOT expose
# CommandLine, so "Get-Process php | Where CommandLine ..." returns nothing
# even when the worker runs. Use CIM or the log instead:
#   Get-CimInstance Win32_Process -Filter "Name='php.exe'" |
#     Where-Object { $_.CommandLine -match 'queue:work' } | Select ProcessId, CommandLine
#   Get-Content branding-builder\storage\logs\queue-worker.log -Tail 5
param([int]$Workers = 0)

$ErrorActionPreference = 'Continue'

$appRoot = Split-Path -Parent $PSScriptRoot
$php = Join-Path $env:USERPROFILE '.config\herd\bin\php.bat'
if (-not (Test-Path $php)) { $php = 'php' }
$artisan = Join-Path $appRoot 'artisan'
$log = Join-Path $appRoot 'storage\logs\queue-worker.log'

function Write-WorkerLog($msg) {
    try { ('{0}  {1}' -f (Get-Date -Format 's'), $msg) | Add-Content -Path $log -Encoding utf8 } catch {}
}

# BB139 — run N workers in parallel so the gather/analyze Bus::batch jobs
# actually execute concurrently (one worker = serial execution). Desired
# count: -Workers param > BB_QUEUE_WORKERS env > default 3.
$desired = if ($Workers -gt 0) { $Workers }
           elseif ($env:BB_QUEUE_WORKERS) { [int]$env:BB_QUEUE_WORKERS }
           else { 3 }

# Top-up guard: count queue:work php processes already running and start only
# the shortfall. Prevents stacking on a Startup re-run / double-click / when
# 'composer dev' already launched a worker, while still reaching $desired.
$existing = @(Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -match 'artisan.*queue:work' })
$toStart = $desired - $existing.Count
if ($toStart -le 0) {
    Write-WorkerLog ('launcher exit: {0} queue worker(s) already running (desired {1})' -f $existing.Count, $desired)
    return
}

Write-WorkerLog ('launcher start (php={0}): {1} running, starting {2} more to reach {3}' -f $php, $existing.Count, $toStart, $desired)

# Each worker is its own restart loop in a detached hidden process, so a
# crash / fatal job / self-exit recovers within seconds independent of its
# siblings. --timeout=600 matches FetchGMapsReviewsJob (BB130 full corpus);
# pair with DB_QUEUE_RETRY_AFTER=660 in .env so a long job is not re-reserved
# and re-run by an idle sibling worker.
$loop = "while (`$true) { & '$php' '$artisan' queue:work --tries=1 --timeout=600 --sleep=3; Start-Sleep -Seconds 3 }"
for ($i = 1; $i -le $toStart; $i++) {
    Start-Process -FilePath 'powershell.exe' `
        -ArgumentList '-NoProfile', '-NonInteractive', '-WindowStyle', 'Hidden', '-Command', $loop `
        -WindowStyle Hidden | Out-Null
    Write-WorkerLog ('spawned worker {0}/{1}' -f $i, $toStart)
}
Write-WorkerLog ('launcher done: {0} queue worker(s) now running' -f $desired)
