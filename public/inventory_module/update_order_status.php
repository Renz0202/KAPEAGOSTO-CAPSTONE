<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_service.php';
require_capability('process_transactions');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Order status updates require POST.');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$newStatus = trim((string) ($_POST['status'] ?? ''));
if ($orderId <= 0 || !in_array($newStatus, ['pending', 'completed', 'cancelled'], true)) {
    http_response_code(400);
    exit('Invalid status update.');
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT status, total FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] !== 'pending') {
        throw new RuntimeException('Only pending orders can be updated.');
    }
    if ($newStatus === 'completed') {
        if (!payment_is_order_paid($pdo, $orderId)) throw new RuntimeException('Complete payment before finishing the order.');
        inventory_service_complete_order($pdo, $orderId, (int) ($_SESSION['user']['id'] ?? 0) ?: null);
        $pdo->prepare("UPDATE orders SET status = 'completed', job_status = 'completed' WHERE id = ?")->execute([$orderId]);
    } elseif ($newStatus === 'cancelled') {
        $pdo->prepare("UPDATE orders SET status = 'cancelled', job_status = 'cancelled', cancelled_at = NOW() WHERE id = ?")->execute([$orderId]);
    }
    $pdo->commit();
    header('Location: order_list.php?success=1&order_id=' . $orderId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Order status update failed: ' . $e->getMessage());
    header('Location: order_list.php?error=complete_failed&order_id=' . $orderId);
}
exit;
