<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/daily_tasks.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_once dirname(__DIR__, 2) . '/includes/analytics.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

require_login();

// Cashiers work from the POS register by default. This also handles existing
// sessions, saved dashboard bookmarks, and stale post-login redirects.
if (is_cashier()) {
    header('Location: orders.php');
    exit;
}

$pdo = db();
$settings = settings_all($pdo);
notifications_refresh_low_stock($pdo);
$currentUser = $_SESSION['user'] ?? [];
$displayName = $currentUser['full_name'] ?? $currentUser['name'] ?? $currentUser['username'] ?? $_SESSION['username'] ?? 'User';
$displayName = trim((string) $displayName) !== '' ? $displayName : 'User';
$isAdminDashboard = is_admin();
$isManagerDashboard = is_manager();
$isIntelligenceDashboard = $isAdminDashboard || $isManagerDashboard;

function dashboard_scalar(PDO $pdo, string $sql, array $params = [], $fallback = 0) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $fallback : $value;
    } catch (Throwable $e) {
        return $fallback;
    }
}

function dashboard_rows(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function dashboard_money($value): string {
    return 'PHP ' . number_format((float) $value, 2);
}

function dashboard_normalize_cup_size($size) {
    if ($size === null) {
        return null;
    }

    $s = trim(strtolower((string) $size));
    if ($s === '' || $s === '-') {
        return null;
    }

    $s = preg_replace('/\s+/', ' ', $s);
    if (preg_match('/12\s*oz.*hot|hot\s*12\s*oz|12\s*hot|12oz\s*hot/', $s)) {
        return '12oz Hot';
    }
    if (preg_match('/^(16\s*oz|16oz)\b/', $s)) {
        return '16oz';
    }
    if (preg_match('/^(22\s*oz|22oz)\b/', $s)) {
        return '22oz';
    }

    return ucwords(trim((string) $size));
}

function dashboard_percent_label(float $value): string {
    $prefix = $value > 0 ? '+' : '';
    return $prefix . number_format($value, 1) . '%';
}

function dashboard_days_label(?float $days): string {
    if ($days === null) {
        return 'No usage trend';
    }
    if ($days < 1) {
        return 'Less than 1 day';
    }
    return number_format($days, 1) . ' days';
}

function dashboard_plural_label(int $count, string $singular, ?string $plural = null): string {
  return $count === 1 ? $singular : ($plural ?? $singular . 's');
}

$monthStart = date('Y-m-01 00:00:00');
$nextMonth = date('Y-m-01 00:00:00', strtotime('+1 month'));
$yearStart = date('Y-01-01 00:00:00');
$nextYear = date('Y-01-01 00:00:00', strtotime('+1 year'));

$activeUsers = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'active'");
$totalUsers = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM users');
$categoryCount = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM categories');
$itemCount = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM items');
$inventoryUnits = (float) dashboard_scalar($pdo, 'SELECT COALESCE(SUM(qty),0) FROM inventory');
$lowStockCount = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM inventory WHERE critical_level IS NOT NULL AND critical_level > 0 AND qty <= critical_level');
$todayOrders = (int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()');
$pendingOrders = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'");
$completedOrders = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'completed'");
$monthlyRevenue = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$monthStart, $nextMonth]);
$todayRevenue = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
$averageOrder = (float) dashboard_scalar($pdo, "SELECT COALESCE(AVG(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$monthStart, $nextMonth]);

$monthlyRows = dashboard_rows($pdo, "
    SELECT MONTH(created_at) AS month,
           COUNT(*) AS orders_count,
           COALESCE(SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END),0) AS revenue
    FROM orders
    WHERE created_at >= ? AND created_at < ?
    GROUP BY MONTH(created_at)
", [$yearStart, $nextYear]);

$ordersData = array_fill(1, 12, 0);
$revenueData = array_fill(1, 12, 0);
foreach ($monthlyRows as $row) {
    $month = (int) $row['month'];
    $ordersData[$month] = (int) $row['orders_count'];
    $revenueData[$month] = (float) $row['revenue'];
}

$statusRows = dashboard_rows($pdo, 'SELECT status, COUNT(*) AS total FROM orders GROUP BY status');
$statusCounts = ['pending' => 0, 'completed' => 0, 'cancelled' => 0, 'void' => 0, 'voided' => 0];
foreach ($statusRows as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}

$recentOrders = dashboard_rows($pdo, "
    SELECT order_code, customer_name, total, status, created_at
    FROM orders
    ORDER BY created_at DESC
    LIMIT 6
");

$lowStockItems = dashboard_rows($pdo, "
    SELECT name, cup_size, qty, unit, critical_level
    FROM inventory
    WHERE critical_level IS NOT NULL AND critical_level > 0 AND qty <= critical_level
    ORDER BY qty ASC, name ASC
    LIMIT 5
");

$latestInventory = dashboard_rows($pdo, "
    SELECT name, cup_size, qty, unit
    FROM inventory
    ORDER BY updated_at DESC, date_added DESC
    LIMIT 6
");

$topItems = dashboard_rows($pdo, "
    SELECT i.name, i.cup_size, SUM(oi.qty) AS qty_sold, COALESCE(SUM(oi.subtotal), SUM(oi.qty * oi.price), 0) AS sales
    FROM order_items oi
    JOIN items i ON i.id = oi.item_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status = 'completed' AND o.created_at >= ? AND o.created_at < ?
    GROUP BY i.id, i.name, i.cup_size
    ORDER BY qty_sold DESC
    LIMIT 5
", [$monthStart, $nextMonth]);

$last7Start = date('Y-m-d 00:00:00', strtotime('-6 days'));
$prev7Start = date('Y-m-d 00:00:00', strtotime('-13 days'));
$prev7End = date('Y-m-d 00:00:00', strtotime('-6 days'));
$last7Sales = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)", [$last7Start]);
$prev7Sales = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$prev7Start, $prev7End]);
$last14CompletedOrders = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)");
$last14Revenue = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)");

$salesGrowth = $prev7Sales > 0 ? (($last7Sales - $prev7Sales) / $prev7Sales) * 100 : ($last7Sales > 0 ? 100.0 : 0.0);
$predictiveForecast = ['orders' => max(0, $last14CompletedOrders / 14), 'revenue' => 0.0, 'date' => date('Y-m-d', strtotime('+1 day'))];
$forecastAccuracy = null;
$movementRows = [];
$turnoverRows = [];
try {
    analytics_evaluate_forecasts($pdo);
    $predictiveForecast = analytics_store_forecasts($pdo);
    $forecastAccuracy = analytics_accuracy($pdo);
    $movementRows = analytics_product_movement($pdo);
    $turnoverRows = analytics_inventory_turnover($pdo);
} catch (Throwable $e) {
    error_log('Predictive analytics refresh failed: ' . $e->getMessage());
}
$forecastOrders = max(0, (int) round($predictiveForecast['orders']));
$forecastRevenue = max(0, (float) $predictiveForecast['revenue']);
$fastMovingCount = count(array_filter($movementRows, fn($row) => $row['movement'] === 'Fast-moving'));
$slowMovingCount = count(array_filter($movementRows, fn($row) => $row['movement'] === 'Slow-moving' || $row['movement'] === 'No movement'));
$averageTurnover = $turnoverRows ? array_sum(array_column($turnoverRows, 'turnover_rate')) / count($turnoverRows) : 0.0;
$topItem = $topItems[0] ?? null;

$inventoryForecastRows = dashboard_rows($pdo, "
    SELECT inv.name, inv.cup_size, inv.qty, inv.unit, inv.critical_level,
           COALESCE(SUM(CASE WHEN il.change_type IN ('used','deduct','auto-deduct','damage') THEN il.quantity ELSE 0 END), 0) AS used_14
    FROM inventory inv
    LEFT JOIN inventory_logs il ON il.inventory_id = inv.id AND il.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY inv.id, inv.name, inv.cup_size, inv.qty, inv.unit, inv.critical_level
");

$inventoryRisk = null;
foreach ($inventoryForecastRows as $row) {
    $used14 = (float) ($row['used_14'] ?? 0);
    $avgDailyUse = $used14 / 14;
    $qty = (float) ($row['qty'] ?? 0);
    $daysLeft = $avgDailyUse > 0 ? $qty / $avgDailyUse : null;
    $isCritical = isset($row['critical_level']) && (float) $row['critical_level'] > 0 && $qty <= (float) $row['critical_level'];
    if ($inventoryRisk === null) {
        $inventoryRisk = $row + ['avg_daily_use' => $avgDailyUse, 'days_left' => $daysLeft, 'is_critical' => $isCritical];
        continue;
    }
    $currentDays = $inventoryRisk['days_left'];
    if (($daysLeft !== null && ($currentDays === null || $daysLeft < $currentDays)) || ($daysLeft === null && $isCritical && empty($inventoryRisk['is_critical']))) {
        $inventoryRisk = $row + ['avg_daily_use' => $avgDailyUse, 'days_left' => $daysLeft, 'is_critical' => $isCritical];
    }
}

$aiEnabled = settings_value($settings, 'ai_enabled') === '1';
$aiApiConfigured = trim(settings_value($settings, 'ai_api_key')) !== '';
$aiProvider = ucfirst(settings_value($settings, 'ai_provider'));
$aiModel = settings_value($settings, 'ai_model');
$aiModeLabel = $aiEnabled && $aiApiConfigured ? $aiProvider . ' Ready' : 'Local Analytics';
$riskName = $inventoryRisk ? trim($inventoryRisk['name'] . ' ' . (dashboard_normalize_cup_size($inventoryRisk['cup_size']) ? '(' . dashboard_normalize_cup_size($inventoryRisk['cup_size']) . ')' : '')) : 'No inventory risk';
$riskDays = $inventoryRisk ? dashboard_days_label($inventoryRisk['days_left']) : 'No usage trend';
$bestSellerName = $topItem ? trim($topItem['name'] . (dashboard_normalize_cup_size($topItem['cup_size']) ? ' (' . dashboard_normalize_cup_size($topItem['cup_size']) . ')' : '')) : 'No sales yet';

$smartRecommendation = 'Keep recording orders and inventory movement to improve forecasting accuracy.';
if ($inventoryRisk && ($inventoryRisk['is_critical'] || ($inventoryRisk['days_left'] !== null && $inventoryRisk['days_left'] <= 3))) {
    $smartRecommendation = 'Prioritize restocking ' . $riskName . ' before peak selling hours.';
} elseif ($salesGrowth > 10) {
    $smartRecommendation = 'Sales are trending upward. Prepare extra stock and staff coverage for the next service period.';
} elseif ($pendingOrders > 0) {
    $smartRecommendation = 'Settle pending orders first so sales analytics and inventory deduction stay accurate.';
}

$aiRecommendation = analytics_ai_recommendation($pdo, $settings, [
    'sales_growth_percent' => round($salesGrowth, 1),
    'tomorrow_orders_forecast' => $forecastOrders,
    'tomorrow_revenue_forecast' => round($forecastRevenue, 2),
    'fast_moving_products' => $fastMovingCount,
    'slow_or_no_movement_products' => $slowMovingCount,
    'lowest_inventory_coverage' => $riskDays,
]);
if ($aiRecommendation !== null) {
    $smartRecommendation = $aiRecommendation;
    $aiModeLabel = $aiProvider . ' Active';
}

$staffInsightText = 'Orders and inventory are ready for daily operations. Continue recording sales accurately.';
if ($pendingOrders > 0) {
  $staffInsightText = 'There ' . ($pendingOrders === 1 ? 'is' : 'are') . ' ' . number_format($pendingOrders) . ' pending ' . dashboard_plural_label($pendingOrders, 'Order') . '. Complete payment and order status before closing service.';
} elseif ($lowStockCount > 0) {
    $staffInsightText = 'Low-stock items need attention. Please notify a manager or administrator before peak service.';
}

$monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$dashboardRoleLabel = role_display_name(current_user_role());
$dashboardContextLabel = $isIntelligenceDashboard ? 'Business Intelligence' : 'Transaction Workspace';
$dashboardTitle = $isManagerDashboard ? 'Manager Dashboard' : ($isAdminDashboard ? 'Dashboard' : 'Cashier Dashboard');
$dashboardSubtitle = $isManagerDashboard
    ? 'Review sales performance, inventory forecasts, reorder risks, and team results from one workspace.'
    : ($isAdminDashboard
        ? 'Track sales, orders, inventory health, AI analytics, and recent activity from one calm workspace.'
        : 'Handle orders, payments, and service activity without management reports or settings access.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kape Agosto - <?= htmlspecialchars($dashboardTitle) ?></title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <?php if ($isIntelligenceDashboard): ?><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><?php endif; ?>
</head>
<body class="kape-pages-dashboard">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content dashboard-page">
  <section class="dashboard-heading" aria-labelledby="dashboard-title">
    <div>
      <p class="dashboard-breadcrumb"><?= htmlspecialchars($dashboardRoleLabel) ?> <i class="fa fa-chevron-right"></i> <?= htmlspecialchars($dashboardContextLabel) ?></p>
      <h1 id="dashboard-title"><?= htmlspecialchars($dashboardTitle) ?></h1>
      <p class="dashboard-subtitle"><?= htmlspecialchars($dashboardSubtitle) ?></p>
    </div>
    <div class="dashboard-date-card">
      <span><?= date('l') ?></span>
      <strong><?= date('M d, Y') ?></strong>
    </div>
  </section>

  <?php if ($isIntelligenceDashboard): ?>
    <section class="dashboard-insight ai-analytics-panel" aria-label="Inventory forecasting and AI sales analytics">
      <div class="insight-copy">
        <span class="insight-label"><i class="fa fa-wand-magic-sparkles"></i> Inventory Forecasting & A.I. Sales Analytics</span>
        <p><?= htmlspecialchars($smartRecommendation) ?></p>
        <div class="ai-mode-line">
          <span><i class="fa fa-microchip"></i> <?= htmlspecialchars($aiModeLabel) ?></span>
          <span><i class="fa fa-layer-group"></i> <?= htmlspecialchars($aiModel ?: 'Model not set') ?></span>
        </div>
      </div>
      <div class="insight-actions">
        <a href="reports.php" class="insight-action">View Reports</a>
        <a href="<?= $isManagerDashboard ? 'manager_settings.php' : 'settings.php' ?>" class="insight-action secondary"><?= $isManagerDashboard ? 'Forecast Settings' : 'AI Settings' ?></a>
      </div>
    </section>
    <section class="ai-analytics-grid" aria-label="Smart analytics cards">
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-chart-line"></i></span><span class="ai-card-label">7-Day Sales Trend</span><strong><?= htmlspecialchars(dashboard_percent_label($salesGrowth)) ?></strong><small><?= dashboard_money($last7Sales) ?> recent sales</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-calendar-day"></i></span><span class="ai-card-label">Tomorrow Forecast</span><strong><?= number_format($forecastOrders) ?> orders</strong><small>Projected <?= dashboard_money($forecastRevenue) ?></small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-trophy"></i></span><span class="ai-card-label">Best Seller</span><strong><?= htmlspecialchars($bestSellerName) ?></strong><small><?= $topItem ? number_format((float) $topItem['qty_sold'], 0) . ' sold this month' : 'Complete orders to train analytics' ?></small></article>
      <article class="ai-analytics-card risk"><span class="ai-card-icon"><i class="fa fa-box-open"></i></span><span class="ai-card-label">Inventory Risk</span><strong><?= htmlspecialchars($riskName) ?></strong><small><?= htmlspecialchars($riskDays) ?> coverage</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-bullseye"></i></span><span class="ai-card-label">Forecast Accuracy</span><strong><?= $forecastAccuracy === null ? 'Collecting data' : number_format($forecastAccuracy, 1) . '%' ?></strong><small>Last 30 days of evaluated forecasts</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-gauge-high"></i></span><span class="ai-card-label">Inventory Turnover</span><strong><?= number_format($averageTurnover, 2) ?>x</strong><small>30-day usage / estimated average stock</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-forward-fast"></i></span><span class="ai-card-label">Fast-moving</span><strong><?= number_format($fastMovingCount) ?></strong><small>Products in the leading movement band</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-hourglass-half"></i></span><span class="ai-card-label">Slow / No Movement</span><strong><?= number_format($slowMovingCount) ?></strong><small>Products requiring review</small></article>
    </section>
  <?php else: ?>
    <section class="dashboard-insight staff-insight-panel" aria-label="Cashier operations insight">
      <div class="insight-copy">
        <span class="insight-label"><i class="fa fa-clipboard-check"></i> Cashier Operations</span>
        <p><?= htmlspecialchars($staffInsightText) ?></p>
      </div>
      <div class="insight-actions"><a href="orders.php" class="insight-action">Manage Orders</a></div>
    </section>
  <?php endif; ?>

  <?php if ($isIntelligenceDashboard): ?>
    <section class="dashboard-kpis" aria-label="Key metrics">
      <article class="dashboard-kpi revenue"><span class="kpi-icon"><i class="fa fa-money-bill-trend-up"></i></span><span class="kpi-label">Monthly Revenue</span><strong><?= dashboard_money($monthlyRevenue) ?></strong><small>Today: <?= dashboard_money($todayRevenue) ?></small></article>
      <article class="dashboard-kpi orders"><span class="kpi-icon"><i class="fa fa-receipt"></i></span><span class="kpi-label">Orders Today</span><strong><?= number_format($todayOrders) ?></strong><small><?= number_format($pendingOrders) ?> pending <?= htmlspecialchars(dashboard_plural_label($pendingOrders, 'Order')) ?></small></article>
      <article class="dashboard-kpi users"><span class="kpi-icon"><i class="fa fa-user-check"></i></span><span class="kpi-label">Active Users</span><strong><?= number_format($activeUsers) ?></strong><small><?= number_format($totalUsers) ?> total accounts</small></article>
      <article class="dashboard-kpi inventory"><span class="kpi-icon"><i class="fa fa-boxes-stacked"></i></span><span class="kpi-label">Inventory Units</span><strong><?= number_format($inventoryUnits, 0) ?></strong><small><?= number_format($lowStockCount) ?> low-stock alerts</small></article>
    </section>
    <section class="dashboard-grid">
      <article class="dashboard-panel dashboard-story"><div><span class="panel-eyebrow">Cafe Operations</span><h2>Crafting excellence, one cup at a time.</h2><p>Average completed <?= htmlspecialchars(dashboard_plural_label($completedOrders, 'Order')) ?> this month is <strong><?= dashboard_money($averageOrder) ?></strong>, across <?= number_format($completedOrders) ?> completed <?= htmlspecialchars(dashboard_plural_label($completedOrders, 'Order')) ?>.</p></div><a href="<?= $isManagerDashboard ? 'order_list.php' : 'orders.php' ?>" class="panel-action"><?= $isManagerDashboard ? 'Review Orders' : 'Manage Orders' ?> <i class="fa fa-arrow-right"></i></a></article>
      <article class="dashboard-panel chart-panel wide"><div class="panel-header"><div><span class="panel-eyebrow">Performance</span><h2>Orders and revenue trend</h2></div><span class="panel-pill"><?= date('Y') ?></span></div><canvas id="trendChart"></canvas></article>
      <article class="dashboard-panel chart-panel"><div class="panel-header"><div><span class="panel-eyebrow">Status</span><h2>Order mix</h2></div></div><canvas id="statusChart"></canvas></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Activity</span><h2>Recent orders</h2></div><a href="order_list.php">View all</a></div><div class="dashboard-list"><?php if ($recentOrders): ?><?php foreach ($recentOrders as $order): ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($order['order_code'] ?: 'Order') ?></strong><span><?= htmlspecialchars($order['customer_name'] ?: 'Walk-in') ?> &middot; <?= date('M d, g:i A', strtotime($order['created_at'])) ?></span></div><div class="row-end"><strong><?= dashboard_money($order['total']) ?></strong><span class="status-chip <?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></div></div><?php endforeach; ?><?php else: ?><p class="empty-state">No recent orders yet.</p><?php endif; ?></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Inventory</span><h2>Low stock watch</h2></div><a href="<?= $isManagerDashboard ? 'manager_settings.php' : 'inventory.php' ?>"><?= $isManagerDashboard ? 'Forecast' : 'Manage' ?></a></div><div class="dashboard-list compact"><?php if ($lowStockItems): ?><?php foreach ($lowStockItems as $item): ?><?php $cup = dashboard_normalize_cup_size($item['cup_size']); ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($cup ? ' (' . $cup . ')' : '')) ?></strong><span>Critical level: <?= number_format((float) $item['critical_level'], 0) ?></span></div><span class="stock-pill"><?= number_format((float) $item['qty'], 0) ?> <?= htmlspecialchars($item['unit'] ?: 'pcs') ?></span></div><?php endforeach; ?><?php else: ?><p class="empty-state">No low-stock items right now.</p><?php endif; ?></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Sales</span><h2>Top items this month</h2></div><span class="panel-pill"><?= number_format($itemCount) ?> items</span></div><div class="dashboard-list compact"><?php if ($topItems): ?><?php foreach ($topItems as $item): ?><?php $cup = dashboard_normalize_cup_size($item['cup_size']); ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($cup ? ' (' . $cup . ')' : '')) ?></strong><span><?= number_format((float) $item['qty_sold'], 0) ?> sold</span></div><strong><?= dashboard_money($item['sales']) ?></strong></div><?php endforeach; ?><?php else: ?><p class="empty-state">Completed sales will appear here.</p><?php endif; ?></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Predictive Analytics</span><h2>Product movement</h2></div><span class="panel-pill">30 days</span></div><div class="dashboard-list compact"><?php if ($movementRows): ?><?php foreach (array_slice($movementRows, 0, 6) as $item): ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($item['cup_size'] ? ' (' . $item['cup_size'] . ')' : '')) ?></strong><span><?= htmlspecialchars($item['movement']) ?> · <?= number_format((float) $item['qty_30'], 0) ?> sold</span></div><strong><?= dashboard_percent_label((float) $item['trend_percent']) ?></strong></div><?php endforeach; ?><?php else: ?><p class="empty-state">Add products and completed sales to classify movement.</p><?php endif; ?></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Stockroom</span><h2>Latest inventory</h2></div><span class="panel-pill"><?= number_format($categoryCount) ?> categories</span></div><div class="dashboard-list compact"><?php if ($latestInventory): ?><?php foreach ($latestInventory as $item): ?><?php $cup = dashboard_normalize_cup_size($item['cup_size']); ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($cup ? ' (' . $cup . ')' : '')) ?></strong><span><?= htmlspecialchars($item['unit'] ?: 'units') ?></span></div><strong><?= number_format((float) $item['qty'], 0) ?></strong></div><?php endforeach; ?><?php else: ?><p class="empty-state">Inventory records will appear here.</p><?php endif; ?></div></article>
    </section>
  <?php else: ?>
    <section class="dashboard-kpis staff-kpis" aria-label="Cashier order queue">
      <article class="dashboard-kpi orders"><span class="kpi-icon"><i class="fa fa-receipt"></i></span><span class="kpi-label">Orders Today</span><strong><?= number_format($todayOrders) ?></strong><small>Current service activity</small></article>
      <article class="dashboard-kpi inventory"><span class="kpi-icon"><i class="fa fa-clock"></i></span><span class="kpi-label">Pending Orders</span><strong><?= number_format($pendingOrders) ?></strong><small>Need payment or completion</small></article>
      <article class="dashboard-kpi users"><span class="kpi-icon"><i class="fa fa-circle-check"></i></span><span class="kpi-label">Completed Orders</span><strong><?= number_format($completedOrders) ?></strong><small>Operational count only</small></article>
    </section>
    <section class="dashboard-grid staff-dashboard-grid">
      <article class="dashboard-panel dashboard-story"><div><span class="panel-eyebrow">Service Workspace</span><h2>Keep orders moving clearly.</h2><p>Use the order tools to create carts, settle payments, and update order status without exposing reports or AI analytics.</p></div><div class="staff-action-row"><a href="orders.php" class="panel-action">Manage Orders <i class="fa fa-arrow-right"></i></a><a href="order_list.php" class="panel-action secondary">Order List <i class="fa fa-list-check"></i></a></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Queue</span><h2>Recent orders</h2></div><a href="order_list.php">View queue</a></div><div class="dashboard-list"><?php if ($recentOrders): ?><?php foreach ($recentOrders as $order): ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($order['order_code'] ?: 'Order') ?></strong><span><?= htmlspecialchars($order['customer_name'] ?: 'Walk-in') ?> &middot; <?= date('M d, g:i A', strtotime($order['created_at'])) ?></span></div><span class="status-chip <?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></div><?php endforeach; ?><?php else: ?><p class="empty-state">No recent orders yet.</p><?php endif; ?></div></article>
    </section>
  <?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<?php if ($isIntelligenceDashboard): ?>
<script>
  const dashboardMonths = <?= json_encode($monthLabels) ?>;
  const dashboardOrders = <?= json_encode(array_values($ordersData)) ?>;
  const dashboardRevenue = <?= json_encode(array_values($revenueData)) ?>;
  const dashboardStatus = <?= json_encode($statusCounts) ?>;

  const trendCanvas = document.getElementById('trendChart');
  if (trendCanvas && window.Chart) {
    new Chart(trendCanvas, {
      type: 'line',
      data: {
        labels: dashboardMonths,
        datasets: [
          { label: 'Revenue', data: dashboardRevenue, borderColor: '#6d3b2b', backgroundColor: 'rgba(109, 59, 43, 0.12)', fill: true, tension: 0.35, yAxisID: 'y' },
          { label: 'Orders', data: dashboardOrders, borderColor: '#d99b36', backgroundColor: 'rgba(217, 155, 54, 0.16)', fill: false, tension: 0.35, yAxisID: 'y1' }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { color: '#4a342c', boxWidth: 12 } } },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#7d6258' } },
          y: { beginAtZero: true, grid: { color: 'rgba(67, 40, 33, 0.08)' }, ticks: { color: '#7d6258' } },
          y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { color: '#7d6258' } }
        }
      }
    });
  }

  const statusCanvas = document.getElementById('statusChart');
  if (statusCanvas && window.Chart) {
    new Chart(statusCanvas, {
      type: 'doughnut',
      data: {
        labels: ['Pending', 'Completed', 'Cancelled', 'Void'],
        datasets: [{ data: [dashboardStatus.pending || 0, dashboardStatus.completed || 0, dashboardStatus.cancelled || 0, (dashboardStatus.void || 0) + (dashboardStatus.voided || 0)], backgroundColor: ['#f2c66d', '#4f7f54', '#b87348', '#6f6a66'], borderWidth: 0 }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#4a342c', boxWidth: 12 } } }, cutout: '68%' }
    });
  }
</script>
<?php endif; ?>
</body>
</html>
