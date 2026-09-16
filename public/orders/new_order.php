<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/order_utils.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . "/includes/name_utils.php";
require_capability('process_transactions');

$pdo = db();

$items = $pdo->query("SELECT * FROM items")->fetchAll();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $customer_name = normalize_uppercase_name((string)($_POST['customer_name'] ?? ''));
    $selected_items = $_POST['items'] ?? [];

    if (!empty($selected_items)) {
        $pdo->beginTransaction();
        try {
            // Insert order (order_code will be assigned centrally after insert)
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, customer_name, total, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
            $total = 0;

            foreach ($selected_items as $item_id => $qty) {
                if ($qty > 0) {
                    $item = $pdo->prepare("SELECT * FROM items WHERE id=?");
                    $item->execute([$item_id]);
                    $itemData = $item->fetch();

                    $total += $itemData['price'] * $qty;
                }
            }

            $stmt->execute([$_SESSION['user']['id'] ?? null, $customer_name, $total]);
            $order_id = $pdo->lastInsertId();

            // Assign centralized daily order code
            ensure_order_code($pdo, (int)$order_id);

            // Insert order items
            foreach ($selected_items as $item_id => $qty) {
                if ($qty > 0) {
                    $item = $pdo->prepare("SELECT * FROM items WHERE id=?");
                    $item->execute([$item_id]);
                    $itemData = $item->fetch();

                    $stmt = $pdo->prepare("INSERT INTO order_items (order_id, item_id, qty, price) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$order_id, $item_id, $qty, $itemData['price']]);
                }
            }

            $pdo->commit();
            header("Location: orders.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            die("Error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Order</title>
    <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-orders-new-order">
    <h1>New Order</h1>
    <form method="post">
        <label>Customer Name:</label><br>
        <input type="text" name="customer_name" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required><br><br>

        <table border="1" cellpadding="5">
            <tr>
                <th>Item</th><th>Price</th><th>Quantity</th>
            </tr>
            <?php foreach ($items as $i): ?>
                <tr>
                    <td><?= htmlspecialchars($i['name']) ?></td>
                    <td>₱<?= number_format($i['price'], 2) ?></td>
                    <td><input type="number" name="items[<?= $i['id'] ?>]" min="0" value="0"></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <br>
        <button type="submit">Submit Order</button>
    </form>
    <p><a href="orders.php">⬅ Back to Orders</a></p>
</body>
</html>
