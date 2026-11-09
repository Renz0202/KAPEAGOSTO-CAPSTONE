$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..')).Path
$phpPath = 'C:\xampp\php\php.exe'
if (!(Test-Path -LiteralPath $phpPath)) { throw 'XAMPP PHP executable not found.' }
$taskName = 'KapeAgosto-DailyDatabaseBackup'
$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$configPath = (Join-Path $projectRoot 'config\backup.php').Replace('\', '/').Replace("'", "\'")
$schedule = & $phpPath -r "require '$configPath'; echo AUTO_BACKUP_TIME;"
if ($LASTEXITCODE -ne 0 -or $schedule -notmatch '^([01]\d|2[0-3]):[0-5]\d$') { throw 'Cannot read backup schedule. Run from the project folder.' }
$action = New-ScheduledTaskAction -Execute $phpPath -Argument ('"' + (Join-Path $projectRoot 'scripts\backup_database.php') + '" --scheduled') -WorkingDirectory $projectRoot
$triggers = @((New-ScheduledTaskTrigger -Daily -At $schedule), (New-ScheduledTaskTrigger -AtLogOn -User $user))
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 12 -RestartInterval (New-TimeSpan -Minutes 5) -ExecutionTimeLimit (New-TimeSpan -Hours 1) -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Limited
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing -and $existing.Description -ne 'Kape Agosto database backups; managed by register_backup_task.ps1') { throw 'An unrelated task uses this name; registration stopped.' }
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Settings $settings -Principal $principal -Description 'Kape Agosto database backups; managed by register_backup_task.ps1' -Force | Out-Null
$registered = Get-ScheduledTask -TaskName $taskName
if ($registered.Actions.Execute -ne $phpPath -or $registered.Principal.LogonType -ne 'S4U') { throw 'Task verification failed.' }
$status = @{ task_name = $taskName; registered_at = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'); user = $user; schedule = $schedule; logon_type = 'S4U' }
[IO.File]::WriteAllText((Join-Path $projectRoot 'backups\.scheduler.json'), ($status | ConvertTo-Json))
$registered | Select-Object TaskName, State
