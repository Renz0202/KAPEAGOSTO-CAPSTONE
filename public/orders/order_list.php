<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_once dirname(__DIR__, 2) . '/includes/order_date_filter.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/item_variant.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_capability('view_order_history');

$pdo = db();
$userRole = current_user_role();
$isAdmin = is_admin();
$canProcessTransactions = can('process_transactions');
$historyScope = (($_GET['scope'] ?? '') === 'all') && (is_admin() || is_manager());
$canFilterDates = is_admin() || is_manager();
$period = $canFilterDates ? (string) ($_GET['period'] ?? '') : '';
$periodValue = (string) ($_GET[$period === 'month' ? 'month' : ($period === 'year' ? 'year' : 'day')] ?? '');
$dateRange = order_date_filter($period, $periodValue);
$dateFilterError = $period !== '' && !$dateRange ? 'Please select a valid date, month, or year.' : '';
if ($dateRange) $historyScope = true;
$rangeLabel = $dateRange ? $periodValue : ($historyScope ? 'All Records' : 'Today');
$serviceFilter = (string) ($_GET['service_type'] ?? '');
if (!in_array($serviceFilter, ['dine_in', 'take_out'], true)) $serviceFilter = '';
$hasAdminOverride = isset($_SESSION['admin_override']['expires']) && $_SESSION['admin_override']['expires'] >= time();
$autoVoidOrderId = 0;

if (isset($_GET['void_order_id']) && ($isAdmin || $hasAdminOverride)) {
    $autoVoidOrderId = (int) $_GET['void_order_id'];
}

function order_list_money($amount): string {
    return 'PHP ' . number_format((float) $amount, 2);
}

function order_list_status_label(string $status): string {
    if ($status === 'void') {
        return 'Voided';
    }
    if ($status === 'voided') {
        return 'Voided';
    }
    return ucfirst($status ?: 'pending');
}

function order_list_status_help(string $status): string {
    if ($status === 'pending') {
        return 'Waiting for payment, completion, or cancellation.';
    }
    if ($status === 'completed') {
        return 'Finished and ready for receipt reprint.';
    }
    if ($status === 'cancelled') {
        return 'Cancelled without inventory deduction.';
    }
    if ($status === 'void' || $status === 'voided') {
        return 'Voided without inventory deduction.';
    }
    return 'Current order status.';
}

function order_list_get_inventory_status(PDO $pdo): array {
    $inventoryCount = (int) $pdo->query('SELECT COUNT(*) FROM inventory')->fetchColumn();
    $requiredSizes = ['12oz Hot', '16oz', '22oz'];
    $missingCupSizes = [];
    $missingLidSizes = [];

    foreach ($requiredSizes as $size) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE material_type = 'packaging' AND name LIKE '%cup%' AND cup_size = ?");
        $stmt->execute([$size]);
        if ((int) $stmt->fetchColumn() === 0) {
            $missingCupSizes[] = $size;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE material_type = 'packaging' AND (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%' OR name LIKE '%lid%') AND cup_size = ?");
        $stmt->execute([$size]);
        if ((int) $stmt->fetchColumn() === 0) {
            $missingLidSizes[] = $size;
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE material_type = 'packaging' AND name LIKE '%straw%'");
    $stmt->execute();
    $missingStraw = ((int) $stmt->fetchColumn() === 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE material_type = 'packaging' AND name LIKE '%stirrer%'");
    $stmt->execute();
    $missingStirrer = ((int) $stmt->fetchColumn() === 0);

    $lowStockItems = $pdo->query("
        SELECT name, cup_size, qty, unit, critical_level
        FROM inventory
        WHERE critical_level IS NOT NULL AND critical_level > 0 AND qty <= critical_level
        ORDER BY name ASC, cup_size ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return [
        'inventoryCount' => $inventoryCount,
        'missing_cup_sizes' => $missingCupSizes,
        'missing_lid_sizes' => $missingLidSizes,
        'missing_straw' => $missingStraw,
        'missing_stirrer' => $missingStirrer,
        'low_stock_items' => $lowStockItems,
        'inventory_blocked' => (
            $inventoryCount === 0 ||
            !empty($missingCupSizes) ||
            !empty($missingLidSizes) ||
            $missingStraw ||
            $missingStirrer
        ),
    ];
}

function ensure_daily_order_code(PDO $pdo, int $orderId, string $createdAt, ?string $existingCode): string {
    $datePart = date('dmy', strtotime($createdAt));

    if (preg_match('/^(\d{6})-(\d{2})$/', (string) $existingCode, $matches) && $matches[1] === $datePart) {
        return (string) $existingCode;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders o2
        WHERE DATE(o2.created_at) = DATE(?)
          AND (o2.created_at < ? OR (o2.created_at = ? AND o2.id <= ?))
    ");
    $stmt->execute([$createdAt, $createdAt, $createdAt, $orderId]);
    $suffix = (int) $stmt->fetchColumn();
    $newCode = $datePart . '-' . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);

    $check = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE order_code = ? AND id <> ?');
    while (true) {
        $check->execute([$newCode, $orderId]);
        if ((int) $check->fetchColumn() === 0) {
            break;
        }
        $suffix++;
        $newCode = $datePart . '-' . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
    }

    $stmt = $pdo->prepare('UPDATE orders SET order_code = ? WHERE id = ?');
    $stmt->execute([$newCode, $orderId]);

    return $newCode;
}

function order_list_fetch_items(PDO $pdo, int $orderId): array {
    $stmt = $pdo->prepare('
        SELECT oi.item_id, i.name, i.cup_size, oi.qty, oi.price
        FROM order_items oi
        JOIN items i ON i.id = oi.item_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function order_list_inventory_warnings(PDO $pdo, array $items): array {
    $warnings = inventory_service_stock_warnings($pdo, $items);
    $warnings['has_hot_drink'] = false;
    $warnings['has_cold_drink'] = false;
    foreach ($items as $item) {
        if (item_variant_is_hot((string) $item['cup_size'], (string) $item['name'])) {
            $warnings['has_hot_drink'] = true;
        } else {
            $warnings['has_cold_drink'] = true;
        }
    }
    return $warnings;
}
function order_list_order_summary(array $items): array {
    $names = [];
    $sizes = [];
    $totalQty = 0;

    foreach ($items as $item) {
        $qty = (int) ($item['qty'] ?? 0);
        $cupSize = trim((string) ($item['cup_size'] ?? ''));
        $names[] = $item['name'] . ' (' . ($cupSize !== '' ? $cupSize : '-') . ', Qty: ' . $qty . ')';
        if ($cupSize !== '') {
            $sizes[$cupSize] = true;
        }
        $totalQty += $qty;
    }

    return [
        'items' => !empty($names) ? implode(', ', $names) : 'No items',
        'sizes' => !empty($sizes) ? implode(', ', array_keys($sizes)) : '-',
        'qty' => $totalQty,
    ];
}

// Refresh order cards when orders, payments, material stock, or recipes change.
$today = date('Y-m-d');
$signatureWhere = '';
$signatureParams = [];
if ($dateRange) {
    $signatureWhere = ' WHERE o.created_at >= ? AND o.created_at < ?';
    $signatureParams = $dateRange;
} elseif (!$historyScope) {
    $signatureWhere = ' WHERE o.created_at >= ? AND o.created_at < ?';
    $signatureParams = [$today . ' 00:00:00', date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00'];
}

if ($serviceFilter !== '') {
    $signatureWhere .= ($signatureWhere === '' ? ' WHERE ' : ' AND ') . 'o.service_type = ?';
    $signatureParams[] = $serviceFilter;
}
$orderSignatureSql = "
    SELECT COUNT(*) AS row_count, COALESCE(MAX(o.id), 0) AS latest_id,
           COALESCE(SUM(CRC32(CONCAT_WS('|', o.id, COALESCE(o.order_code, ''), COALESCE(o.customer_name, ''), o.total, o.status, COALESCE(o.service_type, ''), COALESCE(o.job_status, ''), COALESCE(o.updated_at, o.created_at)))), 0) AS checksum
    FROM orders o
" . $signatureWhere;
$orderSignatureStmt = $pdo->prepare($orderSignatureSql);
$orderSignatureStmt->execute($signatureParams);
$orderSignature = $orderSignatureStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$paymentSignatureSql = "
    SELECT COUNT(p.id) AS row_count, COALESCE(MAX(p.id), 0) AS latest_id,
           COALESCE(SUM(CRC32(CONCAT_WS('|', p.id, p.order_id, p.amount, p.status, COALESCE(p.updated_at, p.created_at)))), 0) AS checksum
    FROM payments p
    INNER JOIN orders o ON o.id = p.order_id
" . ($signatureWhere !== '' ? str_replace('o.created_at', 'o.created_at', $signatureWhere) : '');
$paymentSignatureStmt = $pdo->prepare($paymentSignatureSql);
$paymentSignatureStmt->execute($signatureParams);
$paymentSignature = $paymentSignatureStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$inventorySignature = $pdo->query("SELECT COUNT(*) AS row_count, COALESCE(SUM(CRC32(CONCAT_WS('|', id, name, material_type, COALESCE(cup_size, ''), qty, unit, COALESCE(critical_level, '')))), 0) AS checksum FROM inventory")->fetch(PDO::FETCH_ASSOC) ?: [];
$recipeSignature = $pdo->query("SELECT COUNT(*) AS row_count, COALESCE(SUM(CRC32(CONCAT_WS('|', item_id, inventory_id, quantity_needed))), 0) AS checksum FROM item_requirements")->fetch(PDO::FETCH_ASSOC) ?: [];
$liveVersion = hash('sha256', implode(':', [
    implode(',', $orderSignature),
    implode(',', $paymentSignature),
    implode(',', $inventorySignature),
    implode(',', $recipeSignature),
]));

if (isset($_GET['_changes'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(['version' => $liveVersion]);
    exit;
}
$inventoryStatus = order_list_get_inventory_status($pdo);
$search = trim($_GET['search'] ?? '');
$today = date('Y-m-d');
$params = [];

$query = "
    SELECT o.id, o.order_code, o.transaction_no, o.sales_invoice_no, o.service_type, o.customer_name AS customer, o.customer_id, o.service_details,
           o.job_status, o.total, o.status, o.created_at, c.contact AS customer_contact,
           c.email AS customer_email,
           u.username AS generated_username,
           r.name AS generated_role
    FROM orders o
    LEFT JOIN customers c ON c.id = o.customer_id
    LEFT JOIN users u ON u.id = o.user_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE 1 = 1
";
if ($dateRange) {
    $query .= ' AND o.created_at >= ? AND o.created_at < ?';
    $params = $dateRange;
} elseif (!$historyScope) {
    $query .= ' AND DATE(o.created_at) = ?';
    $params[] = $today;
}

if ($serviceFilter !== '') {
    $query .= ' AND o.service_type = ?';
    $params[] = $serviceFilter;
}
if ($search !== '') {
    $query .= " AND (o.customer_name LIKE ? OR c.customer_code LIKE ? OR c.contact LIKE ? OR c.email LIKE ? OR o.service_details LIKE ? OR CAST(o.id AS CHAR) LIKE ? OR o.order_code LIKE ? OR DATE_FORMAT(o.created_at, '%Y-%m-%d') LIKE ? OR u.full_name LIKE ? OR u.username LIKE ? OR o.transaction_no LIKE ? OR o.sales_invoice_no LIKE ?)";
    for ($i = 0; $i < 12; $i++) $params[] = '%' . $search . '%';
}

$query .= ' ORDER BY o.id DESC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$orderCards = [];
$statusCounts = ['pending' => 0, 'completed' => 0, 'void' => 0, 'cancelled' => 0];
$todayTotal = 0;
$pendingCount = 0;
$completedCount = 0;

foreach ($orders as $order) {
    $orderId = (int) $order['id'];
    $code = ensure_daily_order_code($pdo, $orderId, $order['created_at'], $order['order_code'] ?? '');
    $items = order_list_fetch_items($pdo, $orderId);
    $summary = order_list_order_summary($items);
    $paid = payment_total_paid($pdo, $orderId);
    $remaining = max(0, (float) $order['total'] - $paid);
    $warnings = order_list_inventory_warnings($pdo, $items);
    $status = (string) $order['status'];

    $statusKey = $status === 'voided' ? 'void' : $status;
    if (isset($statusCounts[$statusKey])) {
        $statusCounts[$statusKey]++;
    }
    if ($status === 'pending') {
        $pendingCount++;
    }
    if ($status === 'completed') {
        $completedCount++;
        $todayTotal += (float) $order['total'];
    }

    $orderCards[] = [
        'id' => $orderId,
        'sales_invoice_no' => $order['sales_invoice_no'],
        'transaction_no' => $order['transaction_no'],
        'service_type' => $order['service_type'],
        'code' => $code,
        'customer' => $order['customer'] ?: 'Walk-in',
        'customer_contact' => $order['customer_contact'] ?? '',
        'customer_email' => $order['customer_email'] ?? '',
        'service_details' => $order['service_details'] ?? '',
        'job_status' => $order['job_status'] ?: 'queued',
        'total' => (float) $order['total'],
        'status' => $status,
        'created_at' => $order['created_at'],
        'generated_by' => order_generated_by_label($order['generated_username'] ?? null, $order['generated_role'] ?? null),
        'items_text' => $summary['items'],
        'cup_sizes' => $summary['sizes'],
        'qty' => $summary['qty'],
        'paid' => $paid,
        'remaining' => $remaining,
        'warnings' => $warnings,
    ];
}

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'today' => $today,
        'count' => count($orderCards),
        'orders' => array_map(function($order) {
            return [
                'id' => $order['id'],
                'order_code' => $order['code'],
                'transaction_no' => $order['transaction_no'],
                'sales_invoice_no' => $order['sales_invoice_no'],
                'customer' => $order['customer'],
                'status' => $order['status'],
                'created_at' => $order['created_at'],
                'generated_by' => $order['generated_by'],
                'total' => $order['total'],
                'paid' => $order['paid'],
                'remaining' => $order['remaining'],
            ];
        }, $orderCards),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Order List</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-orders-order-list<?= is_cashier() ? ' cashier-pos-shell cashier-pos-orders' : '' ?>">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content order-list-page" data-live-version="<?= htmlspecialchars($liveVersion, ENT_QUOTES) ?>">
  <section class="order-list-heading" aria-labelledby="order-list-title">
    <div>
      <p class="order-list-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Order List</p>
      <h1 id="order-list-title"><?= $dateRange ? 'Order History — ' . htmlspecialchars($rangeLabel) : ($historyScope ? 'Complete Order History' : 'Order List') ?></h1>
      <p class="order-list-copy"><?= $dateRange ? 'Review orders, payments, and sales for the selected period.' : ($historyScope ? 'Review retained customer, job, payment, and transaction history across all dates.' : ($canProcessTransactions ? "Review today's orders, settle payments, complete paid carts, and manage void requests." : 'Review order history and transaction performance in read-only mode.')) ?></p>
    </div>
    <div class="order-list-summary" id="order-list-summary" aria-label="Order list summary">
      <span><strong><?= number_format(count($orderCards)) ?></strong> <?= htmlspecialchars($rangeLabel) ?></span>
      <span><strong><?= number_format($pendingCount) ?></strong> Pending</span>
      <span><strong><?= number_format($completedCount) ?></strong> Completed</span>
      <span><strong><?= htmlspecialchars(order_list_money($todayTotal)) ?></strong> Sales</span>
    </div>
  </section>

  <?php if ($inventoryStatus['inventory_blocked']): ?>
    <div class="order-list-alert error">
      <strong>Some inventory needs attention.</strong>
      <span>Only orders that require missing or out-of-stock items will be blocked.</span>
      <div class="order-list-alert-grid">
        <?php if ($inventoryStatus['inventoryCount'] === 0): ?><span>No inventory items found.</span><?php endif; ?>
        <?php if (!empty($inventoryStatus['missing_cup_sizes'])): ?><span>Missing cup sizes: <?= htmlspecialchars(implode(', ', $inventoryStatus['missing_cup_sizes'])) ?></span><?php endif; ?>
        <?php if (!empty($inventoryStatus['missing_lid_sizes'])): ?><span>Missing lid sizes: <?= htmlspecialchars(implode(', ', $inventoryStatus['missing_lid_sizes'])) ?></span><?php endif; ?>
        <?php if ($inventoryStatus['missing_straw']): ?><span>Missing required item: Straw</span><?php endif; ?>
        <?php if ($inventoryStatus['missing_stirrer']): ?><span>Missing required item: Coffee Stirrer</span><?php endif; ?>
      </div>
    </div>
  <?php elseif (!empty($inventoryStatus['low_stock_items'])): ?>
    <div class="order-list-alert warning">
      <strong>Low stock notice:</strong>
      <?php
        $lowLabels = array_map(function($item) {
            return $item['name'] . (!empty($item['cup_size']) ? ' (' . $item['cup_size'] . ')' : '');
        }, array_slice($inventoryStatus['low_stock_items'], 0, 4));
      ?>
      <?= htmlspecialchars(implode(', ', $lowLabels)) ?><?= count($inventoryStatus['low_stock_items']) > 4 ? ' and more.' : '.' ?>
    </div>
  <?php endif; ?>

  <?php
    $orderError = (string) ($_GET['error'] ?? '');
    $orderErrorMessages = [
      'void' => 'This order has already been voided and cannot be completed.',
      'not_pending' => 'This order cannot be completed because it is already completed, cancelled, or voided.',
      'unpaid' => 'This order cannot be completed until the payment is fully settled.',
      'inventory' => 'This order cannot be completed because a required inventory item is missing or out of stock.',
      'missing_items' => 'This order cannot be completed because it has no order items.',
      'missing_order' => 'The selected order could not be found.',
      'invalid_order' => 'Invalid order selected.',
      'invalid_request' => 'Invalid completion request.',
      'complete_failed' => 'The order could not be completed. Please try again or check the system logs.',
    ];
  ?>
  <?php if (isset($_GET['void_success'])): ?>
    <div class="order-list-alert success">Order has been voided or cancelled successfully.</div>
  <?php endif; ?>
  <?php if ($orderError !== '' && isset($orderErrorMessages[$orderError])): ?>
    <div class="order-list-alert error"><?= htmlspecialchars($orderErrorMessages[$orderError]) ?></div>
  <?php elseif (isset($_GET['success'])): ?>
    <div class="order-list-alert success">Order has been marked as completed.</div>
  <?php endif; ?>

  <section class="order-list-toolbar">
    <form method="get" class="order-list-search">
      <?php if ($historyScope): ?><input type="hidden" name="scope" value="all"><?php endif; ?>
      <div class="order-search-field"><label for="order-search">Search Orders</label>
      <input id="order-search" type="search" name="search" placeholder="Search by order, customer, or date" value="<?= htmlspecialchars($search) ?>">
      </div>
      <?php if ($canFilterDates): ?>
        <div class="order-date-filters">
          <label for="order-period">Filter by
            <select id="order-period" name="period">
              <option value="">Default view</option>
              <?php foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $period === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label data-order-period="day">Date<input type="date" name="day" value="<?= htmlspecialchars((string) ($_GET['day'] ?? date('Y-m-d'))) ?>"></label>
          <label data-order-period="month">Month<input type="month" name="month" value="<?= htmlspecialchars((string) ($_GET['month'] ?? date('Y-m'))) ?>"></label>
          <label data-order-period="year">Year<input type="number" name="year" min="1000" max="9998" step="1" value="<?= htmlspecialchars((string) ($_GET['year'] ?? date('Y'))) ?>"></label>
        </div>
        <?php if ($dateFilterError): ?><span role="alert"><?= htmlspecialchars($dateFilterError) ?></span><?php endif; ?>
      <?php endif; ?>
      <label class="order-type-filter" for="order-service-filter">Order Type
        <select id="order-service-filter" name="service_type">
          <option value="">All Types</option>
          <option value="dine_in" <?= $serviceFilter === 'dine_in' ? 'selected' : '' ?>>Dine-in</option>
          <option value="take_out" <?= $serviceFilter === 'take_out' ? 'selected' : '' ?>>Take-out</option>
        </select>
      </label>
      <a class="order-filter-reset" href="order_list.php<?= $historyScope ? '?scope=all' : '' ?>">Reset</a>
    </form>
    <?php if (is_admin() || is_manager()): ?>
      <a class="order-list-create" href="<?= $historyScope ? 'order_list.php' : 'order_list.php?scope=all' ?>"><i class="fa fa-clock-rotate-left"></i> <?= $historyScope ? "Today's Orders" : 'Full History' ?></a>
      <a class="order-list-create" href="customers.php"><i class="fa fa-users"></i> Customers</a>
    <?php endif; ?>
    <div class="order-list-live-status" role="status" aria-live="polite">
      <span><i class="fa fa-circle" aria-hidden="true"></i> <span id="order-live-message">Live updates on</span></span>
    </div>
  </section>

  <section class="orders-grid" id="orders-grid" aria-label="<?= $historyScope ? 'Complete order history' : "Today's orders" ?>">
    <?php if (!empty($orderCards)): ?>
      <?php foreach ($orderCards as $order): ?>
        <?php
          $isPending = $order['status'] === 'pending';
          $isCompleted = $order['status'] === 'completed';
          $isVoided = in_array($order['status'], ['void', 'voided', 'cancelled'], true);
          $hasInventoryBlock = !empty($order['warnings']['blocking']);
          $isPaid = $order['remaining'] <= 0.009;
          $canComplete = $isPending && !$hasInventoryBlock && $isPaid && !empty($order['warnings']['requirements']);
          $statusClass = $order['status'] === 'voided' ? 'void' : $order['status'];
        ?>
        <article class="order-card" data-order-id="<?= (int) $order['id'] ?>" data-order-code="<?= htmlspecialchars($order['code']) ?>">
          <div class="order-card-header">
            <div>
              <span class="order-claim-number">Order No. <?= htmlspecialchars($order['code']) ?></span>
              <span class="order-code">Transaction Number: <?= htmlspecialchars(order_transaction_number((int) $order['id'], $order['transaction_no'])) ?></span>
              <?php if ($order['sales_invoice_no']): ?><span class="order-code">Invoice: <?= htmlspecialchars($order['sales_invoice_no']) ?></span><?php endif; ?>
              <h2><?= htmlspecialchars(order_customer_display($order['customer'], $order['service_type'])) ?></h2>
              <?php if (order_customer_display($order['customer'], $order['service_type']) !== order_service_type_label($order['service_type'])): ?><span class="order-type-badge"><?= htmlspecialchars(order_service_type_label($order['service_type'])) ?></span><?php endif; ?>
            </div>
            <span class="order-status status-<?= htmlspecialchars($statusClass) ?>" title="<?= htmlspecialchars(order_list_status_help($order['status'])) ?>">
              <?= htmlspecialchars(order_list_status_label($order['status'])) ?>
            </span>
          </div>

          <dl class="order-details">
            <div>
              <dt>Items</dt>
              <dd><?= htmlspecialchars($order['items_text']) ?></dd>
            </div>
            <div>
              <dt>Cup Sizes</dt>
              <dd><?= htmlspecialchars($order['cup_sizes']) ?></dd>
            </div>
            <div>
              <dt>Total Qty</dt>
              <dd><?= number_format((int) $order['qty']) ?></dd>
            </div>
            <div>
              <dt>Total</dt>
              <dd><?= htmlspecialchars(order_list_money($order['total'])) ?></dd>
            </div>
            <div>
              <dt>Amount Paid</dt>
              <dd><?= htmlspecialchars(order_list_money($order['paid'])) ?></dd>
            </div>
            <div><dt>Payment</dt><dd><?= $isPaid ? 'Paid' : ($order['paid'] > 0 ? 'Partially Paid' : 'Unpaid') ?></dd></div>
            <div>
              <dt>Order Date</dt>
              <dd><?= htmlspecialchars(date('Y-m-d h:i A', strtotime($order['created_at']))) ?></dd>
            </div>
            <div>
              <dt>Generated By</dt>
              <dd><?= htmlspecialchars($order['generated_by']) ?></dd>
            </div>
            <div>
              <dt>Preparation</dt>
              <dd><?= htmlspecialchars(order_preparation_label($order['job_status'])) ?></dd>
            </div>
            <?php if ($order['customer_contact'] !== '' || $order['customer_email'] !== ''): ?>
            <div>
              <dt>Customer Contact</dt>
              <dd><?= htmlspecialchars(trim($order['customer_contact'] . ' ' . $order['customer_email'])) ?></dd>
            </div>
            <?php endif; ?>
            <?php if ($order['service_details'] !== ''): ?>
            <div>
              <dt>Service Details</dt>
              <dd><?= nl2br(htmlspecialchars($order['service_details'])) ?></dd>
            </div>
            <?php endif; ?>
          </dl>

          <?php if ($isPending && !$isPaid): ?>
            <div class="order-card-note warning">
              Remaining balance: <strong><?= htmlspecialchars(order_list_money($order['remaining'])) ?></strong>
            </div>
          <?php endif; ?>

          <?php if ($isPending && !empty($order['warnings']['blocking'])): ?>
            <div class="order-card-note error">
              Cannot complete: <?= htmlspecialchars(implode(', ', $order['warnings']['blocking'])) ?>
            </div>
          <?php elseif ($isPending && !empty($order['warnings']['low'])): ?>
            <div class="order-card-note warning">
              Low stock: <?= htmlspecialchars(implode(', ', $order['warnings']['low'])) ?>
            </div>
          <?php endif; ?>

          <?php if ($isPending && !empty($order['warnings']['requirements'])): ?>
            <div class="order-card-note neutral">
              <strong>Materials used when marked Done:</strong>
              <?= htmlspecialchars(implode('; ', array_map(static function ($material) {
                  return $material['name'] . (!empty($material['cup_size']) ? ' (' . $material['cup_size'] . ')' : '') . ' - ' . inventory_service_format_quantity((float) $material['quantity']) . ' ' . $material['unit'];
              }, $order['warnings']['requirements']))) ?>
            </div>
          <?php endif; ?>
          <?php if ($isPending && $order['warnings']['has_hot_drink']): ?>
            <div class="order-card-note neutral">Hot drinks use Coffee Stirrer only.</div>
          <?php elseif ($isPending && $order['warnings']['has_cold_drink']): ?>
            <div class="order-card-note neutral">Cold drinks deduct one straw per beverage.</div>
          <?php endif; ?>

          <div class="order-actions">
            <?php if ($isPending && $canProcessTransactions): ?>
              <form method="post" action="order_job_status.php" class="order-action-form">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <select name="job_status" aria-label="Job status">
                  <?php foreach (['queued' => 'Queued', 'in_progress' => 'In Progress', 'ready' => 'Ready'] as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $order['job_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="order-action-btn edit"><i class="fa fa-clipboard-check"></i> Update Job</button>
              </form>
              <?php if (!$isPaid): ?>
                <a class="order-action-btn payment" href="order_payment.php?order_id=<?= (int) $order['id'] ?>">
                  <i class="fa fa-credit-card"></i> Settle Payment
                </a>
              <?php endif; ?>
              <a class="order-action-btn edit" href="orders.php?order_id=<?= (int) $order['id'] ?>&customer_name=<?= urlencode($order['customer']) ?>">
                <i class="fa fa-pen-to-square"></i> Edit / Add Items
              </a>
              <form method="post" action="order_done.php" class="order-action-form">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <button type="submit" class="order-action-btn done" <?= $canComplete ? '' : 'disabled title="Complete payment and required inventory first."' ?>>
                  <i class="fa fa-check"></i> Mark as Done
                </button>
              </form>
              <button type="button" class="order-action-btn void" onclick="handleVoidOrder(<?= (int) $order['id'] ?>)">
                <i class="fa fa-ban"></i> Void / Cancel
              </button>
            <?php elseif ($isCompleted): ?>
              <a class="order-action-btn print" href="receipt_template.php?order_id=<?= (int) $order['id'] ?>&copy=company">
                <i class="fa fa-print"></i> Reprint Receipt
              </a>
            <?php elseif ($isVoided): ?>
              <div class="order-card-note neutral full">This order is recorded but does not affect inventory deduction.</div>
            <?php else: ?>
              <div class="order-card-note neutral full">Read-only transaction record.</div>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="order-list-empty">
        <i class="fa fa-receipt"></i>
        <strong>No orders found.</strong>
        <span><?= $search !== '' ? 'Try another search term.' : ($historyScope ? 'No orders recorded for this period.' : 'New orders will appear here today.') ?></span>
      </div>
    <?php endif; ?>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<div id="adminPermissionModal" class="order-list-modal" aria-hidden="true">
  <div class="order-list-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminPermTitle">
    <img src="img/CUP_BLACK.png" alt="Kape Agosto" class="order-modal-logo">
    <h2 id="adminPermTitle">Admin Permission Required</h2>
    <p>Only an Admin can void or cancel orders.</p>
    <p class="modal-order-ref">Order ID: <strong id="adminPermOrderId"></strong></p>
    <div class="order-modal-actions">
      <button type="button" id="proceedAdminLoginBtn" class="order-modal-primary">Proceed to Admin Login</button>
      <button type="button" onclick="closeAdminPermissionModal()" class="order-modal-secondary">Cancel</button>
    </div>
  </div>
</div>

<div id="adminLoginModal" class="order-list-modal" aria-hidden="true">
  <div class="order-list-modal-card" role="dialog" aria-modal="true" aria-labelledby="adminLoginTitle">
    <img src="img/CUP_BLACK.png" alt="Kape Agosto" class="order-modal-logo">
    <h2 id="adminLoginTitle">Admin Login Required</h2>
    <p>Please sign in as an Admin to continue.</p>
    <form id="adminLoginForm" method="post" autocomplete="off" class="order-modal-form">
      <label for="admin_username">Username</label>
      <input id="admin_username" type="text" name="admin_username" placeholder="Admin Username" required>
      <label for="admin_password">Password</label>
      <input id="admin_password" type="password" name="admin_password" placeholder="Admin Password" required>
      <input type="hidden" name="void_order_id" id="voidOrderIdInput">
      <button type="submit" id="adminLoginBtn" class="order-modal-primary">Login</button>
    </form>
    <button type="button" onclick="closeAdminLoginModal()" class="order-modal-secondary">Cancel</button>
    <div id="adminLoginError" class="order-modal-error"></div>
  </div>
</div>

<form method="post" action="order_void.php" id="voidOrderFormUnified" data-no-confirm hidden>
  <input type="hidden" name="order_id" id="voidOrderIdHidden" value="">
  <input type="hidden" name="confirm_void" value="1">
</form>

<script>
const orderPeriodSelect = document.getElementById('order-period');
function syncOrderPeriodInputs() {
  document.querySelectorAll('[data-order-period]').forEach(label => {
    const selected = label.dataset.orderPeriod === orderPeriodSelect?.value;
    label.hidden = !selected;
    label.querySelector('input').disabled = !selected;
  });
}
orderPeriodSelect?.addEventListener('change', syncOrderPeriodInputs);
syncOrderPeriodInputs();
const orderFilterForm = document.querySelector('.order-list-search');
let orderFilterTimer;
let orderFilterRequest;
async function applyOrderFilters() {
  if (!orderFilterForm.reportValidity()) return;
  orderFilterRequest?.abort();
  const controller = new AbortController();
  orderFilterRequest = controller;
  const url = new URL(window.location.href);
  url.search = new URLSearchParams(new FormData(orderFilterForm)).toString();
  const status = document.getElementById('order-live-message');
  status.textContent = 'Filtering orders...';
  try {
    const response = await fetch(url, { signal: controller.signal, cache: 'no-store' });
    if (!response.ok) throw new Error('Filter failed');
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    if (controller.signal.aborted) return;
    for (const selector of ['#orders-grid', '#order-list-summary', '#order-list-title', '.order-list-copy']) {
      const current = document.querySelector(selector);
      const next = page.querySelector(selector);
      if (current && next) current.innerHTML = next.innerHTML;
    }
    // Keep navigation and the live updater on the selected period.
    document.querySelectorAll('.order-list-toolbar > .order-list-create').forEach((link, index) => {
      const next = page.querySelectorAll('.order-list-toolbar > .order-list-create')[index];
      if (next) { link.href = next.href; link.innerHTML = next.innerHTML; }
    });
    history.replaceState(null, '', url);
    status.textContent = 'Live updates on';
  } catch (error) {
    if (error.name !== 'AbortError') status.textContent = 'Unable to filter. Please try again.';
  }
}
function scheduleOrderFilters() {
  clearTimeout(orderFilterTimer);
  orderFilterTimer = setTimeout(applyOrderFilters, 350);
}
orderFilterForm?.addEventListener('submit', event => { event.preventDefault(); clearTimeout(orderFilterTimer); applyOrderFilters(); });
document.getElementById('order-search')?.addEventListener('input', scheduleOrderFilters);
document.getElementById('order-service-filter')?.addEventListener('change', () => { clearTimeout(orderFilterTimer); applyOrderFilters(); });
orderPeriodSelect?.addEventListener('change', () => { clearTimeout(orderFilterTimer); applyOrderFilters(); });
document.querySelectorAll('[data-order-period] input').forEach(input => input.addEventListener('change', scheduleOrderFilters));
function setOrderModal(id, open) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.toggle('is-open', open);
  modal.setAttribute('aria-hidden', open ? 'false' : 'true');
}

function openAdminPermissionModal(orderId) {
  document.getElementById('adminPermOrderId').textContent = orderId;
  setOrderModal('adminPermissionModal', true);
}

function closeAdminPermissionModal() {
  setOrderModal('adminPermissionModal', false);
}

function openAdminLoginModal(orderId) {
  closeAdminPermissionModal();
  document.getElementById('voidOrderIdInput').value = orderId;
  setOrderModal('adminLoginModal', true);
  document.getElementById('admin_username')?.focus();
}

function closeAdminLoginModal() {
  setOrderModal('adminLoginModal', false);
  const error = document.getElementById('adminLoginError');
  if (error) {
    error.textContent = '';
    error.classList.remove('is-visible');
  }
}

async function openConfirmVoidModal(orderId) {
  const confirmed = await window.kapeConfirm({
    title: 'Void Order',
    message: 'Void or cancel Order #' + orderId + '? This action cannot be undone.',
    type: 'danger',
    okLabel: 'Void Order'
  });
  if (!confirmed) return;

  document.getElementById('voidOrderIdHidden').value = orderId;
  document.getElementById('voidOrderFormUnified').submit();
}

function handleVoidOrder(orderId) {
  const userRole = "<?= htmlspecialchars($userRole, ENT_QUOTES) ?>";
  if (userRole === 'admin' || userRole === 'business_admin') {
    openConfirmVoidModal(orderId);
  } else {
    openAdminPermissionModal(orderId);
  }
}

document.getElementById('proceedAdminLoginBtn')?.addEventListener('click', function() {
  openAdminLoginModal(document.getElementById('adminPermOrderId').textContent);
});

document.querySelectorAll('.order-list-modal').forEach(function(modal) {
  modal.addEventListener('click', function(event) {
    if (event.target === modal) {
      setOrderModal(modal.id, false);
    }
  });
});

document.getElementById('adminLoginForm')?.addEventListener('submit', function(event) {
  event.preventDefault();
  const form = event.target;
  const error = document.getElementById('adminLoginError');
  if (error) {
    error.textContent = '';
    error.classList.remove('is-visible');
  }

  fetch('order_list_admin_login.php', {
    method: 'POST',
    body: new FormData(form)
  })
    .then(function(response) {
      return response.json().then(function(data) {
        if (!response.ok) throw data;
        return data;
      });
    })
    .then(function(result) {
      if (result.success) {
        closeAdminLoginModal();
        openConfirmVoidModal(form.void_order_id.value);
      } else {
        throw result;
      }
    })
    .catch(function(result) {
      if (!error) return;
      error.textContent = result.error || 'Admin login failed. Please try again.';
      error.classList.add('is-visible');
    });
});

// Poll a compact order/payment signature, and reload card markup only after a change.
(function() {
  const liveMessage = document.getElementById('order-live-message');
  const currentGrid = document.getElementById('orders-grid');
  const currentSummary = document.getElementById('order-list-summary');
  const currentPage = document.querySelector('main.order-list-page');
  if (!liveMessage || !currentGrid || !currentSummary || !currentPage) return;

  let knownVersion = currentPage.dataset.liveVersion || '';
  let refreshing = false;
  let checking = false;
  const shouldPause = function() {
    return document.hidden ||
      document.querySelector('.order-list-modal.is-open') !== null ||
      (document.activeElement && document.activeElement.closest('.orders-grid'));
  };

  async function refreshOrders() {
    if (refreshing || shouldPause()) return;
    refreshing = true;
    const finishLoading = window.KapeLoading?.start({ label: 'Updating orders...' });
    try {
      const refreshUrl = new URL(window.location.href);
      refreshUrl.searchParams.delete('_changes');
      refreshUrl.searchParams.set('_live_refresh', String(Date.now()));
      const response = await fetch(refreshUrl.toString(), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok) throw new Error('Refresh failed');
      const html = await response.text();
      const refreshedPage = new DOMParser().parseFromString(html, 'text/html');
      const nextGrid = refreshedPage.getElementById('orders-grid');
      const nextSummary = refreshedPage.getElementById('order-list-summary');
      const nextPage = refreshedPage.querySelector('main.order-list-page');
      if (!nextGrid || !nextSummary || !nextPage) throw new Error('Unexpected response');

      if (currentGrid.innerHTML !== nextGrid.innerHTML) currentGrid.innerHTML = nextGrid.innerHTML;
      if (currentSummary.innerHTML !== nextSummary.innerHTML) currentSummary.innerHTML = nextSummary.innerHTML;
      knownVersion = nextPage.dataset.liveVersion || knownVersion;
      currentPage.dataset.liveVersion = knownVersion;
      liveMessage.textContent = 'Updated ' + new Intl.DateTimeFormat([], { hour: 'numeric', minute: '2-digit' }).format(new Date());
    } catch (error) {
      liveMessage.textContent = 'Live refresh unavailable';
    } finally {
      finishLoading?.();
      refreshing = false;
    }
  }

  async function checkForChanges() {
    if (refreshing || checking || shouldPause()) return;
    checking = true;
    try {
      const checkUrl = new URL(window.location.href);
      checkUrl.searchParams.delete('_live_refresh');
      checkUrl.searchParams.set('_changes', '1');
      checkUrl.searchParams.set('_check', String(Date.now()));
      const response = await fetch(checkUrl.toString(), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!response.ok) throw new Error('Change check failed');
      const result = await response.json();
      if (result.version && result.version !== knownVersion) await refreshOrders();
    } catch (error) {
      liveMessage.textContent = 'Live refresh unavailable';
    } finally {
      checking = false;
    }
  }
  window.setInterval(checkForChanges, <?= $historyScope ? 5000 : 2000 ?>);
})();
<?php if ($autoVoidOrderId > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
  openConfirmVoidModal(<?= (int) $autoVoidOrderId ?>);
});
<?php endif; ?>
</script>
</body>
</html>
