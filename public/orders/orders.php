<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/daily_tasks.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_once dirname(__DIR__, 2) . '/includes/order_discounts.php';
require_once dirname(__DIR__, 2) . '/includes/customer_service.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_once dirname(__DIR__, 2) . '/includes/item_variant.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_capability('process_transactions');

$pdo = db();
payment_ensure_schema($pdo);
$checkoutSettings = settings_all($pdo);
$checkoutTaxType = settings_value($checkoutSettings, 'bir_tax_type');
$configuredPromoPercent = max(0, min(100, (float) settings_value($checkoutSettings, 'promo_discount_percent')));
$promoIsActive = settings_promo_is_active($checkoutSettings);
$activePromoPercent = $promoIsActive ? $configuredPromoPercent : 0.0;

function money_order($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
}

function order_display_cup_size(string $cupSize): string {
    return [
        '16oz Cold' => '16oz',
        '22oz Cold' => '22oz',
    ][$cupSize] ?? $cupSize;
}

function order_item_image_src(?string $image): string {
    if (!empty($image)) {
        $fileName = basename($image);
        $absolutePath = dirname(__DIR__) . '/uploads/' . $fileName;
        if (is_file($absolutePath)) {
            return 'uploads/' . rawurlencode($fileName);
        }
    }

    return 'img/CUP_BLACK.png';
}

function get_inventory_status(PDO $pdo): array {
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

    $lowStockItems = [];
    $invStmt = $pdo->query('SELECT name, cup_size, qty, unit, critical_level FROM inventory ORDER BY name ASC, cup_size ASC');
    foreach ($invStmt as $inv) {
        if ($inv['critical_level'] !== null && (float) $inv['critical_level'] > 0 && (float) $inv['qty'] <= (float) $inv['critical_level']) {
            $lowStockItems[] = $inv;
        }
    }

    $inventoryBlocked = $inventoryCount === 0;

    return [
        'inventoryCount' => $inventoryCount,
        'missing_cup_sizes' => $missingCupSizes,
        'missing_lid_sizes' => $missingLidSizes,
        'missing_straw' => $missingStraw,
        'missing_stirrer' => $missingStirrer,
        'low_stock_items' => $lowStockItems,
        'inventory_blocked' => $inventoryBlocked,
    ];
}

function checkout_line_items(PDO $pdo, array $postedItems): array {
    $lines = [];

    foreach ($postedItems as $item) {
        $itemId = (int) ($item['id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $stmt = $pdo->prepare("SELECT id, name, cup_size, price FROM items WHERE id = ? AND cup_size IN ('12oz Hot', '16oz', '22oz', '16oz Cold', '22oz Cold') LIMIT 1");
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            continue;
        }

        $lines[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'cup_size' => order_display_cup_size((string) $row['cup_size']),
            'qty' => $qty,
            // The catalog is authoritative. Never trust a browser-posted price.
            'price' => (float) $row['price'],
        ];
    }

    return $lines;
}

$status = get_inventory_status($pdo);
$inventoryCount = $status['inventoryCount'];
$missingCupSizes = $status['missing_cup_sizes'];
$missingLidSizes = $status['missing_lid_sizes'];
$missingStraw = $status['missing_straw'];
$missingStirrer = $status['missing_stirrer'];
$lowStockItems = $status['low_stock_items'];
$inventoryBlocked = $status['inventory_blocked'];

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$selectedCategoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
$search = trim($_GET['search'] ?? '');
$customerName = trim($_GET['customer_name'] ?? '');
$serviceType = '';
$editingOrderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$sourceOrderIdsInput = (string) ($_GET['source_order_ids'] ?? ($_GET['source_order_id'] ?? ''));
$changeCreditSourceOrderIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $sourceOrderIdsInput)), static fn(int $id): bool => $id > 0)));
$availableChangeCredit = 0.0;
foreach ($changeCreditSourceOrderIds as $sourceOrderId) {
    $availableChangeCredit += payment_change_credit_available($pdo, $sourceOrderId);
}
$preloadItems = [];
$preloadDiscount = ['promo_percent' => 0, 'beneficiary_type' => '', 'customer_name' => '', 'tin' => '', 'id_number' => '', 'exclusive_use' => false];
$editNotice = '';
$checkoutError = (!empty($changeCreditSourceOrderIds) && $availableChangeCredit <= 0)
    ? 'No unused change is available from the original order. Please start a regular new order or recheck the balance.'
    : '';

if ($editingOrderId > 0) {
    $stmt = $pdo->prepare('SELECT o.* FROM orders o WHERE o.id = ? LIMIT 1');
    $stmt->execute([$editingOrderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($order && $order['status'] === 'pending') {
        $stmt = $pdo->prepare('
            SELECT oi.qty, oi.price, i.name, i.cup_size, i.id AS item_id
            FROM order_items oi
            JOIN items i ON oi.item_id = i.id
            WHERE oi.order_id = ?
            ORDER BY oi.id ASC
        ');
        $stmt->execute([$editingOrderId]);
        $preloadItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $customerName = $customerName !== '' ? $customerName : (string) ($order['customer_name'] ?? '');
        $serviceType = (string) ($order['service_type'] ?? '');
        $preloadDiscount = [
            'promo_percent' => (float) ($order['promo_percent'] ?? 0),
            'beneficiary_type' => (string) ($order['discount_beneficiary_type'] ?? ''),
            'customer_name' => (string) ($order['discount_customer_name'] ?? ''),
            'tin' => (string) ($order['discount_customer_tin'] ?? ''),
            'id_number' => (string) ($order['discount_id_number'] ?? ''),
            'exclusive_use' => !empty($order['exclusive_use_confirmed']),
        ];
        $editNotice = 'Editing pending order #' . $editingOrderId . '. Checkout will update the existing order.';
    } else {
        $editingOrderId = 0;
        $checkoutError = 'Only pending orders can be edited.';
    }
}

$items = $pdo->query('
    SELECT i.id, i.category_id, i.name, i.price, i.cup_size, i.image,
           c.name AS category_name,
           COALESCE(sales.units_sold_30, 0) AS units_sold_30
    FROM items i
    JOIN categories c ON c.id = i.category_id
    LEFT JOIN (
        SELECT oi.item_id, SUM(oi.qty) AS units_sold_30
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.status = \'completed\'
          AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY oi.item_id
    ) sales ON sales.item_id = i.id
    WHERE i.cup_size IN (\'12oz Hot\', \'16oz Cold\', \'22oz Cold\', \'16oz\', \'22oz\')
    ORDER BY units_sold_30 DESC, c.name ASC, i.name ASC, i.cup_size ASC
')->fetchAll(PDO::FETCH_ASSOC);

$bestSellerUnits = 0;
foreach ($items as $catalogItem) {
    $bestSellerUnits = max($bestSellerUnits, (int) ($catalogItem['units_sold_30'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $ajaxCheckout = ($_POST['checkout_response'] ?? '') === 'json';
    $customerNamePost = normalize_uppercase_name((string)($_POST['customer_name'] ?? ''));
    $serviceType = (string) ($_POST['service_type'] ?? '');
    $customerNamePost = $customerNamePost !== '' ? $customerNamePost : 'Walk-in';
    $editingId = (int) ($_POST['editing_order_id'] ?? 0);
    $applyConfiguredPromo = ($_POST['apply_configured_promo'] ?? '') === '1';
    $promoPercentInput = $applyConfiguredPromo ? (string) $activePromoPercent : '0';
    if (!$applyConfiguredPromo && $editingId > 0 && $activePromoPercent <= 0) {
        $savedPromoStmt = $pdo->prepare('SELECT promo_percent FROM orders WHERE id = ? LIMIT 1');
        $savedPromoStmt->execute([$editingId]);
        $savedPromoPercent = $savedPromoStmt->fetchColumn();
        if ($savedPromoPercent !== false && is_numeric($savedPromoPercent)) {
            $promoPercentInput = (string) max(0, min(100, (float) $savedPromoPercent));
        }
    }
    $beneficiaryType = is_string($_POST['discount_beneficiary_type'] ?? null) ? trim($_POST['discount_beneficiary_type']) : '';
    $discountCustomerName = normalize_uppercase_name((string) ($_POST['discount_customer_name'] ?? ''));
    $discountCustomerTin = trim((string) ($_POST['discount_customer_tin'] ?? ''));
    $discountIdNumber = trim((string) ($_POST['discount_id_number'] ?? ''));
    $exclusiveUseConfirmed = ($_POST['exclusive_use_confirmed'] ?? '') === '1';
    $sourceOrderIdsInput = (string) ($_POST['change_credit_source_order_ids'] ?? '');
    $changeCreditSourceOrderIds = array_values(array_unique(array_filter(array_map('intval', explode(',', $sourceOrderIdsInput)), static fn(int $id): bool => $id > 0)));
    $orderLines = checkout_line_items($pdo, $_POST['items'] ?? []);

    if (!is_numeric($promoPercentInput) || !is_finite((float) $promoPercentInput) || (float) $promoPercentInput < 0 || (float) $promoPercentInput > 100) {
        $checkoutError = 'Promo discount must be a percentage from 0 to 100.';
    } elseif (!in_array($beneficiaryType, ['', 'senior', 'pwd'], true)) {
        $checkoutError = 'Choose a valid Senior Citizen or PWD discount type.';
    } elseif ($beneficiaryType !== '' && ($discountCustomerName === '' || $discountIdNumber === '' || !$exclusiveUseConfirmed)) {
        $checkoutError = 'For a Senior/PWD discount, enter the beneficiary name and ID, and confirm the items are for their exclusive use.';
    } elseif (strlen($discountIdNumber) > 64 || strlen($discountCustomerName) > 100 || strlen($discountCustomerTin) > 32) {
        $checkoutError = 'The Senior/PWD name or ID is too long.';
    } elseif (!in_array($serviceType, ['dine_in', 'take_out'], true)) {
        $checkoutError = 'Please select Dine-in or Take-out.';
    } elseif ($editingId > 0 && !empty($changeCreditSourceOrderIds)) {
        $checkoutError = 'Change credit can only be applied to a new order.';
    } elseif (empty($orderLines)) {
        $checkoutError = 'Please add at least one item before checkout.';
    } elseif ($inventoryBlocked) {
        $checkoutError = 'Cannot checkout yet. Please complete the required inventory items first.';
    } else {
        $warnings = inventory_service_stock_warnings($pdo, $orderLines);
        $insufficient = $warnings['blocking'];
        if (!empty($insufficient)) {
            $checkoutError = 'Cannot checkout. Required materials: ' . implode(', ', array_unique($insufficient));
        } else {
            $subtotal = 0;
            foreach ($orderLines as $line) {
                $subtotal += ((float) $line['price'] * (int) $line['qty']);
            }
            $discount = order_discount_calculate($subtotal, (float) $promoPercentInput, $beneficiaryType, $checkoutTaxType);
            $total = $discount['total'];

            $pdo->beginTransaction();

            try {
                $customerId = customer_find_or_create($pdo, $customerNamePost);
                if ($editingId > 0) {
                    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1 FOR UPDATE");
                    $stmt->execute([$editingId]);
                    if ($stmt->fetchColumn() !== 'pending') {
                        throw new RuntimeException('Only pending orders can be edited.');
                    }

                    $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$editingId]);
                    $orderId = $editingId;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO orders (user_id, customer_id, customer_name, job_status, total, status, created_at) VALUES (?, ?, ?, 'queued', 0, 'pending', NOW())");
                    $stmt->execute([$_SESSION['user']['id'] ?? null, $customerId, $customerNamePost]);
                    $orderId = (int) $pdo->lastInsertId();
                    ensure_order_code($pdo, $orderId);
                }

                $stmtItem = $pdo->prepare('INSERT INTO order_items (order_id, item_id, qty, price) VALUES (?, ?, ?, ?)');
                foreach ($orderLines as $line) {
                    $stmtItem->execute([$orderId, $line['id'], $line['qty'], $line['price']]);
                }

                $stmt = $pdo->prepare("UPDATE orders SET customer_id = ?, customer_name = ?, service_type = ?, total = ?, subtotal = ?, discount_type = ?, discount_rate = ?, promo_percent = ?, discount_amount = ?, vat_exempt_amount = ?, discount_beneficiary_type = ?, discount_customer_name = ?, discount_customer_tin = ?, discount_id_number = ?, discount_applied_by = ?, exclusive_use_confirmed = ?, status = 'pending' WHERE id = ?");
                $stmt->execute([$customerId, $customerNamePost, $serviceType, $total, $discount['subtotal'], $discount['discount_type'], $discount['discount_rate'], $discount['promo_percent'], $discount['discount_amount'], $discount['vat_exempt_amount'], $beneficiaryType !== '' ? $beneficiaryType : null, $beneficiaryType !== '' ? $discountCustomerName : null, $beneficiaryType !== '' ? $discountCustomerTin : null, $beneficiaryType !== '' ? $discountIdNumber : null, $discount['discount_type'] !== 'none' ? ($_SESSION['user']['id'] ?? null) : null, $beneficiaryType !== '' && $exclusiveUseConfirmed ? 1 : 0, $orderId]);

                if ($editingId > 0 && $total + 0.009 < payment_total_paid($pdo, $orderId)) {
                    throw new RuntimeException('The updated order total cannot be lower than payments already applied.');
                }

                if ($editingId > 0) {
                    payment_apply_pending_order_change_credit($pdo, $orderId);
                } elseif (!empty($changeCreditSourceOrderIds)) {
                    payment_apply_change_credit($pdo, $changeCreditSourceOrderIds, $orderId, $total);
                }

                $pdo->commit();

                if ($ajaxCheckout) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'redirect' => 'order_payment.php?order_id=' . (int) $orderId]);
                    exit;
                }
                echo "<script>localStorage.removeItem('kapeagosto_cart'); window.location.replace('order_payment.php?order_id=" . (int) $orderId . "');</script>";
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $checkoutError = 'Checkout failed: ' . $e->getMessage();
            }
        }
    }
    if ($ajaxCheckout) {
        http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $checkoutError]);
        exit;
    }
}

$activeCategoryName = 'Select Category';
foreach ($categories as $category) {
    if ((int) $category['id'] === $selectedCategoryId) {
        $activeCategoryName = $category['name'];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Manage Order</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-orders-orders<?= is_cashier() ? ' cashier-pos-shell cashier-pos-register' : '' ?>">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content order-page">
  <section class="order-page-heading" aria-labelledby="order-title">
    <div>
      <p class="order-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Order</p>
      <h1 id="order-title">Manage Order</h1>
      <p class="order-page-copy">Tap any menu item to add it instantly, review the ticket, then continue to payment.</p>
    </div>
    <div class="order-summary-pills" aria-label="Order setup summary">
      <span><strong><?= number_format(count($categories)) ?></strong> Categories</span>
      <span><strong><?= number_format(count($items)) ?></strong> Menu Items</span>
      <span class="order-live-status"><i class="fa fa-circle"></i> Register ready</span>
    </div>
  </section>

  <?php if ($editNotice): ?>
    <div class="order-alert info"><?= htmlspecialchars($editNotice) ?></div>
  <?php endif; ?>

  <?php if (!empty($changeCreditSourceOrderIds) && $availableChangeCredit > 0): ?>
    <div class="order-alert info">PHP <?= number_format($availableChangeCredit, 2) ?> of unused change from the previous order(s) will be applied to this order. You will only need to pay the remaining balance.</div>
  <?php endif; ?>

  <?php if ($inventoryBlocked): ?>
    <div class="order-alert error">
      <strong>Checkout is blocked until inventory is complete.</strong>
      <div class="order-alert-grid">
        <?php if ($inventoryCount === 0): ?><span>No inventory items found.</span><?php endif; ?>
        <?php if (!empty($missingCupSizes)): ?><span>Missing cup sizes: <?= htmlspecialchars(implode(', ', $missingCupSizes)) ?></span><?php endif; ?>
        <?php if (!empty($missingLidSizes)): ?><span>Missing lid sizes: <?= htmlspecialchars(implode(', ', $missingLidSizes)) ?></span><?php endif; ?>
        <?php if ($missingStraw): ?><span>Missing required item: Straw</span><?php endif; ?>
        <?php if ($missingStirrer): ?><span>Missing required item: Coffee Stirrer</span><?php endif; ?>
      </div>
    </div>
  <?php elseif (!empty($lowStockItems)): ?>
    <div class="order-alert warning">
      <strong>Low stock notice:</strong>
      <?php
        $lowLabels = array_map(function($item) {
            $size = !empty($item['cup_size']) ? ' (' . $item['cup_size'] . ')' : '';
            return $item['name'] . $size;
        }, array_slice($lowStockItems, 0, 4));
      ?>
      <?= htmlspecialchars(implode(', ', $lowLabels)) ?><?= count($lowStockItems) > 4 ? ' and more.' : '.' ?>
    </div>
  <?php endif; ?>

  <?php if ($checkoutError): ?>
    <div class="order-alert error"><?= htmlspecialchars($checkoutError) ?></div>
  <?php elseif (isset($_GET['success'])): ?>
    <div class="order-alert success">Order placed successfully. You can now create another order.</div>
  <?php endif; ?>

  <div class="order-alert error" id="checkoutClientError" role="alert" hidden></div>

  <section class="order-workspace">
    <section class="order-new-panel" aria-labelledby="new-order-title">
      <div class="order-panel-header">
        <div>
          <span class="panel-eyebrow">Point of Sale</span>
          <h2 id="new-order-title"><?= is_cashier() ? 'Menu' : 'New Order' ?></h2>
        </div>
        <?php if ($editingOrderId > 0): ?>
          <span class="order-chip">Editing #<?= (int) $editingOrderId ?></span>
        <?php endif; ?>
      </div>

      <div class="order-form" id="orderFilterForm">
        <?php if ($editingOrderId > 0): ?>
          <input type="hidden" name="order_id" value="<?= (int) $editingOrderId ?>">
        <?php endif; ?>

        <div class="form-group">
          <label for="customer_name">Customer Name</label>
          <div class="order-input-shell">
            <i class="fa fa-user" aria-hidden="true"></i>
            <input id="customer_name" type="text" name="customer_name" value="<?= htmlspecialchars($customerName) ?>" placeholder="Customer Name" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" autocomplete="off">
          </div>
        </div>

        <div class="form-group order-search-group">
          <label for="searchInput">Search Item</label>
          <div class="order-input-shell">
            <i class="fa fa-magnifying-glass" aria-hidden="true"></i>
            <input id="searchInput" type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search drinks or sizes..." autocomplete="off">
            <kbd>/</kbd>
          </div>
        </div>
      </div>

      <div class="order-category-tabs" role="group" aria-label="Filter menu by category">
        <button type="button" class="order-category-tab <?= $selectedCategoryId === 0 ? 'is-active' : '' ?>" data-category="all" aria-pressed="<?= $selectedCategoryId === 0 ? 'true' : 'false' ?>">
          <i class="fa fa-border-all" aria-hidden="true"></i>
          All items
        </button>
        <?php foreach ($categories as $category): ?>
          <button type="button" class="order-category-tab <?= $selectedCategoryId === (int) $category['id'] ? 'is-active' : '' ?>" data-category="<?= (int) $category['id'] ?>" aria-pressed="<?= $selectedCategoryId === (int) $category['id'] ? 'true' : 'false' ?>">
            <?= htmlspecialchars($category['name']) ?>
          </button>
        <?php endforeach; ?>
      </div>

      <div class="order-items-panel">
        <div class="order-items-header">
          <h3>Select Item</h3>
          <span id="visibleItemCount"><?= number_format(count($items)) ?> available</span>
        </div>

        <?php if (empty($items)): ?>
          <div class="order-empty-state">
            <i class="fa fa-mug-hot"></i>
            <strong>No menu items available.</strong>
            <span>An administrator can add products from Manage Item.</span>
          </div>
        <?php else: ?>
          <div class="order-items-grid" id="orderItemsGrid">
            <?php foreach ($items as $item): ?>
              <?php $isBestSeller = $bestSellerUnits > 0 && (int) $item['units_sold_30'] === $bestSellerUnits; ?>
              <?php $displayCupSize = order_display_cup_size((string) $item['cup_size']); ?>
              <button
                type="button"
                class="order-item-card<?= $isBestSeller ? ' is-best-seller' : '' ?>"
                data-id="<?= (int) $item['id'] ?>"
                data-category="<?= (int) $item['category_id'] ?>"
                data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
                data-cupsize="<?= htmlspecialchars($displayCupSize, ENT_QUOTES) ?>"
                data-price="<?= htmlspecialchars((string) $item['price'], ENT_QUOTES) ?>"
                aria-label="<?= $isBestSeller ? 'Best seller. ' : '' ?>Add <?= htmlspecialchars($item['name']) ?>, <?= htmlspecialchars($displayCupSize) ?>, <?= htmlspecialchars(money_order($item['price'])) ?>"
              >
                <span class="order-item-image-wrap">
                  <img src="<?= htmlspecialchars(order_item_image_src($item['image'] ?? null)) ?>" alt="">
                  <?php if ($isBestSeller): ?>
                    <span class="order-best-seller-badge" title="Highest completed sales in the last 30 days">
                      <i class="fa fa-star" aria-hidden="true"></i>
                      Best Seller
                    </span>
                  <?php endif; ?>
                  <span class="order-item-add"><i class="fa fa-plus" aria-hidden="true"></i></span>
                </span>
                <span class="order-item-meta">
                  <strong><?= htmlspecialchars($item['name']) ?></strong>
                  <small><?= htmlspecialchars($item['category_name']) ?></small>
                </span>
                <span class="order-item-footer">
                  <span class="order-item-size"><?= htmlspecialchars($displayCupSize) ?></span>
                  <span class="order-item-price"><?= htmlspecialchars(money_order($item['price'])) ?></span>
                </span>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="order-empty-state" id="orderFilterEmpty" hidden>
            <i class="fa fa-magnifying-glass"></i>
            <strong>No matching menu item.</strong>
            <span>Try a different search or category.</span>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <aside class="order-summary-panel" aria-labelledby="summary-title">
      <div class="order-panel-header compact">
        <div>
          <span class="panel-eyebrow">Current sale</span>
          <h2 id="summary-title">Ticket</h2>
        </div>
        <span class="order-cart-count" id="cartCount" aria-live="polite">0 items</span>
      </div>

      <form method="post" id="checkoutForm">
        <fieldset class="order-service-choice">
          <legend>Order Type</legend>
          <label><input type="radio" name="service_type" value="dine_in" required <?= $serviceType === 'dine_in' ? 'checked' : '' ?>><span>Dine-in</span></label>
          <label><input type="radio" name="service_type" value="take_out" required <?= $serviceType === 'take_out' ? 'checked' : '' ?>><span>Take-out</span></label>
        </fieldset>
        <?php if ($editingOrderId > 0): ?>
          <input type="hidden" name="editing_order_id" value="<?= (int) $editingOrderId ?>">
        <?php endif; ?>
        <?php if (!empty($changeCreditSourceOrderIds)): ?>
          <input type="hidden" name="change_credit_source_order_ids" value="<?= htmlspecialchars(implode(',', $changeCreditSourceOrderIds), ENT_QUOTES) ?>">
        <?php endif; ?>
        <input type="hidden" name="customer_name" id="checkoutCustomerName" value="<?= htmlspecialchars($customerName) ?>">
        <input type="hidden" name="checkout" value="1">

        <div class="summary-table-wrap" id="summaryTable">
          <div class="summary-ticket-list"></div>
        </div>

        <section class="order-discount-panel" aria-labelledby="order-discount-title">
          <h3 id="order-discount-title">Customer Discount</h3>
          <?php
            $promoDisplayPercent = $editingOrderId > 0 && !$promoIsActive ? (float) $preloadDiscount['promo_percent'] : $configuredPromoPercent;
            $promoStartDate = settings_value($checkoutSettings, 'promo_discount_start_date');
            $promoEndDate = settings_value($checkoutSettings, 'promo_discount_end_date');
            $promoControlLabel = $promoIsActive
                ? 'Apply active promo (' . number_format($activePromoPercent, 2) . '%)'
                : ($editingOrderId > 0 && (float) $preloadDiscount['promo_percent'] > 0
                    ? 'Keep existing ticket promo'
                    : ($configuredPromoPercent > 0 ? 'Promo is outside its active dates' : 'No active promo is set'));
          ?>
          <label class="order-discount-confirm"><input type="checkbox" name="apply_configured_promo" value="1" <?= $editingOrderId > 0 ? ((float) $preloadDiscount['promo_percent'] > 0 ? 'checked' : '') : '' ?> <?= !$promoIsActive ? 'disabled' : '' ?>> <?= htmlspecialchars($promoControlLabel) ?></label>
          <label for="promo_percent">Active promo rate (%) <span>Set in Settings</span></label>
          <input type="number" id="promo_percent" value="<?= number_format($promoDisplayPercent, 2, '.', '') ?>" disabled aria-describedby="promoAdminHelp">
          <p class="order-discount-help" id="promoAdminHelp">The POS uses the rate saved in Settings<?= $promoStartDate !== '' && $promoEndDate !== '' ? ' from ' . htmlspecialchars($promoStartDate) . ' through ' . htmlspecialchars($promoEndDate) : '' ?>. If a Senior/PWD benefit is selected too, the POS applies the more favorable one; discounts are not stacked.</p>
          <label for="discount_beneficiary_type">Statutory discount</label>
          <select id="discount_beneficiary_type" name="discount_beneficiary_type">
            <option value="" <?= $preloadDiscount['beneficiary_type'] === '' ? 'selected' : '' ?>>None</option>
            <option value="senior" <?= $preloadDiscount['beneficiary_type'] === 'senior' ? 'selected' : '' ?>>Senior Citizen</option>
            <option value="pwd" <?= $preloadDiscount['beneficiary_type'] === 'pwd' ? 'selected' : '' ?>>PWD</option>
          </select>
          <div id="statutoryDiscountFields" <?= $preloadDiscount['beneficiary_type'] === '' ? 'hidden' : '' ?>>
            <label for="discount_customer_name">Beneficiary Name</label>
            <input type="text" id="discount_customer_name" name="discount_customer_name" maxlength="100" value="<?= htmlspecialchars($preloadDiscount['customer_name'], ENT_QUOTES) ?>" <?= $preloadDiscount['beneficiary_type'] !== '' ? 'required' : '' ?>>
            <label for="discount_customer_tin">Beneficiary TIN <span>Optional</span></label>
            <input type="text" id="discount_customer_tin" name="discount_customer_tin" maxlength="32" value="<?= htmlspecialchars($preloadDiscount['tin'], ENT_QUOTES) ?>" autocomplete="off">
            <label for="discount_id_number">SC/PWD ID Number</label>
            <input type="text" id="discount_id_number" name="discount_id_number" maxlength="64" value="<?= htmlspecialchars($preloadDiscount['id_number'], ENT_QUOTES) ?>" autocomplete="off" <?= $preloadDiscount['beneficiary_type'] !== '' ? 'required' : '' ?>>
            <label class="order-discount-confirm"><input type="checkbox" name="exclusive_use_confirmed" value="1" <?= $preloadDiscount['exclusive_use'] ? 'checked' : '' ?> <?= $preloadDiscount['beneficiary_type'] !== '' ? 'required' : '' ?>> I verified the ID, and the items are for this customer’s exclusive use.</label>
          </div>
          <p id="discountPreview" class="order-discount-preview" role="status" aria-live="polite"></p>
        </section>

        <div class="order-subtotal-row">
          <span>Subtotal</span>
          <span id="subtotalText">PHP 0.00</span>
        </div>
        <div class="order-total-row">
          <span>Total</span>
          <strong id="totalText">PHP 0.00</strong>
          <input type="hidden" id="totalBox" name="total" value="0.00" readonly>
        </div>

        <div class="checkout-actions">
          <button type="submit" class="order-checkout-btn" <?= $inventoryBlocked ? 'disabled' : '' ?>>
            <i class="fa fa-credit-card"></i>
            Continue to Payment
            <span class="checkout-shortcut">F8</span>
          </button>
          <button type="button" class="order-clear-btn" id="clearCartBtn">
            <i class="fa fa-trash-can"></i>
            Clear Cart
          </button>
        </div>
      </form>
    </aside>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
const checkoutBlocked = <?= $inventoryBlocked ? 'true' : 'false' ?>;
const editingOrderId = <?= (int) $editingOrderId ?>;
const checkoutTaxType = <?= json_encode($checkoutTaxType) ?>;
const preloaded = <?php
    echo json_encode(array_map(function($item) {
        return [
            'id' => (int) $item['item_id'],
            'name' => $item['name'],
            'cupsize' => $item['cup_size'] ?? '',
            'qty' => (int) $item['qty'],
            'price' => (float) $item['price'],
        ];
    }, $preloadItems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>;

const qs = (selector) => document.querySelector(selector);
const summaryTicketList = qs('#summaryTable .summary-ticket-list');
const totalBox = qs('#totalBox');
const totalText = qs('#totalText');
const subtotalText = qs('#subtotalText');
const cartCount = qs('#cartCount');
const checkoutForm = qs('#checkoutForm');
const checkoutClientError = qs('#checkoutClientError');
const customerNameInput = qs('#customer_name');
const promoPercentInput = qs('#promo_percent');
const applyConfiguredPromoInput = qs('input[name="apply_configured_promo"]');
const beneficiaryTypeInput = qs('#discount_beneficiary_type');
const beneficiaryFields = qs('#statutoryDiscountFields');
const beneficiaryNameInput = qs('#discount_customer_name');
const beneficiaryIdInput = qs('#discount_id_number');
const exclusiveUseInput = qs('input[name="exclusive_use_confirmed"]');
const discountPreview = qs('#discountPreview');
function updateTicketCustomer() {
  const type = checkoutForm.querySelector('input[name="service_type"]:checked')?.value;
  const typeLabel = { dine_in: 'Dine-in', take_out: 'Take-out' }[type] || '';
  const name = (customerNameInput?.value || '').trim();
  const named = name && !['WALK-IN', 'WALK IN', 'WALKIN', 'WALK-IN CUSTOMER', 'GUEST', '--'].includes(name.toUpperCase());
  qs('#summary-title').textContent = named ? name + (typeLabel ? ' · ' + typeLabel : '') : (typeLabel || 'Ticket');
}
customerNameInput?.addEventListener('input', updateTicketCustomer);
checkoutForm.querySelectorAll('input[name="service_type"]').forEach(input => input.addEventListener('change', updateTicketCustomer));
updateTicketCustomer();
const searchInput = qs('#searchInput');
const checkoutCustomerName = qs('#checkoutCustomerName');
let cart = {};
let activeCategory = document.querySelector('.order-category-tab.is-active')?.dataset.category || 'all';

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, function(char) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
  });
}

function showCheckoutError(message) {
  if (typeof window.kapeNotify === 'function') {
    window.kapeNotify(message, 'error', 'checkout-error');
    return;
  }
  if (!checkoutClientError) return;
  checkoutClientError.textContent = message;
  checkoutClientError.hidden = false;
  checkoutClientError.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function hideCheckoutError() {
  if (!checkoutClientError) return;
  checkoutClientError.hidden = true;
  checkoutClientError.textContent = '';
}

function saveCart() {
  localStorage.setItem('kapeagosto_cart', JSON.stringify(cart));
}

function loadCart() {
  const saved = localStorage.getItem('kapeagosto_cart');
  if (!saved) return;
  try {
    cart = JSON.parse(saved) || {};
  } catch (error) {
    cart = {};
  }
}

function money(value) {
  return 'PHP ' + Number(value || 0).toFixed(2);
}

function appendHidden(name, value) {
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = name;
  input.value = value;
  input.className = 'cart-hidden-input';
  checkoutForm.appendChild(input);
}

function updateSummary() {
  summaryTicketList.innerHTML = '';
  checkoutForm.querySelectorAll('.cart-hidden-input').forEach((input) => input.remove());

  const items = Object.values(cart);
  let total = 0;
  let itemCount = 0;

  if (items.length === 0) {
    summaryTicketList.innerHTML = '<div class="summary-empty"><span class="summary-empty-icon"><i class="fa fa-basket-shopping"></i></span><strong>Your ticket is empty</strong><span>Tap a menu item to start an order.</span></div>';
  }

  items.forEach((item, index) => {
    const qty = Number(item.qty || 0);
    const price = Number(item.price || 0);
    const subtotal = qty * price;
    const key = item.id + '_' + (item.cupsize || '');
    total += subtotal;
    itemCount += qty;

    summaryTicketList.insertAdjacentHTML('beforeend', `
      <article class="ticket-line">
        <div class="ticket-line-copy">
          <strong>${escapeHtml(item.name)}</strong>
          <span>${escapeHtml(item.cupsize || 'Regular')} &middot; ${money(price)} each</span>
        </div>
        <strong class="ticket-line-total">${money(subtotal)}</strong>
        <div class="ticket-qty-control" aria-label="Quantity for ${escapeHtml(item.name)}">
          <button type="button" class="cart-qty-btn" data-action="decrease" data-key="${escapeHtml(key)}" aria-label="Decrease ${escapeHtml(item.name)} quantity"><i class="fa fa-minus"></i></button>
          <span>${qty}</span>
          <button type="button" class="cart-qty-btn" data-action="increase" data-key="${escapeHtml(key)}" aria-label="Increase ${escapeHtml(item.name)} quantity"><i class="fa fa-plus"></i></button>
        </div>
        <button type="button" class="cart-remove-btn" data-key="${escapeHtml(key)}" title="Remove item" aria-label="Remove ${escapeHtml(item.name)}">
          <i class="fa fa-trash-can"></i>
        </button>
      </article>
    `);

    appendHidden(`items[${index}][id]`, item.id);
    appendHidden(`items[${index}][qty]`, qty);
    appendHidden(`items[${index}][price]`, price);
    appendHidden(`items[${index}][cupsize]`, item.cupsize || '');
  });

  const promoPercent = applyConfiguredPromoInput?.checked
    ? Math.max(0, Math.min(100, Number(promoPercentInput?.value || 0)))
    : 0;
  const beneficiary = beneficiaryTypeInput?.value || '';
  const promoDue = Math.max(0, Math.round((total - total * promoPercent / 100) * 100) / 100);
  const taxableBase = checkoutTaxType === 'vat' ? Math.round((total / 1.12) * 100) / 100 : total;
  const statutoryDiscount = Math.round(taxableBase * 0.20 * 100) / 100;
  const statutoryDue = Math.max(0, Math.round((taxableBase - statutoryDiscount) * 100) / 100);
  let amountDue = total;
  let discountLabel = '';
  let appliedDiscount = 0;
  let vatExempt = 0;
  if (beneficiary) {
    if (promoPercent > 0 && promoDue < statutoryDue) {
      amountDue = promoDue; appliedDiscount = total - promoDue; discountLabel = 'Promo (' + promoPercent.toFixed(2).replace(/\.00$/, '') + '%; more favorable)';
    } else {
      amountDue = statutoryDue; appliedDiscount = statutoryDiscount; vatExempt = checkoutTaxType === 'vat' ? total - taxableBase : 0; discountLabel = (beneficiary === 'senior' ? 'Senior Citizen' : 'PWD') + ' (20%)';
    }
  } else if (promoPercent > 0) {
    amountDue = promoDue; appliedDiscount = total - promoDue; discountLabel = 'Promo (' + promoPercent.toFixed(2).replace(/\.00$/, '') + '%)';
  }
  totalBox.value = amountDue.toFixed(2);
  totalText.textContent = money(amountDue);
  subtotalText.textContent = money(total);
  if (discountPreview) {
    discountPreview.textContent = discountLabel
      ? discountLabel + ': ' + money(appliedDiscount) + (vatExempt > 0 ? ' VAT exemption: ' + money(vatExempt) : '') + ' · Amount due: ' + money(amountDue)
      : 'No discount · Amount due: ' + money(total);
  }
  cartCount.textContent = itemCount + (itemCount === 1 ? ' item' : ' items');
  saveCart();
}

promoPercentInput?.addEventListener('input', updateSummary);
applyConfiguredPromoInput?.addEventListener('change', updateSummary);
beneficiaryTypeInput?.addEventListener('change', function() {
  if (beneficiaryFields) beneficiaryFields.hidden = !beneficiaryTypeInput.value;
  if (beneficiaryTypeInput.value && beneficiaryNameInput && !beneficiaryNameInput.value.trim()) beneficiaryNameInput.value = customerNameInput?.value || '';
  if (beneficiaryIdInput) beneficiaryIdInput.required = Boolean(beneficiaryTypeInput.value);
  if (beneficiaryNameInput) beneficiaryNameInput.required = Boolean(beneficiaryTypeInput.value);
  if (exclusiveUseInput) exclusiveUseInput.required = Boolean(beneficiaryTypeInput.value);
  updateSummary();
});

document.querySelectorAll('.order-item-card').forEach((card) => {
  card.addEventListener('click', function() {
    const item = {
      id: this.dataset.id,
      name: this.dataset.name,
      cupsize: this.dataset.cupsize,
      price: Number(this.dataset.price || 0)
    };
    const key = item.id + '_' + (item.cupsize || '');
    if (cart[key]) {
      cart[key].qty = Number(cart[key].qty || 0) + 1;
    } else {
      cart[key] = { ...item, qty: 1 };
    }
    this.classList.remove('just-added');
    void this.offsetWidth;
    this.classList.add('just-added');
    updateSummary();
  });
});

summaryTicketList?.addEventListener('click', function(event) {
  const button = event.target.closest('.cart-remove-btn');
  if (button) {
    delete cart[button.dataset.key];
    updateSummary();
    return;
  }

  const qtyButton = event.target.closest('.cart-qty-btn');
  if (!qtyButton || !cart[qtyButton.dataset.key]) return;
  if (qtyButton.dataset.action === 'increase') {
    cart[qtyButton.dataset.key].qty = Number(cart[qtyButton.dataset.key].qty || 0) + 1;
  } else {
    cart[qtyButton.dataset.key].qty = Number(cart[qtyButton.dataset.key].qty || 0) - 1;
    if (cart[qtyButton.dataset.key].qty <= 0) delete cart[qtyButton.dataset.key];
  }
  updateSummary();
});

qs('#clearCartBtn')?.addEventListener('click', function() {
  cart = {};
  updateSummary();
});

function filterMenu() {
  const term = (searchInput?.value || '').trim().toLowerCase();
  let visible = 0;
  document.querySelectorAll('.order-item-card').forEach((card) => {
    const matchesCategory = activeCategory === 'all' || card.dataset.category === activeCategory;
    const searchable = `${card.dataset.name || ''} ${card.dataset.cupsize || ''}`.toLowerCase();
    const matchesSearch = !term || searchable.includes(term);
    const show = matchesCategory && matchesSearch;
    card.hidden = !show;
    if (show) visible += 1;
  });
  const count = qs('#visibleItemCount');
  if (count) count.textContent = visible + ' available';
  const empty = qs('#orderFilterEmpty');
  if (empty) empty.hidden = visible !== 0;
  const grid = qs('#orderItemsGrid');
  if (grid) grid.hidden = visible === 0;
}

document.querySelectorAll('.order-category-tab').forEach((button) => {
  button.addEventListener('click', function() {
    activeCategory = this.dataset.category || 'all';
    document.querySelectorAll('.order-category-tab').forEach((tab) => {
      const selected = tab === this;
      tab.classList.toggle('is-active', selected);
      tab.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
    filterMenu();
  });
});

searchInput?.addEventListener('input', filterMenu);

document.addEventListener('keydown', function(event) {
  if (event.key === '/' && document.activeElement !== searchInput && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
    event.preventDefault();
    searchInput?.focus();
  }
  if (event.key === 'F8') {
    event.preventDefault();
    checkoutForm?.requestSubmit();
  }
});

let checkoutInProgress = false;
checkoutForm?.addEventListener('submit', async function(event) {
  event.preventDefault();
  if (checkoutInProgress) return;
  if (checkoutBlocked) {
    event.preventDefault();
    showCheckoutError('Checkout is blocked until required inventory is complete.');
    return;
  }

  if (Object.keys(cart).length === 0) {
    event.preventDefault();
    showCheckoutError('Please add at least one item before checkout.');
    return;
  }

  hideCheckoutError();
  checkoutCustomerName.value = customerNameInput?.value || 'Walk-in';
  updateSummary();
  const checkoutButton = checkoutForm.querySelector('button[type="submit"]');
  const payload = new FormData(checkoutForm);
  payload.set('checkout_response', 'json');
  checkoutInProgress = true;
  if (checkoutButton) checkoutButton.disabled = true;
  checkoutForm.setAttribute('aria-busy', 'true');
  try {
    const response = await fetch(checkoutForm.action || window.location.href, {
      method: 'POST',
      body: payload,
      headers: { Accept: 'application/json' }
    });
    const result = await response.json();
    if (!response.ok || !result.success) {
      showCheckoutError(result.message || 'Checkout failed. Please try again.');
      return;
    }
    localStorage.removeItem('kapeagosto_cart');
    window.location.replace(result.redirect);
  } catch (error) {
    showCheckoutError('Unable to confirm checkout. Your cart is still here. Check the Order List before retrying to avoid a duplicate order.');
  } finally {
    checkoutInProgress = false;
    if (checkoutButton) checkoutButton.disabled = false;
    checkoutForm.removeAttribute('aria-busy');
  }
});

document.addEventListener('DOMContentLoaded', function() {
  if (beneficiaryTypeInput?.value) beneficiaryTypeInput.dispatchEvent(new Event('change'));
  if (preloaded.length > 0) {
    cart = {};
    preloaded.forEach((item) => {
      const key = item.id + '_' + (item.cupsize || '');
      cart[key] = {
        id: String(item.id),
        name: item.name,
        cupsize: item.cupsize || '',
        qty: Number(item.qty || 1),
        price: Number(item.price || 0)
      };
    });
    saveCart();
  } else {
    loadCart();
  }

  updateSummary();
  filterMenu();
});
</script>
</body>
</html>
