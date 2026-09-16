<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_admin();

$pdo = db();

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: inventory.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM inventory WHERE id=?");
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    die("Item not found!");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $qty = (int) $_POST['quantity'];

    if ($qty > 0) {
        if ($qty <= $item['qty']) {
            // 1. Update stock
            $remaining = (float) $item['qty'] - $qty;
            $pdo->prepare("UPDATE inventory SET qty = ? WHERE id=?")->execute([$remaining, $id]);

            // 2. Insert log
            $logStmt = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks) VALUES (?, 'deduct', ?, ?, 'Manual deduction')");
            $logStmt->execute([$id, $qty, $remaining]);
        } else {
            $_SESSION['flash'] = "Not enough stock to deduct! (Available: {$item['qty']})";
            header("Location: inventory.php");
            exit;
        }
    }

    header("Location: inventory.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Deduct <?= htmlspecialchars($item['name']) ?></title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  </head>
<body class="kape-inventory-module-deduct">
  <?php include dirname(__DIR__, 2) . "/includes/sidebar.php"; ?>
  <?php include dirname(__DIR__, 2) . "/includes/topbar.php"; ?>

  <div class="main-content">
    <h1>➖ Deduct: <?= htmlspecialchars($item['name']) ?></h1>

    <div class="form-card">
      <form method="post">
        <div class="form-group">
          <label>Quantity to Deduct:</label>
          <input type="number" name="quantity" min="1" max="<?= htmlspecialchars($item['qty']) ?>" required>
        </div>
        <button type="submit">Deduct Stock</button>
      </form>
    </div>

    <p style="margin-top:15px;">
      <a href="inventory.php" class="btn">⬅ Back to Inventory</a>
    </p>
  </div>
</body>
</html>
