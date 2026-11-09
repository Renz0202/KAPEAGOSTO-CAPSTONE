<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . '/includes/inventory_service.php';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        inventory_service_adjust_stock($pdo, (int) $id, $_POST['quantity'] ?? null, 'deduct', (int) ($_SESSION['user']['id'] ?? 0) ?: null, 'Manual deduct');
        $pdo->commit();
        header('Location: inventory.php?used=1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header('Location: inventory.php?error=1');
    }
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
          <label>Quantity to Deduct (<?= htmlspecialchars($item['unit']) ?>):</label>
          <input type="number" name="quantity" step="0.01" min="0.01" max="<?= htmlspecialchars($item['qty']) ?>" required>
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
