<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_capability('view_order_history');

$pdo = db();
$userRole = current_user_role();
$isAdmin = is_admin();
$canProcessTransactions = can('process_transactions');
$historyScope = (($_GET['scope'] ?? '') === 'all') && (is_admin() || is_manager());
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
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%cup%' AND cup_size = ?");
        $stmt->execute([$size]);
        if ((int) $stmt->fetchColumn() === 0) {
            $missingCupSizes[] = $size;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%' OR name LIKE '%lid%') AND cup_size = ?");
        $stmt->execute([$size]);
        if ((int) $stmt->fetchColumn() === 0) {
            $missingLidSizes[] = $size;
        }
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%straw%'");
    $stmt->execute();
    $missingStraw = ((int) $stmt->fetchColumn() === 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%stirrer%'");
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
        SELECT i.name, i.cup_size, oi.qty, oi.price
        FROM order_items oi
        JOIN items i ON i.id = oi.item_id
        WHERE oi.order_id = ?
        ORDER BY oi.id ASC
    ');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function order_list_inventory_warnings(PDO $pdo, array $items): array {
    $blocking = [];
    $low = [];
    $hasHotDrink = false;
    $hasColdDrink = false;
    $coldDrinkQty = 0;

    foreach ($items as $item) {
        $cupSize = trim((string) ($item['cup_size'] ?? ''));
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $isHot = $cupSize === '12oz Hot' || stripos((string) $item['name'], 'hot') !== false;

        if ($isHot) {
            $hasHotDrink = true;
        } elseif ($cupSize !== '' && $cupSize !== '-') {
            $hasColdDrink = true;
            $coldDrinkQty += $qty;
        }

        $checks = [
            ['label' => 'Cup (' . ($cupSize ?: '-') . ')', 'sql' => "SELECT qty, critical_level FROM inventory WHERE name LIKE '%cup%' AND cup_size = ? LIMIT 1", 'params' => [$cupSize]],
            ['label' => 'Flat Lid (' . ($cupSize ?: '-') . ')', 'sql' => "SELECT qty, critical_level FROM inventory WHERE (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%' OR name LIKE '%lid%') AND cup_size = ? LIMIT 1", 'params' => [$cupSize]],
        ];

        if ($isHot) {
            $checks[] = ['label' => 'Coffee Stirrer', 'sql' => "SELECT qty, critical_level FROM inventory WHERE name LIKE '%stirrer%' LIMIT 1", 'params' => []];
        }

        foreach ($checks as $check) {
            $stmt = $pdo->prepare($check['sql']);
            $stmt->execute($check['params']);
            $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inventory) {
                $blocking[] = $check['label'] . ' missing';
                continue;
            }

            if ((float) $inventory['qty'] < $qty) {
                $blocking[] = $check['label'] . ' out of stock';
                continue;
            }

            if ($inventory['critical_level'] !== null && (float) $inventory['critical_level'] > 0 && (float) $inventory['qty'] <= (float) $inventory['critical_level']) {
                $low[] = $check['label'] . ' low';
            }
        }
    }

    if ($hasColdDrink) {
        $stmt = $pdo->prepare("SELECT qty, critical_level FROM inventory WHERE name LIKE '%straw%' LIMIT 1");
        $stmt->execute();
        $straw = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$straw) {
            $blocking[] = 'Straw missing';
        } elseif ((float) $straw['qty'] < $coldDrinkQty) {
            $blocking[] = 'Straw out of stock';
        } elseif ($straw['critical_level'] !== null && (float) $straw['critical_level'] > 0 && (float) $straw['qty'] <= (float) $straw['critical_level']) {
            $low[] = 'Straw low';
        }
    }

    return [
        'blocking' => array_values(array_unique($blocking)),
        'low' => array_values(array_unique($low)),
        'has_hot_drink' => $hasHotDrink,
        'has_cold_drink' => $hasColdDrink,
    ];
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

$inventoryStatus = order_list_get_inventory_status($pdo);
$search = trim($_GET['search'] ?? '');
$today = date('Y-m-d');
$params = [];

$query = "
    SELECT o.id, o.order_code, o.customer_name AS customer, o.customer_id, o.service_details,
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
if (!$historyScope) {
    $query .= ' AND DATE(o.created_at) = ?';
    $params[] = $today;
}

if ($search !== '') {
    $query .= " AND (o.customer_name LIKE ? OR c.customer_code LIKE ? OR c.contact LIKE ? OR c.email LIKE ? OR o.service_details LIKE ? OR CAST(o.id AS CHAR) LIKE ? OR o.order_code LIKE ? OR DATE_FORMAT(o.created_at, '%Y-%m-%d') LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    for ($i = 0; $i < 10; $i++) $params[] = '%' . $search . '%';
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

<main class="content order-list-page">
  <section class="order-list-heading" aria-labelledby="order-list-title">
    <div>
      <p class="order-list-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Order List</p>
      <h1 id="order-list-title"><?= $historyScope ? 'Complete Order History' : 'Order List' ?></h1>
      <p class="order-list-copy"><?= $historyScope ? 'Review retained customer, job, payment, and transaction history across all dates.' : ($canProcessTransactions ? "Review today's orders, settle payments, complete paid carts, and manage void requests." : 'Review order history and transaction performance in read-only mode.') ?></p>
    </div>
    <div class="order-list-summary" aria-label="Order list summary">
      <span><strong><?= number_format(count($orderCards)) ?></strong> <?= $historyScope ? 'All Records' : 'Today' ?></span>
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
      <label for="order-search">Search Orders</label>
      <input id="order-search" type="search" name="search" placeholder="Search by order, customer, or date" value="<?= htmlspecialchars($search) ?>">
      <button type="submit"><i class="fa fa-magnifying-glass"></i> Search</button>
      <?php if ($search !== ''): ?><a href="order_list.php<?= $historyScope ? '?scope=all' : '' ?>">Reset</a><?php endif; ?>
    </form>
    <?php if (is_admin() || is_manager()): ?>
      <a class="order-list-create" href="<?= $historyScope ? 'order_list.php' : 'order_list.php?scope=all' ?>"><i class="fa fa-clock-rotate-left"></i> <?= $historyScope ? "Today's Orders" : 'Full History' ?></a>
      <a class="order-list-create" href="customers.php"><i class="fa fa-users"></i> Customers</a>
    <?php endif; ?>
    <?php if ($canProcessTransactions): ?><a class="order-list-create" href="orders.php"><i class="fa fa-cash-register"></i> Manage Order</a><?php endif; ?>
  </section>

  <section class="orders-grid" aria-label="<?= $historyScope ? 'Complete order history' : "Today's orders" ?>">
    <?php if (!empty($orderCards)): ?>
      <?php foreach ($orderCards as $order): ?>
        <?php
          $isPending = $order['status'] === 'pending';
          $isCompleted = $order['status'] === 'completed';
          $isVoided = in_array($order['status'], ['void', 'voided', 'cancelled'], true);
          $hasInventoryBlock = !empty($order['warnings']['blocking']);
          $isPaid = $order['remaining'] <= 0.009;
          $canComplete = $isPending && !$hasInventoryBlock && $isPaid;
          $statusClass = $order['status'] === 'voided' ? 'void' : $order['status'];
        ?>
        <article class="order-card" data-order-id="<?= (int) $order['id'] ?>" data-order-code="<?= htmlspecialchars($order['code']) ?>">
          <div class="order-card-header">
            <div>
              <span class="order-code">Order ID: #<?= htmlspecialchars($order['code']) ?></span>
              <h2><?= htmlspecialchars($order['customer']) ?></h2>
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
              <dt>Paid</dt>
              <dd><?= htmlspecialchars(order_list_money($order['paid'])) ?></dd>
            </div>
            <div>
              <dt>Order Date</dt>
              <dd><?= htmlspecialchars(date('Y-m-d h:i A', strtotime($order['created_at']))) ?></dd>
            </div>
            <div>
              <dt>Generated By</dt>
              <dd><?= htmlspecialchars($order['generated_by']) ?></dd>
            </div>
            <div>
              <dt>Job Status</dt>
              <dd><?= htmlspecialchars(ucwords(str_replace('_', ' ', $order['job_status']))) ?></dd>
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
        <span><?= $search !== '' ? 'Try another search term.' : 'New orders will appear here today.' ?></span>
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

<?php if ($autoVoidOrderId > 0): ?>
document.addEventListener('DOMContentLoaded', function() {
  openConfirmVoidModal(<?= (int) $autoVoidOrderId ?>);
});
<?php endif; ?>
</script>
</body>
</html>
