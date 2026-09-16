<?php
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/order_utils.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . "/includes/name_utils.php";
require_capability('process_transactions');

$pdo = db();

// Centralized inventory status check
function get_inventory_status($pdo) {
    $inventoryCount = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
    $required_sizes = ['12oz Hot', '16oz', '22oz'];
    $missing_cup_sizes = [];
    $missing_lid_sizes = [];
    foreach ($required_sizes as $size) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%cup%' AND cup_size = ?");
        $stmt->execute([$size]);
        if ($stmt->fetchColumn() == 0) $missing_cup_sizes[] = $size;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%') AND cup_size = ?");
        $stmt->execute([$size]);
        if ($stmt->fetchColumn() == 0) $missing_lid_sizes[] = $size;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%straw%'");
    $stmt->execute();
    $missing_straw = ($stmt->fetchColumn() == 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%stirrer%'");
    $stmt->execute();
    $missing_stirrer = ($stmt->fetchColumn() == 0);
    $inventory_blocked = (
        $inventoryCount == 0 ||
        !empty($missing_cup_sizes) ||
        !empty($missing_lid_sizes) ||
        $missing_straw ||
        $missing_stirrer
    );
    return $inventory_blocked;
}

$inventory_blocked = get_inventory_status($pdo);

if ($inventory_blocked) {
    echo "❌ Cannot place order: Missing or low inventory items.";
    exit;
}

$pdo->beginTransaction();

try {
    $orderItems = $_POST['items'];
    $errors = [];
    $catalogItems = [];
    $serverTotal = 0.0;

    // 1️⃣ Check raw materials first
    foreach ($orderItems as $item) {
        $catalogStmt = $pdo->prepare('SELECT id, price FROM items WHERE id = ?');
        $catalogStmt->execute([(int) ($item['id'] ?? 0)]);
        $catalogItem = $catalogStmt->fetch(PDO::FETCH_ASSOC);
        if (!$catalogItem || (int) ($item['qty'] ?? 0) <= 0) {
            $errors[] = 'Invalid menu item in order.';
            continue;
        }
        $catalogItems[(int) $catalogItem['id']] = $catalogItem;
        $serverTotal += (float) $catalogItem['price'] * (int) $item['qty'];
        $requirements = $pdo->prepare("
            SELECT inventory_id, quantity_needed 
            FROM item_requirements 
            WHERE item_id = ?
        ");
        $requirements->execute([$item['id']]);
        $reqs = $requirements->fetchAll();

        foreach ($reqs as $req) {
            $totalNeeded = $req['quantity_needed'] * $item['qty'];

            // Get current stock
            $stmt = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
            $stmt->execute([$req['inventory_id']]);
            $stock = $stmt->fetchColumn();

            if ($stock < $totalNeeded) {
                $errors[] = "❌ Not enough stock for Item ID {$item['id']} (Needs $totalNeeded, Only $stock left)";
            }
        }
    }

    // ❌ If not enough stock → cancel order
    if (!empty($errors)) {
        $pdo->rollBack();
        foreach ($errors as $err) {
            echo $err . "<br>";
        }
        exit;
    }

    // 3️⃣ Create order (order_code will be assigned centrally after insert)
    // Always start as pending to ensure no inventory deduction occurs until completion
    $order_status = 'pending';
    $stmt = $pdo->prepare("
        INSERT INTO orders (user_id, customer_name, total, status, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $customerName = normalize_uppercase_name((string)($_POST['customer_name'] ?? ''));
    $stmt->execute([$_SESSION['user']['id'] ?? null, $customerName !== '' ? $customerName : 'Walk-in', $serverTotal, $order_status]);
    $orderId = $pdo->lastInsertId();

    // Assign centralized daily code after we know the id/created_at
    $orderCode = ensure_order_code($pdo, (int)$orderId);

    // 4️⃣ Insert order items + deduct raw materials
    foreach ($orderItems as $item) {
        $stmt = $pdo->prepare("
            INSERT INTO order_items (order_id, item_id, qty, price)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            $orderId,
            $item['id'],
            $item['qty'],
            $catalogItems[(int) $item['id']]['price']
        ]);

            // Important: Inventory deduction happens only when order is marked completed
            // via order_done.php to avoid deducting for orders that may be voided/cancelled later.
            // If you create orders directly as 'completed', ensure order_done.php is used or
            // implement a similar deduction routine there.
    }

    $pdo->commit();
    echo "✅ Order placed successfully!<br>Order Number: <strong>Order#$orderCode</strong>";

} catch (Exception $e) {
    $pdo->rollBack();
    die("Order failed: " . $e->getMessage());
}
?>
