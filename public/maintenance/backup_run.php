<?php
// public/backup_run.php
// Creates a full SQL dump of the application's MySQL database into /backups

require_once dirname(__DIR__, 2) . '/includes/auth.php';
// Try to use shared DB helper, fallback to direct PDO if unavailable
@require_once dirname(__DIR__, 2) . '/includes/db.php';
require_admin();

// Increase limits for long-running operations
@ini_set('memory_limit', '512M');
@set_time_limit(0);

date_default_timezone_set('Asia/Manila');

$backupDir = realpath(dirname(__DIR__, 2) . '/backups');
if ($backupDir === false) {
    $backupDir = dirname(__DIR__, 2) . '/backups';
}

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

// Simple logger
function backup_log(string $message): void {
    global $backupDir;
    $line = '[' . date('Y-m-d H:i:s') . "] " . $message . "\n";
    @file_put_contents($backupDir . '/backup.log', $line, FILE_APPEND);
}

function quote_value(PDO $pdo, $val): string {
    if ($val === null) return 'NULL';
    // Keep numbers as numbers
    if (is_int($val) || is_float($val)) return (string)$val;
    // Numeric strings: leave quoted to be safe
    return $pdo->quote((string)$val);
}

// Ensure all relevant tables (e.g., orders, inventory, reports, etc.) are included
function get_tables(PDO $pdo): array {
    $stmt = $pdo->query('SHOW TABLES');
    $tables = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }
    return $tables;
}

function dump_table_structure(PDO $pdo, string $table): string {
    $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $create = $row['Create Table'] ?? $row['Create View'] ?? '';
    $sql  = "\n--\n-- Structure for table `{$table}`\n--\n\n";
    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
    $sql .= $create . ";\n\n";
    return $sql;
}

function dump_table_data(PDO $pdo, string $table): string {
    $sql = "\n--\n-- Data for table `{$table}`\n--\n";
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    if ($count === 0) {
        $sql .= "\n";
        return $sql;
    }

    $limit = 500;
    for ($offset = 0; $offset < $count; $offset += $limit) {
        $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;

        $columns = array_map(fn($c) => "`" . str_replace("`", "``", $c) . "`", array_keys($rows[0]));
        $sql .= "INSERT INTO `{$table}` (" . implode(', ', $columns) . ") VALUES\n";

        $valuesSql = [];
        foreach ($rows as $r) {
            $vals = [];
            foreach ($r as $v) {
                $vals[] = quote_value($pdo, $v);
            }
            $valuesSql[] = '(' . implode(', ', $vals) . ')';
        }

        $sql .= implode(",\n", $valuesSql) . ";\n\n";
    }

    return $sql;
}

// Add automatic end-of-day backup marker
function mark_auto_backup(string $backupDir): void {
    $markerFile = $backupDir . '/.auto_backup.json';
    $data = ['last_run' => date('Y-m-d')];
    @file_put_contents($markerFile, json_encode($data));
}

try {
    if (function_exists('db')) {
        $pdo = db();
    } else {
        require_once dirname(__DIR__, 2) . '/config/config.php';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    $pdo->query('SET NAMES utf8mb4');
    $pdo->query('SET FOREIGN_KEY_CHECKS=0');
    // Create a consistent snapshot for all tables to keep orders/inventory aligned
    // Uses REPEATABLE READ to avoid mid-backup modifications causing mismatch.
    try {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        // Fallback silently; backup proceeds without snapshot.
    }

    $tables = get_tables($pdo);
    if (empty($tables)) {
        throw new RuntimeException('No tables found to backup.');
    }

    $app = defined('APP_NAME') ? APP_NAME : 'app';
    $dbName = defined('DB_NAME') ? DB_NAME : 'database';
    $timestamp = date('Ymd_His');
    $fileBase = $dbName . '_' . $timestamp;
    $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileBase . '.sql';

    backup_log("Starting backup for DB '{$dbName}' into '{$fileBase}.sql'.");

    $header = '';
    $header .= "-- {$app} database backup\n";
    $header .= "-- Database: {$dbName}\n";
    $header .= "-- Generated at: " . date('c') . "\n";
    $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $header .= "SET time_zone = '+00:00';\n";
    $header .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    file_put_contents($filePath, $header);

    foreach ($tables as $i => $t) {
        backup_log("[" . ($i+1) . "/" . count($tables) . "] Dumping structure for '{$t}'.");
        file_put_contents($filePath, dump_table_structure($pdo, $t), FILE_APPEND);

        backup_log("[" . ($i+1) . "/" . count($tables) . "] Dumping data for '{$t}'.");
        file_put_contents($filePath, dump_table_data($pdo, $t), FILE_APPEND);
    }

    // End snapshot if started
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    // Re-enable FK checks
    file_put_contents($filePath, "SET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND);
    backup_log("Backup completed: {$fileBase}.sql");

    // Mark as automatic backup if triggered at the end of the day
    if (isset($_GET['auto']) && $_GET['auto'] === '1') {
        mark_auto_backup($backupDir);
    }

    // Simple HTML response
    $relPath = '../backups/' . basename($filePath); // for display only
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Backup Completed</title>
        <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
        </head>
    <body class="kape-maintenance-backup-run">
        <?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>
        <?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
        <div class="content backup-run">
            <h1><i class="fa fa-database"></i> Backup Completed</h1>
            <div class="alert success">✅ Backup finished successfully.</div>

            <div class="form-card">
                <h3 style="display:flex;align-items:center;gap:8px;margin:0 0 6px;"><i class="fa fa-file-lines"></i> File Created</h3>
                <p style="margin:4px 0 10px;"><strong>File:</strong> <?php echo htmlspecialchars(basename($filePath)); ?></p>
                <div class="actions-bar">
                    <a class="btn blue" href="backup_download.php?file=<?php echo urlencode(basename($filePath)); ?>"><i class="fa fa-download"></i> Download backup</a>
                    <a class="btn gray" href="backup_restore.php"><i class="fa fa-rotate-left"></i> Back to Backup & Restore</a>
                </div>
            </div>

            <div class="form-card" style="margin-top:16px;">
            <div class="toolbar">
                                <h3 style="margin:0;color:#0f0;font-weight:600;">Backup Log Output</h3>
                                <button class="btn gray" type="button" onclick="copyTerminalLog()"><i class="fa fa-copy"></i> Copy</button>
                        </div>
                        <div class="terminal-log" id="terminalLog">
<?php
    $raw = @file_get_contents($backupDir . '/backup.log');
    $lines = preg_split('/\r?\n/', (string)$raw);
    foreach ($lines as $ln) {
        $trim = trim($ln);
        if ($trim === '') continue;
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*(.*)$/', $trim, $m)) {
                $time = htmlspecialchars($m[1]);
                $msg = htmlspecialchars($m[2]);
                $lower = strtolower($m[2]);
                $cls = '';
                if (str_contains($lower,'failed') || str_contains($lower,'error')) $cls='error';
                elseif (str_contains($lower,'completed') || str_contains($lower,'done') || str_contains($lower,'success')) $cls='ok';
                elseif (str_contains($lower,'starting') || str_contains($lower,'dumping')) $cls='info';
                echo "<div class='log-line $cls'><span class='log-time'>[$time]</span><span class='log-msg'>$msg</span></div>"; 
        } else {
                $safe = htmlspecialchars($trim);
                echo "<div class='log-line'><span class='log-time'>—</span><span class='log-msg'>$safe</span></div>";
        }
    }
?>
                                    </div>
                        <script>
                            function copyTerminalLog(){
                                const box=document.getElementById('terminalLog');
                                const lines=[...box.querySelectorAll('.log-line')].map(l=>{
                                    const t=l.querySelector('.log-time').textContent.replace(/^\[|\]$/g,'');
                                    const m=l.querySelector('.log-msg').textContent;return (t&&t!=='—'? '['+t+'] ':'')+m;});
                                const text=lines.join('\n');
                                const done=(btn)=>{ if(btn){ const orig=btn.innerHTML; btn.innerHTML='<i class="fa fa-check"></i> Copied'; setTimeout(()=>btn.innerHTML=orig,1200);} };
                                const btn=document.querySelector('.toolbar button');
                                if(navigator.clipboard) navigator.clipboard.writeText(text).then(()=>done(btn)).catch(()=>done(btn));
                                else { const ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');}catch{} document.body.removeChild(ta); done(btn);} }
                        </script>
                                    </div>
        </div>
    </body>
    </html>
    <?php
} catch (Throwable $e) {
    backup_log('Backup failed: ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Backup Failed</title>
    </head>
    <body>
        <?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>
        <?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
        <div class="content backup-run">
            <h1><i class="fa fa-database"></i> Backup Failed</h1>
            <div class="alert error">⚠️ <?php echo htmlspecialchars($e->getMessage()); ?></div>
            <div class="actions-bar">
                <a class="btn gray" href="backup_restore.php"><i class="fa fa-rotate-left"></i> Back</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
