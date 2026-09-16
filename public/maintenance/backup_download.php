<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

$backupDir = realpath(dirname(__DIR__, 2) . '/backups');
if ($backupDir === false) {
    http_response_code(404);
    exit('Backups directory not found.');
}

$file = $_GET['file'] ?? '';
if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+\.sql$/', $file)) {
    http_response_code(400);
    exit('Invalid file parameter.');
}

$path = $backupDir . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private');
readfile($path);
exit;
