<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/backup_service.php';
require_admin();
@ini_set('memory_limit', '512M');
@set_time_limit(0);
try {
    $result = backup_run(db(), dirname(__DIR__, 2) . '/backups', ($_GET['auto'] ?? '') === '1');
    if ($result['status'] === 'busy') { http_response_code(409); exit('Another backup is running. Please try again shortly.'); }
    header('Location: backup_restore.php');
    exit;
} catch (Throwable $error) {
    error_log('Backup failed: ' . $error->getMessage());
    http_response_code(500);
    echo 'Backup failed. Check the backup log in System Settings > Backup & Restore.';
}