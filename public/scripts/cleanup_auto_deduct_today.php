<?php
// One-time cleanup: revert legacy auto-deduct logs created at order placement for today's pending orders
// Use: Visit /public/scripts/cleanup_auto_deduct_today.php while logged in as admin

require_once __DIR__ . "/../../includes/db.php";
require_once __DIR__ . "/../../includes/auth.php";
require_login();

$pdo = db();

// Require admin
session_start();
if (!isset($_SESSION['user']) || !is_admin()) {
    http_response_code(403);
    echo "Admin only.";
    exit;
}

$today = date('Y-m-d');

// Get today's PENDING orders with order_code
$orders = $pdo->prepare("SELECT id, order_code FROM orders WHERE DATE(created_at)=CURDATE() AND status='pending' AND order_code IS NOT NULL");
$orders->execute();
$pending = $orders->fetchAll(PDO::FETCH_ASSOC);

$affected = 0;
$restoredQty = 0.0;

foreach ($pending as $o) {
    $code = $o['order_code'];
    if (!$code) continue;

    // For this order, find any legacy auto-deduct logs for today
    $logsStmt = $pdo->prepare("
        SELECT inventory_id, SUM(quantity) AS total_qty
        FROM inventory_logs
        WHERE change_type='auto-deduct'
          AND DATE(created_at)=CURDATE()
          AND remarks LIKE ?
        GROUP BY inventory_id
    ");
    $logsStmt->execute(["%Order#" . $code . "%"]);
    $rows = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $rb) {
        $invId = (int)$rb['inventory_id'];
        $qtyAdd = (float)$rb['total_qty'];
        if ($invId && $qtyAdd > 0) {
            // add back
            $pdo->prepare("UPDATE inventory SET qty = qty + ? WHERE id = ?")->execute([$qtyAdd, $invId]);
            // fetch remaining
            $rem = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
            $rem->execute([$invId]);
            $remaining = $rem->fetchColumn();
            // log reversal
            $pdo->prepare("
                INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)
                VALUES (?, 'restock', ?, ?, ?, NOW())
            ")->execute([$invId, $qtyAdd, $remaining, 'Cleanup: revert legacy auto-deduct for pending Order#' . $code]);
            $affected++;
            $restoredQty += $qtyAdd;
        }
    }
}

echo "Cleanup complete. Reverted entries: " . $affected . ", Total quantity restored: " . $restoredQty . ".";
