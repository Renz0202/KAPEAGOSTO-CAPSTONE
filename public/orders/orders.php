<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/daily_tasks.php';
require_once dirname(__DIR__, 2) . '/includes/order_utils.php';
require_once dirname(__DIR__, 2) . '/includes/customer_service.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_capability('process_transactions');

$pdo = db();

function money_order($amount) {
    return 'PHP ' . number_format((float) $amount, 2);
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

    $lowStockItems = [];
    $invStmt = $pdo->query('SELECT name, cup_size, qty, unit, critical_level FROM inventory ORDER BY name ASC, cup_size ASC');
    foreach ($invStmt as $inv) {
        if ($inv['critical_level'] !== null && (float) $inv['critical_level'] > 0 && (float) $inv['qty'] <= (float) $inv['critical_level']) {
            $lowStockItems[] = $inv;
        }
    }

    $inventoryBlocked = (
        $inventoryCount === 0 ||
        !empty($missingCupSizes) ||
        !empty($missingLidSizes) ||
        $missingStraw ||
        $missingStirrer
    );

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

function order_item_type($name): string {
    $name = strtolower((string) $name);
    if (strpos($name, 'straw') !== false) {
        return 'straw';
    }
    if (strpos($name, 'cup') !== false) {
        return 'cup';
    }
    if (strpos($name, 'lid') !== false || strpos($name, 'cover') !== false) {
        return 'lid';
    }
    if (strpos($name, 'stirrer') !== false) {
        return 'stirrer';
    }
    return 'item';
}

function check_inventory_stock(PDO $pdo, string $itemName, ?string $cupSize, int $qty): bool {
    $type = order_item_type($itemName);
    $nameLike = $type === 'cup' ? '%cup%' :
        ($type === 'lid' ? '%lid%' :
        ($type === 'straw' ? '%straw%' :
        ($type === 'stirrer' ? '%stirrer%' : '%' . $itemName . '%')));

    if ($type === 'straw') {
        $stmt = $pdo->prepare('SELECT qty, critical_level FROM inventory WHERE name LIKE ? AND cup_size IS NULL');
        $stmt->execute([$nameLike]);
        $totalQty = 0;
        $criticalLevel = null;

        foreach ($stmt as $inv) {
            $totalQty += (float) $inv['qty'];
            if ($inv['critical_level'] !== null && ($criticalLevel === null || (float) $inv['critical_level'] < $criticalLevel)) {
                $criticalLevel = (float) $inv['critical_level'];
            }
        }

        return $totalQty >= $qty;
    }

    $stmt = $pdo->prepare('SELECT qty, critical_level FROM inventory WHERE name LIKE ? AND (cup_size = ? OR cup_size IS NULL) ORDER BY cup_size IS NULL ASC LIMIT 1');
    $stmt->execute([$nameLike, $cupSize]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inv) {
        return false;
    }

    return (float) $inv['qty'] >= $qty;
}

function checkout_line_items(PDO $pdo, array $postedItems): array {
    $lines = [];

    foreach ($postedItems as $item) {
        $itemId = (int) ($item['id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $stmt = $pdo->prepare('SELECT id, name, cup_size, price FROM items WHERE id = ? LIMIT 1');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            continue;
        }

        $lines[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'cup_size' => $row['cup_size'],
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
$editingOrderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$preloadItems = [];
$editNotice = '';
$checkoutError = '';

if ($editingOrderId > 0) {
    $stmt = $pdo->prepare('SELECT o.status, o.customer_name FROM orders o WHERE o.id = ? LIMIT 1');
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
    ORDER BY units_sold_30 DESC, c.name ASC, i.name ASC, i.cup_size ASC
')->fetchAll(PDO::FETCH_ASSOC);

$bestSellerUnits = 0;
foreach ($items as $catalogItem) {
    $bestSellerUnits = max($bestSellerUnits, (int) ($catalogItem['units_sold_30'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $customerNamePost = normalize_uppercase_name((string)($_POST['customer_name'] ?? ''));
    $customerNamePost = $customerNamePost !== '' ? $customerNamePost : 'Walk-in';
    $editingId = (int) ($_POST['editing_order_id'] ?? 0);
    $orderLines = checkout_line_items($pdo, $_POST['items'] ?? []);

    if (empty($orderLines)) {
        $checkoutError = 'Please add at least one item before checkout.';
    } elseif ($inventoryBlocked) {
        $checkoutError = 'Cannot checkout yet. Please complete the required inventory items first.';
    } else {
        $insufficient = [];
        $coldDrinkQty = 0;

        foreach ($orderLines as $line) {
            $cupSize = $line['cup_size'];
            $qty = (int) $line['qty'];

            if (!check_inventory_stock($pdo, 'cup', $cupSize, $qty)) {
                $insufficient[] = $cupSize . ' Cup';
            }

            if ($cupSize === '12oz Hot') {
                if (!check_inventory_stock($pdo, 'stirrer', null, $qty)) {
                    $insufficient[] = 'Coffee Stirrer';
                }
                if (!check_inventory_stock($pdo, 'lid', $cupSize, $qty)) {
                    $insufficient[] = 'Flat Lid (' . $cupSize . ')';
                }
            } else {
                $coldDrinkQty += $qty;
                if (!check_inventory_stock($pdo, 'lid', $cupSize, $qty)) {
                    $insufficient[] = 'Flat Lid (' . $cupSize . ')';
                }
            }
        }

        if ($coldDrinkQty > 0 && !check_inventory_stock($pdo, 'straw', null, $coldDrinkQty)) {
            $insufficient[] = 'Straw';
        }

        if (!empty($insufficient)) {
            $checkoutError = 'Cannot checkout. Insufficient stock for: ' . implode(', ', array_unique($insufficient));
        } else {
            $total = 0;
            foreach ($orderLines as $line) {
                $total += ((float) $line['price'] * (int) $line['qty']);
            }

            $pdo->beginTransaction();

            try {
                $customerId = customer_find_or_create($pdo, $customerNamePost);
                if ($editingId > 0) {
                    $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1");
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

                $stmt = $pdo->prepare("UPDATE orders SET customer_id = ?, customer_name = ?, total = ?, status = 'pending' WHERE id = ?");
                $stmt->execute([$customerId, $customerNamePost, $total, $orderId]);

                $pdo->commit();

                echo "<script>localStorage.removeItem('kapeagosto_cart'); window.location.replace('order_payment.php?order_id=" . (int) $orderId . "');</script>";
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $checkoutError = 'Checkout failed: ' . $e->getMessage();
            }
        }
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
            <input id="customer_name" type="text" name="customer_name" value="<?= htmlspecialchars($customerName) ?>" placeholder="Walk-in customer" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" autocomplete="off">
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
              <button
                type="button"
                class="order-item-card<?= $isBestSeller ? ' is-best-seller' : '' ?>"
                data-id="<?= (int) $item['id'] ?>"
                data-category="<?= (int) $item['category_id'] ?>"
                data-name="<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>"
                data-cupsize="<?= htmlspecialchars($item['cup_size'], ENT_QUOTES) ?>"
                data-price="<?= htmlspecialchars((string) $item['price'], ENT_QUOTES) ?>"
                aria-label="<?= $isBestSeller ? 'Best seller. ' : '' ?>Add <?= htmlspecialchars($item['name']) ?>, <?= htmlspecialchars($item['cup_size']) ?>, <?= htmlspecialchars(money_order($item['price'])) ?>"
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
                  <span class="order-item-size"><?= htmlspecialchars($item['cup_size']) ?></span>
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
        <?php if ($editingOrderId > 0): ?>
          <input type="hidden" name="editing_order_id" value="<?= (int) $editingOrderId ?>">
        <?php endif; ?>
        <input type="hidden" name="customer_name" id="checkoutCustomerName" value="<?= htmlspecialchars($customerName) ?>">
        <input type="hidden" name="checkout" value="1">

        <div class="summary-table-wrap" id="summaryTable">
          <div class="summary-ticket-list"></div>
        </div>

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
    window.kapeNotify(message, 'error');
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

  totalBox.value = total.toFixed(2);
  totalText.textContent = money(total);
  subtotalText.textContent = money(total);
  cartCount.textContent = itemCount + (itemCount === 1 ? ' item' : ' items');
  saveCart();
}

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

checkoutForm?.addEventListener('submit', function(event) {
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
});

document.addEventListener('DOMContentLoaded', function() {
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
