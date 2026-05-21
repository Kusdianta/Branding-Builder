# Keep the branding-builder queue worker alive.
#
# Runs `php artisan queue:work` in a restart loop so a crash, a fatal
# job, or a worker self-exit (memory / --max-time) is recovered within a
# few seconds. Registered as a Windows Scheduled Task at logon by
# install-queue-worker-task.ps1 — the durable fix for audits stalling at
# 0% / the wizard "Sistem belum siap" banner when nothing drains the queue.
#
# --timeout=600 matches FetchGMapsReviewsJob (BB130 full-corpus scrape).
$ErrorActionPreference = 'Continue'

$appRoot = Split-Path -Parent $PSScriptRoot            # branding-builder/
$php = Join-Path $env:USERPROFILE '.config\herd\bin\php.bat'
if (-not (Test-Path $php)) { $php = 'php' }            # fall back to PATH

while ($true) {
    try {
        & $php (Join-Path $appRoot 'artisan') queue:work --tries=1 --timeout=600 --sleep=3
    } catch {
        # swallow and restart below
    }
    Start-Sleep -Seconds 3
}
