<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/daily_tasks.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_service.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_recipe_editor.php';
require_once dirname(__DIR__, 2) . '/includes/material_catalog.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_history.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_lists.php';
require_admin();

date_default_timezone_set('Asia/Manila');
$pdo = db();
notifications_refresh_low_stock($pdo);

function formatQty($num) {
    return ($num == floor($num)) ? (int)$num : rtrim(rtrim(number_format((float)$num, 2, '.', ''), '0'), '.');
}

function formatCurrency($amount) {
    return 'PHP ' . ((floor((float)$amount) == (float)$amount) ? number_format((float)$amount, 0) : number_format((float)$amount, 2));
}

function inventorySku(string $posted, string $name, ?string $size = null): string {
    $base = strtoupper(trim($posted));
    if ($base === '') {
        $base = 'INV-' . substr(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($name)), 0, 36);
    }
    $base = trim(preg_replace('/[^A-Z0-9-]+/', '-', $base), '-');
    if ($size !== null && $size !== '') {
        $base .= '-' . preg_replace('/[^A-Z0-9]+/', '', strtoupper($size));
    }
    return substr($base, 0, 64);
}

function normalizeCupSize($size) {
    if ($size === null) {
        return null;
    }

    $s = trim(strtolower((string)$size));
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

    return ucwords(trim((string)$size));
}

function getItemType($name, string $materialType = 'other') {
    if ($materialType === 'raw_material') return 'Raw Material';
    $kind = inventory_service_packaging_kind(['name' => $name, 'material_type' => $materialType]);
    return $kind !== null ? ucfirst($kind) : 'Others';
}

function getInventoryByNameAndSize(PDO $pdo, string $name, ?string $cupSize, string $materialType) {
    $stmt = $pdo->prepare('SELECT id, qty, unit, critical_level, material_group FROM inventory WHERE name = ? AND cup_size <=> ? AND material_type = ? ORDER BY id LIMIT 1 FOR UPDATE');
    $stmt->execute([$name, $cupSize, $materialType]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function inventoryStockNumber($value, bool $nullable = false): ?float {
    if ($nullable && ($value === '' || $value === null)) return null;
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 99999999.99 || abs(round((float) $value, 2) - (float) $value) > 0.000001) {
        throw new InvalidArgumentException('Quantities must be zero or greater, with at most two decimal places.');
    }
    return round((float) $value, 2);
}
function fetchInventory(PDO $pdo, string $search = '') {
    $sql = "
        SELECT i.*, lr.last_restock_at, COALESCE(usage14.used_14, 0) AS used_14
        FROM inventory i
        LEFT JOIN (
            SELECT inventory_id, MAX(created_at) AS last_restock_at
            FROM inventory_logs
            WHERE change_type = 'restock'
            GROUP BY inventory_id
        ) lr ON lr.inventory_id = i.id
        LEFT JOIN (
            SELECT inventory_id, SUM(ABS(quantity)) AS used_14
            FROM inventory_logs
            WHERE change_type IN ('used','deduct','auto-deduct','damage')
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
              AND created_at < CURDATE()
            GROUP BY inventory_id
        ) usage14 ON usage14.inventory_id = i.id
    ";
    $params = [];

    if ($search !== '') {
        $sql .= " WHERE i.sku LIKE ? ESCAPE '!' OR i.name LIKE ? ESCAPE '!' OR i.cup_size LIKE ? ESCAPE '!' OR i.unit LIKE ? ESCAPE '!'";
        $kw = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $params = [$kw, $kw, $kw, $kw];
    }

    $sql .= ' ORDER BY i.name ASC, i.cup_size ASC, i.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function groupInventory(array $inventory) {
    $groups = ['Raw Material' => [], 'Cup' => [], 'Lid' => [], 'Straw' => [], 'Stirrer' => [], 'Others' => []];
    foreach ($inventory as $item) {
        $groups[getItemType($item['name'], $item['material_type'])][] = $item;
    }

    foreach ($groups as &$items) {
        usort($items, function($a, $b) {
            $cmp = strcmp(strtolower($a['name']), strtolower($b['name']));
            return $cmp === 0 ? strcmp((string)$a['cup_size'], (string)$b['cup_size']) : $cmp;
        });
    }
    unset($items);

    return $groups;
}

function filterInventory(array $inventory, string $category = '', string $stockStatus = '', string $materialGroup = '') {
    return array_values(array_filter($inventory, function($item) use ($category, $stockStatus, $materialGroup) {
        if ($materialGroup !== '' && ($item['material_group'] ?? '') !== $materialGroup) return false;
        if ($category !== '' && str_replace(' ', '_', strtolower(getItemType($item['name'], $item['material_type']))) !== $category) {
            return false;
        }

        $qty = (float)$item['qty'];
        $isLow = inventory_needs_restock($item);
        if ($stockStatus === 'low' && !$isLow) {
            return false;
        }
        if ($stockStatus === 'in_stock' && ($qty <= 0 || $isLow)) {
            return false;
        }
        if ($stockStatus === 'out_of_stock' && $qty > 0) {
            return false;
        }
        return true;
    }));
}

function lowStockItems(array $inventory) {
    $low = [];
    foreach ($inventory as $item) {
        if (inventory_needs_restock($item)) $low[] = $item;
    }
    return $low;
}

function unitFromPost() {
    $unitPosted = trim($_POST['unit'] ?? '');
    $unitCustom = trim($_POST['unit_custom'] ?? '');
    return ($unitPosted === 'other' && $unitCustom !== '') ? $unitCustom : $unitPosted;
}

function redirectInventory(string $query = '') {
    header('Location: inventory.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_recipe'])) {
        $postedItemId = $_POST['item_id'] ?? '';
        $recipeItemId = is_scalar($postedItemId) && ctype_digit((string) $postedItemId) ? (int) $postedItemId : 0;
        $postedRecipe = $_POST['requirements'] ?? [];
        try {
            if ($recipeItemId <= 0 || !is_array($postedRecipe)) {
                throw new InvalidArgumentException('Choose a product and size, then enter valid recipe quantities.');
            }
            $pdo->beginTransaction();
            inventory_service_save_recipe($pdo, $recipeItemId, $postedRecipe);
            $pdo->commit();
            $_SESSION['inventory_recipe_notice'] = ['type' => 'success', 'message' => 'Recipe saved. These quantities will be used when POS orders are marked Done.'];
            unset($_SESSION['inventory_recipe_draft']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['inventory_recipe_notice'] = ['type' => 'error', 'message' => $e instanceof InvalidArgumentException ? $e->getMessage() : 'The recipe could not be saved. Reload the page and try again.'];
            $_SESSION['inventory_recipe_draft'] = ['item_id' => $recipeItemId, 'requirements' => is_array($postedRecipe) ? $postedRecipe : []];
        }
        redirectInventory('tab=recipes&recipe_item=' . $recipeItemId . '#inventory-recipes');
    }
    if (isset($_POST['add_inventory'])) {
        try {
            $name = strtoupper(trim($_POST['name'] ?? ''));
            $skuPosted = trim($_POST['sku'] ?? '');
            $materialType = trim($_POST['material_type'] ?? 'raw_material');
            $cupSize = trim($_POST['cup_size'] ?? '');
            $unit = unitFromPost();
            $qty = inventoryStockNumber($_POST['quantity'] ?? null);
            $unitCost = inventoryStockNumber($_POST['unit_cost'] ?? null, true);
            $unitCostSource = $unitCost === null ? null : (string) ($_POST['unit_cost_source'] ?? 'verified');
            if ($unitCostSource !== null && !in_array($unitCostSource, ['estimate', 'verified'], true)) throw new InvalidArgumentException('Choose whether the unit cost is estimated or verified.');
            $critical = inventoryStockNumber($_POST['critical_level'] ?? null, true);
            if ($name === '' || strlen($name) > 100 || $unit === '' || strlen($unit) > 50 || !in_array($materialType, ['raw_material', 'packaging', 'other'], true)) {
                throw new InvalidArgumentException('Provide a valid material name, type, unit, and quantity.');
            }
            $kind = inventory_service_packaging_kind(['name' => $name, 'material_type' => $materialType]);
            $materialGroup = inventory_material_group_from_post($_POST['material_group'] ?? null, $name, $materialType);
            $isSized = in_array($kind, ['cup', 'lid'], true);
            if ($isSized && !in_array($cupSize, ['all', '12oz Hot', '16oz', '22oz'], true)) {
                throw new InvalidArgumentException('Select a cup size for cups and lids.');
            }
            $sizes = $isSized && $cupSize === 'all' ? ['12oz Hot', '16oz', '22oz'] : [$isSized ? $cupSize : null];
            $pdo->beginTransaction();
            foreach ($sizes as $sizeToSave) {
                $sku = inventorySku($skuPosted, $name, $sizeToSave);
                $existing = getInventoryByNameAndSize($pdo, $name, $sizeToSave, $materialType);
                if ($existing) {
                    if (strcasecmp($existing['unit'], $unit) !== 0) {
                        throw new InvalidArgumentException('Restock using the existing unit (' . $existing['unit'] . '). Convert the amount before adding it.');
                    }
                    $newQty = inventoryStockNumber((float) $existing['qty'] + $qty);
                    $pdo->prepare('UPDATE inventory SET qty = ?, unit_cost = COALESCE(?, unit_cost), unit_cost_source = IF(? IS NULL, unit_cost_source, ?), critical_level = ?, material_group = COALESCE(material_group, ?) WHERE id = ?')->execute([$newQty, $unitCost, $unitCostSource, $unitCostSource, $critical, $materialGroup, $existing['id']]);
                    $inventoryId = (int) $existing['id'];
                } else {
                    $pdo->prepare('INSERT INTO inventory (sku, name, material_type, material_group, cup_size, unit, qty, unit_cost, unit_cost_source, critical_level, date_added) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')->execute([$sku, $name, $materialType, $materialGroup, $sizeToSave, $unit, $qty, $unitCost, $unitCostSource, $critical]);
                    $inventoryId = (int) $pdo->lastInsertId();
                    $newQty = $qty;
                }
                $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, 'restock', ?, ?, 'Manual Add', NOW())");
                $log->execute([$inventoryId, (int) ($_SESSION['user']['id'] ?? 0) ?: null, $qty, $newQty]);
                notifications_refresh_low_stock($pdo, $inventoryId);
            }
            $pdo->commit();
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
            redirectInventory('success=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Inventory could not be saved. Check for a duplicate SKU and try again.';
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $message]);
                exit;
            }
            redirectInventory('error=1');
        }
    }
    if (isset($_POST['update_inventory'])) {
        try {
            $id = (int) ($_POST['id'] ?? 0);
            $sku = inventorySku((string) ($_POST['sku'] ?? ''), (string) ($_POST['name'] ?? ''));
            $name = strtoupper(trim($_POST['name'] ?? ''));
            $unit = unitFromPost();
            $materialType = trim($_POST['material_type'] ?? 'raw_material');
            $qty = inventoryStockNumber($_POST['quantity'] ?? null);
            $unitCost = inventoryStockNumber($_POST['unit_cost'] ?? null, true);
            $unitCostSource = $unitCost === null ? null : (string) ($_POST['unit_cost_source'] ?? 'verified');
            if ($unitCostSource !== null && !in_array($unitCostSource, ['estimate', 'verified'], true)) throw new InvalidArgumentException('Choose whether the unit cost is estimated or verified.');
            $critical = inventoryStockNumber($_POST['critical_level'] ?? null, true);
            $kind = inventory_service_packaging_kind(['name' => $name, 'material_type' => $materialType]);
            $cupSize = in_array($kind, ['cup', 'lid'], true) ? trim($_POST['cup_size'] ?? '') : null;
            if ($id <= 0 || $name === '' || strlen($name) > 100 || $unit === '' || strlen($unit) > 50 || !in_array($materialType, ['raw_material', 'packaging', 'other'], true) || ($cupSize !== null && !in_array($cupSize, ['12oz Hot', '16oz', '22oz'], true))) {
                throw new InvalidArgumentException('Invalid inventory details.');
            }
            $pdo->beginTransaction();
            $materialGroup = inventory_material_group_from_post($_POST['material_group'] ?? null, $name, $materialType);
            $beforeStmt = $pdo->prepare('SELECT qty, unit FROM inventory WHERE id = ? FOR UPDATE');
            $beforeStmt->execute([$id]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new InvalidArgumentException('Inventory item not found.');
            if (strcasecmp($before['unit'], $unit) !== 0) {
                $linked = $pdo->prepare('SELECT COUNT(*) FROM item_requirements WHERE inventory_id = ?');
                $linked->execute([$id]);
                if ((int) $linked->fetchColumn() > 0 || (float) $before['qty'] > 0) {
                    $pdo->rollBack();
                    redirectInventory('error=unit_in_use');
                }
            }
            $pdo->prepare('UPDATE inventory SET sku = ?, name = ?, material_type = ?, material_group = ?, cup_size = ?, unit = ?, qty = ?, unit_cost = ?, unit_cost_source = ?, critical_level = ? WHERE id = ?')->execute([$sku, $name, $materialType, $materialGroup, $cupSize, $unit, $qty, $unitCost, $unitCostSource, $critical, $id]);
            $remarks = abs((float) $before['qty'] - $qty) > 0.00001 ? 'Stock count adjusted from ' . formatQty($before['qty']) . ' to ' . formatQty($qty) : 'Inventory metadata updated';
            $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, 'adjustment', ?, ?, ?, NOW())");
            $log->execute([$id, (int) ($_SESSION['user']['id'] ?? 0) ?: null, abs($qty - (float) $before['qty']), $qty, $remarks]);
            notifications_refresh_low_stock($pdo, $id);
            $pdo->commit();
            redirectInventory('updated=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirectInventory('error=1');
        }
    }
    if (isset($_POST['delete_inventory'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $historyStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_logs WHERE inventory_id = ?');
            $historyStmt->execute([$id]);
            if ((int) $historyStmt->fetchColumn() > 0) {
                redirectInventory('error=history');
            }
            $linked = $pdo->prepare('SELECT COUNT(*) FROM item_requirements WHERE inventory_id = ?');
            $linked->execute([$id]);
            if ((int) $linked->fetchColumn() > 0) redirectInventory('error=recipe_in_use');
            $stmt = $pdo->prepare('DELETE FROM inventory WHERE id = ?');
            $stmt->execute([$id]);
        }
        redirectInventory('deleted=1');
    }

    if (isset($_POST['report_damage']) || isset($_POST['report_loss'])) {
        try {
            $lossType = (string) ($_POST['loss_type'] ?? 'damage');
            if (!in_array($lossType, ['damage', 'spoilage'], true)) throw new InvalidArgumentException('Select damage or spoilage.');
            $pdo->beginTransaction();
            inventory_service_adjust_stock($pdo, (int) ($_POST['id'] ?? 0), $_POST['quantity'] ?? null, $lossType, (int) ($_SESSION['user']['id'] ?? 0) ?: null, trim($_POST['remarks'] ?? '') ?: ucfirst($lossType) . ' reported');
            $pdo->commit();
            redirectInventory($lossType . '=1');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirectInventory('error=1');
        }
    }
}

$stockFilters = inventory_stock_filters($_GET);
$search = $stockFilters['search'];
$stockMaterialGroup = $stockFilters['material_group'];
$stockCategory = $stockFilters['stock_category'];
$stockStatus = $stockFilters['stock_status'];
$voidFilters = inventory_void_filters($_GET);
$historyFilters = inventory_history_filters($_GET);
$from = $historyFilters['from'];
$to = $historyFilters['to'];
$logType = $historyFilters['log_type'];
$logSearch = $historyFilters['log_search'];
if (isset($_GET['ajax_inventory']) && $_GET['ajax_inventory'] === '1') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: text/html; charset=UTF-8');
    $summaryRows = fetchInventory($pdo);
    header('X-Inventory-Items: ' . count($summaryRows));
    header('X-Inventory-Raw-Materials: ' . count(array_filter($summaryRows, static fn($row) => $row['material_type'] === 'raw_material')));
    header('X-Inventory-Low-Stock: ' . count(lowStockItems($summaryRows)));
    $stockRows = filterInventory(fetchInventory($pdo, $search), $stockCategory, $stockStatus, $stockMaterialGroup);
    echo inventory_stock_render(inventory_stock_page($stockRows, $stockFilters));
    exit;
}

if (($_GET['ajax_voided'] ?? '') === '1') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Inventory-Voided-Today: ' . inventory_void_today_count($pdo));
    echo inventory_void_render(inventory_void_fetch($pdo, $voidFilters));
    exit;
}
if (($_GET['ajax_logs'] ?? '') === '1') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: text/html; charset=UTF-8');
    echo inventory_history_render(inventory_history_fetch($pdo, $historyFilters));
    exit;
}
if (($_GET['ajax_recipes'] ?? '') === '1') {
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    echo json_encode(inventory_recipe_editor_data($pdo), JSON_THROW_ON_ERROR);
    exit;
}

$recipeEditorData = inventory_recipe_editor_data($pdo);
$recipeEditorData['initial_item_id'] = max(0, (int) ($_GET['recipe_item'] ?? 0));
$recipeNotice = is_array($_SESSION['inventory_recipe_notice'] ?? null) ? $_SESSION['inventory_recipe_notice'] : null;
$recipeDraft = is_array($_SESSION['inventory_recipe_draft'] ?? null) ? $_SESSION['inventory_recipe_draft'] : [];
if ((int) ($recipeDraft['item_id'] ?? 0) === $recipeEditorData['initial_item_id']) {
    $recipeEditorData['draft'] = $recipeDraft['requirements'] ?? null;
}
unset($_SESSION['inventory_recipe_notice'], $_SESSION['inventory_recipe_draft']);

$orderCountStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE() AND status NOT IN ('void', 'voided', 'cancelled')");
$orderCountStmt->execute();
$order_count_today = (int)$orderCountStmt->fetchColumn();

$inventory = filterInventory(fetchInventory($pdo, $search), $stockCategory, $stockStatus, $stockMaterialGroup);
$stockPage = inventory_stock_page($inventory, $stockFilters);
$allInventory = fetchInventory($pdo);
$historyPage = inventory_history_fetch($pdo, $historyFilters);
$low_stock_items = lowStockItems($allInventory);

$required_sizes = ['12oz Hot', '16oz', '22oz'];
$presentCupSizes = [];
$presentLidSizes = [];
foreach ($allInventory as $item) {
    $size = normalizeCupSize($item['cup_size'] ?? null);
    $kind = inventory_service_packaging_kind($item);
    if ($size && $kind === 'cup') {
        $presentCupSizes[$size] = true;
    }
    if ($size && $kind === 'lid') {
        $presentLidSizes[$size] = true;
    }
}

$missing_cup_sizes = [];
$missing_lid_sizes = [];
foreach ($required_sizes as $size) {
    if (empty($presentCupSizes[$size])) {
        $missing_cup_sizes[] = $size;
    }
    if (empty($presentLidSizes[$size])) {
        $missing_lid_sizes[] = $size;
    }
}

$hasStraw = false;
$hasStirrer = false;
foreach ($allInventory as $item) {
    $kind = inventory_service_packaging_kind($item);
    if ($kind === 'straw') {
        $hasStraw = true;
    }
    if ($kind === 'stirrer') {
        $hasStirrer = true;
    }
}
$missing_straw = !$hasStraw;
$missing_stirrer = !$hasStirrer;
$missing_required_count = ($missing_straw ? 1 : 0) + ($missing_stirrer ? 1 : 0) + count($missing_cup_sizes) + count($missing_lid_sizes);

$voidPage = inventory_void_fetch($pdo, $voidFilters);
$voided_count_today = inventory_void_today_count($pdo);

$total_inventory_items = count($allInventory);
$raw_material_count = count(array_filter($allInventory, static fn($item) => $item['material_type'] === 'raw_material'));
$low_stock_count = count($low_stock_items);
$damageOptions = $allInventory;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Manage Inventory</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-inventory-module-inventory">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content inventory-page">
  <section class="inventory-page-heading" aria-labelledby="inventory-title">
    <div>
      <p class="inventory-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Inventory</p>
      <h1 id="inventory-title">Inventory Management</h1>
      <p class="inventory-page-copy">Track raw materials and packaging, set product recipes, and see stock decrease when POS orders are marked Done.</p>
    </div>
    <div class="inventory-heading-actions">
      <a href="#inventory-recipes" data-configure-recipes class="inventory-secondary-action"><i class="fa fa-flask"></i> Configure Recipes</a>
      <a href="reports.php" class="inventory-secondary-action"><i class="fa fa-chart-line"></i> View Reports</a>
      <button type="button" class="inventory-danger-action" onclick="showDamageDropdownModal()"><i class="fa fa-triangle-exclamation"></i> Record Loss</button>
    </div>
  </section>

  <section class="inventory-summary-grid" aria-label="Inventory summary">
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-boxes-stacked"></i></span>
      <span class="summary-label">Inventory Items</span>
      <strong id="inventory-summary-items"><?= number_format($total_inventory_items) ?></strong>
    </article>
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-layer-group"></i></span>
      <span class="summary-label">Raw Materials</span>
      <strong id="inventory-summary-raw-materials"><?= number_format($raw_material_count) ?></strong>
    </article>
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-arrow-trend-down"></i></span>
      <span class="summary-label">Low Stock</span>
      <strong id="inventory-summary-low-stock"><?= number_format($low_stock_count) ?></strong>
    </article>
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-receipt"></i></span>
      <span class="summary-label">Orders Today</span>
      <strong><?= number_format($order_count_today) ?></strong>
    </article>
  </section>

  <?php if(isset($_GET['success'])): ?>
    <div class="inventory-alert success">Inventory added successfully.</div>
  <?php elseif(isset($_GET['damage'])): ?>
    <div class="inventory-alert warning">Damage report recorded successfully.</div>
  <?php elseif(isset($_GET['updated'])): ?>
    <div class="inventory-alert success">Inventory item updated successfully.</div>
  <?php elseif(isset($_GET['deleted'])): ?>
    <div class="inventory-alert success">Inventory item deleted successfully.</div>
  <?php elseif(isset($_GET['error']) && $_GET['error'] === 'straw_hot'): ?>
    <div class="inventory-alert error">For hot coffee (12oz Hot), straw is not deducted from inventory. Only Coffee Stirrer should be used.</div>
  <?php elseif(isset($_GET['error']) && $_GET['error'] === 'voided_order'): ?>
    <div class="inventory-alert error">No inventory deduction for voided/cancelled orders.</div>
  <?php elseif(isset($_GET['error']) && $_GET['error'] === 'history'): ?>
    <div class="inventory-alert error">This inventory item has audit history and cannot be deleted. Set its quantity to zero instead.</div>
  <?php elseif(($_GET['error'] ?? '') === 'unit_in_use'): ?>
    <div class="inventory-alert error">A material with stock or linked recipes must keep its unit. Create a separate inventory record when changing units.</div>
  <?php elseif(($_GET['error'] ?? '') === 'recipe_in_use'): ?>
    <div class="inventory-alert error">This material is used in a product recipe. Update the recipe before deleting it.</div>
  <?php elseif(isset($_GET['error'])): ?>
    <div class="inventory-alert error">Invalid input or insufficient stock.</div>
  <?php elseif(isset($_GET['used'])): ?>
    <div class="inventory-alert success">Materials deducted from inventory.</div>
  <?php endif; ?>

  <?php if($missing_required_count > 0): ?>
    <div class="inventory-alert error">
      <strong>Missing Required Inventory Items</strong>
      <ul>
        <?php if($missing_straw): ?><li>Straw is required for cold drinks.</li><?php endif; ?>
        <?php if($missing_stirrer): ?><li>Coffee Stirrer is required for hot drinks.</li><?php endif; ?>
        <?php if(!empty($missing_cup_sizes)): ?><li>Missing cup sizes: <?= htmlspecialchars(implode(', ', $missing_cup_sizes)) ?></li><?php endif; ?>
        <?php if(!empty($missing_lid_sizes)): ?><li>Missing lid sizes: <?= htmlspecialchars(implode(', ', $missing_lid_sizes)) ?></li><?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="inventory-tabs" data-inventory-tabs>
    <div class="inventory-tab-list" role="tablist" aria-label="Inventory sections">
      <button type="button" class="inventory-tab" id="inventory-tab-stock" role="tab" aria-controls="inventory-panel-stock" aria-selected="true" tabindex="0" data-tab="stock">
        <i class="fa fa-boxes-stacked" aria-hidden="true"></i><span>Current Stock</span>
      </button>
      <button type="button" class="inventory-tab" id="inventory-tab-recipes" role="tab" aria-controls="inventory-panel-recipes" aria-selected="false" tabindex="-1" data-tab="recipes">
        <i class="fa fa-flask" aria-hidden="true"></i><span>Configure Recipes</span>
      </button>
      <button type="button" class="inventory-tab" id="inventory-tab-logs" role="tab" aria-controls="inventory-panel-logs" aria-selected="false" tabindex="-1" data-tab="logs">
        <i class="fa fa-clock-rotate-left" aria-hidden="true"></i><span>Movement History</span>
      </button>
      <button type="button" class="inventory-tab" id="inventory-tab-voided" role="tab" aria-controls="inventory-panel-voided" aria-selected="false" tabindex="-1" data-tab="voided">
        <i class="fa fa-receipt" aria-hidden="true"></i><span>Voided / Cancelled</span>
        <span class="inventory-tab-count" id="inventory-voided-today" title="Voided / cancelled today" aria-label="Voided and cancelled orders today"><?= number_format($voided_count_today) ?></span>
      </button>
    </div>
    <section class="inventory-tab-panel" id="inventory-panel-stock" role="tabpanel" aria-labelledby="inventory-tab-stock" data-tab-panel="stock">
      <section class="inventory-toolbar">
        <form method="get" class="inventory-search-form inventory-list-filters" id="inventoryStockFilters">
          <input type="hidden" name="tab" value="stock">
          <label class="inventory-list-search" for="inventory-search"><span>Search Inventory</span>
          <input id="inventory-search" type="search" name="search" placeholder="Search SKU or item..." value="<?= htmlspecialchars($search) ?>" maxlength="160" aria-controls="inventoryRealtime"></label>
          <label for="stock_material_group"><span>Material Group</span>
          <select id="stock_material_group" name="material_group">
            <option value="">All material groups</option>
            <?php foreach (inventory_material_groups() as $groupKey => $groupLabel): ?>
              <option value="<?= htmlspecialchars($groupKey) ?>" <?= $stockMaterialGroup === $groupKey ? 'selected' : '' ?>><?= htmlspecialchars($groupLabel) ?></option>
            <?php endforeach; ?>
          </select></label>
          <label for="stock_category"><span>Type</span>
          <select id="stock_category" name="stock_category">
            <option value="" <?= $stockCategory === '' ? 'selected' : '' ?>>All types</option>
            <option value="raw_material" <?= $stockCategory === 'raw_material' ? 'selected' : '' ?>>Raw Materials</option>
            <option value="stirrer" <?= $stockCategory === 'stirrer' ? 'selected' : '' ?>>Stirrer</option>
            <option value="straw" <?= $stockCategory === 'straw' ? 'selected' : '' ?>>Straw</option>
            <option value="cup" <?= $stockCategory === 'cup' ? 'selected' : '' ?>>Cup</option>
            <option value="lid" <?= $stockCategory === 'lid' ? 'selected' : '' ?>>Lid</option>
            <option value="others" <?= $stockCategory === 'others' ? 'selected' : '' ?>>Others</option>
          </select></label>
          <label for="stock_status"><span>Stock</span>
          <select id="stock_status" name="stock_status">
            <option value="" <?= $stockStatus === '' ? 'selected' : '' ?>>All stock</option>
            <option value="low" <?= $stockStatus === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In stock</option>
            <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of stock</option>
          </select></label>
          <div class="inventory-history-filter-actions">
            <button type="submit" class="inventory-filter-btn"><i class="fa fa-filter" aria-hidden="true"></i> Filter</button>
            <a href="inventory.php?tab=stock" class="inventory-reset-link">Reset</a>
          </div>
        </form>
        <button type="button" class="inventory-primary-action" onclick="openAddInventoryModal()" aria-haspopup="dialog" aria-controls="addInventoryModal">
          <i class="fa fa-plus"></i> Add Inventory
        </button>
      </section>
      <p id="inventoryStockStatus" class="inventory-history-status" role="status" hidden></p>
      <section class="inventory-table-panel" id="inventoryRealtime">
        <?= inventory_stock_render($stockPage) ?>
      </section>
      <dialog id="inventoryStockDetails" class="inventory-movement-dialog inventory-details-dialog" aria-labelledby="inventoryStockTitle">
        <header class="inventory-details-header">
          <div><span>Stock Setup</span><h2 id="inventoryStockTitle" data-list-field="title">Stock Details</h2></div>
          <button type="button" class="inventory-details-close" data-close-list-details autofocus>Close</button>
        </header>
        <dl class="inventory-details-grid">
          <div><dt>SKU</dt><dd data-list-field="sku"></dd></div>
          <div><dt>Type</dt><dd data-list-field="type"></dd></div>
          <div><dt>Material Group</dt><dd data-list-field="group"></dd></div>
          <div><dt>Cup Size</dt><dd data-list-field="size"></dd></div>
          <div><dt>Current Stock</dt><dd data-list-field="quantity"></dd></div>
          <div><dt>Unit</dt><dd data-list-field="unit"></dd></div>
          <div><dt>Cost per Unit</dt><dd data-list-field="unit_cost"></dd></div>
          <div><dt>Status</dt><dd data-list-field="status"></dd></div>
          <div><dt>Critical Level</dt><dd data-list-field="critical"></dd></div>
          <div><dt>Restock Threshold</dt><dd data-list-field="reorder_threshold"></dd></div>
          <div><dt>Threshold Basis</dt><dd data-list-field="threshold_source"></dd></div>
          <div><dt>Date Added</dt><dd data-list-field="added"></dd></div>
          <div><dt>Last Restocked</dt><dd data-list-field="restocked"></dd></div>
        </dl>
      </dialog>
    </section>
    <?php include dirname(__DIR__) . '/partials/inventory_recipes.php'; ?>
    <section class="inventory-tab-panel" id="inventory-panel-logs" role="tabpanel" aria-labelledby="inventory-tab-logs" data-tab-panel="logs" hidden>
      <section class="inventory-logs-panel">
        <div class="inventory-panel-header">
          <div><span class="panel-eyebrow">Inventory Activity</span><h2>Stock Movements</h2><small>Review usage, restocks, damage, and spoilage by date.</small></div>
          <span class="inventory-updated">Last updated: <span id="logsUpdatedAt">-</span></span>
        </div>
        <form method="get" class="inventory-filter-form inventory-history-filters" id="inventoryHistoryFilters">
          <input type="hidden" name="tab" value="logs">
          <label class="inventory-history-item-field" for="log_search"><span>Item</span>
            <input type="search" id="log_search" name="log_search" list="inventoryHistoryItems" value="<?= htmlspecialchars($logSearch, ENT_QUOTES) ?>" placeholder="All items / search name or SKU" autocomplete="off" maxlength="160" aria-controls="logsRealtime">
          </label>
          <datalist id="inventoryHistoryItems">
            <?php foreach ($allInventory as $historyItem): ?>
              <option value="<?= htmlspecialchars(inventory_history_item_label($historyItem), ENT_QUOTES) ?>"><?= htmlspecialchars($historyItem['sku']) ?></option>
            <?php endforeach; ?>
          </datalist>
          <label for="from"><span>From</span><input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>"></label>
          <label for="to"><span>To</span><input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>"></label>
          <label for="log_type"><span>Movement Type</span>
            <select id="log_type" name="log_type">
              <option value="" <?= $logType === '' ? 'selected' : '' ?>>All types</option>
              <option value="added" <?= $logType === 'added' ? 'selected' : '' ?>>Added</option>
              <option value="restock" <?= $logType === 'restock' ? 'selected' : '' ?>>Restock</option>
              <option value="used" <?= $logType === 'used' ? 'selected' : '' ?>>Used</option>
              <option value="damage" <?= $logType === 'damage' ? 'selected' : '' ?>>Damage</option>
              <option value="spoilage" <?= $logType === 'spoilage' ? 'selected' : '' ?>>Spoilage</option>
              <option value="adjustment" <?= $logType === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
            </select>
          </label>
          <div class="inventory-history-filter-actions">
            <button type="submit" class="inventory-filter-btn"><i class="fa fa-filter" aria-hidden="true"></i> Filter</button>
            <a href="inventory.php?tab=logs" class="inventory-reset-link">Reset</a>
          </div>
        </form>
        <p id="inventoryHistoryStatus" class="inventory-history-status" role="status" hidden></p>
        <div id="logsRealtime"><?= inventory_history_render($historyPage) ?></div>
        <dialog id="inventoryMovementDetails" class="inventory-movement-dialog" aria-labelledby="inventoryMovementTitle">
          <header>
            <h3 id="inventoryMovementTitle" data-log-field="title">Movement Details</h3>
            <button type="button" class="inventory-cancel-btn" data-close-log-details autofocus>Close</button>
          </header>
          <dl>
            <div><dt>Item</dt><dd data-log-field="item"></dd></div>
            <div><dt>SKU</dt><dd data-log-field="sku"></dd></div>
            <div><dt>Type</dt><dd data-log-field="type"></dd></div>
            <div><dt>Quantity</dt><dd data-log-field="quantity"></dd></div>
            <div><dt>Cost per Unit</dt><dd data-log-field="loss_unit_cost"></dd></div>
            <div><dt>Recorded Loss Value</dt><dd data-log-field="loss_value"></dd></div>
            <div><dt>Stock When Recorded</dt><dd data-log-field="balance"></dd></div>
            <div><dt>Current Stock</dt><dd data-log-field="current_stock"></dd></div>
            <div><dt>Critical Level</dt><dd data-log-field="critical_level"></dd></div>
            <div><dt>Sale / Movement Date</dt><dd data-log-field="movement_at"></dd></div>
            <div><dt>Recorded On</dt><dd data-log-field="recorded_at"></dd></div>
            <div><dt>Order Reference</dt><dd data-log-field="order_reference"></dd></div>
            <div class="inventory-movement-remarks"><dt>Remarks</dt><dd data-log-field="remarks"></dd></div>
          </dl>
        </dialog>
      </section>
    </section>
    <section class="inventory-tab-panel" id="inventory-panel-voided" role="tabpanel" aria-labelledby="inventory-tab-voided" data-tab-panel="voided" hidden>
      <section class="inventory-void-panel">
        <div class="inventory-panel-header">
          <div><span class="panel-eyebrow">Sales Exceptions</span><h2>Voided / Cancelled Orders</h2></div>
          <span class="inventory-updated">Last updated: <span id="voidsUpdatedAt"><?= date('h:i:s A') ?></span></span>
        </div>
        <form method="get" class="inventory-filter-form inventory-list-filters" id="inventoryVoidFilters">
          <input type="hidden" name="tab" value="voided">
          <label class="inventory-list-search" for="void_search"><span>Search Orders</span><input type="search" id="void_search" name="void_search" value="<?= htmlspecialchars($voidFilters['void_search'], ENT_QUOTES) ?>" placeholder="Order number, customer or user..." maxlength="160" aria-controls="voidsRealtime"></label>
          <label for="void_from"><span>From</span><input type="date" id="void_from" name="void_from" value="<?= htmlspecialchars($voidFilters['void_from']) ?>"></label>
          <label for="void_to"><span>To</span><input type="date" id="void_to" name="void_to" value="<?= htmlspecialchars($voidFilters['void_to']) ?>"></label>
          <label for="void_status"><span>Status</span><select id="void_status" name="void_status">
            <option value="">All statuses</option>
            <option value="voided" <?= $voidFilters['void_status'] === 'voided' ? 'selected' : '' ?>>Voided</option>
            <option value="cancelled" <?= $voidFilters['void_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
          </select></label>
          <div class="inventory-history-filter-actions"><button type="submit" class="inventory-filter-btn"><i class="fa fa-filter" aria-hidden="true"></i> Filter</button><a href="inventory.php?tab=voided" class="inventory-reset-link">Reset</a></div>
        </form>
        <p class="inventory-list-hint">Defaults to today. Change the dates to review earlier orders. The tab badge counts today's orders.</p>
        <p id="inventoryVoidStatus" class="inventory-history-status" role="status" hidden></p>
        <div id="voidsRealtime"><?= inventory_void_render($voidPage) ?></div>
        <dialog id="inventoryVoidDetails" class="inventory-movement-dialog" aria-labelledby="inventoryVoidTitle">
          <header><h3 id="inventoryVoidTitle" data-list-field="title">Order Details</h3><button type="button" class="inventory-cancel-btn" data-close-list-details autofocus>Close</button></header>
          <dl>
            <div><dt>Order ID</dt><dd data-list-field="id"></dd></div>
            <div><dt>Customer</dt><dd data-list-field="customer"></dd></div>
            <div><dt>Created By</dt><dd data-list-field="user"></dd></div>
            <div><dt>Total</dt><dd data-list-field="total"></dd></div>
            <div><dt>Status</dt><dd data-list-field="status"></dd></div>
            <div><dt>Voided / Cancelled At</dt><dd data-list-field="event"></dd></div>
            <div><dt>Order Created At</dt><dd data-list-field="created"></dd></div>
            <div class="inventory-movement-remarks"><dt>Service Details</dt><dd data-list-field="service"></dd></div>
          </dl>
        </dialog>
      </section>
    </section>
  </div>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<div id="addInventoryModal" class="inventory-modal inventory-add-modal" aria-hidden="true">
  <section class="inventory-add-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="add-inventory-modal-title">
    <header class="inventory-add-modal-header">
      <div>
        <span>Stock Setup</span>
        <h2 id="add-inventory-modal-title">Add Inventory</h2>
      </div>
    </header>

    <form method="post" class="inventory-add-modal-form" id="addInventoryForm">
      <input type="hidden" name="add_inventory" value="1">

      <div class="form-group">
        <label for="material_type_field"><i class="fa fa-leaf"></i> Inventory Type</label>
        <select id="material_type_field" name="material_type" onchange="updateInventoryType()" required>
          <option value="" selected disabled>Select inventory type</option>
          <option value="raw_material">Raw Material / Ingredient</option>
          <option value="packaging">Packaging / Consumables</option>
          <option value="other">Other Supply</option>
        </select>
      </div>
      <div class="form-group">
        <label for="material_group_field"><i class="fa fa-layer-group"></i> Material Group</label>
        <select id="material_group_field" name="material_group">
          <option value="">Automatic / Other</option>
          <?php foreach (inventory_material_groups() as $groupKey => $groupLabel): ?>
            <option value="<?= htmlspecialchars($groupKey) ?>"><?= htmlspecialchars($groupLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="item_name"><i class="fa fa-box"></i> Item Name</label>
        <input id="item_name" type="text" name="name" placeholder="e.g. COFFEE BEANS or CUPS" list="inventory-suggestions" maxlength="100" required oninput="toggleCupSizeField()" autocomplete="off">
      </div>

      <datalist id="inventory-suggestions">
        <?php foreach (array_unique(array_column(inventory_material_catalog(), 'name')) as $catalogName): ?>
          <option value="<?= htmlspecialchars($catalogName) ?>">
        <?php endforeach; ?>
      </datalist>
      <div class="form-group">
        <label for="inventory_sku"><i class="fa fa-barcode"></i> SKU</label>
        <input id="inventory_sku" type="text" name="sku" maxlength="64" placeholder="Auto-generated if blank" autocomplete="off">
      </div>

      <div class="form-group cup-size-group">
        <label for="cup_size_field"><i class="fa fa-mug-hot"></i> Cup Size</label>
        <select id="cup_size_field" name="cup_size">
          <option value="">Select Size</option>
          <option value="all">All Sizes</option>
          <option value="12oz Hot">12oz Hot</option>
          <option value="16oz">16oz</option>
          <option value="22oz">22oz</option>
        </select>
      </div>

      <div class="form-group">
        <label for="unit_field"><i class="fa fa-ruler"></i> Unit</label>
        <select id="unit_field" name="unit" required>
          <option value="" selected disabled>Select unit</option>
          <option value="pcs">pcs</option>
          <option value="g">g</option>
          <option value="ml">ml</option>
          <option value="pack">pack</option>
          <option value="box">box</option>
          <option value="set">set</option>
          <option value="sachet">sachet</option>
          <option value="tray">tray</option>
          <option value="bottle">bottle</option>
          <option value="other">Other</option>
        </select>
      </div>

      <div class="form-group custom-unit-group">
        <label for="unit_custom"><i class="fa fa-pen"></i> Custom Unit</label>
        <input id="unit_custom" type="text" name="unit_custom" placeholder="Custom Unit" autocomplete="off">
      </div>

      <div class="form-group">
        <label for="quantity"><i class="fa fa-layer-group"></i> Quantity</label>
        <input id="quantity" type="number" step="0.01" name="quantity" min="0" max="99999999.99" required placeholder="0">
      </div>

      <div class="form-group">
        <label for="unit_cost"><i class="fa fa-peso-sign"></i> Cost per Unit (PHP) <span>Optional</span></label>
        <input id="unit_cost" type="number" step="0.01" name="unit_cost" min="0" max="99999999.99" placeholder="e.g. 0.50 per g">
      </div>
      <div class="form-group">
        <label for="unit_cost_source">Cost Basis</label>
        <select id="unit_cost_source" name="unit_cost_source"><option value="verified">Verified purchase cost</option><option value="estimate">Estimate</option></select>
      </div>

      <div class="form-group">
        <label for="critical_level"><i class="fa fa-triangle-exclamation"></i> Critical Level <span>Optional</span></label>
        <input id="critical_level" type="number" step="0.01" name="critical_level" min="0" placeholder="0">
        <small>Leave blank to use the 14-day usage forecast. A value here overrides the forecast threshold.</small>
      </div>

      <div class="inventory-add-modal-actions">
        <button type="button" class="inventory-cancel-btn" onclick="closeAddInventoryModal()">Cancel</button>
        <button type="submit" class="inventory-submit-btn"><i class="fa fa-plus"></i> Add Inventory</button>
      </div>
    </form>
  </section>
</div>

<div id="damageDropdownModal" class="inventory-modal" aria-hidden="true">
  <div class="inventory-modal-card">
    <h3>Select Stock Item</h3>
    <select id="damage_item_select">
      <?php foreach ($damageOptions as $item): ?>
        <?php $label = $item['name'] . (normalizeCupSize($item['cup_size'] ?? null) ? ' (' . normalizeCupSize($item['cup_size']) . ')' : ''); ?>
        <option value="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($label, ENT_QUOTES) ?>" data-unit="<?= htmlspecialchars($item['unit'], ENT_QUOTES) ?>" data-qty="<?= htmlspecialchars($item['qty'], ENT_QUOTES) ?>" data-cost="<?= htmlspecialchars($item['unit_cost'] ?? '', ENT_QUOTES) ?>" data-cost-source="<?= htmlspecialchars($item['unit_cost_source'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="inventory-modal-actions">
      <button type="button" class="inventory-cancel-btn" onclick="closeDamageDropdownModal()">Cancel</button>
      <button type="button" class="inventory-submit-btn" onclick="selectDamageItem()">Next</button>
    </div>
  </div>
</div>

<div id="damageModal" class="inventory-modal" aria-hidden="true">
  <div class="inventory-modal-card">
    <h3>Loss Details</h3>
    <form method="post" class="inventory-modal-form">
      <input type="hidden" name="report_loss" value="1">
      <input type="hidden" name="id" id="damage_id">
      <label for="damage_name">Item Name</label>
      <input type="text" id="damage_name" readonly>
      <label for="loss_type">Reason</label>
      <select id="loss_type" name="loss_type" required>
        <option value="damage">Damage</option>
        <option value="spoilage">Spoilage / expired</option>
      </select>
      <label for="damage_quantity">Quantity Affected</label>
      <input type="number" id="damage_quantity" step="0.01" name="quantity" min="0.01" required oninput="updateLossEstimate()">
      <label for="damage_unit_cost">Cost per Unit (PHP)</label>
      <input type="text" id="damage_unit_cost" readonly>
      <p id="damage_cost_source" class="inventory-cost-source"></p>
      <p id="loss_estimate" role="status">Estimated loss: PHP 0.00</p>
      <label for="damage_remarks">Remarks / Description</label>
      <textarea id="damage_remarks" name="remarks" rows="3" maxlength="255" required placeholder="e.g. expired, spilled, broken during handling"></textarea>
      <div class="inventory-modal-actions">
        <button type="button" class="inventory-cancel-btn" onclick="closeDamageReport()">Cancel</button>
        <button type="submit" class="inventory-danger-action">Submit</button>
      </div>
    </form>
  </div>
</div>

<div id="editInventoryModal" class="inventory-modal inventory-add-modal inventory-edit-modal" aria-hidden="true">
  <section class="inventory-add-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-inventory-modal-title">
    <header class="inventory-add-modal-header">
      <div>
        <span>Stock Setup</span>
        <h2 id="edit-inventory-modal-title">Edit Inventory</h2>
      </div>
    </header>

    <form method="post" class="inventory-add-modal-form" id="editInventoryForm">
      <input type="hidden" name="update_inventory" value="1">
      <input type="hidden" name="id" id="edit_id">

      <div class="form-group">
        <label for="edit_material_type"><i class="fa fa-leaf"></i> Inventory Type</label>
        <select id="edit_material_type" name="material_type" required onchange="updateEditInventoryType()">
          <option value="raw_material">Raw Material / Ingredient</option>
          <option value="packaging">Packaging / Consumables</option>
          <option value="other">Other Supply</option>
        </select>
      </div>
      <div class="form-group">
        <label for="edit_material_group"><i class="fa fa-layer-group"></i> Material Group</label>
        <select id="edit_material_group" name="material_group">
          <option value="">Automatic / Other</option>
          <?php foreach (inventory_material_groups() as $groupKey => $groupLabel): ?>
            <option value="<?= htmlspecialchars($groupKey) ?>"><?= htmlspecialchars($groupLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="edit_name"><i class="fa fa-box"></i> Item Name</label>
        <input type="text" id="edit_name" name="name" maxlength="100" list="inventory-suggestions" required autocomplete="off" oninput="toggleEditCupSizeField()">
      </div>
      <div class="form-group">
        <label for="edit_sku"><i class="fa fa-barcode"></i> SKU</label>
        <input type="text" id="edit_sku" name="sku" maxlength="64" autocomplete="off">
      </div>
      <div class="form-group cup-size-group" id="editCupSizeGroup">
        <label for="edit_cup_size"><i class="fa fa-mug-hot"></i> Cup Size</label>
        <select id="edit_cup_size" name="cup_size">
          <option value="">Select Size</option>
          <option value="12oz Hot">12oz Hot</option>
          <option value="16oz">16oz</option>
          <option value="22oz">22oz</option>
        </select>
      </div>
      <div class="form-group">
        <label for="edit_unit_field"><i class="fa fa-ruler"></i> Unit</label>
        <select id="edit_unit_field" name="unit" required onchange="toggleEditCustomUnit()">
          <option value="pcs">pcs</option>
          <option value="g">g</option>
          <option value="ml">ml</option>
          <option value="pack">pack</option>
          <option value="box">box</option>
          <option value="set">set</option>
          <option value="sachet">sachet</option>
          <option value="tray">tray</option>
          <option value="bottle">bottle</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div class="form-group custom-unit-group" id="editCustomUnitGroup">
        <label for="edit_unit_custom"><i class="fa fa-pen"></i> Custom Unit</label>
        <input id="edit_unit_custom" type="text" name="unit_custom" placeholder="Custom Unit" autocomplete="off">
      </div>
      <div class="form-group">
        <label for="edit_quantity"><i class="fa fa-layer-group"></i> Quantity</label>
        <input type="number" id="edit_quantity" name="quantity" step="0.01" min="0" max="99999999.99" required placeholder="0">
      </div>
      <div class="form-group">
        <label for="edit_unit_cost"><i class="fa fa-peso-sign"></i> Cost per Unit (PHP) <span>Optional</span></label>
        <input type="number" id="edit_unit_cost" name="unit_cost" step="0.01" min="0" max="99999999.99" placeholder="e.g. 0.50 per g">
      </div>
      <div class="form-group">
        <label for="edit_unit_cost_source">Cost Basis</label>
        <select id="edit_unit_cost_source" name="unit_cost_source"><option value="verified">Verified purchase cost</option><option value="estimate">Estimate</option></select>
      </div>
      <div class="form-group">
        <label for="edit_critical_level"><i class="fa fa-triangle-exclamation"></i> Critical Level <span>Optional</span></label>
        <input type="number" id="edit_critical_level" name="critical_level" step="0.01" min="0" placeholder="0">
        <small>Leave blank to use the 14-day usage forecast. A value here overrides the forecast threshold.</small>
      </div>
      <div class="inventory-add-modal-actions">
        <button type="button" class="inventory-cancel-btn" onclick="closeEditInventory()">Cancel</button>
        <button type="submit" class="inventory-submit-btn"><i class="fa fa-floppy-disk"></i> Save Changes</button>
      </div>
    </form>
  </section>
</div>

<script>
function qs(selector) {
  return document.querySelector(selector);
}

function setModal(id, open) {
  const modal = document.getElementById(id);
  if (!modal) return;
  modal.classList.toggle('is-open', open);
  modal.setAttribute('aria-hidden', open ? 'false' : 'true');
  document.documentElement.classList.toggle('inventory-modal-lock', Boolean(document.querySelector('.inventory-modal.is-open')));
}

function openAddInventoryModal() {
  toggleCupSizeField();
  setModal('addInventoryModal', true);
  window.setTimeout(function () {
    qs('#material_type_field')?.focus();
  }, 0);
}

function updateInventoryType() {
  const type = qs('#material_type_field')?.value || '';
  const unit = qs('#unit_field');
  const item = qs('#item_name');
  const materialGroup = qs('#material_group_field');
  if (materialGroup) Array.from(materialGroup.options).forEach(option => { option.disabled = type === 'packaging' ? Boolean(option.value && option.value !== 'packaging') : option.value === 'packaging'; });
  if (materialGroup) {
    if (type === 'packaging') materialGroup.value = 'packaging';
    else if (materialGroup.value === 'packaging') materialGroup.value = '';
  }
  if (unit) {
    unit.value = type === 'raw_material' ? 'g' : (type ? 'pcs' : '');
    unit.dispatchEvent(new Event('change'));
  }
  if (item) {
    item.placeholder = type === 'raw_material' ? 'e.g. COFFEE BEANS or FRESH MILK'
      : type === 'packaging' ? 'e.g. CUP, LID or STRAW'
      : type === 'other' ? 'e.g. NAPKINS or CLEANING SUPPLIES'
      : 'e.g. COFFEE BEANS or CUPS';
  }
  toggleCupSizeField();
}
function closeAddInventoryModal() {
  setModal('addInventoryModal', false);
}

function toggleCupSizeField() {
  const item = qs('#item_name');
  if (item) item.value = (item.value || '').toUpperCase();
  const name = (item?.value || '').toLowerCase();
  const group = qs('.cup-size-group');
  const cupSize = qs('#cup_size_field');
  const show = qs('#material_type_field')?.value === 'packaging' && /\b(cups?|lids?|covers?)\b/.test(name) && !/\bcup\s+sleeves?\b/.test(name);
  const hide = name.includes('straw') || name.includes('stirrer');
  if (group) group.classList.toggle('is-visible', show && !hide);
  if (cupSize) {
    cupSize.required = show && !hide;
    if (!show || hide) cupSize.value = '';
  }
}

function updateEditInventoryType() {
  const type = qs('#edit_material_type')?.value || '';
  const group = qs('#edit_material_group');
  if (group) Array.from(group.options).forEach(option => { option.disabled = type === 'packaging' ? Boolean(option.value && option.value !== 'packaging') : option.value === 'packaging'; });
  if (group) {
    if (type === 'packaging') group.value = 'packaging';
    else if (group.value === 'packaging') group.value = '';
  }
  toggleEditCupSizeField();
}

function toggleEditCupSizeField() {
  const item = qs('#edit_name');
  if (item) item.value = (item.value || '').toUpperCase();
  const name = (item?.value || '').toLowerCase();
  const type = qs('#edit_material_type')?.value || '';
  const group = qs('#editCupSizeGroup');
  const cupSize = qs('#edit_cup_size');
  const show = type === 'packaging' && /\b(cups?|lids?|covers?)\b/.test(name) && !/\bcup\s+sleeves?\b/.test(name);
  const hide = name.includes('straw') || name.includes('stirrer');
  if (group) group.classList.toggle('is-visible', show && !hide);
  if (cupSize) cupSize.required = show && !hide;
  if ((!show || hide) && cupSize) cupSize.value = '';
}

function toggleEditCustomUnit() {
  const isCustom = qs('#edit_unit_field')?.value === 'other';
  qs('#editCustomUnitGroup')?.classList.toggle('is-visible', isCustom);
  const customUnit = qs('#edit_unit_custom');
  if (customUnit) customUnit.required = isCustom;
}

function showDamageDropdownModal() {
  setModal('damageDropdownModal', true);
}

function closeDamageDropdownModal() {
  setModal('damageDropdownModal', false);
}

function selectDamageItem() {
  const select = qs('#damage_item_select');
  if (!select || !select.value) return;
  qs('#damage_id').value = select.value;
  qs('#damage_name').value = select.selectedOptions[0].dataset.name || select.selectedOptions[0].textContent;
  const option = select.selectedOptions[0];
  const quantity = qs('#damage_quantity');
  quantity.max = option.dataset.qty || '';
  quantity.value = '';
  const costInput = qs('#damage_unit_cost');
  costInput.value = option.dataset.cost ? 'PHP ' + Number(option.dataset.cost).toFixed(2) + ' per ' + (option.dataset.unit || '') : '';
  costInput.dataset.unit = option.dataset.unit || '';
  costInput.placeholder = option.dataset.cost ? 'Saved cost' : 'Not set; value cannot be calculated';
  qs('#damage_cost_source').textContent = option.dataset.cost ? 'Cost basis: ' + (option.dataset.costSource === 'estimate' ? 'Estimate' : 'Verified purchase cost') : 'Cost per unit is not set.';
  qs('#loss_estimate').textContent = option.dataset.cost ? 'Estimated loss: PHP 0.00' : 'Estimated loss unavailable. Add a cost per unit by editing this inventory item.';
  closeDamageDropdownModal();
  setModal('damageModal', true);
}

function updateLossEstimate() {
  const qty = Number(qs('#damage_quantity')?.value || 0);
  const costValue = qs('#damage_unit_cost')?.value;
  const unit = qs('#damage_unit_cost')?.dataset.unit || '';
  const estimate = qs('#loss_estimate');
  if (!estimate) return;
  const numericCost = Number((costValue || '').replace(/^PHP\s+/, '').split(' per ')[0]);
  if (costValue === '' || costValue === undefined || !Number.isFinite(numericCost)) {
    estimate.textContent = 'Estimated loss unavailable. Add a cost per unit by editing this inventory item.';
    return;
  }
  estimate.textContent = 'Estimated loss: PHP ' + (qty * numericCost).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (unit ? ' (' + qty + ' ' + unit + ')' : '');
}

function closeDamageReport() {
  setModal('damageModal', false);
}

function openEditInventory(data) {
  qs('#edit_id').value = data.id || '';
  qs('#edit_sku').value = data.sku || '';
  qs('#edit_name').value = data.name || '';
  qs('#edit_cup_size').value = data.cup_size || '';
  qs('#edit_material_type').value = data.material_type || 'raw_material';
  qs('#edit_material_group').value = data.material_group || '';
  const knownUnits = ['pcs', 'g', 'ml', 'pack', 'box', 'set', 'sachet', 'tray', 'bottle'];
  const unitValue = (data.unit || '').toString();
  qs('#edit_unit_field').value = knownUnits.includes(unitValue) ? unitValue : 'other';
  qs('#edit_unit_custom').value = knownUnits.includes(unitValue) ? '' : unitValue;
  qs('#edit_quantity').value = data.qty || '';
  qs('#edit_unit_cost').value = data.unit_cost || '';
  qs('#edit_unit_cost_source').value = data.unit_cost_source || 'verified';
  qs('#edit_critical_level').value = data.critical_level || '';
  updateEditInventoryType();
  toggleEditCustomUnit();
  setModal('editInventoryModal', true);
  window.setTimeout(function () {
    qs('#edit_name')?.focus();
  }, 0);
}

function closeEditInventory() {
  setModal('editInventoryModal', false);
}

document.addEventListener('change', function(event) {
  if (event.target?.id === 'edit_material_type') {
    const type = event.target.value;
    const group = qs('#edit_material_group');
    if (type === 'packaging') group.value = 'packaging';
    else if (group.value === 'packaging') group.value = '';
  }
  if (event.target?.id === 'unit_field') {
    document.body.classList.toggle('show-custom-unit', event.target.value === 'other');
  }

});

document.addEventListener('click', function(event) {
  if (event.target?.id === 'addInventoryModal') {
    closeAddInventoryModal();
    return;
  }
  if (event.target?.id === 'editInventoryModal') {
    closeEditInventory();
    return;
  }

  const editButton = event.target.closest('.js-edit-inventory');
  if (editButton) {
    openEditInventory(JSON.parse(editButton.dataset.inventory || '{}'));
  }
});

document.addEventListener('keydown', function(event) {
  if (event.key === 'Escape' && qs('#addInventoryModal')?.classList.contains('is-open')) {
    event.preventDefault();
    closeAddInventoryModal();
  } else if (event.key === 'Escape' && qs('#editInventoryModal')?.classList.contains('is-open')) {
    event.preventDefault();
    closeEditInventory();
  }
});

document.addEventListener('submit', async function(event) {
  const form = event.target;
  if (form?.id !== 'addInventoryForm') return;
  event.preventDefault();

  const formData = new FormData(form);
  const item = (formData.get('name') || '').toString().toUpperCase();
  const unit = formData.get('unit') === 'other' ? (formData.get('unit_custom') || '') : (formData.get('unit') || '');
  const size = formData.get('cup_size') || '';
  const inventoryType = qs('#material_type_field')?.selectedOptions[0]?.textContent || 'Inventory';
  const qty = formData.get('quantity') || '0';
  const critical = formData.get('critical_level') || 'N/A';
  const confirmed = await window.kapeConfirm({
    title: 'Add Inventory',
    message: 'Add ' + qty + ' ' + unit + ' of ' + item + ' as ' + inventoryType + (size ? ' (cup size: ' + size + ')' : '') + ' with critical level ' + critical + '?',
    type: 'warning',
    okLabel: 'Add Inventory'
  });
  if (!confirmed) return;

  const payload = new URLSearchParams(formData);
  payload.append('ajax', '1');
  const response = await fetch('inventory.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: payload
  });
  const result = await response.json().catch(() => ({ success: false, error: 'Failed to add inventory.' }));
  if (result.success) {
    qs('#addInventoryForm')?.reset();
    updateInventoryType();
    await refreshInventory();
    await refreshLogs();
    await window.refreshInventoryRecipes?.();
    closeAddInventoryModal();
    window.kapeNotify('Inventory added successfully.', 'success');
  } else {
    window.kapeNotify(result.error || 'Failed to add inventory.', 'error');
  }
});

function setInventoryTab(tabName, updateUrl) {
  const root = qs('[data-inventory-tabs]');
  if (!root) return;
  const tabs = Array.from(root.querySelectorAll('.inventory-tab-list > [role="tab"]'));
  const panels = Array.from(root.querySelectorAll(':scope > .inventory-tab-panel'));
  const selectedTab = tabs.find(function(tab) { return tab.dataset.tab === tabName; }) || tabs[0];
  if (!selectedTab) return;

  tabs.forEach(function(tab) {
    const selected = tab === selectedTab;
    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
    tab.tabIndex = selected ? 0 : -1;
  });
  panels.forEach(function(panel) {
    panel.hidden = panel.dataset.tabPanel !== selectedTab.dataset.tab;
  });

  if (updateUrl) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', selectedTab.dataset.tab);
    window.history.replaceState({}, '', url);
  }
}

document.addEventListener('click', function(event) {
  const recipeLink = event.target.closest('[data-configure-recipes]');
  if (recipeLink) {
    event.preventDefault();
    setInventoryTab('recipes', true);
    qs('#inventory-panel-recipes')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    qs('#inventory_recipe_product')?.focus({ preventScroll: true });
    return;
  }
  const tab = event.target.closest('[data-inventory-tabs] > .inventory-tab-list > [role="tab"]');
  if (tab) setInventoryTab(tab.dataset.tab, true);
});

document.addEventListener('keydown', function(event) {
  const tab = event.target.closest('[data-inventory-tabs] > .inventory-tab-list > [role="tab"]');
  if (!tab) return;

  const tabs = Array.from(tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]'));
  const currentIndex = tabs.indexOf(tab);
  let nextIndex = currentIndex;
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % tabs.length;
  else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
  else if (event.key === 'Home') nextIndex = 0;
  else if (event.key === 'End') nextIndex = tabs.length - 1;
  else return;

  event.preventDefault();
  tabs[nextIndex].focus();
  setInventoryTab(tabs[nextIndex].dataset.tab, true);
});

window.addEventListener('hashchange', function() {
  const hashTab = window.location.hash.replace('#inventory-', '');
  if (hashTab) setInventoryTab(hashTab, false);
});
document.addEventListener('DOMContentLoaded', function() {
  const requestedTab = new URLSearchParams(window.location.search).get('tab');
  const hashTab = window.location.hash.replace('#inventory-', '');
  setInventoryTab(requestedTab || hashTab || 'stock', false);
  updateInventoryType();
  refreshInventory();
  refreshLogs();
  refreshVoidedOrders();
  setInterval(refreshVoidedOrders, 5000);
  setInterval(refreshInventory, 5000);
  setInterval(refreshLogs, 5000);
});
</script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/item-recipe.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/item-recipe.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/inventory-recipes.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/inventory-recipes.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/inventory-history.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/inventory-history.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/inventory-lists.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/inventory-lists.js')) ?>" defer></script>
</body>
</html>
