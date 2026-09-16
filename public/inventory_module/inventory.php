<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/daily_tasks.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';
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

function normalizeChangeType($type) {
    $t = strtolower(trim((string)$type));
    $usedSynonyms = [
        'used', 'use', 'usage', 'consume', 'consumed', 'consumption',
        'deduct', 'deduction', 'auto-deduct', 'auto_deduct', 'auto deduct',
        'auto-use', 'auto use', 'auto usage',
    ];

    return in_array($t, $usedSynonyms, true) ? 'used' : $t;
}

function isAutoDeductRemark($remarks) {
    $r = strtolower(trim((string)$remarks));
    return $r !== '' && (
        strpos($r, 'auto-deduct') !== false ||
        strpos($r, 'auto deduct') !== false ||
        strpos($r, 'auto_deduct') !== false ||
        strpos($r, 'auto-use') !== false ||
        strpos($r, 'auto use') !== false ||
        strpos($r, 'auto usage') !== false
    );
}

function getItemType($name) {
    $n = strtolower((string)$name);
    if (strpos($n, 'straw') !== false) {
        return 'Straw';
    }
    if (strpos($n, 'cup') !== false) {
        return 'Cup';
    }
    if (strpos($n, 'lid') !== false || strpos($n, 'cover') !== false) {
        return 'Lid';
    }
    return 'Others';
}

function getInventoryByNameAndSize(PDO $pdo, string $name, ?string $cupSize) {
    if ($cupSize === null) {
        $stmt = $pdo->prepare('SELECT id, qty, unit, critical_level FROM inventory WHERE name = ? AND cup_size IS NULL LIMIT 1');
        $stmt->execute([$name]);
    } else {
        $stmt = $pdo->prepare('SELECT id, qty, unit, critical_level FROM inventory WHERE name = ? AND cup_size = ? LIMIT 1');
        $stmt->execute([$name, $cupSize]);
    }

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchInventory(PDO $pdo, string $search = '') {
    $sql = "
        SELECT i.*, lr.last_restock_at
        FROM inventory i
        LEFT JOIN (
            SELECT inventory_id, MAX(created_at) AS last_restock_at
            FROM inventory_logs
            WHERE change_type = 'restock'
            GROUP BY inventory_id
        ) lr ON lr.inventory_id = i.id
    ";
    $params = [];

    if ($search !== '') {
        $sql .= ' WHERE i.sku LIKE ? OR i.name LIKE ? OR i.cup_size LIKE ? OR i.unit LIKE ?';
        $kw = '%' . $search . '%';
        $params = [$kw, $kw, $kw, $kw];
    }

    $sql .= ' ORDER BY i.name ASC, i.cup_size ASC, i.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function groupInventory(array $inventory) {
    $groups = ['Straw' => [], 'Cup' => [], 'Lid' => [], 'Others' => []];
    foreach ($inventory as $item) {
        $groups[getItemType($item['name'])][] = $item;
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

function lowStockItems(array $inventory) {
    $low = [];
    foreach ($inventory as $item) {
        if ($item['critical_level'] !== null && (float)$item['critical_level'] > 0 && (float)$item['qty'] <= (float)$item['critical_level']) {
            $low[] = $item;
        }
    }
    return $low;
}

function renderInventoryTable(array $inventory) {
    $groups = groupInventory($inventory);
    $out = '<div class="inventory-table-wrap"><table class="inventory-table">';
    $out .= '<thead><tr><th>#</th><th>SKU</th><th>Name</th><th>Cup Size</th><th>Qty</th><th>Unit</th><th>Critical</th><th>Date & Time</th><th>Action</th></tr></thead><tbody>';
    $rownum = 1;
    $hasRows = false;

    foreach ($groups as $type => $items) {
        if (!count($items)) {
            continue;
        }

        $hasRows = true;
        foreach ($items as $inv) {
            $isLow = $inv['critical_level'] !== null && (float)$inv['critical_level'] > 0 && (float)$inv['qty'] <= (float)$inv['critical_level'];
            $normSize = stripos($inv['name'], 'straw') !== false ? null : normalizeCupSize($inv['cup_size'] ?? null);
            $lastDate = !empty($inv['last_restock_at'])
                ? date('F d, Y h:i A', strtotime($inv['last_restock_at']))
                : (!empty($inv['date_added']) ? date('F d, Y h:i A', strtotime($inv['date_added'])) : '-');
            $critical = $inv['critical_level'] !== null ? formatQty($inv['critical_level']) : 'N/A';
            $editPayload = [
                'id' => (int)$inv['id'],
                'sku' => $inv['sku'],
                'name' => $inv['name'],
                'cup_size' => $normSize ?? '',
                'unit' => $inv['unit'],
                'qty' => formatQty($inv['qty']),
                'critical_level' => $inv['critical_level'] !== null ? formatQty($inv['critical_level']) : '',
            ];

            $out .= '<tr class="' . ($isLow ? 'low-stock' : '') . '">';
            $out .= '<td class="inventory-id">#' . $rownum++ . '</td>';
            $out .= '<td><strong>' . htmlspecialchars($inv['sku']) . '</strong></td>';
            $out .= '<td><strong>' . htmlspecialchars($inv['name']) . '</strong><span class="inventory-type-chip">' . htmlspecialchars($type) . '</span></td>';
            $out .= '<td>' . htmlspecialchars($normSize ?? '-') . '</td>';
            $out .= '<td><span class="qty-pill">' . htmlspecialchars(formatQty($inv['qty'])) . '</span></td>';
            $out .= '<td>' . htmlspecialchars($inv['unit']) . '</td>';
            $out .= '<td>' . htmlspecialchars($critical) . '</td>';
            $out .= '<td>' . htmlspecialchars($lastDate) . '</td>';
            $out .= '<td><div class="inventory-actions">';
            $out .= '<button type="button" class="inventory-action-btn edit js-edit-inventory" data-inventory=\'' . htmlspecialchars(json_encode($editPayload), ENT_QUOTES) . '\' title="Edit inventory"><i class="fa fa-pen-to-square"></i></button>';
            $out .= '<form method="post" class="inventory-inline-form" data-confirm="Delete inventory item ' . htmlspecialchars($inv['name'], ENT_QUOTES) . '?" data-confirm-title="Delete Inventory Item" data-confirm-type="danger">';
            $out .= '<input type="hidden" name="delete_inventory" value="1"><input type="hidden" name="id" value="' . (int)$inv['id'] . '">';
            $out .= '<button type="submit" class="inventory-action-btn delete" title="Delete inventory"><i class="fa fa-trash"></i></button>';
            $out .= '</form></div></td>';
            $out .= '</tr>';
        }
    }

    if (!$hasRows) {
        $out .= '<tr><td colspan="9" class="inventory-empty">No inventory items found.</td></tr>';
    }

    $out .= '</tbody></table></div>';
    return $out;
}

function renderInventoryPanel(array $inventory) {
    $lowItems = lowStockItems($inventory);
    $out = '';

    if (count($lowItems) > 0) {
        $out .= '<div class="inventory-alert warning"><strong>Low Stock (' . count($lowItems) . ')</strong><ul>';
        foreach ($lowItems as $item) {
            $size = normalizeCupSize($item['cup_size'] ?? null);
            $out .= '<li>' . htmlspecialchars($item['name']) . ($size ? ' (' . htmlspecialchars($size) . ')' : '') .
                ' - Qty: ' . htmlspecialchars(formatQty($item['qty'])) .
                ' / Critical: ' . htmlspecialchars(formatQty($item['critical_level'])) . '</li>';
        }
        $out .= '</ul></div>';
    }

    $out .= '<div class="inventory-panel-header compact"><div><span class="panel-eyebrow">Stock Ledger</span><h2>Current Inventory</h2></div><span class="inventory-updated">Last updated: <span id="invUpdatedAt">-</span></span></div>';
    $out .= renderInventoryTable($inventory);
    return $out;
}

function fetchLogs(PDO $pdo, string $from, string $to, string $search = '') {
    $sql = "
        SELECT l.id, l.inventory_id, i.name, i.cup_size, l.change_type, l.quantity,
               l.remaining, i.qty AS current_qty, i.critical_level, l.remarks, l.created_at
        FROM inventory_logs l
        JOIN inventory i ON l.inventory_id = i.id
    ";
    $where = [];
    $params = [];

    if ($from && $to && $from === $to) {
        $where[] = 'DATE(l.created_at) = ?';
        $params[] = $from;
    } else {
        if ($from) {
            $where[] = 'DATE(l.created_at) >= ?';
            $params[] = $from;
        }
        if ($to) {
            $where[] = 'DATE(l.created_at) <= ?';
            $params[] = $to;
        }
    }

    if ($search !== '') {
        $where[] = '(i.name LIKE ? OR i.cup_size LIKE ?)';
        $kw = '%' . $search . '%';
        $params[] = $kw;
        $params[] = $kw;
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY l.created_at DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function logSummaryLabel(string $from, string $to) {
    if ($from && $to && $from === $to) {
        return 'Showing logs for ' . date('F d, Y', strtotime($from));
    }
    if ($from && $to) {
        return 'Showing logs from ' . date('F d, Y', strtotime($from)) . ' to ' . date('F d, Y', strtotime($to));
    }
    if ($from) {
        return 'Showing logs from ' . date('F d, Y', strtotime($from)) . ' onwards';
    }
    if ($to) {
        return 'Showing logs up to ' . date('F d, Y', strtotime($to));
    }
    return 'Showing recent logs';
}

function renderLogsPanel(array $logs, string $from, string $to) {
    $totalQty = 0;
    foreach ($logs as $log) {
        $type = normalizeChangeType($log['change_type']);
        if (isAutoDeductRemark($log['remarks'] ?? '')) {
            $type = 'used';
        }
        if (!in_array($type, ['used', 'damage'], true)) {
            $totalQty += (float)$log['quantity'];
        }
    }

    $out = '<div class="inventory-log-summary"><strong>' . htmlspecialchars(logSummaryLabel($from, $to)) . '</strong><span>Total Qty: ' . htmlspecialchars(formatQty($totalQty)) . '</span></div>';
    $out .= '<div class="inventory-table-wrap"><table class="inventory-table log-table">';
    $out .= '<thead><tr><th>#</th><th>Item</th><th>Cup Size</th><th>Type</th><th>Qty</th><th>Current Qty</th><th>Critical</th><th>Remarks</th><th>Date & Time</th></tr></thead><tbody>';

    if (!count($logs)) {
        $out .= '<tr><td colspan="9" class="inventory-empty">No logs found for the selected date range.</td></tr>';
    } else {
        foreach ($logs as $index => $log) {
            $base = normalizeChangeType($log['change_type']);
            if (isAutoDeductRemark($log['remarks'] ?? '')) {
                $base = 'used';
            }
            $typeLabel = $base === 'used' ? 'Used' : ucfirst(strtolower($base));
            $loss = in_array($base, ['used', 'damage'], true);
            $out .= '<tr class="' . ($loss ? 'log-used' : '') . '">';
            $out .= '<td class="inventory-id">#' . ($index + 1) . '</td>';
            $out .= '<td>' . htmlspecialchars($log['name']) . '</td>';
            $out .= '<td>' . htmlspecialchars(normalizeCupSize($log['cup_size']) ?? '-') . '</td>';
            $out .= '<td><span class="log-type-chip ' . htmlspecialchars($base) . '">' . htmlspecialchars($typeLabel) . '</span></td>';
            $out .= '<td>' . htmlspecialchars(formatQty($log['quantity'])) . '</td>';
            $out .= '<td>' . htmlspecialchars(formatQty($log['current_qty'] ?? $log['remaining'])) . '</td>';
            $out .= '<td>' . ($log['critical_level'] !== null ? htmlspecialchars(formatQty($log['critical_level'])) : 'N/A') . '</td>';
            $out .= '<td>' . htmlspecialchars($log['remarks'] ?: '-') . '</td>';
            $out .= '<td>' . (!empty($log['created_at']) ? date('F d, Y h:i:s A', strtotime($log['created_at'])) : '-') . '</td>';
            $out .= '</tr>';
        }
    }

    $out .= '</tbody></table></div>';
    return $out;
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
    if (isset($_POST['add_inventory'])) {
        $name = strtoupper(trim($_POST['name'] ?? ''));
        $skuPosted = trim($_POST['sku'] ?? '');
        $cupSize = trim($_POST['cup_size'] ?? '');
        $unit = unitFromPost();
        $qty = is_numeric($_POST['quantity'] ?? null) ? (float)$_POST['quantity'] : 0;
        $critical = (isset($_POST['critical_level']) && $_POST['critical_level'] !== '' && is_numeric($_POST['critical_level']))
            ? (float)$_POST['critical_level']
            : null;

        if ($name === '' || $unit === '' || $qty <= 0) {
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Please provide an item name, unit, and quantity greater than zero.']);
                exit;
            }
            redirectInventory('error=1');
        }

        $isCupOrLid = stripos($name, 'cup') !== false || stripos($name, 'lid') !== false || stripos($name, 'cover') !== false;
        $isNoSizeItem = stripos($name, 'straw') !== false || stripos($name, 'stirrer') !== false;
        $sizes = ($isCupOrLid && $cupSize === 'all') ? ['12oz Hot', '16oz', '22oz'] : [($isCupOrLid && !$isNoSizeItem ? normalizeCupSize($cupSize) : null)];

        foreach ($sizes as $sizeToSave) {
            $sku = inventorySku($skuPosted, $name, $sizeToSave);
            $existing = getInventoryByNameAndSize($pdo, $name, $sizeToSave);
            if ($existing) {
                $newQty = (float)$existing['qty'] + $qty;
                $stmt = $pdo->prepare('UPDATE inventory SET qty = ?, unit = ?, critical_level = ? WHERE id = ?');
                $stmt->execute([$newQty, $unit, $critical, $existing['id']]);
                $inventoryId = (int)$existing['id'];
            } else {
                $stmt = $pdo->prepare('INSERT INTO inventory (sku, name, cup_size, unit, qty, critical_level, date_added) VALUES (?, ?, ?, ?, ?, ?, NOW())');
                $stmt->execute([$sku, $name, $sizeToSave, $unit, $qty, $critical]);
                $inventoryId = (int)$pdo->lastInsertId();
                $newQty = $qty;
            }

            $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, 'restock', ?, ?, 'Manual Add', NOW())");
            $log->execute([$inventoryId, $qty, $newQty]);
            notifications_refresh_low_stock($pdo, $inventoryId);
        }

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        redirectInventory('success=1');
    }

    if (isset($_POST['update_inventory'])) {
        $id = (int)($_POST['id'] ?? 0);
        $sku = inventorySku((string) ($_POST['sku'] ?? ''), (string) ($_POST['name'] ?? ''));
        $name = strtoupper(trim($_POST['name'] ?? ''));
        $unit = unitFromPost();
        $qty = is_numeric($_POST['quantity'] ?? null) ? (float)$_POST['quantity'] : -1;
        $critical = (isset($_POST['critical_level']) && $_POST['critical_level'] !== '' && is_numeric($_POST['critical_level']))
            ? (float)$_POST['critical_level']
            : null;
        $cupSize = trim($_POST['cup_size'] ?? '');
        $isSized = stripos($name, 'cup') !== false || stripos($name, 'lid') !== false || stripos($name, 'cover') !== false;
        $isNoSizeItem = stripos($name, 'straw') !== false || stripos($name, 'stirrer') !== false;
        $cupSizeToSave = ($isSized && !$isNoSizeItem && $cupSize !== '') ? normalizeCupSize($cupSize) : null;

        if ($id <= 0 || $name === '' || $unit === '' || $qty < 0) {
            redirectInventory('error=1');
        }

        $beforeStmt = $pdo->prepare('SELECT qty FROM inventory WHERE id = ?');
        $beforeStmt->execute([$id]);
        $beforeQty = $beforeStmt->fetchColumn();
        $stmt = $pdo->prepare('UPDATE inventory SET sku = ?, name = ?, cup_size = ?, unit = ?, qty = ?, critical_level = ? WHERE id = ?');
        $stmt->execute([$sku, $name, $cupSizeToSave, $unit, $qty, $critical, $id]);
        if ($beforeQty !== false && abs((float) $beforeQty - $qty) > 0.00001) {
            $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, 'adjustment', ?, ?, ?, NOW())");
            $log->execute([$id, (int) ($_SESSION['user']['id'] ?? 0) ?: null, abs($qty - (float) $beforeQty), $qty, 'Stock count adjusted from ' . formatQty($beforeQty) . ' to ' . formatQty($qty)]);
        } elseif ($beforeQty !== false) {
            $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, 'adjustment', 0, ?, 'Inventory metadata updated', NOW())");
            $log->execute([$id, (int) ($_SESSION['user']['id'] ?? 0) ?: null, $qty]);
        }
        notifications_refresh_low_stock($pdo, $id);
        redirectInventory('updated=1');
    }

    if (isset($_POST['delete_inventory'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $historyStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_logs WHERE inventory_id = ?');
            $historyStmt->execute([$id]);
            if ((int) $historyStmt->fetchColumn() > 0) {
                redirectInventory('error=history');
            }
            $stmt = $pdo->prepare('DELETE FROM inventory WHERE id = ?');
            $stmt->execute([$id]);
        }
        redirectInventory('deleted=1');
    }

    if (isset($_POST['report_damage'])) {
        $id = (int)($_POST['id'] ?? 0);
        $qtyDamaged = is_numeric($_POST['quantity'] ?? null) ? (float)$_POST['quantity'] : 0;
        $remarks = trim($_POST['remarks'] ?? '');

        $stmt = $pdo->prepare('SELECT id, qty FROM inventory WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item || $qtyDamaged <= 0 || $qtyDamaged > (float)$item['qty']) {
            redirectInventory('error=1');
        }

        $newQty = (float)$item['qty'] - $qtyDamaged;
        $stmt = $pdo->prepare('UPDATE inventory SET qty = ? WHERE id = ?');
        $stmt->execute([$newQty, $id]);

        $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, 'damage', ?, ?, ?, NOW())");
        $log->execute([$id, $qtyDamaged, $newQty, $remarks !== '' ? $remarks : 'Damage reported']);
        notifications_refresh_low_stock($pdo, $id);
        redirectInventory('damage=1');
    }
}

$search = trim($_GET['search'] ?? '');
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$filter_active = ($from !== '' || $to !== '');
if ($from && $to && strtotime($to) < strtotime($from)) {
    $to = $from;
}
if (!$filter_active) {
    $from = $to = date('Y-m-d');
}

if (isset($_GET['ajax_inventory']) && $_GET['ajax_inventory'] === '1') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo renderInventoryPanel(fetchInventory($pdo, trim($_GET['search'] ?? '')));
    exit;
}

if (isset($_GET['ajax_logs']) && $_GET['ajax_logs'] === '1') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $ajaxFrom = $_GET['from'] ?? '';
    $ajaxTo = $_GET['to'] ?? '';
    if ($ajaxFrom && $ajaxTo && strtotime($ajaxTo) < strtotime($ajaxFrom)) {
        $ajaxTo = $ajaxFrom;
    }
    if ($ajaxFrom === '' && $ajaxTo === '') {
        $ajaxFrom = $ajaxTo = date('Y-m-d');
    }
    echo renderLogsPanel(fetchLogs($pdo, $ajaxFrom, $ajaxTo, trim($_GET['search'] ?? '')), $ajaxFrom, $ajaxTo);
    exit;
}

$orderCountStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE() AND status NOT IN ('void', 'voided', 'cancelled')");
$orderCountStmt->execute();
$order_count_today = (int)$orderCountStmt->fetchColumn();

$inventory = fetchInventory($pdo, $search);
$allInventory = fetchInventory($pdo);
$logs = fetchLogs($pdo, $from, $to, $search);
$low_stock_items = lowStockItems($allInventory);

$required_sizes = ['12oz Hot', '16oz', '22oz'];
$presentCupSizes = [];
$presentLidSizes = [];
foreach ($allInventory as $item) {
    $size = normalizeCupSize($item['cup_size'] ?? null);
    $name = strtolower($item['name'] ?? '');
    if ($size && strpos($name, 'cup') !== false) {
        $presentCupSizes[$size] = true;
    }
    if ($size && (strpos($name, 'lid') !== false || strpos($name, 'cover') !== false)) {
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
    $name = strtolower($item['name'] ?? '');
    if (strpos($name, 'straw') !== false) {
        $hasStraw = true;
    }
    if (strpos($name, 'stirrer') !== false) {
        $hasStirrer = true;
    }
}
$missing_straw = !$hasStraw;
$missing_stirrer = !$hasStirrer;
$missing_required_count = ($missing_straw ? 1 : 0) + ($missing_stirrer ? 1 : 0) + count($missing_cup_sizes) + count($missing_lid_sizes);

$voidedOrdersStmt = $pdo->prepare("
    SELECT o.id, COALESCE(o.voided_at, o.cancelled_at, o.created_at) AS event_at,
           u.username, o.total, o.status
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE (o.status = 'void' OR o.status = 'voided' OR o.status = 'cancelled')
      AND DATE(COALESCE(o.voided_at, o.cancelled_at, o.created_at)) = CURDATE()
    ORDER BY event_at DESC
");
$voidedOrdersStmt->execute();
$voided_orders_today = $voidedOrdersStmt->fetchAll(PDO::FETCH_ASSOC);

$total_inventory_items = count($allInventory);
$total_inventory_qty = 0;
foreach ($allInventory as $item) {
    $total_inventory_qty += (float)$item['qty'];
}
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
      <p class="inventory-page-copy">Track supplies, add stock, record damage, and review recent inventory movement.</p>
    </div>
    <div class="inventory-heading-actions">
      <a href="reports.php" class="inventory-secondary-action"><i class="fa fa-chart-line"></i> View Reports</a>
      <button type="button" class="inventory-danger-action" onclick="showDamageDropdownModal()"><i class="fa fa-triangle-exclamation"></i> Report Damage</button>
    </div>
  </section>

  <section class="inventory-summary-grid" aria-label="Inventory summary">
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-boxes-stacked"></i></span>
      <span class="summary-label">Inventory Items</span>
      <strong><?= number_format($total_inventory_items) ?></strong>
    </article>
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-layer-group"></i></span>
      <span class="summary-label">Total Quantity</span>
      <strong><?= htmlspecialchars(formatQty($total_inventory_qty)) ?></strong>
    </article>
    <article class="inventory-summary-card">
      <span class="summary-icon"><i class="fa fa-arrow-trend-down"></i></span>
      <span class="summary-label">Low Stock</span>
      <strong><?= number_format($low_stock_count) ?></strong>
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

  <section class="inventory-create-panel" aria-labelledby="add-inventory-title">
    <div class="inventory-create-intro">
      <span class="panel-eyebrow">Add New Inventory</span>
      <h2 id="add-inventory-title">Add New Inventory</h2>
    </div>
    <form method="post" class="inventory-form" id="addInventoryForm">
      <input type="hidden" name="add_inventory" value="1">
      <div class="form-group">
        <label for="item_name">Item Name</label>
        <input id="item_name" type="text" name="name" placeholder="Item Name" required oninput="toggleCupSizeField()">
      </div>
      <div class="form-group">
        <label for="inventory_sku">SKU</label>
        <input id="inventory_sku" type="text" name="sku" maxlength="64" placeholder="Auto-generated if blank">
      </div>
      <div class="form-group cup-size-group">
        <label for="cup_size_field">Cup Size</label>
        <select id="cup_size_field" name="cup_size">
          <option value="">Select Size</option>
          <option value="all">All Sizes</option>
          <option value="12oz Hot">12oz Hot</option>
          <option value="16oz">16oz</option>
          <option value="22oz">22oz</option>
        </select>
      </div>
      <div class="form-group">
        <label for="unit_field">Unit</label>
        <select id="unit_field" name="unit" required>
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
        <label for="unit_custom">Custom Unit</label>
        <input id="unit_custom" type="text" name="unit_custom" placeholder="Custom Unit">
      </div>
      <div class="form-group">
        <label for="quantity">Quantity</label>
        <input id="quantity" type="number" step="0.01" name="quantity" min="0" required>
      </div>
      <div class="form-group">
        <label for="critical_level">Critical Level (optional)</label>
        <input id="critical_level" type="number" step="0.01" name="critical_level" min="0">
      </div>
      <button type="submit" class="inventory-submit-btn"><i class="fa fa-plus"></i> Add Inventory</button>
    </form>
  </section>

  <section class="inventory-toolbar">
    <form method="get" class="inventory-search-form">
      <label for="inventory-search">Search Inventory</label>
      <input id="inventory-search" type="search" name="search" placeholder="Search SKU or item..." value="<?= htmlspecialchars($search) ?>">
      <button type="submit" class="inventory-icon-btn"><i class="fa fa-search"></i></button>
      <?php if($search): ?><a href="inventory.php" class="inventory-reset-link">Reset</a><?php endif; ?>
    </form>
  </section>

  <section class="inventory-table-panel" id="inventoryRealtime">
    <?= renderInventoryPanel($inventory) ?>
  </section>

  <section class="inventory-logs-panel">
    <div class="inventory-panel-header">
      <div>
        <span class="panel-eyebrow">Movement History</span>
        <h2>Recent Inventory Logs</h2>
      </div>
      <span class="inventory-updated">Last updated: <span id="logsUpdatedAt">-</span></span>
    </div>
    <form method="get" class="inventory-filter-form">
      <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
      <label for="from">From</label>
      <input type="date" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
      <label for="to">To</label>
      <input type="date" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
      <button type="submit" class="inventory-filter-btn"><i class="fa fa-filter"></i> Filter</button>
      <?php if ($filter_active): ?><a href="inventory.php" class="inventory-reset-link">Reset</a><?php endif; ?>
    </form>
    <div id="logsRealtime">
      <?= renderLogsPanel($logs, $from, $to) ?>
    </div>
  </section>

  <section class="inventory-void-panel">
    <div class="inventory-panel-header">
      <div>
        <span class="panel-eyebrow">Sales Exceptions</span>
        <h2>Voided / Cancelled Orders Today</h2>
      </div>
      <span class="panel-pill"><?= number_format(count($voided_orders_today)) ?> record<?= count($voided_orders_today) === 1 ? '' : 's' ?></span>
    </div>
    <div class="inventory-table-wrap">
      <table class="inventory-table">
        <thead><tr><th>#</th><th>Date & Time</th><th>User</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (!empty($voided_orders_today)): ?>
            <?php foreach ($voided_orders_today as $order): ?>
              <tr>
                <td class="inventory-id">#<?= (int)$order['id'] ?></td>
                <td><?= htmlspecialchars(date('F d, Y h:i A', strtotime($order['event_at']))) ?></td>
                <td><?= htmlspecialchars($order['username'] ?: 'System') ?></td>
                <td><?= htmlspecialchars(formatCurrency($order['total'])) ?></td>
                <td><span class="log-type-chip damage"><?= htmlspecialchars(ucfirst($order['status'])) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="inventory-empty">No voided or cancelled orders today.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<div id="damageDropdownModal" class="inventory-modal" aria-hidden="true">
  <div class="inventory-modal-card">
    <h3>Select Item to Report Damage</h3>
    <select id="damage_item_select">
      <?php foreach ($damageOptions as $item): ?>
        <?php $label = $item['name'] . (normalizeCupSize($item['cup_size'] ?? null) ? ' (' . normalizeCupSize($item['cup_size']) . ')' : ''); ?>
        <option value="<?= (int)$item['id'] ?>" data-name="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></option>
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
    <h3>Report Damaged Item</h3>
    <form method="post" class="inventory-modal-form">
      <input type="hidden" name="report_damage" value="1">
      <input type="hidden" name="id" id="damage_id">
      <label for="damage_name">Item Name</label>
      <input type="text" id="damage_name" readonly>
      <label for="damage_quantity">Quantity Affected</label>
      <input type="number" id="damage_quantity" step="0.01" name="quantity" required>
      <label for="damage_remarks">Remarks / Description</label>
      <textarea id="damage_remarks" name="remarks" rows="3" required></textarea>
      <div class="inventory-modal-actions">
        <button type="button" class="inventory-cancel-btn" onclick="closeDamageReport()">Cancel</button>
        <button type="submit" class="inventory-danger-action">Submit</button>
      </div>
    </form>
  </div>
</div>

<div id="editInventoryModal" class="inventory-modal" aria-hidden="true">
  <div class="inventory-modal-card wide">
    <h3>Edit Inventory Item</h3>
    <form method="post" class="inventory-modal-form grid">
      <input type="hidden" name="update_inventory" value="1">
      <input type="hidden" name="id" id="edit_id">
      <label for="edit_sku">SKU</label>
      <input type="text" id="edit_sku" name="sku" maxlength="64" required>
      <label for="edit_name">Item Name</label>
      <input type="text" id="edit_name" name="name" required>
      <label for="edit_cup_size">Cup Size</label>
      <select id="edit_cup_size" name="cup_size">
        <option value="">None</option>
        <option value="12oz Hot">12oz Hot</option>
        <option value="16oz">16oz</option>
        <option value="22oz">22oz</option>
      </select>
      <label for="edit_unit">Unit</label>
      <input type="text" id="edit_unit" name="unit" required>
      <label for="edit_quantity">Quantity</label>
      <input type="number" id="edit_quantity" name="quantity" step="0.01" min="0" required>
      <label for="edit_critical_level">Critical Level</label>
      <input type="number" id="edit_critical_level" name="critical_level" step="0.01" min="0">
      <div class="inventory-modal-actions full">
        <button type="button" class="inventory-cancel-btn" onclick="closeEditInventory()">Cancel</button>
        <button type="submit" class="inventory-submit-btn">Update Inventory</button>
      </div>
    </form>
  </div>
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
}

function toggleCupSizeField() {
  const item = qs('#item_name');
  if (item) item.value = (item.value || '').toUpperCase();
  const name = (item?.value || '').toLowerCase();
  const group = qs('.cup-size-group');
  const cupSize = qs('#cup_size_field');
  const show = name.includes('cup') || name.includes('lid') || name.includes('cover');
  const hide = name.includes('straw') || name.includes('stirrer');
  if (group) group.classList.toggle('is-visible', show && !hide);
  if (cupSize && (!show || hide)) cupSize.value = '';
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
  closeDamageDropdownModal();
  setModal('damageModal', true);
}

function closeDamageReport() {
  setModal('damageModal', false);
}

function openEditInventory(data) {
  qs('#edit_id').value = data.id || '';
  qs('#edit_sku').value = data.sku || '';
  qs('#edit_name').value = data.name || '';
  qs('#edit_cup_size').value = data.cup_size || '';
  qs('#edit_unit').value = data.unit || '';
  qs('#edit_quantity').value = data.qty || '';
  qs('#edit_critical_level').value = data.critical_level || '';
  setModal('editInventoryModal', true);
}

function closeEditInventory() {
  setModal('editInventoryModal', false);
}

async function refreshInventory() {
  const search = qs('#inventory-search')?.value || '';
  const response = await fetch('inventory.php?ajax_inventory=1&search=' + encodeURIComponent(search) + '&t=' + Date.now(), { cache: 'no-store' });
  if (!response.ok) return;
  qs('#inventoryRealtime').innerHTML = await response.text();
  const stamp = qs('#invUpdatedAt');
  if (stamp) stamp.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

async function refreshLogs() {
  const from = qs('#from')?.value || '';
  const to = qs('#to')?.value || '';
  const search = qs('#inventory-search')?.value || '';
  const response = await fetch('inventory.php?ajax_logs=1&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to) + '&search=' + encodeURIComponent(search) + '&t=' + Date.now(), { cache: 'no-store' });
  if (!response.ok) return;
  qs('#logsRealtime').innerHTML = await response.text();
  const stamp = qs('#logsUpdatedAt');
  if (stamp) stamp.textContent = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

document.addEventListener('change', function(event) {
  if (event.target?.id === 'unit_field') {
    document.body.classList.toggle('show-custom-unit', event.target.value === 'other');
  }
  if (event.target?.id === 'from' || event.target?.id === 'to') {
    const from = qs('#from');
    const to = qs('#to');
    if (from && to) {
      to.min = from.value;
      if (to.value && to.value < from.value) to.value = from.value;
    }
    refreshLogs();
  }
});

document.addEventListener('click', function(event) {
  const editButton = event.target.closest('.js-edit-inventory');
  if (editButton) {
    openEditInventory(JSON.parse(editButton.dataset.inventory || '{}'));
  }
});

document.addEventListener('submit', async function(event) {
  const form = event.target;
  if (form?.id !== 'addInventoryForm') return;
  event.preventDefault();

  const formData = new FormData(form);
  const item = (formData.get('name') || '').toString().toUpperCase();
  const unit = formData.get('unit') === 'other' ? (formData.get('unit_custom') || '') : (formData.get('unit') || '');
  const size = formData.get('cup_size') || '-';
  const qty = formData.get('quantity') || '0';
  const critical = formData.get('critical_level') || 'N/A';
  const confirmed = await window.kapeConfirm({
    title: 'Add Inventory',
    message: 'Add ' + qty + ' ' + unit + ' of ' + item + ' (cup size: ' + size + ', critical level: ' + critical + ')?',
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
    document.body.classList.remove('show-custom-unit');
    toggleCupSizeField();
    await refreshInventory();
    await refreshLogs();
  } else {
    window.kapeNotify(result.error || 'Failed to add inventory.', 'error');
  }
});

let searchTimer = null;
qs('#inventory-search')?.addEventListener('input', function() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(function() {
    refreshInventory();
    refreshLogs();
  }, 350);
});

document.addEventListener('DOMContentLoaded', function() {
  toggleCupSizeField();
  qs('#unit_field')?.dispatchEvent(new Event('change'));
  refreshInventory();
  refreshLogs();
  setInterval(refreshInventory, 5000);
  setInterval(refreshLogs, 5000);
});
</script>
</body>
</html>
