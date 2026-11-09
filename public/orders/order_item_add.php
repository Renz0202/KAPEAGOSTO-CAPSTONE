<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/order_discounts.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_capability('process_transactions');

header('Content-Type: application/json');

$pdo = db();
payment_ensure_schema($pdo);
$checkoutTaxType = settings_value(settings_all($pdo), 'bir_tax_type');

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$itemId  = isset($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
$qty     = isset($_POST['qty']) ? (int)$_POST['qty'] : 0;

if ($orderId <= 0 || $itemId <= 0 || $qty <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'message'=>'Invalid input']);
    exit;
}

// Ensure order is pending
$stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$status = $stmt->fetchColumn();
if (!$status) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'message'=>'Order not found']);
    exit;
}
if ($status !== 'pending') {
    http_response_code(409);
    echo json_encode(['ok'=>false, 'message'=>'Only pending orders can be modified']);
    exit;
}

// Fetch item details
$stmt = $pdo->prepare("SELECT price FROM items WHERE id = ? AND cup_size IN ('12oz Hot', '16oz', '22oz', '16oz Cold', '22oz Cold')");
$stmt->execute([$itemId]);
$price = $stmt->fetchColumn();
if ($price === false) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'message'=>'Item not found']);
    exit;
}

try{
    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT status FROM orders WHERE id = ? FOR UPDATE');
    $lock->execute([$orderId]);
    if ($lock->fetchColumn() !== 'pending') {
        throw new RuntimeException('Only pending orders can be modified.');
    }
    $existingItems = $pdo->prepare('SELECT item_id, qty FROM order_items WHERE order_id = ?');
    $existingItems->execute([$orderId]);
    $lines = $existingItems->fetchAll(PDO::FETCH_ASSOC);
    $lines[] = ['item_id' => $itemId, 'qty' => $qty];
    $warnings = inventory_service_stock_warnings($pdo, $lines);
    if ($warnings['blocking']) {
        throw new RuntimeException('Insufficient materials: ' . implode(', ', $warnings['blocking']));
    }

    // Insert order item
    $ins = $pdo->prepare('INSERT INTO order_items (order_id, item_id, qty, price) VALUES (?,?,?,?)');
    $ins->execute([$orderId, $itemId, $qty, $price]);

    // Recompute order total from order_items
    $sum = $pdo->prepare('SELECT COALESCE(SUM(qty * price),0) FROM order_items WHERE order_id = ?');
    $sum->execute([$orderId]);
    $newSubtotal = (float)$sum->fetchColumn();
    $discount = order_discount_recalculate_pending($pdo, $orderId, $newSubtotal, $checkoutTaxType);
    $newTotal = $discount['total'];

    payment_apply_pending_order_change_credit($pdo, $orderId);

    $paid = payment_total_paid($pdo, $orderId);
    $remaining = max(0, $newTotal - $paid);

    $pdo->commit();
    echo json_encode(['ok'=>true, 'total'=>$newTotal, 'paid'=>$paid, 'remaining'=>$remaining]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false, 'message'=>$e->getMessage()]);
}

?>
