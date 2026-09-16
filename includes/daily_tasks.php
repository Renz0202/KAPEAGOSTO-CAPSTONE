<?php
// includes/daily_tasks.php
// Auto-generate daily reports. Transaction records are permanent source data and
// must never be deleted by a page-load task because reporting and forecasting
// depend on the original orders, items, and payments.

if (!isset($pdo)) {
    require_once __DIR__ . '/db.php';
    $pdo = db();
}

try {
    // Ensure daily_reports table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS daily_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_date DATE NOT NULL UNIQUE,
            total_sales DECIMAL(10,2) DEFAULT 0,
            total_orders INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
} catch (Exception $e) {
    // Fail silently (optional logging)
}

// 🔹 Prepare folder for reports
$reportBase = __DIR__ . '/../reports';
if (!is_dir($reportBase)) mkdir($reportBase, 0777, true);

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

// 🔹 Check if report for yesterday already exists
try {
    $chk = $pdo->prepare("SELECT id FROM daily_reports WHERE report_date = ? LIMIT 1");
    $chk->execute([$yesterday]);

    if ($chk->rowCount() === 0) {
        // Compute yesterday's totals
        $r = $pdo->prepare("
            SELECT 
                COALESCE(SUM(total),0) AS total_sales,
                COUNT(*) AS total_orders
            FROM orders
            WHERE DATE(created_at) = ? AND status = 'completed'
        ");
        $r->execute([$yesterday]);
        $data = $r->fetch(PDO::FETCH_ASSOC);

        // Insert report into database
        $ins = $pdo->prepare("
            INSERT INTO daily_reports (report_date, total_sales, total_orders)
            VALUES (?, ?, ?)
        ");
        $ins->execute([$yesterday, $data['total_sales'], $data['total_orders']]);

        // 🧾 Create folder for that day's report
        $folder = $reportBase . '/' . $yesterday;
        if (!is_dir($folder)) mkdir($folder, 0777, true);

        // Fetch detailed item sales
        $detailsStmt = $pdo->prepare("
            SELECT i.name, SUM(oi.qty) as qty_sold, SUM(oi.price * oi.qty) as total_price
            FROM order_items oi
            JOIN items i ON oi.item_id = i.id
            JOIN orders o ON oi.order_id = o.id
            WHERE DATE(o.created_at) = ? AND o.status = 'completed'
            GROUP BY i.id
        ");
        $detailsStmt->execute([$yesterday]);
        $details = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

        // Save as JSON report
        $reportData = [
            'date' => $yesterday,
            'total_sales' => $data['total_sales'],
            'total_orders' => $data['total_orders'],
            'details' => $details
        ];

        file_put_contents($folder . '/report.json', json_encode($reportData, JSON_PRETTY_PRINT));

        // Optional: also save a text summary
        $summaryText = "📅 Date: " . date('F d, Y', strtotime($yesterday)) . "\n💰 Total Sales: ₱{$data['total_sales']}\n🧾 Total Orders: {$data['total_orders']}\n\n";
        foreach ($details as $d) {
            $summaryText .= "- {$d['name']}: {$d['qty_sold']} sold (₱{$d['total_price']})\n";
        }
        file_put_contents($folder . '/summary.txt', $summaryText);

        // Deliberately retain orders, order_items, and payments. Foreign-key
        // cascades make deleting an order destructive to its payment history.
    }
} catch (Exception $e) {
    // Optionally log errors
    // file_put_contents(__DIR__ . '/daily_tasks_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// =============================
// Automatic Daily Database Backup
// =============================
// Goal: Run one backup per day automatically (no UI), keep recent backups, log to backups/backup.log
// Assumptions: This file is included on frequently visited pages (e.g., dashboard, orders, inventory)
// Notes: Kept lightweight and self-contained to avoid CLI/mysqldump dependencies

try {
    @ini_set('memory_limit', '512M');
    @set_time_limit(0);

    $backupDir = realpath(__DIR__ . '/../backups');
    if ($backupDir === false) { $backupDir = __DIR__ . '/../backups'; }
    if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }

    // Optional overrides
    @require_once __DIR__ . '/../config/backup.php';
    $autoEnabled = defined('AUTO_BACKUP_ENABLED') ? AUTO_BACKUP_ENABLED : true;
    if (!$autoEnabled) { return; }

    $markerFile = $backupDir . '/.auto_backup.json';
    $today = date('Y-m-d');
    $nowTime = date('H:i');
    $threshold = defined('AUTO_BACKUP_TIME') ? AUTO_BACKUP_TIME : '23:55'; // run near end-of-day if there is traffic
    $marker = ['last_run' => null];
    if (is_file($markerFile)) {
        $j = json_decode(@file_get_contents($markerFile), true);
        if (is_array($j) && isset($j['last_run'])) { $marker = $j; }
    }

    // Run policy:
    // - Prefer to run at the end of the day (>= threshold) if we haven't run today
    // - If we missed one or more days (no traffic at night), run as soon as possible the next time there's traffic
    $lastRun = $marker['last_run'] ?? '';
    $daysSince = ($lastRun && strtotime($lastRun)) ? floor((strtotime($today) - strtotime($lastRun)) / 86400) : PHP_INT_MAX;
    $shouldRun = false;
    if ($lastRun !== $today) {
        if ($nowTime >= $threshold) {
            $shouldRun = true; // end-of-day run
        } elseif ($daysSince >= 1) {
            $shouldRun = true; // catch-up if we missed yesterday
        }
    }

    // Helper: append to backup log
    $logFile = $backupDir . '/backup.log';
    $blog = function(string $m) use ($logFile) {
        $line = '[' . date('Y-m-d H:i:s') . "] " . $m . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
    };

    if ($shouldRun) {
        // Build backup filename
        if (!defined('DB_NAME')) {
            // Load config if not already
            @require_once __DIR__ . '/../config/config.php';
        }
        $dbName = defined('DB_NAME') ? DB_NAME : 'database';
        $timestamp = date('Ymd_His');
        $fileBase = $dbName . '_' . $timestamp;
        $filePath = $backupDir . DIRECTORY_SEPARATOR . $fileBase . '.sql';

        // Dump helper functions
        $quoteValue = function(PDO $pdo, $val): string {
            if ($val === null) return 'NULL';
            if (is_int($val) || is_float($val)) return (string)$val;
            return $pdo->quote((string)$val);
        };

        $getTables = function(PDO $pdo): array {
            $stmt = $pdo->query('SHOW TABLES');
            $t = [];
            while ($r = $stmt->fetch(PDO::FETCH_NUM)) { $t[] = $r[0]; }
            return $t;
        };

        $dumpStructure = function(PDO $pdo, string $table): string {
            $row = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $create = $row['Create Table'] ?? $row['Create View'] ?? '';
            $sql  = "\n--\n-- Structure for table `{$table}`\n--\n\n";
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create . ";\n\n";
            return $sql;
        };

        $dumpData = function(PDO $pdo, string $table) use ($quoteValue): string {
            $sql = "\n--\n-- Data for table `{$table}`\n--\n";
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            if ($count === 0) { return $sql . "\n"; }

            $limit = 500;
            for ($offset = 0; $offset < $count; $offset += $limit) {
                $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$limit} OFFSET {$offset}");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) continue;
                $cols = array_map(fn($c)=>"`".str_replace('`','``',$c)."`", array_keys($rows[0]));
                $sql .= "INSERT INTO `{$table}` (" . implode(', ',$cols) . ") VALUES\n";
                $vals = [];
                foreach ($rows as $r) {
                    $items = [];
                    foreach ($r as $v) { $items[] = $quoteValue($pdo, $v); }
                    $vals[] = '(' . implode(', ', $items) . ')';
                }
                $sql .= implode(",\n", $vals) . ";\n\n";
            }
            return $sql;
        };

        try {
            $blog("Auto-backup starting: {$fileBase}.sql");
            $pdo->exec('SET NAMES utf8mb4');
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

            $tables = $getTables($pdo);
            if (!$tables) { throw new RuntimeException('No tables found.'); }

            $header = "-- Auto database backup\n-- Database: {$dbName}\n-- Generated at: " . date('c') . "\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\n\n";
            file_put_contents($filePath, $header);

            foreach ($tables as $i => $t) {
                $blog("[" . ($i+1) . "/" . count($tables) . "] Structure: {$t}");
                file_put_contents($filePath, $dumpStructure($pdo, $t), FILE_APPEND);
                $blog("[" . ($i+1) . "/" . count($tables) . "] Data: {$t}");
                file_put_contents($filePath, $dumpData($pdo, $t), FILE_APPEND);
            }

            file_put_contents($filePath, "SET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND);
            $blog("Auto-backup done: {$fileBase}.sql");

            // Update marker
            @file_put_contents($markerFile, json_encode(['last_run' => $today]));

            // Retention: delete backups older than N days (configurable)
            $keepDays = defined('AUTO_BACKUP_KEEP_DAYS') ? (int)AUTO_BACKUP_KEEP_DAYS : 14;
            foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $f) {
                if (@filemtime($f) !== false && (time() - filemtime($f)) > ($keepDays*86400)) {
                    @unlink($f);
                }
            }
        } catch (Throwable $be) {
            $blog('Auto-backup failed: ' . $be->getMessage());
            // Don't throw further; avoid breaking page load
        }
    }
} catch (Throwable $_) {
    // Swallow any backup errors to avoid impacting normal app flow
}
?>
