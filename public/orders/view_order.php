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
    <?php if (($order['discount_type'] ?? 'none') !== 'none'): ?>
        <p><strong>Subtotal before discount:</strong> PHP <?= number_format((float) ($order['subtotal'] ?? 0), 2) ?></p>
        <?php if ((float) ($order['vat_exempt_amount'] ?? 0) > 0): ?><p><strong>VAT exemption:</strong> PHP <?= number_format((float) $order['vat_exempt_amount'], 2) ?></p><?php endif; ?>
        <p><strong><?= in_array($order['discount_type'], ['senior', 'pwd'], true) ? 'Senior/PWD 20% discount' : 'Promo discount (' . number_format((float) $order['discount_rate'], 2) . '%)' ?>:</strong> PHP <?= number_format((float) ($order['discount_amount'] ?? 0), 2) ?></p>
        <?php if (!empty($order['discount_id_number'])): ?><p><strong>Discount beneficiary:</strong> <?= htmlspecialchars($order['discount_customer_name'] ?? '') ?> (<?= htmlspecialchars(strtoupper($order['discount_beneficiary_type'] ?? '')) ?> ID <?= htmlspecialchars($order['discount_id_number']) ?>)</p><?php endif; ?>
    <?php endif; ?>
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
