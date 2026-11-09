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

// Page requests provide catch-up if a scheduled run was missed.
require_once __DIR__ . '/backup_service.php';
try {
    $backupDirectory = dirname(__DIR__) . '/backups';
    if (backup_is_due(backup_read_state($backupDirectory), new DateTimeImmutable())) {
        backup_run($pdo, $backupDirectory);
    }
} catch (Throwable $error) {
    error_log('Automatic backup failed: ' . $error->getMessage());
}