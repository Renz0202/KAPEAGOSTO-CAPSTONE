<?php
// config/backup.php
// Optional overrides for automatic backup/restore behavior.
// You can safely delete or ignore this file; sensible defaults apply when not present.

// Enable scheduled backups and page-request catch-up for missed scheduled dates.
if (!defined('AUTO_BACKUP_ENABLED')) {
    define('AUTO_BACKUP_ENABLED', true);
}

// Daily time in Philippine time. Re-run scripts/register_backup_task.ps1 after changing.
if (!defined('AUTO_BACKUP_TIME')) {
    define('AUTO_BACKUP_TIME', '23:55');
}

// Retain service-owned auto_*.sql files this many days. Manual/legacy files are preserved.
if (!defined('AUTO_BACKUP_KEEP_DAYS')) {
    define('AUTO_BACKUP_KEEP_DAYS', 14);
}

// Automatic restore is NOT recommended; leave disabled unless you fully understand the impact.
// If you need scheduled restore, use scripts/restore_database.php with Windows Task Scheduler.
if (!defined('AUTO_RESTORE_ENABLED')) {
    define('AUTO_RESTORE_ENABLED', false);
}

// Time (HH:MM) to perform an automatic restore if enabled (not used by web passive job)
if (!defined('AUTO_RESTORE_TIME')) {
    define('AUTO_RESTORE_TIME', '00:10');
}

// Source of restore if automatic restore is ever enabled: 'latest' or a specific filename
if (!defined('AUTO_RESTORE_SOURCE')) {
    define('AUTO_RESTORE_SOURCE', 'latest');
}
