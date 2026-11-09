<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/order_utils.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_capability('process_transactions');

$pdo = db();

$cart = $_POST['cart'] ?? []; 
// Example: [ ['id'=>1, 'qty'=>2], ['id'=>3, 'qty'=>1] ]

if (empty($cart)) {
    $_SESSION['flash'] = "⚠ No items selected.";
    header("Location: orders.php");
    exit;
}

try {
    $cart = inventory_service_checkout_lines($pdo, is_array($cart) ? $cart : []);
    inventory_service_assert_stock($pdo, $cart);
    $pdo->beginTransaction();

    // 1. Create order (order_code will be assigned centrally after insert)
    $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, created_at, status) VALUES (?, 0, NOW(), 'pending')");
    $stmt->execute([$_SESSION['user']['id'] ?? null]);
    $order_id = $pdo->lastInsertId();

    // Ensure order code is set immediately after creation
    ensure_order_code($pdo, (int)$order_id);

    $total = 0;

    // 2. Insert order items
    foreach ($cart as $c) {
        $item_id = $c['id'];
        $qty = $c['qty'];

        $price = $c['price'];
        $subtotal = $price * $qty;
        $total += $subtotal;

        $pdo->prepare("
            INSERT INTO order_items (order_id, item_id, qty, price)
            VALUES (?,?,?,?)
        ")->execute([$order_id, $item_id, $qty, $price]);
    }

    // 3. Update total
    $pdo->prepare("UPDATE orders SET total=? WHERE id=?")->execute([$total, $order_id]);

    $pdo->commit();
    $_SESSION['flash'] = "✅ Order saved!";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = "❌ Error: " . $e->getMessage();
}

header("Location: order_list.php");
exit;
