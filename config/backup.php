<?php
// config/backup.php
// Optional overrides for automatic backup/restore behavior.
// You can safely delete or ignore this file; sensible defaults apply when not present.

// Enable/disable the passive auto-backup that runs near end-of-day when pages are visited
if (!defined('AUTO_BACKUP_ENABLED')) {
    define('AUTO_BACKUP_ENABLED', true);
}

// End-of-day time (24h HH:MM) to trigger the backup when there is traffic
if (!defined('AUTO_BACKUP_TIME')) {
    define('AUTO_BACKUP_TIME', '23:55');
}

// How many days of .sql backups to keep in /backups
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
