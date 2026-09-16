<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

$backupDir = realpath(dirname(__DIR__, 2) . '/backups');
if ($backupDir === false) $backupDir = dirname(__DIR__, 2) . '/backups';
$logFile = $backupDir . '/backup.log';

header('Content-Type: text/plain; charset=utf-8');
if (is_file($logFile)) {
    readfile($logFile);
} else {
    echo "No backup activity yet.";
}
exit;
?>
