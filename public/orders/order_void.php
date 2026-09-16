<?php
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_login();
require_transactions_available();

$pdo = db();

// --- Require admin permission for voiding ---
$is_admin = is_admin();
$has_admin_override = isset($_SESSION['admin_override']) && isset($_SESSION['admin_override']['expires']) && $_SESSION['admin_override']['expires'] >= time();

// --- Branching for void condition ---
if (!$is_admin && !$has_admin_override) {
  // Staff: show admin permission modal
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    renderAdminPermissionModal((int)$_POST['order_id']);
    exit;
  }
} else {
  // Admin or override: show confirmation modal, then process void
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && !isset($_POST['confirm_void'])) {
    header('Location: order_list.php?void_order_id=' . (int) $_POST['order_id']);
    exit;
  }
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id']) && isset($_POST['confirm_void'])) {
    // Proceed with void using unified id

    // --- Added strict numeric validation ---
    $raw_order_id = $_POST['order_id'];
    if (!ctype_digit((string)$raw_order_id)) {
      header("Location: order_list.php?error=bad_order_id");
      exit;
    }
    $order_id = (int)$raw_order_id;

    // Check current status + existence
    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
      // --- Order not found ---
      if ($has_admin_override) unset($_SESSION['admin_override']);
      header("Location: order_list.php?error=not_found&order_id=$order_id");
      exit;
    }

    if ($status === 'pending') {
      // Only set to void if still pending
      $upd = $pdo->prepare("UPDATE orders SET status = 'void', job_status = 'cancelled', voided_at = NOW() WHERE id = ?");
      $upd->execute([$order_id]);

      // Attempt to rollback any auto-deducted inventory tied to this order (if any)
      try {
        // Get order_code if present
        $ocStmt = $pdo->prepare("SELECT order_code FROM orders WHERE id = ?");
        $ocStmt->execute([$order_id]);
        $order_code = $ocStmt->fetchColumn();

        if ($order_code) {
          // Find any auto-deduct or used logs for this order based on remarks convention
          $logsStmt = $pdo->prepare("\n            SELECT inventory_id, SUM(quantity) AS total_qty\n            FROM inventory_logs\n            WHERE change_type IN ('auto-deduct','used') AND remarks LIKE ?\n            GROUP BY inventory_id\n          ");
          $logsStmt->execute(["%Order#" . $order_code . "%"]);
          $rollbacks = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

          foreach ($rollbacks as $rb) {
            $invId = (int)$rb['inventory_id'];
            $qtyAdd = (float)$rb['total_qty'];
            if ($invId && $qtyAdd > 0) {
              // Add back to inventory
              $updInv = $pdo->prepare("UPDATE inventory SET qty = qty + ? WHERE id = ?");
              $updInv->execute([$qtyAdd, $invId]);

              // Get new remaining
              $remStmt = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
              $remStmt->execute([$invId]);
              $remaining = $remStmt->fetchColumn();

              // Log the reversal as a restock
              $logStmt = $pdo->prepare("\n                INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)\n                VALUES (?, 'restock', ?, ?, ?, NOW())\n              ");
              $logStmt->execute([$invId, $qtyAdd, $remaining, 'Reversal for void Order#' . $order_code]);
            }
          }
        }
      } catch (Exception $e) {
        // Failing rollback shouldn't block voiding; optionally log error
      }

      // Determine admin identity for logging (use override if present)
      if ($has_admin_override) {
        $admin_username = $_SESSION['admin_override']['username'];
        $admin_user_id = $_SESSION['admin_override']['id'];
      } else {
        $admin_username = $_SESSION['user']['username'] ?? 'admin';
        $admin_user_id = $_SESSION['user']['id'] ?? null;
      }

      // Log notification for admin account (optional, only if table exists)
      try {
        $pdo->query("SELECT 1 FROM notifications LIMIT 1");
        $notif_stmt = $pdo->prepare("INSERT INTO notifications (user_id, message, created_at) VALUES (?, ?, NOW())");
        $notif_stmt->execute([$admin_user_id, "Order #$order_id has been voided by admin $admin_username"]);
      } catch (PDOException $e) {
        // Table does not exist, skip notification
      }

      // --- Rebuild / update daily sales report safely ---
      $today = date('Y-m-d');
      try {
        // Ensure report tables exist; if not, skip gracefully
        $pdo->query("SELECT 1 FROM daily_sales_reports LIMIT 1");
        $pdo->query("SELECT 1 FROM daily_sales_items LIMIT 1");

        $total_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn();
        $total_sales = $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status='completed'")->fetchColumn();
        $sales_tax = round($total_sales * 0.12, 2);

        $pdo->beginTransaction();

        // Fetch existing report id
        $report_id_stmt = $pdo->prepare("SELECT id FROM daily_sales_reports WHERE report_date=?");
        $report_id_stmt->execute([$today]);
        $report_id = $report_id_stmt->fetchColumn();

        if (!$report_id) {
          // Insert new report row if missing
            $ins_report = $pdo->prepare("INSERT INTO daily_sales_reports (report_date,total_sales,total_orders,sales_tax,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())");
            $ins_report->execute([$today,$total_sales,$total_orders,$sales_tax]);
            $report_id = $pdo->lastInsertId();
        } else {
          // Update existing report
          $upd_report = $pdo->prepare("UPDATE daily_sales_reports SET total_sales=?, total_orders=?, sales_tax=?, updated_at=NOW() WHERE id=?");
          $upd_report->execute([$total_sales,$total_orders,$sales_tax,$report_id]);
        }

        // Rebuild items
        $del_stmt = $pdo->prepare("DELETE FROM daily_sales_items WHERE report_id=?");
        $del_stmt->execute([$report_id]);

        $items = $pdo->query("
          SELECT 
            oi.item_id,
            i.name AS item_name,
            i.price,
            SUM(oi.qty) AS total_qty,
            SUM(oi.subtotal) AS total_amount
          FROM order_items oi
          JOIN orders o ON oi.order_id = o.id
          JOIN items i ON oi.item_id = i.id
          WHERE DATE(o.created_at) = CURDATE() AND o.status = 'completed'
          GROUP BY oi.item_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($items)) {
          $item_stmt = $pdo->prepare("INSERT INTO daily_sales_items (report_id, item_id, item_name, price, qty, subtotal) VALUES (?,?,?,?,?,?)");
          foreach ($items as $it) {
            $item_stmt->execute([
              $report_id,
              $it['item_id'],
              $it['item_name'],
              $it['price'],
              $it['total_qty'],
              $it['total_amount']
            ]);
          }
        }

        $pdo->commit();
      } catch (PDOException $e) {
        // Rollback if something failed during report rebuild
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Silent fail; do not block void operation
      }

      // If we used a temporary admin override, clear it so session returns to the original user
      if ($has_admin_override) {
        unset($_SESSION['admin_override']);
      }
      header("Location: order_list.php?void_success=1&order_id=$order_id");
      exit;
    } else if ($status === 'void') {
      // Already voided
      // If there was an override, clear it
      if ($has_admin_override) unset($_SESSION['admin_override']);
      header("Location: order_list.php?error=void&order_id=$order_id");
      exit;
    } else {
      // Do not change status if already completed or cancelled
      if ($has_admin_override) unset($_SESSION['admin_override']);
      header("Location: order_list.php?error=not_pending&order_id=$order_id");
      exit;
    }
  } // closes confirm_void block
} // closes outer else block (admin or override)

// Continue with page rendering
$date = $_GET['date'] ?? date('Y-m-d');

// Fetch voided orders for the date
$stmt = $pdo->prepare("
    SELECT o.id, COALESCE(o.voided_at, o.created_at) AS event_at, u.username, o.total
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE o.status = 'void' AND DATE(COALESCE(o.voided_at, o.created_at)) = ?
    ORDER BY event_at DESC
");

$stmt->execute([$date]);
$voided_orders = $stmt->fetchAll();

// Fetch items for each voided order
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

function renderAdminPermissionModal(int $order_id): void { ?>
  <div class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="adminPermTitle" style="background:rgba(80,80,80,0.96);display:grid;place-items:center;min-height:100vh;">
    <div class="modal-card brand" style="max-width:420px;">
      <img src="img/CUP_BLACK.png" alt="Kape Agosto" class="modal-logo">
      <h2 id="adminPermTitle" style="font-size:24px;margin-bottom:14px;">Admin Permission Required</h2>
      <p class="modal-desc" style="font-size:16px;">
        To void/cancel this order, please contact your Admin or sign in as an Admin.<br>
        <span class="emphasis" style="color:#c40024;">Only an Admin can proceed with this action</span>
      </p>
      <p style="font-size:15px;margin:0 0 18px;"><b>Order ID:</b> <?= htmlspecialchars($order_id) ?></p>
      <form method="get" action="order_list.php" style="display:flex;flex-direction:column;gap:10px;">
        <input type="hidden" name="void_order_id" value="<?= $order_id ?>">
        <input type="hidden" name="void_by_staff" value="1">
        <button type="submit" class="btn-wide btn-outline" style="height:44px;font-size:17px;border-radius:14px;background:#fff;border:2px solid #222;color:#222;">Proceed to Admin Login</button>
        <button type="button" onclick="location.href='order_list.php'" class="btn-wide btn-secondary" style="height:44px;font-size:17px;border-radius:14px;background:#eee;color:#222;">Cancel</button>
      </form>
    </div>
  </div>
<?php }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Added viewport for responsiveness -->
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
    <title>Voided Orders – <?= htmlspecialchars($date) ?> | Kape Agosto</title>
    <!-- removed external link -->
      <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
    </head>
<body class="kape-orders-order-void">
<?php include dirname(__DIR__, 2) . "/includes/sidebar.php"; ?>
<?php include dirname(__DIR__, 2) . "/includes/topbar.php"; ?>

<div class="content">
    <h1>🚫 Voided Orders for <?= htmlspecialchars($date) ?></h1>
    <div style="text-align:center;">
      <?php if ($is_admin): ?>
        <a href="reports.php" class="btn gray"><i class="fa fa-arrow-left"></i> Back to Reports</a>
      <?php else: ?>
        <a href="order_list.php" class="btn gray"><i class="fa fa-arrow-left"></i> Back to Orders</a>
      <?php endif; ?>
    </div>
    <div id="voidedOrdersContainer" class="table-responsive">
    <?php if (empty($voided_orders)): ?>
      <div class="alert warning" style="text-align:center;">No voided orders for this date.</div>
    <?php else: ?>
      <table class="voided-orders-table">
        <thead>
          <tr>
            <th>Order ID</th>
            <th>User</th>
            <th>Total</th>
            <th>Voided At</th>
            <th>Items</th>
          </tr>
        </thead>
        <tbody id="voidedOrdersRows">
          <?php foreach ($voided_orders as $order): ?>
          <tr>
            <td><?= $order['id'] ?></td>
            <td><?= htmlspecialchars($order['username'] ?? '-') ?></td>
            <td>₱<?= number_format($order['total'],2) ?></td>
            <td><?= $order['event_at'] ?></td>
            <td>
              <?php if (!empty($order_items[$order['id']])): ?>
                <table class="order-items-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th>Qty</th>
                      <th>Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($order_items[$order['id']] as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= $item['qty'] ?></td>
                      <td>₱<?= number_format($item['subtotal'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php else: ?>
                <span style="color:#aaa;">No items</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div>
</div>
<script>
// Real-time polling for voided orders every 10 seconds
setInterval(function() {
    fetch('order_void_data.php?date=<?= urlencode($date) ?>')
        .then(response => response.text())
        .then(html => {
            document.getElementById('voidedOrdersContainer').innerHTML = html;
        });
}, 10000);

// Center safeguard
function enforceModalCenter(){
  document.querySelectorAll('.modal-overlay,.login-modal-bg').forEach(m=>{
    if (getComputedStyle(m).display !== 'none') {
      m.style.display='grid';
      m.style.placeItems='center';
      m.style.justifyItems='center';
      m.style.minHeight='100vh';
    }
  });
}
enforceModalCenter();
</script>
</body>
</html>
