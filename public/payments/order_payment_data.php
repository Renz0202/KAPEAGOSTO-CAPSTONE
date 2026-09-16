<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_capability('process_transactions');

header('Content-Type: application/json');

$pdo = db();
payment_ensure_schema($pdo);

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>'Invalid order_id']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, total, status FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$order) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'message'=>'Order not found']);
    exit;
}

$total = (float)$order['total'];
$paid  = payment_total_paid($pdo, $orderId);
$remaining = max(0, $total - $paid);

$rawList = payment_list_for_order($pdo, $orderId);
$list = [];
foreach ($rawList as $p) {
    if (($p['status'] ?? '') !== 'succeeded') continue;
    $list[] = [
        'id' => (int)$p['id'],
        'provider' => $p['provider'],
        'amount' => (float)$p['amount'],
        'created_at' => $p['created_at'],
        'removable' => ($p['provider'] === 'manual')
    ];
}

echo json_encode([
    'ok' => true,
    'order' => [
        'id' => (int)$order['id'],
        'status' => $order['status'],
        'total' => $total,
        'paid' => $paid,
        'remaining' => $remaining
    ],
    'payments' => $list
]);
?>
