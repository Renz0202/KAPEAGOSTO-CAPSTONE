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
$method  = trim($_POST['method'] ?? 'cash');
$amount  = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;

// Enforce allowed payment methods (only cash for now)
if (!in_array($method, ALLOWED_MANUAL_METHODS, true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'message'=>'Payment method not allowed']);
    exit;
}

if ($orderId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>'Invalid input']);
    exit;
}

// Fetch order total
$stmt = $pdo->prepare('SELECT total, status FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'message'=>'Order not found']);
    exit;
}
if ($order['status'] !== 'pending') {
    http_response_code(409);
    echo json_encode(['ok'=>false, 'message'=>'Payments can only be added to pending orders']);
    exit;
}

$total = (float)$order['total'];
$paid  = payment_total_paid($pdo, $orderId);
$remaining = max(0, $total - $paid);

$tendered = $amount;
$amount = min($amount, $remaining);
if ($amount <= 0) {
    http_response_code(409);
    echo json_encode(['ok'=>false, 'message'=>'This order is already fully paid']);
    exit;
}

// Insert as manual payment (succeeded)
$raw = json_encode(['method'=>$method, 'tendered'=>$tendered, 'change'=>max(0, $tendered - $amount)]);
$ins = $pdo->prepare('INSERT INTO payments (order_id, provider, amount, currency, status, raw_response, paid_at) VALUES (?,?,?,?,"succeeded",?,NOW())');
$ins->execute([$orderId, 'manual', $amount, 'php', $raw]);

$paid  = payment_total_paid($pdo, $orderId);
$remaining = max(0, $total - $paid);

echo json_encode(['ok'=>true, 'paid'=>$paid, 'remaining'=>$remaining]);
