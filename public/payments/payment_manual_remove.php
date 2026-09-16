<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_capability('process_transactions');

header('Content-Type: application/json');

$pdo = db();
payment_ensure_schema($pdo);

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$paymentId = isset($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;

if ($orderId <= 0 || $paymentId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>'Invalid input']);
    exit;
}

// Only allow removing manual payments for safety
$stmt = $pdo->prepare('DELETE FROM payments WHERE id = ? AND order_id = ? AND provider = "manual"');
$stmt->execute([$paymentId, $orderId]);

// Return updated totals
$stmt = $pdo->prepare('SELECT total FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$total = (float)($stmt->fetchColumn());
$paid  = payment_total_paid($pdo, $orderId);
$remaining = max(0, $total - $paid);

echo json_encode(['ok'=>true, 'paid'=>$paid, 'remaining'=>$remaining]);
