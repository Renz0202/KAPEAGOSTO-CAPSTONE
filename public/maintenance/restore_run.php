<?php
// public/restore_run.php
// Restores the database from an uploaded or existing .sql dump

require_once dirname(__DIR__, 2) . '/includes/auth.php';
@require_once dirname(__DIR__, 2) . '/includes/db.php';
require_admin();

@set_time_limit(0);
@ini_set('memory_limit', '512M');

date_default_timezone_set('Asia/Manila');

$backupDir = realpath(dirname(__DIR__, 2) . '/backups');
if ($backupDir === false) {
    $backupDir = dirname(__DIR__, 2) . '/backups';
}
if (!is_dir($backupDir)) @mkdir($backupDir, 0775, true);

function restore_log(string $message): void {
    global $backupDir;
    $line = '[' . date('Y-m-d H:i:s') . "] " . $message . "\n";
    @file_put_contents($backupDir . '/restore.log', $line, FILE_APPEND);
}

function load_sql_statements(string $sql): array {
    // Remove BOM
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3);
    }
    // Normalize line endings
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    // Remove comments (lines starting with -- or #) and empty lines, but keep inside statements
    $lines = explode("\n", $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) continue;
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    // Split on semicolon at end of line
    $parts = preg_split('/;\s*\n/', $sql);
    $stmts = [];
    foreach ($parts as $p) {
        $s = trim($p);
        if ($s !== '') $stmts[] = $s . ';';
    }
    return $stmts;
}

try {
    $source = null;
    if (!empty($_POST['existing_backup'])) {
        $candidate = basename($_POST['existing_backup']);
        if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $candidate)) {
            throw new RuntimeException('Invalid backup filename.');
        }
        $path = $backupDir . DIRECTORY_SEPARATOR . $candidate;
        if (!is_file($path)) throw new RuntimeException('Selected backup does not exist.');
        $source = $path;
    } elseif (!empty($_FILES['sql_file']['name'])) {
        if ($_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed.');
        }
        $ext = strtolower(pathinfo($_FILES['sql_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') throw new RuntimeException('Only .sql files are allowed.');
        $dest = $backupDir . DIRECTORY_SEPARATOR . 'uploaded_' . date('Ymd_His') . '.sql';
        if (!move_uploaded_file($_FILES['sql_file']['tmp_name'], $dest)) {
            throw new RuntimeException('Unable to move uploaded file.');
        }
        $source = $dest;
    } else {
        throw new RuntimeException('Please select a backup or upload a file.');
    }

    restore_log('Starting restore from ' . basename($source));

    $sql = file_get_contents($source);
    if ($sql === false) throw new RuntimeException('Unable to read SQL file.');

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
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    // Use a single transaction to apply schema/data consistently
    try {
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
    } catch (Throwable $e) {
        // ignore and continue without explicit transaction isolation change
        $pdo->beginTransaction();
    }

    $statements = load_sql_statements($sql);
    $total = count($statements);
    foreach ($statements as $i => $stmt) {
        // Skip SET and comments already removed
        $pdo->exec($stmt);
        if ((($i+1) % 50) === 0) {
            restore_log('Executed ' . ($i+1) . ' / ' . $total . ' statements...');
        }
    }

    $pdo->commit();
    // Recalculate derived/order totals to ensure restored data is consistent
    try {
        // Some backups may have stale totals if items changed post-backup.
        $pdo->exec("UPDATE orders o SET total = (
            SELECT COALESCE(SUM(oi.qty * oi.price),0) FROM order_items oi WHERE oi.order_id = o.id
        )");
    } catch (Throwable $e) {
        // log silently
        restore_log('Post-restore order total recalculation failed: ' . $e->getMessage());
    }
    try {
        // Inventory sanity: ensure no negative quantities (clamp to zero).
        $pdo->exec("UPDATE inventory SET qty = 0 WHERE qty < 0");
    } catch (Throwable $e) {
        restore_log('Post-restore inventory clamp failed: ' . $e->getMessage());
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    restore_log('Restore completed successfully.');

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Restore Completed</title>
        <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
    </head>
    <body class="kape-maintenance-restore-run">
        <?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>
        <div class="container" style="max-width:900px;margin:20px auto;">
            <h2>Database Restore</h2>
            <p>Restore finished successfully from <strong><?php echo htmlspecialchars(basename($source)); ?></strong>.</p>
            <p>
                <a class="btn" href="backup_restore.php">Back to Backup & Restore</a>
            </p>
            <hr>
            <pre style="background:#111;color:#0f0;padding:12px;max-height:300px;overflow:auto;">
<?php echo htmlspecialchars(@file_get_contents($backupDir . '/restore.log')); ?>
            </pre>
        </div>
    </body>
    </html>
    <?php
} catch (Throwable $e) {
    restore_log('Restore failed: ' . $e->getMessage());
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Restore Failed</title>
        <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
    </head>
    <body class="kape-maintenance-restore-run">
        <div class="container" style="max-width:900px;margin:20px auto;">
            <h2>Restore Failed</h2>
            <p><?php echo htmlspecialchars($e->getMessage()); ?></p>
            <p><a href="backup_restore.php">Back</a></p>
        </div>
    </body>
    </html>
    <?php
}
