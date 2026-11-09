<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/backup_service.php';
@ini_set('memory_limit', '512M');
@set_time_limit(0);
$directory = dirname(__DIR__) . '/backups';
$automatic = in_array('--scheduled', $argv, true);
$connected = false;
try {
    // The web db() helper exits on connection failure, which would hide failure from Task Scheduler.
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+08:00'");
    $connected = true;
    $result = backup_run($pdo, $directory, $automatic);
    echo json_encode($result), PHP_EOL;
    exit($result['status'] === 'busy' ? 1 : 0);
} catch (Throwable $error) {
    if ($automatic && !$connected && is_dir($directory)) {
        $lock = fopen($directory . '/.backup.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            try {
                $state = backup_read_state($directory);
                $state['last_attempt_at'] = date(DATE_ATOM);
                $state['last_attempt_status'] = 'failed';
                $state['last_error'] = 'Database connection unavailable. Check that MySQL is running.';
                backup_write_state($directory, $state);
                backup_log_event($directory, $state['last_error']);
            } finally { flock($lock, LOCK_UN); }
        }
        if ($lock) fclose($lock);
    }
    fwrite(STDERR, 'Backup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
