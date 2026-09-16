<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_service.php';
require_capability('process_transactions');

$pdo = db();
payment_ensure_schema($pdo);

function order_done_redirect(string $error, int $orderId): void {
    header('Location: order_list.php?error=' . urlencode($error) . '&order_id=' . $orderId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['order_id'])) {
    header('Location: order_list.php?error=invalid_request');
    exit;
}

$orderId = (int) $_POST['order_id'];
if ($orderId <= 0) {
    order_done_redirect('invalid_order', $orderId);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, order_code, total, TRIM(LOWER(status)) AS status FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        order_done_redirect('missing_order', $orderId);
    }

    if ((string) $order['status'] !== 'pending') {
        $pdo->rollBack();
        order_done_redirect('not_pending', $orderId);
    }

    $paid = payment_total_paid($pdo, $orderId);
    $total = (float) $order['total'];
    if ($total <= 0 || $paid + 0.009 < $total) {
        $pdo->rollBack();
        order_done_redirect('unpaid', $orderId);
    }

    inventory_service_complete_order($pdo, $orderId, (int) ($_SESSION['user']['id'] ?? 0) ?: null);

    $update = $pdo->prepare("UPDATE orders SET status = 'completed', job_status = 'completed' WHERE id = ? AND TRIM(LOWER(status)) = 'pending'");
    $update->execute([$orderId]);

    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        order_done_redirect('not_pending', $orderId);
    }

    $pdo->commit();
    header('Location: order_list.php?success=1&order_id=' . $orderId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order completion failed for order ' . $orderId . ': ' . $e->getMessage());
    order_done_redirect('complete_failed', $orderId);
}
?>
