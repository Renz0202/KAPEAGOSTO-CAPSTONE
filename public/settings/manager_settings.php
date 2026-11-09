<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/analytics.php';
require_once dirname(__DIR__, 2) . '/includes/manager_forecast.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_capability('manage_reorder_settings');
$pdo = db();
$settings = settings_all($pdo);
$promoSettingsFlash = '';
$promoSettingsFlashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_promo_discount'])) {
    try {
        settings_save_promo($pdo, [
            'promo_discount_percent' => $_POST['promo_discount_percent'] ?? null,
            'promo_discount_start_date' => $_POST['promo_discount_start_date'] ?? '',
            'promo_discount_end_date' => $_POST['promo_discount_end_date'] ?? '',
        ]);
        $settings = settings_all($pdo);
        $promoSettingsFlash = 'Promotional discount rate updated. Cashiers can now apply this rate on POS tickets.';
    } catch (InvalidArgumentException $e) {
        $promoSettingsFlash = $e->getMessage();
        $promoSettingsFlashType = 'error';
    } catch (Throwable $e) {
        error_log('Promotional discount setting save failed: ' . $e->getMessage());
        $promoSettingsFlash = 'Promotional discount rate could not be saved. Please try again.';
        $promoSettingsFlashType = 'error';
    }
}
function manager_money($value): string { return 'PHP ' . number_format((float) $value, 2); }
function manager_number($value): string { return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'); }
function manager_forecast_inventory(PDO $pdo): array {
    return $pdo->query("
        SELECT i.id, i.name, i.cup_size, i.unit, i.qty, i.lead_time_days,
               COALESCE(SUM(CASE WHEN il.change_type IN ('used','deduct','auto-deduct','damage')
                 AND il.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND il.created_at < CURDATE()
                 THEN ABS(il.quantity) ELSE 0 END), 0) AS used_14
        FROM inventory i LEFT JOIN inventory_logs il ON il.inventory_id = i.id
        GROUP BY i.id, i.name, i.cup_size, i.unit, i.qty, i.lead_time_days
        ORDER BY i.name, i.cup_size
    ")->fetchAll(PDO::FETCH_ASSOC);
}
$inventory = manager_forecast_inventory($pdo);
if (isset($_GET['ajax_forecast'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $items = [];
    foreach ($inventory as $item) {
        $items[] = array_merge(['id' => (int) $item['id']], manager_inventory_display($item, manager_inventory_estimate($item)));
    }
    echo json_encode(['items' => $items]);
    exit;
}
$salesSeries = analytics_daily_sales($pdo, 28);
array_pop($salesSeries); // Exclude today's incomplete sales.
$dailyForecast = analytics_weighted_forecast(array_column($salesSeries, 'revenue'));
$hasSalesHistory = array_sum(array_column($salesSeries, 'orders')) > 0;
$teamPerformance = $pdo->query("
    SELECT u.username, u.full_name,
      COUNT(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN o.id END) AS completed_orders,
      COALESCE(SUM(CASE WHEN o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN o.total ELSE 0 END), 0) AS total_sales,
      COALESCE(SUM(CASE WHEN o.created_at < DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN o.total ELSE 0 END), 0) AS baseline_sales
    FROM users u JOIN roles r ON r.id = u.role_id
    LEFT JOIN orders o ON o.user_id = u.id AND o.status = 'completed'
      AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 59 DAY) AND o.created_at <= NOW()
    WHERE LOWER(r.name) IN ('cashier', 'staff') AND u.status = 'active'
    GROUP BY u.id, u.username, u.full_name ORDER BY total_sales DESC
")->fetchAll(PDO::FETCH_ASSOC);
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
    <div><p>Manager <i class="fa fa-chevron-right"></i> Business Intelligence</p><h1>Forecast & Team Settings</h1><span>Automatic sales estimates, cashier trends, and inventory recommendations from recorded activity.</span></div>
    <a href="reports.php"><i class="fa fa-chart-line"></i> View Reports</a>
  </section>
  <div class="manager-section-tabs" role="tablist" aria-label="Forecast sections">
    <button type="button" id="tab-sales" role="tab" aria-selected="true" aria-controls="panel-sales" data-manager-tab="sales">Sales Forecast</button>
    <button type="button" id="tab-team" role="tab" aria-selected="false" aria-controls="panel-team" tabindex="-1" data-manager-tab="team">Team Performance</button>
    <button type="button" id="tab-inventory" role="tab" aria-selected="false" aria-controls="panel-inventory" tabindex="-1" data-manager-tab="inventory">Inventory Forecast</button>
  </div>
  <section class="manager-settings-panel" id="panel-sales" role="tabpanel" aria-labelledby="tab-sales" tabindex="0">
    <div class="manager-panel-heading"><div><span>Automatic Forecast</span><h2>Expected sales</h2></div><p>Generated from completed sales over the last two full weeks, with more weight on the latest week. Estimates are not guaranteed sales.</p></div>
    <div class="manager-reorder-fields">
      <?php foreach (['Daily estimate' => 1, 'Weekly estimate' => 7, 'Monthly estimate' => 30] as $label => $days): ?>
        <label><?= htmlspecialchars($label) ?><strong><?= $hasSalesHistory ? htmlspecialchars(manager_money($dailyForecast * $days)) : 'Awaiting sales history' ?></strong></label>
      <?php endforeach; ?>
      <label>Review period<strong>Last 30 days</strong></label>
    </div>
  </section>
  <section class="manager-settings-panel" aria-labelledby="panel-promo-title">
    <div class="manager-panel-heading"><div><span>POS Controls</span><h2 id="panel-promo-title">Promotional discount</h2></div><p>Set the approved promotional percentage and optional inclusive date range. Cashiers can apply it only while active.</p></div>
    <?php if ($promoSettingsFlash !== ''): ?><div class="user-flash <?= $promoSettingsFlashType === 'error' ? 'error' : 'success' ?>" role="alert"><?= htmlspecialchars($promoSettingsFlash) ?></div><?php endif; ?>
    <form method="post" class="manager-reorder-fields">
      <label for="promo_discount_percent">Promo rate (%)<input id="promo_discount_percent" name="promo_discount_percent" type="number" min="0" max="100" step="0.01" value="<?= htmlspecialchars(settings_value($settings, 'promo_discount_percent')) ?>" required></label>
      <label for="promo_discount_start_date">Start date (inclusive)<input id="promo_discount_start_date" name="promo_discount_start_date" type="date" value="<?= htmlspecialchars(settings_value($settings, 'promo_discount_start_date')) ?>"></label>
      <label for="promo_discount_end_date">End date (inclusive)<input id="promo_discount_end_date" name="promo_discount_end_date" type="date" value="<?= htmlspecialchars(settings_value($settings, 'promo_discount_end_date')) ?>"></label>
      <button type="submit" name="save_promo_discount" value="1">Save promo</button>
    </form>
    <p>Leave both dates blank for a promo with no date limit. The rate is active through the end date.</p>
  </section>
  <section class="manager-settings-panel" id="panel-team" role="tabpanel" aria-labelledby="tab-team" tabindex="0" hidden>
    <div class="manager-panel-heading"><div><span>Team Performance</span><h2>Cashier sales overview</h2></div><p>Last 30 days (including today) versus the preceding 30 days. No manual goals required.</p></div>
    <div class="manager-table-wrap"><table><thead><tr><th>Cashier</th><th>Completed Orders</th><th>Total Sales</th><th>Average Order</th><th>Previous 30 Days</th><th>Sales Change</th></tr></thead><tbody>
      <?php if (!$teamPerformance): ?><tr><td colspan="6" class="manager-empty">No active cashier performance data is available.</td></tr><?php endif; ?>
      <?php foreach ($teamPerformance as $member): $orders = (int) $member['completed_orders']; ?>
        <tr>
          <td><strong><?= htmlspecialchars($member['full_name'] ?: $member['username']) ?></strong><small>@<?= htmlspecialchars($member['username']) ?></small></td>
          <td><?= number_format($orders) ?></td>
          <td><?= htmlspecialchars(manager_money($member['total_sales'])) ?></td>
          <td><?= htmlspecialchars(manager_money($orders ? (float) $member['total_sales'] / $orders : 0)) ?></td>
          <td><?= htmlspecialchars(manager_money($member['baseline_sales'])) ?></td>
          <td><?= (float) $member['baseline_sales'] > 0 ? sprintf('%+.1f%%', (((float) $member['total_sales'] / (float) $member['baseline_sales']) - 1) * 100) : 'Awaiting prior history' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="manager-settings-panel" id="panel-inventory" role="tabpanel" aria-labelledby="tab-inventory" tabindex="0" hidden>
    <div class="manager-panel-heading"><div><span>Inventory Forecasting</span><h2>Automatic restock recommendations</h2></div><p>Uses 14 complete days of consumption, a 2-day buffer, and saved supplier lead time (minimum 1 day). Restock to 7 days beyond lead time plus buffer when stock reaches the calculated reorder point.<span class="manager-forecast-live" id="managerForecastUpdated" aria-live="polite">Auto-updates every 15 seconds</span></p></div>
    <div class="manager-reorder-grid">
      <?php if (!$inventory): ?><p class="manager-empty">No inventory records are available.</p><?php endif; ?>
      <?php foreach ($inventory as $item): $display = manager_inventory_display($item, manager_inventory_estimate($item)); ?>
      <article class="manager-reorder-card" data-forecast-item="<?= (int) $item['id'] ?>">
        <header><div><h3><?= htmlspecialchars($item['name']) ?></h3><span><?= htmlspecialchars($item['cup_size'] ?: 'Standard') ?> &middot; <?= htmlspecialchars($item['unit']) ?></span></div><strong><span data-forecast-qty><?= htmlspecialchars($display['qty']) ?></span> on hand</strong></header>
        <div class="manager-forecast-line"><span data-forecast-daysLeft><?= htmlspecialchars($display['daysLeft']) ?></span><span>Recommended: <strong data-forecast-recommended><?= htmlspecialchars($display['recommended']) ?></strong></span></div>
        <div class="manager-reorder-fields">
          <?php foreach (['reorder' => 'Calculated reorder point', 'buffer' => 'Calculated safety stock', 'lead' => 'Supplier lead time (days)', 'target' => 'Calculated target stock'] as $key => $label): ?>
            <label><?= htmlspecialchars($label) ?><strong data-forecast-<?= $key ?>><?= htmlspecialchars($display[$key]) ?></strong></label>
          <?php endforeach; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
<script>
(function () {
  const tabs = Array.from(document.querySelectorAll('[data-manager-tab]'));
  function selectTab(tab) {
    tabs.forEach(function (button) {
      const selected = button === tab;
      button.setAttribute('aria-selected', String(selected));
      button.tabIndex = selected ? 0 : -1;
      document.getElementById(button.getAttribute('aria-controls')).hidden = !selected;
    });
    if (tab.dataset.managerTab === 'inventory') updateManagerForecast();
    window.dispatchEvent(new Event('resize'));
  }
  tabs.forEach(function (tab, index) {
    tab.addEventListener('click', function () { selectTab(tab); });
    tab.addEventListener('keydown', function (event) {
      let next;
      if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next === undefined) return;
      event.preventDefault();
      selectTab(tabs[next]);
      tabs[next].focus();
    });
  });
  async function updateManagerForecast() {
    if (document.hidden || document.getElementById('panel-inventory').hidden) return;
    try {
      const response = await fetch('manager_settings.php?ajax_forecast=1&t=' + Date.now(), { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      (data.items || []).forEach(function (item) {
        const card = document.querySelector('[data-forecast-item="' + item.id + '"]');
        if (!card) return;
        ['qty', 'daysLeft', 'recommended', 'reorder', 'buffer', 'lead', 'target'].forEach(function (key) {
          const field = card.querySelector('[data-forecast-' + key + ']');
          if (field) field.textContent = item[key];
        });
      });
      const stamp = document.getElementById('managerForecastUpdated');
      if (stamp) stamp.textContent = 'Auto-updating · Last updated ' + new Date().toLocaleTimeString('en-PH');
    } catch (error) {
      const stamp = document.getElementById('managerForecastUpdated');
      if (stamp) stamp.textContent = 'Update unavailable. Showing last received forecast; retrying automatically.';
    }
  }
  window.setInterval(updateManagerForecast, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) updateManagerForecast(); });
})();
</script>
</body>
</html>
