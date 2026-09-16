<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';

require_capability('manage_reorder_settings');

$pdo = db();
$flash = '';
$flashType = 'success';

if (empty($_SESSION['manager_settings_csrf'])) {
    $_SESSION['manager_settings_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['manager_settings_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session token expired. Please reload the page and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_targets') {
            settings_save_manager_targets($pdo, $_POST);
            $flash = 'Sales-team performance targets updated successfully.';
        } elseif ($action === 'save_reorder') {
            $inventoryId = (int) ($_POST['inventory_id'] ?? 0);
            $criticalLevel = filter_var($_POST['critical_level'] ?? null, FILTER_VALIDATE_FLOAT);
            $safetyStock = filter_var($_POST['safety_stock'] ?? null, FILTER_VALIDATE_FLOAT);
            $leadTimeDays = filter_var($_POST['lead_time_days'] ?? null, FILTER_VALIDATE_INT);
            $targetStock = filter_var($_POST['target_stock'] ?? null, FILTER_VALIDATE_FLOAT);

            if ($inventoryId <= 0 || $criticalLevel === false || $safetyStock === false || $leadTimeDays === false || $targetStock === false) {
                throw new InvalidArgumentException('Enter valid numeric reorder values.');
            }
            if ($criticalLevel < 0 || $safetyStock < 0 || $leadTimeDays < 1 || $leadTimeDays > 365 || $targetStock < $criticalLevel) {
                throw new InvalidArgumentException('Target stock must be at least the reorder point, and all values must be within their allowed ranges.');
            }

            $stmt = $pdo->prepare('UPDATE inventory SET critical_level = ?, safety_stock = ?, lead_time_days = ?, target_stock = ? WHERE id = ?');
            $stmt->execute([$criticalLevel, $safetyStock, $leadTimeDays, $targetStock, $inventoryId]);
            if ($stmt->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM inventory WHERE id = ?');
                $exists->execute([$inventoryId]);
                if (!(int) $exists->fetchColumn()) {
                    throw new RuntimeException('The selected inventory item no longer exists.');
                }
            }
            $flash = 'Reorder parameters updated successfully.';
        } else {
            throw new InvalidArgumentException('Unknown settings action.');
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$settings = settings_all($pdo);
$inventory = $pdo->query("
    SELECT i.id, i.name, i.cup_size, i.unit, i.qty, i.critical_level,
           i.safety_stock, i.lead_time_days, i.target_stock,
           COALESCE(SUM(CASE WHEN il.change_type IN ('used','deduct','auto-deduct','damage')
                       AND il.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                       THEN il.quantity ELSE 0 END), 0) AS used_14
    FROM inventory i
    LEFT JOIN inventory_logs il ON il.inventory_id = i.id
    GROUP BY i.id, i.name, i.cup_size, i.unit, i.qty, i.critical_level, i.safety_stock, i.lead_time_days, i.target_stock
    ORDER BY i.name, i.cup_size
")->fetchAll(PDO::FETCH_ASSOC);

$periodDays = max(1, (int) settings_value($settings, 'performance_period_days'));
$teamStmt = $pdo->prepare("
    SELECT u.username, u.full_name, r.name AS role_name,
           COUNT(DISTINCT o.id) AS completed_orders,
           COALESCE(SUM(o.total), 0) AS total_sales
    FROM users u
    JOIN roles r ON r.id = u.role_id
    LEFT JOIN orders o ON o.user_id = u.id AND o.status = 'completed'
      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    WHERE LOWER(r.name) IN ('cashier', 'staff') AND u.status = 'active'
    GROUP BY u.id, u.username, u.full_name, r.name
    ORDER BY total_sales DESC
");
$teamStmt->execute([$periodDays - 1]);
$teamPerformance = $teamStmt->fetchAll(PDO::FETCH_ASSOC);
$periodTarget = $periodDays <= 1
    ? (float) settings_value($settings, 'sales_target_daily')
    : ($periodDays <= 7
        ? (float) settings_value($settings, 'sales_target_weekly')
        : (float) settings_value($settings, 'sales_target_monthly') * ($periodDays / 30));

function manager_money($value): string { return 'PHP ' . number_format((float) $value, 2); }
function manager_number($value): string { return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forecast & Team Settings - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-manager-settings">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content manager-settings-page">
  <section class="manager-settings-hero">
    <div><p>Manager <i class="fa fa-chevron-right"></i> Business Intelligence</p><h1>Forecast & Team Settings</h1><span>Set inventory reorder inputs and measure cashier performance without exposing system credentials.</span></div>
    <a href="reports.php"><i class="fa fa-chart-line"></i> View Reports</a>
  </section>

  <?php if ($flash !== ''): ?><div class="manager-settings-alert <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert"><?= htmlspecialchars($flash) ?></div><?php endif; ?>

  <section class="manager-settings-panel">
    <div class="manager-panel-heading"><div><span>Performance Controls</span><h2>Sales-team targets</h2></div><p>Used to monitor active cashiers over the selected review period.</p></div>
    <form method="post" class="manager-target-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['manager_settings_csrf']) ?>">
      <input type="hidden" name="action" value="save_targets">
      <label>Daily Target (PHP)<input type="number" min="0" step="0.01" name="sales_target_daily" value="<?= htmlspecialchars(settings_value($settings, 'sales_target_daily')) ?>" required></label>
      <label>Weekly Target (PHP)<input type="number" min="0" step="0.01" name="sales_target_weekly" value="<?= htmlspecialchars(settings_value($settings, 'sales_target_weekly')) ?>" required></label>
      <label>Monthly Target (PHP)<input type="number" min="0" step="0.01" name="sales_target_monthly" value="<?= htmlspecialchars(settings_value($settings, 'sales_target_monthly')) ?>" required></label>
      <label>Review Period (days)<input type="number" min="1" max="365" name="performance_period_days" value="<?= (int) $periodDays ?>" required></label>
      <button type="submit"><i class="fa fa-save"></i> Save Team Targets</button>
    </form>
  </section>

  <section class="manager-settings-panel">
    <div class="manager-panel-heading"><div><span>Team Performance</span><h2>Cashier sales overview</h2></div><p>Completed transactions from the last <?= number_format($periodDays) ?> days.</p></div>
    <div class="manager-table-wrap"><table><thead><tr><th>Cashier</th><th>Completed Orders</th><th>Total Sales</th><th>Average Order</th><th>Target Progress</th></tr></thead><tbody>
      <?php if (!$teamPerformance): ?><tr><td colspan="5" class="manager-empty">No active cashier performance data is available.</td></tr><?php endif; ?>
      <?php foreach ($teamPerformance as $member): $orders = (int) $member['completed_orders']; ?>
        <tr><td><strong><?= htmlspecialchars($member['full_name'] ?: $member['username']) ?></strong><small>@<?= htmlspecialchars($member['username']) ?></small></td><td><?= number_format($orders) ?></td><td><?= htmlspecialchars(manager_money($member['total_sales'])) ?></td><td><?= htmlspecialchars(manager_money($orders ? (float) $member['total_sales'] / $orders : 0)) ?></td><td><?= $periodTarget > 0 ? number_format(min(999, ((float) $member['total_sales'] / $periodTarget) * 100), 1) . '%' : 'Not set' ?></td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>

  <section class="manager-settings-panel">
    <div class="manager-panel-heading"><div><span>Inventory Forecasting</span><h2>Reorder parameters</h2></div><p>Recommended quantity is the target level minus current stock when an item reaches its reorder point.</p></div>
    <div class="manager-reorder-grid">
      <?php if (!$inventory): ?><p class="manager-empty">No inventory records are available.</p><?php endif; ?>
      <?php foreach ($inventory as $item):
        $dailyUse = (float) $item['used_14'] / 14;
        $daysLeft = $dailyUse > 0 ? (float) $item['qty'] / $dailyUse : null;
        $target = $item['target_stock'] !== null ? (float) $item['target_stock'] : max((float) $item['qty'], (float) ($item['critical_level'] ?? 0));
        $recommended = max(0, $target - (float) $item['qty']);
      ?>
      <form method="post" class="manager-reorder-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['manager_settings_csrf']) ?>">
        <input type="hidden" name="action" value="save_reorder"><input type="hidden" name="inventory_id" value="<?= (int) $item['id'] ?>">
        <header><div><h3><?= htmlspecialchars($item['name']) ?></h3><span><?= htmlspecialchars($item['cup_size'] ?: 'Standard') ?> · <?= htmlspecialchars($item['unit']) ?></span></div><strong><?= htmlspecialchars(manager_number($item['qty'])) ?> on hand</strong></header>
        <div class="manager-forecast-line"><span><?= $daysLeft === null ? 'No usage trend' : number_format($daysLeft, 1) . ' days remaining' ?></span><span>Recommended: <?= htmlspecialchars(manager_number($recommended)) ?></span></div>
        <div class="manager-reorder-fields">
          <label>Reorder Point<input type="number" min="0" step="0.01" name="critical_level" value="<?= htmlspecialchars(manager_number($item['critical_level'] ?? 0)) ?>" required></label>
          <label>Safety Stock<input type="number" min="0" step="0.01" name="safety_stock" value="<?= htmlspecialchars(manager_number($item['safety_stock'])) ?>" required></label>
          <label>Lead Time (days)<input type="number" min="1" max="365" name="lead_time_days" value="<?= max(1, (int) $item['lead_time_days']) ?>" required></label>
          <label>Target Stock<input type="number" min="0" step="0.01" name="target_stock" value="<?= htmlspecialchars(manager_number($target)) ?>" required></label>
        </div>
        <button type="submit">Save Parameters</button>
      </form>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
