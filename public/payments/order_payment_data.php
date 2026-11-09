<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_capability('process_transactions');

header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo = db();
payment_ensure_schema($pdo);

$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>'Invalid order_id']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, total, status, sales_invoice_no FROM orders WHERE id = ?');
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
    $decoded = !empty($p['raw_response']) ? json_decode((string) $p['raw_response'], true) : [];
    $cashDetails = payment_cash_details($p);
    $provider = (string) ($p['provider'] ?? '');
    $sourceOrderId = ($provider === 'change_credit' && is_array($decoded)) ? (int) ($decoded['source_order_id'] ?? 0) : 0;
    $providerLabel = [
        'manual' => 'Cash',
        'stripe' => 'Card',
        'change_credit' => 'Change Credit',
    ][$provider] ?? ucfirst($provider);
    $list[] = [
        'id' => (int)$p['id'],
        'provider' => $provider,
        'label' => $providerLabel,
        'source_order_id' => $sourceOrderId,
        'amount' => (float)$p['amount'],
        'tendered' => $cashDetails['tendered'] ?? null,
        'change' => $cashDetails['change'] ?? 0,
        'created_at' => $p['created_at'],
        'removable' => ($provider === 'manual' && empty($order['sales_invoice_no']))
    ];
}

echo json_encode([
    'ok' => true,
    'order' => [
        'id' => (int)$order['id'],
        'sales_invoice_no' => $order['sales_invoice_no'],
        'status' => $order['status'],
        'total' => $total,
        'paid' => $paid,
        'remaining' => $remaining
    ],
    'cash_summary' => payment_receipt_summary($pdo, payment_related_order_ids($pdo, $orderId)),
    'payments' => $list
]);
?>
