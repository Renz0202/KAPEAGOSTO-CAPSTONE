<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_login();

$pdo = db();
$date = $_GET['date'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT o.id, COALESCE(o.voided_at, o.created_at) AS event_at, u.username, o.total
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.status = 'void' AND DATE(COALESCE(o.voided_at, o.created_at)) = ?
    ORDER BY event_at DESC
");
$stmt->execute([$date]);
$voided_orders = $stmt->fetchAll();

$order_items = [];
foreach ($voided_orders as $order) {
    $items_stmt = $pdo->prepare("
        SELECT i.name, oi.qty, oi.subtotal
        FROM order_items oi
        JOIN items i ON oi.item_id = i.id
        WHERE oi.order_id = ?
    ");
    $items_stmt->execute([$order['id']]);
    $order_items[$order['id']] = $items_stmt->fetchAll();
}

if (empty($voided_orders)) {
    echo '<div class="alert warning">No voided orders for this date.</div>';
} else {
    echo '<table class="voided-orders-table">
        <thead>
          <tr>
            <th>Order ID</th>
            <th>User</th>
            <th>Total</th>
            <th>Voided At</th>
            <th>Items</th>
          </tr>
        </thead>
        <tbody>';
    foreach ($voided_orders as $order) {
        echo '<tr>
            <td>' . $order['id'] . '</td>
            <td>' . htmlspecialchars($order['username'] ?? '-') . '</td>
            <td>₱' . number_format($order['total'],2) . '</td>
            <td>' . $order['event_at'] . '</td>
            <td>';
        if (!empty($order_items[$order['id']])) {
            echo '<table class="order-items-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Qty</th>
                      <th>Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>';
            foreach ($order_items[$order['id']] as $item) {
                echo '<tr>
                      <td>' . htmlspecialchars($item['name']) . '</td>
                      <td>' . $item['qty'] . '</td>
                      <td>₱' . number_format($item['subtotal'],2) . '</td>
                    </tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<span style="color:#aaa;">No items</span>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}
?>
