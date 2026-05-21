# Register (or refresh) the Windows Scheduled Task that keeps the
# branding-builder queue worker alive at logon. Idempotent (-Force).
#
# Run once (no admin needed for a per-user logon task):
#   powershell -ExecutionPolicy Bypass -File scripts\install-queue-worker-task.ps1
#
# Then either log off/on, or start it immediately:
#   Start-ScheduledTask -TaskName 'NemaBrandingQueueWorker'
$ErrorActionPreference = 'Stop'

$taskName = 'NemaBrandingQueueWorker'
$worker = Join-Path $PSScriptRoot 'run-queue-worker.ps1'

$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument ('-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File "{0}"' -f $worker)
$trigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable `
    -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger `
    -Settings $settings -Principal $principal -Force | Out-Null

Write-Output "Registered scheduled task '$taskName' (runs at logon, restarts on crash)."
Write-Output "Start it now without logging off:  Start-ScheduledTask -TaskName '$taskName'"
