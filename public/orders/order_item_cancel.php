<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_capability('process_transactions');

header('Content-Type: application/json');

$pdo = db();

$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$orderItemId = isset($_POST['order_item_id']) ? (int)$_POST['order_item_id'] : 0;

if ($orderId <= 0 || $orderItemId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid input']);
    exit;
}

// Validate order exists and is pending
$stmt = $pdo->prepare('SELECT status FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$orderStatus = $stmt->fetchColumn();
if (!$orderStatus) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Order not found']);
    exit;
}
if ($orderStatus !== 'pending') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'message' => 'Only pending orders can be modified']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Ensure the order item belongs to the order
    $stmt = $pdo->prepare('SELECT subtotal FROM order_items WHERE id = ? AND order_id = ?');
    $stmt->execute([$orderItemId, $orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Order item not found');
    }

    // Delete the order item (no soft-cancel column exists)
    $del = $pdo->prepare('DELETE FROM order_items WHERE id = ? AND order_id = ?');
    $del->execute([$orderItemId, $orderId]);

    // Recompute order total from remaining items
    $sum = $pdo->prepare('SELECT COALESCE(SUM(subtotal),0) FROM order_items WHERE order_id = ?');
    $sum->execute([$orderId]);
    $newTotal = (float)$sum->fetchColumn();

    $upd = $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?');
    $upd->execute([$newTotal, $orderId]);

    // Compute updated payment stats
    $paid = payment_total_paid($pdo, $orderId);
    $remaining = max(0, $newTotal - $paid);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'total' => $newTotal,
        'paid' => $paid,
        'remaining' => $remaining
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
?>
