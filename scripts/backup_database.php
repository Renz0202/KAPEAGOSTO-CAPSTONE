<?php
// scripts/backup_database.php
// CLI-only: create a full SQL dump into /backups (no HTML output)

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

@ini_set('memory_limit', '512M');
@set_time_limit(0);

require_once __DIR__ . '/../config/config.php';

$backupDir = realpath(__DIR__ . '/../backups');
if ($backupDir === false) $backupDir = __DIR__ . '/../backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0775, true);

function blog(string $m){
    global $backupDir;
    $line = '[' . date('Y-m-d H:i:s') . "] " . $m . "\n";
    file_put_contents($backupDir . '/backup.log', $line, FILE_APPEND);
    echo $line;
}

function quote_value(PDO $pdo, $val): string {
    if ($val === null) return 'NULL';
    if (is_int($val) || is_float($val)) return (string)$val;
    return $pdo->quote((string)$val);
}

try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    $tables = [];
    $res = $pdo->query('SHOW TABLES');
    while ($r = $res->fetch(PDO::FETCH_NUM)) { $tables[] = $r[0]; }
    if (!$tables) throw new RuntimeException('No tables found.');

    $dbName = DB_NAME;
    $timestamp = date('Ymd_His');
    $fileBase = $dbName . '_' . $timestamp;
    $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileBase . '.sql';

    blog("Starting backup to {$fileBase}.sql");

    $header = "-- CLI database backup\n-- Database: {$dbName}\n-- Generated at: " . date('c') . "\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    file_put_contents($filePath, $header);

    foreach ($tables as $i => $t) {
        blog("[" . ($i+1) . "/" . count($tables) . "] Structure: {$t}");
        $row = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
        $create = $row['Create Table'] ?? '';
        $chunk = "\n-- Structure for `{$t}`\nDROP TABLE IF EXISTS `{$t}`;\n{$create};\n\n";
        file_put_contents($filePath, $chunk, FILE_APPEND);

        blog("[" . ($i+1) . "/" . count($tables) . "] Data: {$t}");
        $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        if ($count === 0) continue;
        $limit = 500;
        for ($offset = 0; $offset < $count; $offset += $limit) {
            $stmt = $pdo->query("SELECT * FROM `{$t}` LIMIT {$limit} OFFSET {$offset}");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            $cols = array_map(fn($c)=>"`".str_replace('`','``',$c)."`", array_keys($rows[0]));
            $part = "INSERT INTO `{$t}` (" . implode(', ',$cols) . ") VALUES\n";
            $vals = [];
            foreach ($rows as $r) {
                $items = [];
                foreach ($r as $v) { $items[] = quote_value($pdo, $v); }
                $vals[] = '(' . implode(', ', $items) . ')';
            }
            $part .= implode(",\n", $vals) . ";\n\n";
            file_put_contents($filePath, $part, FILE_APPEND);
        }
    }

    file_put_contents($filePath, "SET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND);
    blog("Backup done: {$fileBase}.sql");
    exit(0);
} catch (Throwable $e) {
    blog('Backup failed: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
