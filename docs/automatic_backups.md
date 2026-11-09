# Automatic database backups

The Windows task `KapeAgosto-DailyDatabaseBackup` calls:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\KAPEAGOSTO\scripts\backup_database.php --scheduled
```

The daily time is configured in `config/backup.php`, currently 23:55 Philippine time. The task also checks for missed backups at Windows sign-in, starts when a missed scheduled start becomes available, and retries failures every five minutes up to twelve times. It runs under the registering user with limited privileges and an S4U token, so the user does not need to be signed in. S4U does not provide access to network resources; this task uses local project files and a local MySQL server. The PC must be on and MySQL must be running. This does not configure MySQL to start automatically or wake a powered-off PC.

To register the task again after moving the project or changing the schedule, run `scripts/register_backup_task.ps1` in PowerShell with permission to manage scheduled tasks. Registration refuses to replace an unrelated task with the same name. Its `.scheduler.json` marker is evidence of registration, not a live health check; Windows Task Scheduler remains the source for current task state.

Dashboard, Inventory and Orders requests provide catch-up. Before today's scheduled time, yesterday's scheduled date is the latest due date. Once it has completed, morning requests and midnight do not create another automatic backup. After today's scheduled time, today's date becomes due. Old date-only markers are treated conservatively as completed through that date.

The shared backup service takes an exclusive file lock, uses a consistent InnoDB snapshot, omits generated columns from data INSERTs, writes to a `.partial` file, and publishes the `.sql` only after completion. It records attempts, errors, the last successful time and covered scheduled date in `backups/.auto_backup.json`. Database views are explicitly rejected rather than silently omitted. Avoid schema changes during export; this PHP exporter does not export routines, triggers or events. Use a suitable mysqldump workflow if these objects are added.

Only service-created `auto_YYYYMMDD_HHMMSS_hex.sql` files older than the configured retention period are removed after a successful automatic backup. Manual and legacy files are preserved. Manual backups do not satisfy the scheduled backup slot. No restore is scheduled or performed by this implementation.

The page displays the configured next scheduled time, the registration notice, last successful time, overdue state and latest failure. A configured next time is not a guarantee that the machine, user session and MySQL will be available.

Verification: `C:\xampp\php\php.exe tests\backup_service_test.php`. Without `--scheduled`, the CLI makes a manual backup. Keep an additional backup copy outside this computer for recovery from disk loss.
