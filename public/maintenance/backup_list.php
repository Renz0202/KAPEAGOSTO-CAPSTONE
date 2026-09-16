<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

date_default_timezone_set('Asia/Manila');

$backupDir = realpath(dirname(__DIR__, 2) . '/backups');
if ($backupDir === false) {
    $backupDir = dirname(__DIR__, 2) . '/backups';
}
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}

function list_backups_json(string $dir): array {
    if (!is_dir($dir)) return [];
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $out = [];
    foreach ($files as $f) {
        $out[] = [
            'name' => basename($f),
            'size' => (int)@filesize($f),
            'time' => (int)@filemtime($f),
            'time_iso' => date('Y-m-d H:i:s', @filemtime($f)),
        ];
    }
    usort($out, fn($a,$b) => $b['time'] <=> $a['time']);
    return $out;
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode([
    'ok' => true,
    'backups' => list_backups_json($backupDir),
]);
?>
