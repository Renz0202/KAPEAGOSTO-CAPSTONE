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

// Keep a cash payment in place if its change has already been credited.
try {
    $pdo->beginTransaction();
    $orderLock = $pdo->prepare('SELECT sales_invoice_no FROM orders WHERE id = ? FOR UPDATE');
    $orderLock->execute([$orderId]);
    if ($orderLock->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'An invoiced payment must be retained. Use the void process instead.']);
        exit;
    }

    $sourceStmt = $pdo->prepare('SELECT id FROM payments WHERE id = ? AND order_id = ? AND provider = ? LIMIT 1 FOR UPDATE');
    $sourceStmt->execute([$paymentId, $orderId, 'manual']);
    if (!$sourceStmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok'=>false, 'message'=>'Manual payment not found.']);
        exit;
    }

    $creditStmt = $pdo->prepare("SELECT cc.destination_payment_id FROM payment_change_credits cc JOIN payments credited_payment ON credited_payment.id = cc.destination_payment_id JOIN orders destination_order ON destination_order.id = credited_payment.order_id WHERE cc.source_payment_id = ? AND credited_payment.status = 'succeeded' AND LOWER(TRIM(destination_order.status)) NOT IN ('void', 'voided', 'cancelled') LIMIT 1 FOR UPDATE");
    $creditStmt->execute([$paymentId]);
    if ($creditStmt->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['ok'=>false, 'message'=>'This payment cannot be removed because its change has already been applied to added items.']);
        exit;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM payments WHERE id = ? AND order_id = ? AND provider = ?');
    $deleteStmt->execute([$paymentId, $orderId, 'manual']);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false, 'message'=>$e->getMessage()]);
    exit;
}

// Return updated totals
$stmt = $pdo->prepare('SELECT total FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$total = (float)($stmt->fetchColumn());
$paid  = payment_total_paid($pdo, $orderId);
$remaining = max(0, $total - $paid);

echo json_encode(['ok'=>true, 'paid'=>$paid, 'remaining'=>$remaining]);
