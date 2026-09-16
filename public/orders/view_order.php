<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_capability('view_order_history');

$pdo = db();

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: orders.php");
    exit;
}

$order = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$order->execute([$id]);
$order = $order->fetch();

if (!$order) {
    die("Order not found!");
}

$items = $pdo->prepare("
    SELECT oi.qty AS quantity, oi.price, it.name
    FROM order_items oi 
    JOIN items it ON oi.item_id = it.id 
    WHERE oi.order_id=?
");
$items->execute([$id]);
$order_items = $items->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?= $order['id'] ?></title>
    <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-orders-view-order">
    <h1>Order #<?= $order['id'] ?></h1>
    <p><strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
    <p><strong>Job status:</strong> <?= htmlspecialchars(ucwords(str_replace('_', ' ', $order['job_status'] ?? 'queued'))) ?></p>
    <p><strong>Service details:</strong> <?= nl2br(htmlspecialchars($order['service_details'] ?? '')) ?></p>
    <p><strong>Total:</strong> ₱<?= number_format($order['total'], 2) ?></p>
    <p><strong>Date:</strong> <?= $order['created_at'] ?></p>

    <h3>Items</h3>
    <table border="1" cellpadding="5">
        <tr>
            <th>Item</th><th>Price</th><th>Qty</th><th>Subtotal</th>
        </tr>
        <?php foreach ($order_items as $i): ?>
            <tr>
                <td><?= htmlspecialchars($i['name']) ?></td>
                <td>₱<?= number_format($i['price'], 2) ?></td>
                <td><?= $i['quantity'] ?></td>
                <td>₱<?= number_format($i['price'] * $i['quantity'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p><a href="orders.php">⬅ Back to Orders</a></p>
</body>
</html>
