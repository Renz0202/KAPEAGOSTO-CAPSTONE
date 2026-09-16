<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';

$pdo = db();
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$reason = $_GET['reason'] ?? 'cancelled';

// best-effort: mark latest pending payment as cancelled
if ($orderId > 0) {
    $latest = payment_latest_for_order($pdo, $orderId);
    if ($latest && in_array($latest['status'], ['created','pending'])) {
        $upd = $pdo->prepare('UPDATE payments SET status = "cancelled", updated_at = NOW() WHERE id = ?');
        $upd->execute([$latest['id']]);
    }
}

header('Location: receipt_template.php?order_id=' . $orderId . '&cancelled=1');
exit;
