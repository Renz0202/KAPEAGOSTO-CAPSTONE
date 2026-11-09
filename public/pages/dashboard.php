<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/daily_tasks.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_once dirname(__DIR__, 2) . '/includes/analytics.php';
require_once dirname(__DIR__, 2) . '/includes/dashboard_inventory_forecast.php';
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
$inventoryPlanning = dashboard_inventory_forecast($pdo);
$restockCount = count(array_filter($inventoryPlanning, static fn($row) => $row['needs_restock']));
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

if (isset($_GET['generate_ai_recommendation'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!$isIntelligenceDashboard) {
            http_response_code(403);
            throw new RuntimeException('Access denied.');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            throw new RuntimeException('Use the Generate button to request a recommendation.');
        }
        if (empty($_SESSION['ai_recommendation_token']) || !hash_equals($_SESSION['ai_recommendation_token'], (string) ($_POST['token'] ?? ''))) {
            http_response_code(403);
            throw new RuntimeException('Session expired. Refresh the page and try again.');
        }
        if (time() - (int) ($_SESSION['ai_recommendation_requested_at'] ?? 0) < 30) {
            http_response_code(429);
            header('Retry-After: ' . max(0, 30 - (time() - (int) $_SESSION['ai_recommendation_requested_at'])));
            throw new RuntimeException('Please wait 30 seconds before generating again.');
        }
        if (($settings['ai_enabled'] ?? '0') !== '1' || !in_array(strtolower($settings['ai_provider'] ?? ''), ['openai', 'gemini'], true) || trim($settings['ai_api_key'] ?? '') === '' || !function_exists('curl_init')) {
            http_response_code(422);
            throw new RuntimeException('Enable AI analytics and configure the selected provider API key in AI settings. The server also needs PHP cURL.');
        }
        $_SESSION['ai_recommendation_requested_at'] = time();
        $series = analytics_daily_sales($pdo, 28, true);
        $revenues = array_column($series, 'revenue');
        $recent = array_sum(array_slice($revenues, -7));
        $previous = array_sum(array_slice($revenues, -14, 7));
        $movement = analytics_product_movement($pdo);
        $context = [
            'sales_growth_percent' => $previous > 0 ? round(($recent - $previous) / $previous * 100, 1) : null,
            'comparison' => analytics_sales_comparison($series),
            'forecast_method' => '60% recent same weekdays and 40% completed-day trend; trend only when fewer than 3 same weekdays',
            'tomorrow_orders_forecast' => (int) round(analytics_weekday_forecast($series, 'orders')),
            'tomorrow_revenue_forecast' => round(analytics_weekday_forecast($series, 'revenue'), 2),
            'fast_moving_products' => count(array_filter($movement, fn($row) => $row['movement'] === 'Fast-moving')),
            'slow_or_no_movement_products' => count(array_filter($movement, fn($row) => $row['movement'] !== 'Fast-moving')),
        ];
        $failure = null;
        $recommendation = analytics_ai_recommendation($pdo, $settings, $context, true, $failure);
        if ($recommendation === null) {
            http_response_code(502);
            throw new RuntimeException(($failure ?? 'AI generation is unavailable. Check AI settings.') . ' The previous recommendation is retained.');
        }
        echo json_encode(['ok' => true, 'recommendation' => $recommendation, 'generatedAt' => date('M d, Y h:i:s A T'), 'model' => ai_model($settings), 'dataUsed' => $context]);
    } catch (Throwable $e) {
        if (http_response_code() < 400) {
            http_response_code(500);
            error_log('Manual AI recommendation failed: ' . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Unable to generate a recommendation. Please try again.']);
        } else {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    exit;
}
if (empty($_SESSION['ai_recommendation_token'])) {
    $_SESSION['ai_recommendation_token'] = bin2hex(random_bytes(32));
}

if (isset($_GET['ajax_dashboard_counts']) && $isIntelligenceDashboard) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode([
        'todayOrders' => number_format((int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()')),
        'pendingOrders' => number_format((int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'")),
    ]);
    exit;
}

if (isset($_GET['ajax_dashboard_live']) && $isIntelligenceDashboard) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $monthStartLive = date('Y-m-01 00:00:00');
    $nextMonthLive = date('Y-m-01 00:00:00', strtotime('+1 month'));
    $monthlyRevenueLive = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$monthStartLive, $nextMonthLive]);
    $todayRevenueLive = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND DATE(created_at) = CURDATE()");
    $completedOrdersLive = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'completed'");
    $averageOrderLive = (float) dashboard_scalar($pdo, "SELECT COALESCE(AVG(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$monthStartLive, $nextMonthLive]);
    $monthlyRowsLive = dashboard_rows($pdo, "SELECT MONTH(created_at) AS month, COUNT(*) AS orders_count, COALESCE(SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END),0) AS revenue FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY MONTH(created_at)", [date('Y-01-01 00:00:00'), date('Y-01-01 00:00:00', strtotime('+1 year'))]);
    $ordersChart = array_fill(1, 12, 0);
    $revenueChart = array_fill(1, 12, 0);
    foreach ($monthlyRowsLive as $monthRow) {
        $month = (int) $monthRow['month'];
        $ordersChart[$month] = (int) $monthRow['orders_count'];
        $revenueChart[$month] = (float) $monthRow['revenue'];
    }
    $statusRowsLive = dashboard_rows($pdo, 'SELECT status, COUNT(*) AS total FROM orders GROUP BY status');
    $statusCountsLive = ['pending' => 0, 'completed' => 0, 'cancelled' => 0, 'void' => 0, 'voided' => 0];
    foreach ($statusRowsLive as $statusRow) $statusCountsLive[$statusRow['status']] = (int) $statusRow['total'];
    $recentOrdersLive = dashboard_rows($pdo, "SELECT order_code, customer_name, total, status, created_at FROM orders ORDER BY created_at DESC LIMIT 5");
    foreach ($recentOrdersLive as &$order) {
        $order['order_code'] = (string) ($order['order_code'] ?: 'Order');
        $order['customer_name'] = (string) ($order['customer_name'] ?: 'Walk-in');
        $order['created_label'] = !empty($order['created_at']) ? date('M d, g:i A', strtotime($order['created_at'])) : '';
        $order['total_label'] = dashboard_money($order['total']);
        $order['status_label'] = ucfirst((string) $order['status']);
    }
    unset($order);
    $topItemsLive = dashboard_rows($pdo, "SELECT i.name, i.cup_size, SUM(oi.qty) AS qty_sold, COALESCE(SUM(oi.subtotal * CASE WHEN o.subtotal > 0 THEN o.total / o.subtotal ELSE 1 END), SUM(oi.qty * oi.price), 0) AS sales FROM order_items oi JOIN items i ON i.id = oi.item_id JOIN orders o ON o.id = oi.order_id WHERE o.status = 'completed' AND o.created_at >= ? AND o.created_at < ? GROUP BY i.id, i.name, i.cup_size ORDER BY qty_sold DESC LIMIT 5", [$monthStartLive, $nextMonthLive]);
    foreach ($topItemsLive as &$item) {
        $item['label'] = (string) $item['name'] . (dashboard_normalize_cup_size($item['cup_size']) ? ' (' . dashboard_normalize_cup_size($item['cup_size']) . ')' : '');
        $item['qty_label'] = number_format((float) $item['qty_sold'], 0) . ' sold';
        $item['sales_label'] = dashboard_money($item['sales']);
    }
    unset($item);
    $lowStockLive = dashboard_rows($pdo, "SELECT name, cup_size, qty, unit, critical_level FROM inventory WHERE critical_level IS NOT NULL AND critical_level > 0 AND qty <= critical_level ORDER BY qty ASC, name ASC LIMIT 5");
    foreach ($lowStockLive as &$item) {
        $item['label'] = (string) $item['name'] . (dashboard_normalize_cup_size($item['cup_size']) ? ' (' . dashboard_normalize_cup_size($item['cup_size']) . ')' : '');
        $item['critical_label'] = number_format((float) $item['critical_level'], 0);
        $item['qty_label'] = number_format((float) $item['qty'], 0) . ' ' . (string) ($item['unit'] ?: 'pcs');
    }
    unset($item);
    $movementLive = analytics_product_movement($pdo);
    foreach ($movementLive as &$item) {
        $item['label'] = (string) $item['name'] . (!empty($item['cup_size']) ? ' (' . $item['cup_size'] . ')' : '');
        $item['movement_label'] = (string) $item['movement'] . ' · ' . number_format((float) $item['qty_30'], 0) . ' sold';
        $item['trend_label'] = dashboard_percent_label((float) $item['trend_percent']);
    }
    unset($item);
    $movementLive = array_slice($movementLive, 0, 5);
    $latestInventoryLive = dashboard_rows($pdo, "SELECT name, cup_size, qty, unit FROM inventory ORDER BY updated_at DESC, date_added DESC LIMIT 5");
    foreach ($latestInventoryLive as &$item) {
        $item['label'] = (string) $item['name'] . (dashboard_normalize_cup_size($item['cup_size']) ? ' (' . dashboard_normalize_cup_size($item['cup_size']) . ')' : '');
        $item['unit'] = (string) ($item['unit'] ?: 'units');
        $item['qty_label'] = number_format((float) $item['qty'], 0);
    }
    unset($item);
    echo json_encode([
        'todayOrders' => number_format((int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()')),
        'pendingOrders' => number_format((int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'")),
        'monthlyRevenue' => dashboard_money($monthlyRevenueLive),
        'todayRevenue' => dashboard_money($todayRevenueLive),
        'activeUsers' => number_format((int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status = 'active'")),
        'totalUsers' => number_format((int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM users')),
        'inventoryUnits' => number_format((float) dashboard_scalar($pdo, 'SELECT COALESCE(SUM(qty),0) FROM inventory'), 0),
        'lowStockCount' => number_format($restockCount),
        'inventoryForecastHtml' => dashboard_inventory_table($inventoryPlanning),
        'inventoryForecastData' => $inventoryPlanning,
        'categoryCount' => number_format((int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM categories')),
        'itemCount' => number_format((int) dashboard_scalar($pdo, 'SELECT COUNT(*) FROM items')),
        'completedOrders' => number_format($completedOrdersLive),
        'averageOrder' => dashboard_money($averageOrderLive),
        'ordersChart' => array_values($ordersChart),
        'revenueChart' => array_values($revenueChart),
        'statusCounts' => $statusCountsLive,
        'recentOrders' => $recentOrdersLive,
        'topItems' => $topItemsLive,
        'lowStockItems' => $lowStockLive,
        'movementItems' => $movementLive,
        'latestInventory' => $latestInventoryLive,
    ]);
    exit;
}

if (isset($_GET['ajax_dashboard_forecast']) && $isIntelligenceDashboard) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        analytics_evaluate_forecasts($pdo);
        $dailySeries = analytics_daily_sales($pdo, 28, true);
        $forecastOrders = (int) round(analytics_weekday_forecast($dailySeries, 'orders'));
        $forecastRevenue = analytics_weekday_forecast($dailySeries, 'revenue');
        $last7Start = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $prev7Start = date('Y-m-d 00:00:00', strtotime('-14 days'));
        $prev7End = date('Y-m-d 00:00:00', strtotime('-7 days'));
        $last7Sales = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < CURDATE()", [$last7Start]);
        $prev7Sales = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$prev7Start, $prev7End]);
        $salesGrowth = $prev7Sales > 0 ? (($last7Sales - $prev7Sales) / $prev7Sales) * 100 : null;
        $movementRows = analytics_product_movement($pdo);
        $turnoverRows = analytics_inventory_turnover($pdo);
        $fastMovingCount = count(array_filter($movementRows, fn($row) => $row['movement'] === 'Fast-moving'));
        $slowMovingCount = count(array_filter($movementRows, fn($row) => $row['movement'] === 'Slow-moving' || $row['movement'] === 'No movement'));
        $averageTurnover = $turnoverRows ? array_sum(array_column($turnoverRows, 'turnover_rate')) / count($turnoverRows) : 0.0;
        $inventoryForecastRows = dashboard_rows($pdo, "
            SELECT inv.name, inv.cup_size, inv.qty, inv.unit, inv.critical_level,
                   COALESCE(SUM(CASE WHEN il.change_type IN ('used','deduct','auto-deduct','damage') THEN il.quantity ELSE 0 END), 0) AS used_14
            FROM inventory inv
            LEFT JOIN inventory_logs il ON il.inventory_id = inv.id AND il.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
            GROUP BY inv.id, inv.name, inv.cup_size, inv.qty, inv.unit, inv.critical_level
        ");
        $inventoryRisk = null;
        foreach ($inventoryForecastRows as $row) {
            $dailyUse = (float) ($row['used_14'] ?? 0) / 14;
            $qty = (float) ($row['qty'] ?? 0);
            $daysLeft = $dailyUse > 0 ? $qty / $dailyUse : null;
            $isCritical = isset($row['critical_level']) && (float) $row['critical_level'] > 0 && $qty <= (float) $row['critical_level'];
            if ($inventoryRisk === null || ($daysLeft !== null && ($inventoryRisk['days_left'] === null || $daysLeft < $inventoryRisk['days_left'])) || ($daysLeft === null && $isCritical && empty($inventoryRisk['is_critical']))) {
                $inventoryRisk = $row + ['days_left' => $daysLeft, 'is_critical' => $isCritical];
            }
        }
        $topItem = dashboard_rows($pdo, "
            SELECT i.name, i.cup_size, SUM(oi.qty) AS qty_sold
            FROM order_items oi
            JOIN items i ON i.id = oi.item_id
            JOIN orders o ON o.id = oi.order_id
            WHERE o.status = 'completed' AND o.created_at >= ? AND o.created_at < ?
            GROUP BY i.id, i.name, i.cup_size
            ORDER BY qty_sold DESC
            LIMIT 1
        ", [date('Y-m-01 00:00:00'), date('Y-m-01 00:00:00', strtotime('+1 month'))])[0] ?? null;
        $accuracy = analytics_accuracy($pdo);
        $riskName = $inventoryRisk ? trim($inventoryRisk['name'] . ' ' . (dashboard_normalize_cup_size($inventoryRisk['cup_size']) ? '(' . dashboard_normalize_cup_size($inventoryRisk['cup_size']) . ')' : '')) : 'No inventory risk';
        $riskDays = $inventoryRisk ? dashboard_days_label($inventoryRisk['days_left']) : 'No usage trend';
        $smartRecommendation = 'Keep recording orders and inventory movement to improve forecasting accuracy.';
        if ($salesGrowth > 10) {
            $smartRecommendation = 'Sales are trending upward. Prepare extra stock and staff coverage for the next service period.';
        } elseif ((int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'pending'") > 0) {
            $smartRecommendation = 'Settle pending orders first so sales analytics and inventory deduction stay accurate.';
        }
        $aiRecommendation = analytics_ai_recommendation($pdo, $settings, [
            'sales_growth_percent' => ($salesGrowth === null ? null : round($salesGrowth, 1)),
            'tomorrow_orders_forecast' => $forecastOrders,
            'tomorrow_revenue_forecast' => round($forecastRevenue, 2),
            'fast_moving_products' => $fastMovingCount,
            'slow_or_no_movement_products' => $slowMovingCount,
            
        ]);
        if ($aiRecommendation !== null) $smartRecommendation = $aiRecommendation;
        echo json_encode([
            'salesGrowth' => ($salesGrowth === null ? 'No baseline' : dashboard_percent_label($salesGrowth)),
            'last7Sales' => dashboard_money($last7Sales) . ' over 7 complete days',
            'forecastOrders' => number_format($forecastOrders),
            'forecastRevenue' => dashboard_money($forecastRevenue),
            'bestSeller' => $topItem ? trim($topItem['name'] . (dashboard_normalize_cup_size($topItem['cup_size']) ? ' (' . dashboard_normalize_cup_size($topItem['cup_size']) . ')' : '')) : 'No sales yet',
            'bestSellerQty' => $topItem ? number_format((float) $topItem['qty_sold'], 0) . ' sold this month' : 'Complete orders to train analytics',
            'riskName' => $riskName,
            'riskDays' => $riskDays,
            'accuracy' => $accuracy === null ? 'Collecting data' : number_format($accuracy, 1) . '%',
            'turnover' => number_format($averageTurnover, 2) . 'x',
            'fastMoving' => number_format($fastMovingCount),
            'slowMoving' => number_format($slowMovingCount),
            'recommendation' => $smartRecommendation,
            'salesChart' => analytics_sales_chart($pdo),
            'inventoryForecastHtml' => dashboard_inventory_table($inventoryPlanning),
        'inventoryForecastData' => $inventoryPlanning,
            'restockCount' => number_format($restockCount),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('Dashboard forecast auto-update failed: ' . $e->getMessage());
        echo json_encode(['error' => 'Forecast update is temporarily unavailable.']);
    }
    exit;
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
    LIMIT 5
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
    LIMIT 5
");

$topItems = dashboard_rows($pdo, "
    SELECT i.name, i.cup_size, SUM(oi.qty) AS qty_sold, COALESCE(SUM(oi.subtotal * CASE WHEN o.subtotal > 0 THEN o.total / o.subtotal ELSE 1 END), SUM(oi.qty * oi.price), 0) AS sales
    FROM order_items oi
    JOIN items i ON i.id = oi.item_id
    JOIN orders o ON o.id = oi.order_id
    WHERE o.status = 'completed' AND o.created_at >= ? AND o.created_at < ?
    GROUP BY i.id, i.name, i.cup_size
    ORDER BY qty_sold DESC
    LIMIT 5
", [$monthStart, $nextMonth]);

$last7Start = date('Y-m-d 00:00:00', strtotime('-7 days'));
$prev7Start = date('Y-m-d 00:00:00', strtotime('-14 days'));
$prev7End = date('Y-m-d 00:00:00', strtotime('-7 days'));
$last7Sales = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < CURDATE()", [$last7Start]);
$prev7Sales = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?", [$prev7Start, $prev7End]);
$last14CompletedOrders = (int) dashboard_scalar($pdo, "SELECT COUNT(*) FROM orders WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)");
$last14Revenue = (float) dashboard_scalar($pdo, "SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)");

$salesGrowth = $prev7Sales > 0 ? (($last7Sales - $prev7Sales) / $prev7Sales) * 100 : null;
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
$salesAnalyticsChart = null;
if ($isIntelligenceDashboard) {
    try { $salesAnalyticsChart = analytics_sales_chart($pdo); }
    catch (Throwable $e) { error_log('Sales chart unavailable: ' . $e->getMessage()); }
}
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
$aiModel = ai_model($settings);
$aiModeLabel = $aiEnabled && $aiApiConfigured ? $aiProvider . ' Configured (not verified)' : 'Local Analytics';
$riskName = $inventoryRisk ? trim($inventoryRisk['name'] . ' ' . (dashboard_normalize_cup_size($inventoryRisk['cup_size']) ? '(' . dashboard_normalize_cup_size($inventoryRisk['cup_size']) . ')' : '')) : 'No inventory risk';
$riskDays = $inventoryRisk ? dashboard_days_label($inventoryRisk['days_left']) : 'No usage trend';
$bestSellerName = $topItem ? trim($topItem['name'] . (dashboard_normalize_cup_size($topItem['cup_size']) ? ' (' . dashboard_normalize_cup_size($topItem['cup_size']) . ')' : '')) : 'No sales yet';

$smartRecommendation = 'Keep recording orders and inventory movement to improve forecasting accuracy.';
if ($salesGrowth > 10) {
    $smartRecommendation = 'Sales are trending upward. Prepare extra stock and staff coverage for the next service period.';
} elseif ($pendingOrders > 0) {
    $smartRecommendation = 'Settle pending orders first so sales analytics and inventory deduction stay accurate.';
}

$aiRecommendation = analytics_ai_recommendation($pdo, $settings, [
    'sales_growth_percent' => ($salesGrowth === null ? null : round($salesGrowth, 1)),
    'tomorrow_orders_forecast' => $forecastOrders,
    'tomorrow_revenue_forecast' => round($forecastRevenue, 2),
    'fast_moving_products' => $fastMovingCount,
    'slow_or_no_movement_products' => $slowMovingCount,
    
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
    ? 'Point of Sale System with Inventory Forecasting and AI Sales Analytics.'
    : ($isAdminDashboard
        ? 'Point of Sale System with Inventory Forecasting and AI Sales Analytics.'
        : 'Handle orders, payments, and service activity without management reports or settings access.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kape Agosto - <?= htmlspecialchars($dashboardTitle) ?></title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/management-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/management-workspace.css')) ?>">
  <?php if ($isIntelligenceDashboard): ?><script src="https://cdn.jsdelivr.net/npm/chart.js"></script><?php endif; ?>
</head>
<body class="kape-pages-dashboard compact-management-page">
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
    <div class="dashboard-tabs" data-dashboard-tabs>
    <section class="dashboard-kpis" aria-label="Key metrics">
      <article class="dashboard-kpi revenue"><span class="kpi-icon"><i class="fa fa-money-bill-trend-up"></i></span><span class="kpi-label">Monthly Revenue</span><strong id="dashboardMonthlyRevenue"><?= dashboard_money($monthlyRevenue) ?></strong><small>Today: <span id="dashboardTodayRevenue"><?= dashboard_money($todayRevenue) ?></span></small></article>
      <article class="dashboard-kpi orders"><span class="kpi-icon"><i class="fa fa-receipt"></i></span><span class="kpi-label">Orders Today</span><strong id="dashboardTodayOrders"><?= number_format($todayOrders) ?></strong><small><span id="dashboardPendingOrders"><?= number_format($pendingOrders) ?></span> pending <?= htmlspecialchars(dashboard_plural_label($pendingOrders, 'Order')) ?></small></article>
      <article class="dashboard-kpi users"><span class="kpi-icon"><i class="fa fa-user-check"></i></span><span class="kpi-label">Active Users</span><strong id="dashboardActiveUsers"><?= number_format($activeUsers) ?></strong><small><span id="dashboardTotalUsers"><?= number_format($totalUsers) ?></span> total accounts</small></article>
      <article class="dashboard-kpi inventory"><span class="kpi-icon"><i class="fa fa-boxes-stacked"></i></span><span class="kpi-label">Inventory Units</span><strong id="dashboardInventoryUnits"><?= number_format($inventoryUnits, 0) ?></strong><small><span id="dashboardLowStockCount"><?= number_format($restockCount) ?></span> restock alerts</small></article>
    </section>
      <div class="dashboard-tab-list" role="tablist" aria-label="Dashboard sections">
        <button type="button" class="dashboard-tab" id="dashboard-tab-sales" role="tab" aria-controls="dashboard-panel-sales" aria-selected="true" tabindex="0" data-tab="sales"><i class="fa fa-chart-line" aria-hidden="true"></i><span>Sales &amp; Orders</span></button>
        <button type="button" class="dashboard-tab" id="dashboard-tab-inventory" role="tab" aria-controls="dashboard-panel-inventory" aria-selected="false" tabindex="-1" data-tab="inventory"><i class="fa fa-boxes-stacked" aria-hidden="true"></i><span>Inventory Forecasting</span><span class="dashboard-tab-count" id="dashboardTabLowStockCount"><?= number_format($restockCount) ?></span></button>
        <button type="button" class="dashboard-tab" id="dashboard-tab-ai" role="tab" aria-controls="dashboard-panel-ai" aria-selected="false" tabindex="-1" data-tab="ai"><i class="fa fa-wand-magic-sparkles" aria-hidden="true"></i><span>AI Sales Analytics</span></button>
      </div>
      <section class="dashboard-tab-panel" id="dashboard-panel-ai" role="tabpanel" aria-labelledby="dashboard-tab-ai" data-tab-panel="ai" hidden>
      <section class="dashboard-insight ai-analytics-panel" aria-label="AI sales analytics and recommendations">
        <div class="insight-copy">
        <span class="insight-label"><i class="fa fa-wand-magic-sparkles"></i> AI Sales Analytics</span>
        <p id="dashboardSmartRecommendation"><?= htmlspecialchars($smartRecommendation) ?></p>
        <div class="ai-generation-controls">
          <button type="button" class="insight-action" id="generateAiRecommendation">Generate New AI Recommendation</button>
          <small>Generate insights from your latest sales data. You can generate again after 30 seconds.</small>
        </div>
        <p id="aiGenerationStatus" role="status" aria-live="polite">Generate a recommendation to request an AI response. Auto-refresh updates local forecasts only and does not use API quota.</p>
        <p id="aiGenerationReady" role="status" aria-live="polite"></p>
        <p id="aiGeneratedAt"><?php if ($aiRecommendation !== null && !empty($settings['ai_sales_last_generated_at'])): ?>Saved AI recommendation generated at <?= htmlspecialchars($settings['ai_sales_last_generated_at']) ?> (server time)<?php endif; ?></p>
        <details id="aiDataUsed" hidden><summary>Sales summary used by AI</summary><dl id="aiDataUsedContent" class="ai-data-summary"></dl><small>Forecasts use completed days and matching weekdays. Today so far is excluded from the comparison.</small></details>
        <div class="ai-mode-line" id="dashboardForecastUpdated" aria-live="polite"><span><i class="fa fa-clock"></i> Auto-updates every 15 seconds</span></div>
        <div class="ai-mode-line">
          <span><i class="fa fa-microchip"></i> <?= htmlspecialchars($aiModeLabel) ?></span>
          <span><i class="fa fa-layer-group"></i> <?= htmlspecialchars($aiModel ?: 'Model not set') ?></span>
        </div>
      </div>
      <div class="insight-actions">
        <a href="reports.php" class="insight-action">View Reports</a>
        <a href="<?= $isManagerDashboard ? 'manager_settings.php' : 'ai_settings.php' ?>" class="insight-action secondary"><?= $isManagerDashboard ? 'Forecast Settings' : 'AI Settings' ?></a>
      </div>
      </section>
      <article class="dashboard-panel ai-sales-chart-panel">
        <div class="panel-header"><div><span class="panel-eyebrow">Sales Analytics</span><h2>Actual sales &amp; forecast</h2></div>
          <label for="aiSalesMetric">Show <select id="aiSalesMetric"><option value="revenue">Revenue (PHP)</option><option value="orders">Completed orders</option></select></label>
        </div>
        <p>Actual sales include today so far. Tomorrow's estimate uses 28 completed days and recent matching weekdays.</p>
        <p id="aiSalesChartStatus" role="status"></p>
        <p id="aiSalesPattern" aria-live="polite"></p>
        <div class="dashboard-chart-frame"><canvas id="aiSalesChart" role="img" aria-label="Daily completed sales, 7-day average, saved forecasts, and tomorrow's estimate"></canvas></div>
        <details class="ai-sales-method"><summary>View chart data</summary><div class="manager-table-wrap"><table><thead><tr><th>Date</th><th>Actual</th><th>7-day average</th><th>Saved forecast</th><th>Tomorrow estimate</th></tr></thead><tbody id="aiSalesChartRows"></tbody></table></div></details>
      </article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Predictive Analytics</span><h2>Product movement</h2></div><span class="panel-pill">30 days</span></div><div class="dashboard-list compact" id="dashboardMovementList"><?php if ($movementRows): ?><?php foreach (array_slice($movementRows, 0, 5) as $item): ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($item['cup_size'] ? ' (' . $item['cup_size'] . ')' : '')) ?></strong><span><?= htmlspecialchars($item['movement']) ?> · <?= number_format((float) $item['qty_30'], 0) ?> sold</span></div><strong><?= dashboard_percent_label((float) $item['trend_percent']) ?></strong></div><?php endforeach; ?><?php else: ?><p class="empty-state">Add products and completed sales to classify movement.</p><?php endif; ?></div></article>
      <section class="ai-analytics-grid" aria-label="Sales analytics cards">
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-chart-line"></i></span><span class="ai-card-label">7-Day Sales Trend</span><strong id="dashboardSalesGrowth"><?= htmlspecialchars(($salesGrowth === null ? 'No baseline' : dashboard_percent_label($salesGrowth))) ?></strong><small id="dashboardLast7Sales"><?= dashboard_money($last7Sales) ?> over 7 complete days</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-calendar-day"></i></span><span class="ai-card-label">Tomorrow Forecast</span><strong><span id="dashboardForecastOrders"><?= number_format($forecastOrders) ?></span> orders</strong><small>Projected <span id="dashboardForecastRevenue"><?= dashboard_money($forecastRevenue) ?></span></small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-trophy"></i></span><span class="ai-card-label">Best Seller</span><strong id="dashboardBestSeller"><?= htmlspecialchars($bestSellerName) ?></strong><small id="dashboardBestSellerQty"><?= $topItem ? number_format((float) $topItem['qty_sold'], 0) . ' sold this month' : 'Complete orders to train analytics' ?></small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-bullseye"></i></span><span class="ai-card-label">Forecast Accuracy</span><strong id="dashboardForecastAccuracy"><?= $forecastAccuracy === null ? 'Collecting data' : number_format($forecastAccuracy, 1) . '%' ?></strong><small>Last 30 days of evaluated forecasts</small></article>

      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-forward-fast"></i></span><span class="ai-card-label">Fast-moving</span><strong id="dashboardFastMovingCount"><?= number_format($fastMovingCount) ?></strong><small>Products in the leading movement band</small></article>
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-hourglass-half"></i></span><span class="ai-card-label">Slow / No Movement</span><strong id="dashboardSlowMovingCount"><?= number_format($slowMovingCount) ?></strong><small>Products requiring review</small></article>
      </section>
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

    <section class="dashboard-tab-panel" id="dashboard-panel-sales" role="tabpanel" aria-labelledby="dashboard-tab-sales" data-tab-panel="sales">
      <section class="dashboard-grid">
      <article class="dashboard-panel dashboard-story"><div><span class="panel-eyebrow">Cafe Overview</span><h2>Sales Summary</h2><p>Average completed order value this month is <strong id="dashboardAverageOrder"><?= dashboard_money($averageOrder) ?></strong>; <strong id="dashboardCompletedOrders"><?= number_format($completedOrders) ?></strong> completed <?= htmlspecialchars(dashboard_plural_label($completedOrders, 'Order')) ?> overall.</p></div><a href="<?= $isManagerDashboard ? 'order_list.php' : 'orders.php' ?>" class="panel-action"><?= $isManagerDashboard ? 'Review Orders' : 'Manage Orders' ?> <i class="fa fa-arrow-right"></i></a></article>
      <article class="dashboard-panel chart-panel wide"><div class="panel-header"><div><span class="panel-eyebrow">Performance</span><h2>Orders and revenue trend</h2></div><span class="panel-pill"><?= date('Y') ?></span></div><div class="dashboard-chart-frame"><canvas id="trendChart"></canvas></div></article>
      <article class="dashboard-panel chart-panel"><div class="panel-header"><div><span class="panel-eyebrow">Status</span><h2>Order mix</h2></div></div><div class="dashboard-chart-frame"><canvas id="statusChart"></canvas></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Activity</span><h2>Recent orders</h2></div><a href="order_list.php">View all</a></div><div class="dashboard-list" id="dashboardRecentOrdersList"><?php if ($recentOrders): ?><?php foreach ($recentOrders as $order): ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($order['order_code'] ?: 'Order') ?></strong><span><?= htmlspecialchars($order['customer_name'] ?: 'Walk-in') ?> &middot; <?= date('M d, g:i A', strtotime($order['created_at'])) ?></span></div><div class="row-end"><strong><?= dashboard_money($order['total']) ?></strong><span class="status-chip <?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></div></div><?php endforeach; ?><?php else: ?><p class="empty-state">No recent orders yet.</p><?php endif; ?></div></article>
      <article class="dashboard-panel list-panel"><div class="panel-header"><div><span class="panel-eyebrow">Sales</span><h2>Top items this month</h2></div><div class="dashboard-panel-links"><span class="panel-pill"><span id="dashboardItemCount"><?= number_format($itemCount) ?></span> items</span><a href="items.php">View menu</a></div></div><div class="dashboard-list compact" id="dashboardTopItemsList"><?php if ($topItems): ?><?php foreach ($topItems as $item): ?><?php $cup = dashboard_normalize_cup_size($item['cup_size']); ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($cup ? ' (' . $cup . ')' : '')) ?></strong><span><?= number_format((float) $item['qty_sold'], 0) ?> sold</span></div><strong><?= dashboard_money($item['sales']) ?></strong></div><?php endforeach; ?><?php else: ?><p class="empty-state">Completed sales will appear here.</p><?php endif; ?></div></article>
          </section>
    </section>
    <section class="dashboard-tab-panel" id="dashboard-panel-inventory" role="tabpanel" aria-labelledby="dashboard-tab-inventory" data-tab-panel="inventory" hidden>
      <section class="dashboard-insight"><div class="insight-copy"><span class="insight-label"><i class="fa fa-boxes-stacked"></i> Inventory Forecasting</span><p>What will run out, when should we reorder, and how much should we buy? Estimates use 14 complete days of material usage, saved supplier lead time, a 2-day buffer, and 7 days of extra coverage. Quantities use each material's unit.</p></div><div class="insight-actions"><a href="<?= $isManagerDashboard ? 'manager_settings.php' : 'inventory.php' ?>" class="insight-action"><?= $isManagerDashboard ? 'View Forecast Details' : 'Manage Stock' ?></a></div></section>
      <section class="dashboard-grid dashboard-inventory-grid" aria-label="Inventory overview">
      <article class="ai-analytics-card"><span class="ai-card-icon"><i class="fa fa-gauge-high"></i></span><span class="ai-card-label">Inventory Turnover</span><strong id="dashboardAverageTurnover"><?= number_format($averageTurnover, 2) ?>x</strong><small>30-day usage / estimated average stock</small></article>

      <details class="dashboard-panel list-panel inventory-latest-summary"><summary class="panel-header"><div><span class="panel-eyebrow">Stockroom</span><h2>Latest inventory</h2><small>Expand to view 5 recently updated materials</small></div><div class="dashboard-panel-links"><span class="panel-pill"><span id="dashboardCategoryCount"><?= number_format($categoryCount) ?></span> categories</span><span class="inventory-summary-toggle">Show / hide</span></div></summary><div class="dashboard-list compact" id="dashboardLatestInventoryList"><?php if ($latestInventory): ?><?php foreach ($latestInventory as $item): ?><?php $cup = dashboard_normalize_cup_size($item['cup_size']); ?><div class="dashboard-list-row"><div><strong><?= htmlspecialchars($item['name'] . ($cup ? ' (' . $cup . ')' : '')) ?></strong><span><?= htmlspecialchars($item['unit'] ?: 'units') ?></span></div><strong><?= number_format((float) $item['qty'], 0) ?></strong></div><?php endforeach; ?><?php else: ?><p class="empty-state">Inventory records will appear here.</p><?php endif; ?></div><a class="inventory-summary-link" href="<?= $isManagerDashboard ? 'manager_settings.php' : 'inventory.php' ?>">View inventory details &rarr;</a></details>
      </section>
      <?php include dirname(__DIR__) . '/partials/inventory_forecast_workspace.php'; ?>
      <article class="dashboard-panel list-panel" style="margin:14px 0"><div class="panel-header"><div><span class="panel-eyebrow">Restock Planning</span><h2>Stock coverage &amp; recommended purchases</h2></div><span class="panel-pill">Automatic forecast</span></div><p>Restock alerts use the calculated reorder point or an empty balance. Missing usage history is shown explicitly; forecasts do not change stock or place orders.</p><div class="manager-table-wrap"><table><thead><tr><th>Material</th><th>On hand</th><th>Average usage</th><th>Coverage</th><th>Reorder point</th><th>Recommended purchase</th><th>Status</th></tr></thead><tbody id="dashboardInventoryForecastRows"><?= dashboard_inventory_table($inventoryPlanning) ?></tbody></table></div></article>

    </section>
  </div>
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
<script type="application/json" id="inventoryPlanningData"><?= json_encode($inventoryPlanning, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/inventory-forecast.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/inventory-forecast.js')) ?>"></script>
<script type="application/json" id="aiSalesChartData"><?= json_encode($salesAnalyticsChart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/ai-sales-chart.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/ai-sales-chart.js')) ?>"></script>
<script>
  let manualAiRecommendation = false;
  const generateAiButton = document.getElementById('generateAiRecommendation');
  let aiRequestInProgress = false;
  let aiReadyTimer;
  let aiReadyAt = Date.now() + <?= max(0, 30 - (time() - (int) ($_SESSION['ai_recommendation_requested_at'] ?? 0))) ?> * 1000;
  function updateAiReadiness(announce = true) {
    clearTimeout(aiReadyTimer);
    const remaining = aiReadyAt - Date.now();
    generateAiButton.disabled = aiRequestInProgress || remaining > 0;
    generateAiButton.textContent = aiRequestInProgress ? 'Generating...' : 'Generate New AI Recommendation';
    const ready = document.getElementById('aiGenerationReady');
    ready.textContent = remaining > 0
      ? 'Please wait before generating again.'
      : announce && !aiRequestInProgress ? 'You can generate a new recommendation now.' : '';
    if (remaining > 0) aiReadyTimer = setTimeout(() => updateAiReadiness(), remaining + 50);
  }
  updateAiReadiness(false);
  generateAiButton?.addEventListener('click', async () => {
    if (aiRequestInProgress || Date.now() < aiReadyAt) return;
    const status = document.getElementById('aiGenerationStatus');
    aiRequestInProgress = true;
    aiReadyAt = Date.now() + 30000;
    updateAiReadiness();
    status.textContent = 'Requesting a fresh response from the AI provider...';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 70000);
    try {
      const url = new URL(window.location.href);
      url.search = '';
      url.searchParams.set('generate_ai_recommendation', '1');
      const response = await fetch(url, {
        method: 'POST', cache: 'no-store', signal: controller.signal,
        body: new URLSearchParams({token: <?= json_encode($_SESSION['ai_recommendation_token']) ?>})
      });
      const data = await response.json();
      const retryAfter = Number(response.headers.get('Retry-After'));
      if (response.status === 429 && retryAfter > 0) aiReadyAt = Date.now() + retryAfter * 1000;
      if (!response.ok || !data.ok) throw new Error(data.error || 'AI request failed.');
      manualAiRecommendation = true;
      document.getElementById('dashboardSmartRecommendation').textContent = data.recommendation;
      document.getElementById('aiGeneratedAt').textContent = 'Generated at ' + data.generatedAt;
      const metrics = data.dataUsed || {};
      const number = new Intl.NumberFormat('en-PH');
      const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
      const growth = metrics.sales_growth_percent == null ? NaN : Number(metrics.sales_growth_percent);
      const period = metrics.comparison;
      const summary = [
        ['Sales change (complete days)', Number.isFinite(growth) ? (growth > 0 ? '+' : '') + growth.toFixed(1) + '%' : 'Unavailable'],
        ['Estimated orders tomorrow', metrics.tomorrow_orders_forecast == null ? 'Unavailable' : number.format(metrics.tomorrow_orders_forecast)],
        ['Estimated revenue tomorrow', metrics.tomorrow_revenue_forecast == null ? 'Unavailable' : currency.format(metrics.tomorrow_revenue_forecast)],
        ['Fast-moving products', metrics.fast_moving_products == null ? 'Unavailable' : number.format(metrics.fast_moving_products)],
        ['Slow / no-movement products', metrics.slow_or_no_movement_products == null ? 'Unavailable' : number.format(metrics.slow_or_no_movement_products)]
      ];
      if (period) {
        summary.unshift(['Recent sales (' + period.recentStart + ' to ' + period.recentEnd + ')', currency.format(period.recentRevenue)], ['Previous sales (' + period.previousStart + ' to ' + period.previousEnd + ')', currency.format(period.previousRevenue)]);
      }
      document.getElementById('aiDataUsedContent').replaceChildren(...summary.map(([label, value]) => {
        const row = document.createElement('div');
        const term = document.createElement('dt');
        const description = document.createElement('dd');
        term.textContent = label;
        description.textContent = value;
        row.append(term, description);
        return row;
      }));
      document.getElementById('aiDataUsed').hidden = false;
      status.textContent = 'Fresh AI response received • Model: ' + data.model;
    } catch (error) {
      status.textContent = error.name === 'AbortError'
        ? 'AI request timed out. The previous recommendation is retained. Please try again shortly.'
        : error instanceof TypeError
          ? 'Unable to reach the server. Check your connection and try again. The previous recommendation is retained.'
          : error.message;
    } finally {
      clearTimeout(timeout);
      aiRequestInProgress = false;
      updateAiReadiness();
    }
  });
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
  function setDashboardTab(tabName, updateUrl) {
    const root = document.querySelector('[data-dashboard-tabs]');
    if (!root) return;
    const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
    const panels = Array.from(root.querySelectorAll('[role="tabpanel"]'));
    const activeTab = tabs.find(function(tab) { return tab.dataset.tab === tabName; }) || tabs[0];
    if (!activeTab) return;

    tabs.forEach(function(tab) {
      const selected = tab === activeTab;
      tab.setAttribute('aria-selected', selected ? 'true' : 'false');
      tab.tabIndex = selected ? 0 : -1;
    });
    panels.forEach(function(panel) {
      panel.hidden = panel.dataset.tabPanel !== activeTab.dataset.tab;
    });

    if (window.Chart) {
      window.requestAnimationFrame(function() {
        [document.getElementById('trendChart'), document.getElementById('statusChart'), document.getElementById('aiSalesChart')].forEach(function(canvas) {
          const chart = canvas && Chart.getChart(canvas);
          if (chart) chart.resize();
        });
      });
    }

    if (updateUrl) {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', activeTab.dataset.tab);
      window.history.replaceState({}, '', url);
    }
  }

  document.addEventListener('click', function(event) {
    const tab = event.target.closest('[data-dashboard-tabs] [role="tab"]');
    if (tab) setDashboardTab(tab.dataset.tab, true);
  });

  document.addEventListener('keydown', function(event) {
    const tab = event.target.closest('[data-dashboard-tabs] [role="tab"]');
    if (!tab) return;

    const tabs = Array.from(tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]'));
    const index = tabs.indexOf(tab);
    let next = index;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = tabs.length - 1;
    else return;

    event.preventDefault();
    tabs[next].focus();
    setDashboardTab(tabs[next].dataset.tab, true);
  });

  async function updateDashboardForecast() {
    if (document.hidden) return;
    const finishLoading = window.KapeLoading?.start({ label: 'Updating forecast...' });
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('ajax_dashboard_forecast', '1');
      url.searchParams.set('_', Date.now().toString());
      const response = await fetch(url.toString(), { cache: 'no-store' });
      if (!response.ok) throw new Error('Forecast endpoint returned ' + response.status);
      const data = await response.json();
      updateInventoryPlanning(data);
      if (data.salesChart && window.renderAiSalesChart) window.renderAiSalesChart(data.salesChart);
      const fields = {
        dashboardSalesGrowth: data.salesGrowth,
        dashboardLast7Sales: data.last7Sales,
        dashboardForecastOrders: data.forecastOrders,
        dashboardForecastRevenue: data.forecastRevenue,
        dashboardBestSeller: data.bestSeller,
        dashboardBestSellerQty: data.bestSellerQty,
        dashboardRiskName: data.riskName,
        dashboardRiskDays: data.riskDays,
        dashboardForecastAccuracy: data.accuracy,
        dashboardAverageTurnover: data.turnover,
        dashboardFastMovingCount: data.fastMoving,
        dashboardSlowMovingCount: data.slowMoving,
        dashboardSmartRecommendation: data.recommendation
      };
      Object.keys(fields).forEach(function(id) {
        const element = document.getElementById(id);
        if (id === 'dashboardSmartRecommendation' && (manualAiRecommendation || generateAiButton?.disabled)) return;
        if (element && fields[id] !== undefined) element.textContent = fields[id];
      });
      const status = document.getElementById('dashboardForecastUpdated');
      if (status) status.innerHTML = '<span><i class="fa fa-clock"></i> Last updated ' + new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + '</span>';
    } catch (error) {
      const status = document.getElementById('dashboardForecastUpdated');
      if (status) status.innerHTML = '<span><i class="fa fa-triangle-exclamation"></i> Auto-update will retry</span>';
    } finally {
      finishLoading?.();
    }
  }
  async function updateDashboardOrderCounts() {
    if (document.hidden) return;
    const finishLoading = window.KapeLoading?.start({ label: 'Updating order totals...' });
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('ajax_dashboard_counts', '1');
      url.searchParams.set('_', Date.now().toString());
      const response = await fetch(url.toString(), { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      const todayOrders = document.getElementById('dashboardTodayOrders');
      const pendingOrders = document.getElementById('dashboardPendingOrders');
      if (todayOrders) todayOrders.textContent = data.todayOrders;
      if (pendingOrders) pendingOrders.textContent = data.pendingOrders;
    } catch (error) {
      // Retry on the next poll while keeping the latest visible counts.
    } finally {
      finishLoading?.();
    }
  }

  function updateInventoryPlanning(data) {
    if (data.inventoryForecastData && window.renderInventoryForecast) window.renderInventoryForecast(data.inventoryForecastData);
    if (data.inventoryForecastHtml !== undefined) {
      const rows = document.getElementById('dashboardInventoryForecastRows');
      if (rows) rows.innerHTML = data.inventoryForecastHtml;
      window.filterInventoryForecast?.();
    }
    const count = data.restockCount !== undefined ? data.restockCount : data.lowStockCount;
    if (count !== undefined) {
      setDashboardText('dashboardLowStockCount', count);
      setDashboardText('dashboardTabLowStockCount', count);
    }
  }
  function setDashboardText(id, value) {
    const element = document.getElementById(id);
    if (element && value !== undefined && value !== null) element.textContent = value;
  }

  function renderDashboardList(id, rows, emptyText, kind) {
    const list = document.getElementById(id);
    if (!list) return;
    list.replaceChildren();
    if (!rows || !rows.length) {
      const empty = document.createElement('p');
      empty.className = 'empty-state';
      empty.textContent = emptyText;
      list.appendChild(empty);
      return;
    }
    rows.forEach(function(row) {
      const entry = document.createElement('div');
      entry.className = 'dashboard-list-row';
      const info = document.createElement('div');
      const title = document.createElement('strong');
      const detail = document.createElement('span');
      if (kind === 'recent') {
        title.textContent = row.order_code;
        detail.textContent = row.customer_name + ' · ' + row.created_label;
        info.append(title, detail);
        const end = document.createElement('div');
        end.className = 'row-end';
        const total = document.createElement('strong');
        total.textContent = row.total_label;
        const status = document.createElement('span');
        status.className = 'status-chip ' + String(row.status || '').replace(/[^a-z0-9_-]/gi, '');
        status.textContent = row.status_label;
        end.append(total, status);
        entry.append(info, end);
      } else if (kind === 'top') {
        title.textContent = row.label;
        detail.textContent = row.qty_label;
        info.append(title, detail);
        const value = document.createElement('strong');
        value.textContent = row.sales_label;
        entry.append(info, value);
      } else if (kind === 'low-stock') {
        title.textContent = row.label;
        detail.textContent = 'Critical level: ' + row.critical_label;
        info.append(title, detail);
        const value = document.createElement('span');
        value.className = 'stock-pill';
        value.textContent = row.qty_label;
        entry.append(info, value);
      } else if (kind === 'movement') {
        title.textContent = row.label;
        detail.textContent = row.movement_label;
        info.append(title, detail);
        const value = document.createElement('strong');
        value.textContent = row.trend_label;
        entry.append(info, value);
      } else {
        title.textContent = row.label;
        detail.textContent = row.unit;
        info.append(title, detail);
        const value = document.createElement('strong');
        value.textContent = row.qty_label;
        entry.append(info, value);
      }
      list.appendChild(entry);
    });
  }

  async function updateDashboardPanels() {
    if (document.hidden) return;
    const finishLoading = window.KapeLoading?.start({ label: 'Updating dashboard...' });
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('ajax_dashboard_live', '1');
      url.searchParams.set('_', Date.now().toString());
      const response = await fetch(url.toString(), { cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      updateInventoryPlanning(data);
      [
        ['dashboardMonthlyRevenue', data.monthlyRevenue], ['dashboardTodayRevenue', data.todayRevenue],
        ['dashboardActiveUsers', data.activeUsers], ['dashboardTotalUsers', data.totalUsers],
        ['dashboardInventoryUnits', data.inventoryUnits], ['dashboardLowStockCount', data.lowStockCount],
        ['dashboardItemCount', data.itemCount], ['dashboardCategoryCount', data.categoryCount], ['dashboardTabLowStockCount', data.lowStockCount],
        ['dashboardAverageOrder', data.averageOrder], ['dashboardCompletedOrders', data.completedOrders]
      ].forEach(function(pair) { setDashboardText(pair[0], pair[1]); });
      renderDashboardList('dashboardRecentOrdersList', data.recentOrders, 'No recent orders yet.', 'recent');
      renderDashboardList('dashboardTopItemsList', data.topItems, 'Completed sales will appear here.', 'top');
      renderDashboardList('dashboardLowStockList', data.lowStockItems, 'No low-stock items right now.', 'low-stock');
      renderDashboardList('dashboardMovementList', data.movementItems, 'Add products and completed sales to classify movement.', 'movement');
      renderDashboardList('dashboardLatestInventoryList', data.latestInventory, 'Inventory records will appear here.', 'latest');
      if (window.Chart) {
        const trend = trendCanvas && Chart.getChart(trendCanvas);
        if (trend) {
          trend.data.datasets[0].data = data.revenueChart;
          trend.data.datasets[1].data = data.ordersChart;
          trend.update('none');
        }
        const statusChart = statusCanvas && Chart.getChart(statusCanvas);
        if (statusChart) {
          const counts = data.statusCounts || {};
          statusChart.data.datasets[0].data = [counts.pending || 0, counts.completed || 0, counts.cancelled || 0, (counts.void || 0) + (counts.voided || 0)];
          statusChart.update('none');
        }
      }
    } catch (error) {
      // Keep the last rendered dashboard data and retry on the next poll.
    } finally {
      finishLoading?.();
    }
  }

  updateDashboardOrderCounts();
  window.setInterval(updateDashboardOrderCounts, 5000);
  updateDashboardPanels();
  window.setInterval(updateDashboardPanels, 15000);

  updateDashboardForecast();
  window.setInterval(updateDashboardForecast, 15000);
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) { updateDashboardOrderCounts(); updateDashboardPanels(); updateDashboardForecast(); }
  });

  const requestedDashboardTab = new URLSearchParams(window.location.search).get('tab');
  if (requestedDashboardTab) setDashboardTab(requestedDashboardTab, false);
</script>
<?php endif; ?>
</body>
</html>
