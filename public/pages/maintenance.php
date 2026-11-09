<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';

require_login();

if (!system_is_maintenance()) {
    header('Location: dashboard.php');
    exit;
}

$settings = settings_all(db());
$maintenanceMessage = trim(settings_value($settings, 'maintenance_message'));
if ($maintenanceMessage === '') {
    $maintenanceMessage = 'Transactions are temporarily unavailable while system maintenance is in progress.';
}

unset($_SESSION['maintenance_notice']);
http_response_code(503);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Maintenance Mode - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-maintenance-mode-page">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<dialog class="maintenance-alert-modal" id="maintenanceAlertModal" aria-labelledby="maintenance-alert-title" aria-describedby="maintenance-alert-message">
  <div class="maintenance-alert-content">
    <span class="maintenance-alert-icon" aria-hidden="true"><i class="fa fa-screwdriver-wrench"></i></span>
    <p class="maintenance-alert-eyebrow">System notice</p>
    <h2 id="maintenance-alert-title">Maintenance in progress</h2>
    <p id="maintenance-alert-message"><?= htmlspecialchars($maintenanceMessage) ?></p>
    <form method="dialog">
      <button type="submit" autofocus>Understood</button>
    </form>
  </div>
</dialog>

<main class="content maintenance-mode-main">
  <section class="maintenance-mode-card" aria-labelledby="maintenance-mode-title">
    <span class="maintenance-mode-icon" aria-hidden="true"><i class="fa fa-screwdriver-wrench"></i></span>
    <p class="maintenance-mode-eyebrow">System status</p>
    <h1 id="maintenance-mode-title">Transactions are paused</h1>
    <p class="maintenance-mode-message"><?= htmlspecialchars($maintenanceMessage) ?></p>

    <div class="maintenance-mode-details">
      <div><i class="fa fa-lock"></i><span><strong>Currently unavailable</strong>New orders, item changes, payments, completion, job updates, and voiding.</span></div>
      <div><i class="fa fa-eye"></i><span><strong>Still available</strong><?= is_cashier() ? 'Item list, order list, and profile.' : 'Dashboard, order history, reports, inventory review, profile, and administration.' ?></span></div>
    </div>

    <div class="maintenance-mode-actions">
      <?php if (is_cashier()): ?>
        <a class="maintenance-mode-primary" href="items.php"><i class="fa fa-mug-hot"></i> Item List</a>
        <a href="order_list.php"><i class="fa fa-list"></i> Order List</a>
      <?php else: ?>
        <a class="maintenance-mode-primary" href="dashboard.php"><i class="fa fa-house"></i> Dashboard</a>
        <a href="order_list.php?scope=all"><i class="fa fa-clock-rotate-left"></i> Order History</a>
      <?php endif; ?>
      <?php if (is_admin()): ?>
        <a href="settings.php"><i class="fa fa-gear"></i> System Settings</a>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php if (!is_cashier()) { include dirname(__DIR__) . '/partials/admin_footer.php'; } ?>
<script>
  (function () {
    var modal = document.getElementById('maintenanceAlertModal');
    if (modal && typeof modal.showModal === 'function') modal.showModal();
  })();
</script>
</body>
</html>
