<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_capability('process_transactions');

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Order creation requires POST.');
}
try {
    $orderItems = inventory_service_checkout_lines($pdo, is_array($_POST['items'] ?? null) ? $_POST['items'] : []);
    inventory_service_assert_stock($pdo, $orderItems);
    $total = array_sum(array_map(static fn($line) => $line['price'] * $line['qty'], $orderItems));
    $customerName = normalize_uppercase_name((string) ($_POST['customer_name'] ?? ''));
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, customer_name, total, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
    $stmt->execute([$_SESSION['user']['id'] ?? null, $customerName !== '' ? $customerName : 'Walk-in', $total]);
    $orderId = (int) $pdo->lastInsertId();
    $orderCode = ensure_order_code($pdo, $orderId);
    $insert = $pdo->prepare('INSERT INTO order_items (order_id, item_id, qty, price) VALUES (?, ?, ?, ?)');
    foreach ($orderItems as $line) $insert->execute([$orderId, $line['id'], $line['qty'], $line['price']]);
    // Pending orders consume stock only when marked Done.
    $pdo->commit();
    echo 'Order placed successfully!<br>Order Number: <strong>Order#' . htmlspecialchars((string) $orderCode) . '</strong>';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($e instanceof InvalidArgumentException ? 400 : 409);
    if ($e instanceof PDOException) {
        error_log('Order creation failed: ' . $e->getMessage());
        exit('The order could not be saved. Please try again.');
    }
    echo htmlspecialchars($e->getMessage());
}
