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

<main class="content maintenance-mode-main">
  <section class="maintenance-mode-card" aria-labelledby="maintenance-mode-title">
    <span class="maintenance-mode-icon" aria-hidden="true"><i class="fa fa-screwdriver-wrench"></i></span>
    <p class="maintenance-mode-eyebrow">System status</p>
    <h1 id="maintenance-mode-title">Transactions are paused</h1>
    <p class="maintenance-mode-message"><?= htmlspecialchars($maintenanceMessage) ?></p>

    <div class="maintenance-mode-details">
      <div><i class="fa fa-lock"></i><span><strong>Currently unavailable</strong>New orders, item changes, payments, completion, job updates, and voiding.</span></div>
      <div><i class="fa fa-eye"></i><span><strong>Still available</strong>Dashboard, order history, reports, inventory review, profile, and administration.</span></div>
    </div>

    <div class="maintenance-mode-actions">
      <a class="maintenance-mode-primary" href="dashboard.php"><i class="fa fa-house"></i> Dashboard</a>
      <a href="order_list.php?scope=all"><i class="fa fa-clock-rotate-left"></i> Order History</a>
      <?php if (is_admin()): ?>
        <a href="settings.php"><i class="fa fa-gear"></i> System Settings</a>
      <?php endif; ?>
    </div>
  </section>
</main>

<?php if (!is_cashier()) { include dirname(__DIR__) . '/partials/admin_footer.php'; } ?>
</body>
</html>
