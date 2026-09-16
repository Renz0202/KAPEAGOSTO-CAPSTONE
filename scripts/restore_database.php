<?php
// scripts/restore_database.php
// CLI-only helper to restore the database from a .sql file in /backups or a provided path.
// Usage (PowerShell): php scripts/restore_database.php                # restore from latest
//                         php scripts/restore_database.php some.sql    # restore specific file

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config/config.php';

$backupDir = realpath(__DIR__ . '/../backups');
if ($backupDir === false) $backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) {
    fwrite(STDERR, "Backups directory not found.\n");
    exit(2);
}

function rlog(string $m){
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $m . "\n";
    echo $line;
    file_put_contents(__DIR__ . '/../backups/restore.log', $line, FILE_APPEND);
}

function load_sql_statements(string $sql): array {
    if (substr($sql,0,3) === "\xEF\xBB\xBF") $sql = substr($sql,3);
    $sql = str_replace(["\r\n","\r"],"\n",$sql);
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim,'--') || str_starts_with($trim,'#')) continue;
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);
    $parts = preg_split('/;\s*\n/', $sql);
    $stmts = [];
    foreach ($parts as $p) { $s = trim($p); if ($s !== '') $stmts[] = $s.';'; }
    return $stmts;
}

$targetArg = $argv[1] ?? '';
if ($targetArg !== '') {
    $candidate = basename($targetArg);
    if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $candidate)) {
        fwrite(STDERR, "Invalid filename: $candidate\n");
        exit(3);
    }
    $source = $backupDir . DIRECTORY_SEPARATOR . $candidate;
    if (!is_file($source)) {
        fwrite(STDERR, "File not found: $candidate\n");
        exit(4);
    }
} else {
    // Pick latest
    $files = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    usort($files, fn($a,$b)=>filemtime($b) <=> filemtime($a));
    $source = $files[0] ?? null;
    if (!$source) { fwrite(STDERR, "No backup files present.\n"); exit(5); }
}

try {
    rlog('Starting restore from ' . basename($source));
    $sql = file_get_contents($source);
    if ($sql === false) throw new RuntimeException('Unable to read SQL file.');

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->beginTransaction();
    $stmts = load_sql_statements($sql);
    $total = count($stmts);
    foreach ($stmts as $i => $stmt) {
        $pdo->exec($stmt);
        if ((($i+1) % 50) === 0) rlog('Executed ' . ($i+1) . ' / ' . $total . ' statements...');
    }
    $pdo->commit();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    rlog('Restore completed successfully.');
    exit(0);
} catch (Throwable $e) {
    rlog('Restore failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(10);
}
