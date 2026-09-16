<?php
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . "/includes/daily_tasks.php";
require_admin();

// This legacy file duplicated inventory management and bypassed the canonical
// audited workflow. Keep the route compatible while sending administrators to
// the maintained inventory module.
header('Location: inventory.php');
exit;

$pdo = db();

// Centralized inventory status check
function get_inventory_status($pdo) {
    $inventoryCount = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
    $required_sizes = ['12oz Hot', '16oz', '22oz'];
    $missing_cup_sizes = [];
    $missing_lid_sizes = [];
    foreach ($required_sizes as $size) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%cup%' AND cup_size = ?");
        $stmt->execute([$size]);
        if ($stmt->fetchColumn() == 0) $missing_cup_sizes[] = $size;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%') AND cup_size = ?");
        $stmt->execute([$size]);
        if ($stmt->fetchColumn() == 0) $missing_lid_sizes[] = $size;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%straw%'");
    $stmt->execute();
    $missing_straw = ($stmt->fetchColumn() == 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%stirrer%'");
    $stmt->execute();
    $missing_stirrer = ($stmt->fetchColumn() == 0);
    $inventory_blocked = (
        $inventoryCount == 0 ||
        !empty($missing_cup_sizes) ||
        !empty($missing_lid_sizes) ||
        $missing_straw ||
        $missing_stirrer
    );
    return $inventory_blocked;
}

$inventory_blocked = get_inventory_status($pdo);

if ($inventory_blocked) {
    echo "❌ Cannot create order: Missing or low inventory items.";
    exit;
}

// Fetch count of orders for today (excluding void/cancelled)
$order_count_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM orders
    WHERE DATE(created_at) = CURDATE() AND status NOT IN ('void', 'cancelled')
");
$order_count_stmt->execute();
$order_count_today = $order_count_stmt->fetchColumn();

/* 🔹 FORMATTERS */
function formatQty($num) {
    return ($num == floor($num)) ? (int)$num : rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}
function formatCurrency($amount) {
    return '₱' . ((floor($amount) == $amount) ? number_format($amount, 0) : number_format($amount, 2));
}

/* 🔹 ADD NEW INVENTORY ITEM */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_inventory"])) {
    $name  = trim($_POST["name"]);
    $cup_size = trim($_POST["cup_size"]);
    $unit  = trim($_POST["unit"]);
    $qty   = is_numeric($_POST["quantity"]) ? floatval($_POST["quantity"]) : 0;
    $crit  = is_numeric($_POST["critical_level"]) ? floatval($_POST["critical_level"]) : null;

    $is_cup = stripos($name, 'cup') !== false || stripos($name, 'lid') !== false;
    $is_straw = stripos($name, 'straw') !== false;
    // For straw, always set cup_size to null
    if ($is_straw) $cup_size = null;

    $required_sizes = ['12oz Hot', '16oz', '22oz'];

    // If "All Sizes" is selected for cup/lid, insert for all sizes
    if ($is_cup && $cup_size === 'all') {
        foreach ($required_sizes as $size) {
            $stmt = $pdo->prepare("
                INSERT INTO inventory (name, cup_size, unit, qty, critical_level, date_added)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$name, $size, $unit, $qty, $crit]);
            $inv_id = $pdo->lastInsertId();
            $log = $pdo->prepare("
                INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)
                VALUES (?, 'restock', ?, ?, 'Manual Add', NOW())
            ");
            $log->execute([$inv_id, $qty, $qty]);
        }
        header("Location: inventory.php?success=1");
        exit;
    }

    if (!empty($name) && ($is_cup ? !empty($cup_size) : true) && $qty >= 0) {
        $stmt = $pdo->prepare("
            INSERT INTO inventory (name, cup_size, unit, qty, critical_level, date_added)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$name, $cup_size ?: null, $unit, $qty, $crit]);

        $inv_id = $pdo->lastInsertId();
        $log = $pdo->prepare("
            INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)
            VALUES (?, 'restock', ?, ?, 'Manual Add', NOW())
        ");
        $log->execute([$inv_id, $qty, $qty]);

        header("Location: inventory.php?success=1");
        exit;
    }
}

/* 🔹 REPORT DAMAGED MATERIAL */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["report_damage"])) {
    $id = intval($_POST["id"]);
    $qty = floatval($_POST["quantity"]);
    $remarks = trim($_POST["remarks"]);

    $stmt = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if ($item && $qty > 0 && $item['qty'] >= $qty) {
        $upd = $pdo->prepare("UPDATE inventory SET qty = qty - ? WHERE id = ?");
        $upd->execute([$qty, $id]);

        $stmt = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        $remaining = $stmt->fetchColumn();

        $log = $pdo->prepare("
            INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)
            VALUES (?, 'damage', ?, ?, ?, NOW())
        ");
        $log->execute([$id, $qty, $remaining, "Damage: " . $remarks]);

        header("Location: inventory.php?damage=1");
        exit;
    } else {
        header("Location: inventory.php?error=1");
        exit;
    }
}

/* 🔹 RESTOCK INVENTORY ITEM */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["restock_inventory"])) {
    $id = intval($_POST["id"]);
    $qty = floatval($_POST["quantity"]);
    $remarks = trim($_POST["remarks"]);

    if ($qty > 0) {
        $upd = $pdo->prepare("UPDATE inventory SET qty = qty + ? WHERE id = ?");
        $upd->execute([$qty, $id]);

        $stmt = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        $remaining = $stmt->fetchColumn();

        $log = $pdo->prepare("
            INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)
            VALUES (?, 'restock', ?, ?, ?, NOW())
        ");
        $log->execute([$id, $qty, $remaining, "Restock: " . $remarks]);

        header("Location: inventory.php?success=1");
        exit;
    } else {
        header("Location: inventory.php?error=1");
        exit;
    }
}

/* 🔹 USE INVENTORY ITEM */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["use_inventory"])) {
    $id = intval($_POST["id"]);
    $qty = floatval($_POST["quantity"]);
    $remarks = trim($_POST["remarks"]);

    // Prevent deduction if order is void/cancelled
    if (isset($_POST['order_status']) && in_array($_POST['order_status'], ['void', 'cancelled'])) {
        header("Location: inventory.php?error=voided_order");
        exit;
    }

    // Fetch inventory item details
    $stmt = $pdo->prepare("SELECT name, cup_size, qty FROM inventory WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    // Prevent straw deduction for hot coffee
    if ($item && stripos($item['name'], 'straw') !== false && $item['cup_size'] === '12oz Hot') {
        header("Location: inventory.php?error=straw_hot");
        exit;
    }

    if ($item && $qty > 0 && $item['qty'] >= $qty) {
        $upd = $pdo->prepare("UPDATE inventory SET qty = qty - ? WHERE id = ?");
        $upd->execute([$qty, $id]);

        $stmt = $pdo->prepare("SELECT qty FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        $remaining = $stmt->fetchColumn();

        $log = $pdo->prepare("
            INSERT INTO inventory_logs (inventory_id, change_type, quantity, remaining, remarks, created_at)
            VALUES (?, 'used', ?, ?, ?, NOW())
        ");
        $log->execute([$id, $qty, $remaining, "Used: " . $remarks]);

        header("Location: inventory.php?used=1");
        exit;
    } else {
        header("Location: inventory.php?error=1");
        exit;
    }
}

/* 🔹 SEARCH FEATURE */
$search = trim($_GET['search'] ?? '');
if ($search) {
    // For straw, always ignore cup_size in search
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE (name LIKE ? OR unit LIKE ? OR (name NOT LIKE '%straw%' AND cup_size LIKE ?)) ORDER BY name ASC, cup_size ASC, id DESC");
    $stmt->execute(["%$search%", "%$search%", "%$search%"]);
    $inventory = $stmt->fetchAll();
} else {
    $inventory = $pdo->query("SELECT * FROM inventory ORDER BY name ASC, cup_size ASC, id DESC")->fetchAll();
}

/* 🔹 FETCH RECENT LOGS */
$logs = $pdo->query("
    SELECT l.id, i.name, i.cup_size, l.change_type, l.quantity, l.remaining, i.critical_level, l.remarks, l.created_at
    FROM inventory_logs l
    JOIN inventory i ON l.inventory_id = i.id
    ORDER BY l.created_at DESC
    LIMIT 20
")->fetchAll();

// 🔹 GROUP LOGS BY TYPE
$grouped_logs = ['Straw'=>[], 'Cup'=>[], 'Lid'=>[], 'Others'=>[]];
foreach ($logs as $log) {
    $type = getItemType($log['name']);
    $grouped_logs[$type][] = $log;
}

/* 🔹 FETCH LOW STOCK ITEMS */
$low_stock_items = $pdo->query("
    SELECT name, cup_size, qty, unit, critical_level
    FROM inventory
    WHERE critical_level IS NOT NULL AND qty <= critical_level AND critical_level > 0
")->fetchAll();

/* 🔹 CHECK REQUIRED ITEMS */
$required_sizes = ['12oz Hot', '16oz', '22oz'];
$missing_cup_sizes = [];
$missing_lid_sizes = [];
foreach ($required_sizes as $size) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%cup%' AND cup_size = ?");
    $stmt->execute([$size]);
    if ($stmt->fetchColumn() == 0) $missing_cup_sizes[] = $size;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%') AND cup_size = ?");
    $stmt->execute([$size]);
    if ($stmt->fetchColumn() == 0) $missing_lid_sizes[] = $size;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%straw%'");
$stmt->execute();
$missing_straw = ($stmt->fetchColumn() == 0);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%stirrer%'");
$stmt->execute();
$missing_stirrer = ($stmt->fetchColumn() == 0);

/* 🔹 GROUPING LOGIC */
function getItemType($name) {
    $n = strtolower($name);
    if (strpos($n, 'straw') !== false) return 'Straw';
    if (strpos($n, 'cup') !== false) return 'Cup';
    if (strpos($n, 'lid') !== false) return 'Lid';
    return 'Others';
}
// Group and sort inventory
$grouped_inventory = ['Straw'=>[], 'Cup'=>[], 'Lid'=>[], 'Others'=>[]];
foreach ($inventory as $inv) {
    $type = getItemType($inv['name']);
    $grouped_inventory[$type][] = $inv;
}
// Sort each group by name then cup_size
foreach ($grouped_inventory as &$items) {
    usort($items, function($a, $b) {
        $cmp = strcmp(strtolower($a['name']), strtolower($b['name']));
        if ($cmp === 0) {
            return strcmp((string)$a['cup_size'], (string)$b['cup_size']);
        }
        return $cmp;
    });
}
unset($items);

// Fetch voided/cancelled orders for today (fix status values)
$voided_orders_today = $pdo->prepare("
    SELECT o.id, o.created_at, u.username, o.total, o.status
    FROM orders o
    LEFT JOIN users u ON o.user_id = u.id
    WHERE (o.status = 'void' OR o.status = 'cancelled') AND DATE(o.created_at) = CURDATE()
    ORDER BY o.created_at DESC
");
$voided_orders_today->execute();
$voided_orders_today = $voided_orders_today->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kape Agosto | Inventory</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  </head>
<body class="kape-orders-order-create">
  <?php include dirname(__DIR__, 2) . "/includes/sidebar.php"; ?>
  <?php include dirname(__DIR__, 2) . "/includes/topbar.php"; ?>

  <div class="content">
    <!-- Realtime order count display -->
    <div class="alert info" style="background:#e7f3fe;color:#31708f;border-color:#bce8f1;margin-bottom:16px;">
      <b>Orders Today:</b> <?= $order_count_today ?>
    </div>
    <h1>📦 Inventory Management</h1>

    <!-- Notifications -->
    <?php if(isset($_GET['success'])): ?>
      <div class="alert success">✅ Inventory added successfully!</div>
    <?php elseif(isset($_GET['damage'])): ?>
      <div class="alert warning">⚠️ Damage report recorded successfully!</div>
    <?php elseif(isset($_GET['error']) && $_GET['error'] === 'straw_hot'): ?>
      <div class="alert error">⚠️ For hot coffee (12oz Hot), <b>straw is NOT deducted</b> from inventory. Only Coffee Stirrer should be used.</div>
    <?php elseif(isset($_GET['error']) && $_GET['error'] === 'voided_order'): ?>
      <div class="alert error">⚠️ No inventory deduction for voided/cancelled orders!</div>
    <?php elseif(isset($_GET['error'])): ?>
      <div class="alert error">⚠️ Invalid input or insufficient stock!</div>
    <?php elseif(isset($_GET['used'])): ?>
      <div class="alert success">✅ Materials deducted from inventory!</div>
    <?php endif; ?>

    <!-- Grouped Missing Items Warnings -->
    <?php if($missing_straw || $missing_stirrer || !empty($missing_cup_sizes) || !empty($missing_lid_sizes)): ?>
      <div class="alert error">
        <b>⚠️ Missing Required Inventory Items:</b>
        <ul style="margin-top:8px;">
          <?php if($missing_straw): ?>
            <li><b>Straw</b> <span style="color:#888;">(required for cold drinks)</span></li>
          <?php endif; ?>
          <?php if($missing_stirrer): ?>
            <li><b>Coffee Stirrer</b> <span style="color:#888;">(required for hot drinks)</span></li>
          <?php endif; ?>
          <?php if(!empty($missing_cup_sizes)): ?>
            <li>
              Missing cup sizes:
              <ul>
                <?php foreach($missing_cup_sizes as $size): ?>
                  <li><?= htmlspecialchars($size) ?></li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endif; ?>
          <?php if(!empty($missing_lid_sizes)): ?>
            <li>
              Missing flat lid cover sizes:
              <ul>
                <?php foreach($missing_lid_sizes as $size): ?>
                  <li><?= htmlspecialchars($size) ?></li>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="alert info" style="background:#fff3cd;color:#856404;border-color:#ffeeba;">
      <b>Note:</b> For hot coffee (12oz Hot), <b>straw is NOT deducted</b> from inventory. Only Coffee Stirrer is required.<br>
      For cold drinks, <b>straw is deducted only ONCE per order</b> (not per item).
    </div>

    <!-- Add Inventory -->
    <h2>➕ Add New Inventory</h2>
    <form method="post" class="form-grid" id="addInventoryForm" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
      <input type="hidden" name="add_inventory" value="1">
      <input type="text" name="name" id="item_name" placeholder="Item Name" required oninput="toggleCupSizeField()" style="flex:1 1 120px; min-width:120px;">
      <select name="cup_size" id="cup_size_field" style="display:none; flex:1 1 120px; min-width:120px;">
        <option value="">-- Select Size --</option>
        <option value="all">All Sizes</option>
        <option value="12oz Hot">12oz Hot</option>
        <option value="16oz">16oz</option>
        <option value="22oz">22oz</option>
      </select>
      <select id="unit_field" name="unit" required style="flex:1 1 80px; min-width:80px;">
        <option value="pcs">pcs</option>
        <option value="g">g</option>
        <option value="ml">ml</option>
        <option value="pack">pack</option>
        <option value="box">box</option>
        <option value="set">set</option>
        <option value="sachet">sachet</option>
        <option value="tray">tray</option>
        <option value="bottle">bottle</option>
        <option value="other">Other (type below)</option>
      </select>
      <input type="text" id="unit_custom" placeholder="Custom Unit" style="display:none; flex:1 1 80px; min-width:80px;">
      <input type="number" step="0.01" name="quantity" placeholder="Quantity" min="0" required style="flex:1 1 80px; min-width:80px;">
      <input type="number" step="0.01" name="critical_level" placeholder="Critical Level (optional)" min="0" style="flex:1 1 120px; min-width:120px;">
      <button type="submit" class="btn green" style="flex:0 0 auto;"><i class="fa fa-plus"></i> Add Inventory</button>
    </form>

    <!-- Inventory Search -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin:20px 0;">
      <form method="get" style="display:flex; gap:10px;">
        <input type="text" name="search" placeholder="🔍 Search item..." value="<?= htmlspecialchars($search) ?>" style="padding:5px;">
        <button type="submit" class="btn blue"><i class="fa fa-search"></i> Search</button>
        <?php if($search): ?>
          <a href="inventory.php" class="btn gray">Reset</a>
        <?php endif; ?>
      </form>
      <div style="display:flex; gap:10px;">
        <?php if (is_admin()): ?>
          <a href="reports.php" class="btn blue"><i class="fa fa-chart-line"></i> View Reports</a>
        <?php endif; ?>
        <button type="button" class="btn btn-danger" style="background:#e74c3c; color:#fff;" onclick="showDamageDropdownModal()">
          <i class="fa fa-exclamation-triangle"></i> Report Damage
        </button>
      </div>
    </div>

    <!-- Damage Dropdown Modal -->
    <div id="damageDropdownModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
      background:rgba(0,0,0,0.6); justify-content:center; align-items:center; z-index:1000;">
      <div style="background:#fff; padding:20px; border-radius:8px; width:350px;">
        <h3>Select Item to Report Damage</h3>
        <select id="damage_item_select" style="width:100%; margin-bottom:16px;">
          <?php
          $all_items = array_merge(...array_values($grouped_inventory));
          foreach ($all_items as $item):
            $label = $item['name'] . ($item['cup_size'] ? ' (' . $item['cup_size'] . ')' : '');
          ?>
            <option value="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="text-align:right;">
          <button type="button" class="btn gray" onclick="closeDamageDropdownModal()">Cancel</button>
          <button type="button" class="btn red" style="background:#e74c3c; color:#fff;" onclick="selectDamageItem()">Next</button>
        </div>
      </div>
    </div>

    <!-- Damage Modal -->
    <div id="damageModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
      background:rgba(255, 255, 255, 0.6); justify-content:center; align-items:center;">
      <div style="background:#fff; padding:20px; border-radius:8px; width:400px;">
        <h3>Report Damaged Item</h3>
        <form method="post">
          <input type="hidden" name="report_damage" value="1">
          <input type="hidden" name="id" id="damage_id">
          <label>Item Name</label>
          <input type="text" id="damage_name" readonly style="width:100%; margin-bottom:10px;">
          <label>Quantity Affected</label>
          <input type="number" step="0.01" name="quantity" required style="width:100%; margin-bottom:10px;">
          <label>Remarks / Description</label>
          <textarea name="remarks" rows="3" required style="width:100%; margin-bottom:10px;"></textarea>
          <div style="text-align:right;">
            <button type="button" onclick="closeDamageReport()" class="btn gray">Cancel</button>
            <button type="submit" class="btn red" style="background:#e74c3c; color:#fff;">Submit</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Inventory Table & Low Stock Warning -->
    <div id="inventoryRealtime">
      <h2>📋 Current Inventory</h2>
      <table>
        <thead>
          <tr>
            <th>#</th><th>Name</th><th>Cup Size</th><th>Qty</th><th>Unit</th><th>Critical</th><th>Date & Time</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $rownum = 1;
          foreach ($grouped_inventory as $type => $items):
            if (count($items) > 0): ?>
              <tr style="background:#f5f5f5; font-weight:bold;">
                <td colspan="7"><?= htmlspecialchars($type) ?></td>
              </tr>
              <?php foreach($items as $inv): ?>
                <?php
                $is_low = ($inv['critical_level'] !== null && $inv['critical_level'] > 0 && $inv['qty'] <= $inv['critical_level']);
                ?>
                <tr class="<?= $is_low ? 'low-stock' : '' ?>"
                    style="<?= $is_low ? 'background:#ffdddd;' : '' ?>">
                  <td><?= $rownum++ ?></td>
                  <td><?= htmlspecialchars($inv['name']) ?></td>
                  <td>
                    <?php
                    // For straw, always show "-"
                    echo (stripos($inv['name'], 'straw') !== false) ? '-' : htmlspecialchars($inv['cup_size'] ?? '-');
                    ?>
                  </td>
                  <td><?= formatQty($inv['qty']) ?></td>
                  <td><?= htmlspecialchars($inv['unit']) ?></td>
                  <td><?= $inv['critical_level'] !== null ? formatQty($inv['critical_level']) : 'N/A' ?></td>
                  <td><?= $inv['date_added'] ? date('F d, Y h:i A', strtotime($inv['date_added'])) : '-' ?></td>
                </tr>
              <?php endforeach;
            endif;
          endforeach;
          ?>
        </tbody>
      </table>
    </div>

    <!-- Recent Logs -->
    <h2>🕑 Recent Inventory Logs</h2>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Item</th><th>Cup Size</th><th>Type</th><th>Qty</th><th>Remaining</th><th>Critical</th><th>Remarks</th><th>Date & Time</th>
        </tr>
      </thead>
      <tbody>
        <?php
        foreach ($grouped_logs as $type => $logsArr):
          if (count($logsArr) > 0): ?>
            <tr style="background:#f5f5f5; font-weight:bold;">
              <td colspan="9"><?= htmlspecialchars($type) ?></td>
            </tr>
            <?php foreach($logsArr as $log):
              $loss = in_array($log['change_type'], ['used','damage']); ?>
              <tr style="<?= $loss ? 'background:#ffeaea;' : '' ?>">
                <td><?= $log['id'] ?></td>
                <td><?= htmlspecialchars($log['name']) ?></td>
                <td><?= htmlspecialchars($log['cup_size'] ?? '-') ?></td>
                <td><?= ucfirst($log['change_type']) ?></td>
                <td><?= formatQty($log['quantity']) ?></td>
                <td><?= formatQty($log['remaining']) ?></td>
                <td><?= $log['critical_level'] !== null ? formatQty($log['critical_level']) : 'N/A' ?></td>
                <td><?= htmlspecialchars($log['remarks']) ?></td>
                <td><?= $log['created_at'] ? date('F d, Y h:i A', strtotime($log['created_at'])) : '-' ?></td>
              </tr>
            <?php endforeach;
          endif;
        endforeach;
        ?>
      </tbody>
    </table>

    <!-- Voided/Cancelled Orders Today -->
    <h2>🗑️ Voided/Cancelled Orders Today</h2>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Date & Time</th><th>User</th><th>Total</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($voided_orders_today as $order): ?>
          <tr>
            <td><?= $order['id'] ?></td>
            <td><?= date("F d, Y h:i A", strtotime($order['created_at'])) ?></td>
            <td><?= htmlspecialchars($order['username']) ?></td>
            <td><?= formatCurrency($order['total']) ?></td>
            <td><?= htmlspecialchars(ucfirst($order['status'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script>
    // Toggle custom unit input field
    $('#unit_field').on('change', function() {
      if ($(this).val() === 'other') {
        $('#unit_custom').show().prop('required', true);
      } else {
        $('#unit_custom').hide().prop('required', false);
      }
    });

    // Form validation and submission for add inventory
    $('#addInventoryForm').on('submit', function(e) {
      e.preventDefault();

      // Custom validation for quantity and critical level
      var qty = parseFloat($('input[name="quantity"]').val());
      var crit = parseFloat($('input[name="critical_level"]').val());
      if (isNaN(qty) || qty < 0) {
        window.kapeNotify('Invalid quantity!', 'error');
        return;
      }
      if (!isNaN(crit) && crit < 0) {
        window.kapeNotify('Invalid critical level!', 'error');
        return;
      }

      this.submit();
    });

    // Show damage dropdown modal
    function showDamageDropdownModal() {
      $('#damageDropdownModal').show();
    }

    // Close damage dropdown modal
    function closeDamageDropdownModal() {
      $('#damageDropdownModal').hide();
    }

    // Select damage item and open damage report modal
    function selectDamageItem() {
      var itemId = $('#damage_item_select').val();
      var itemName = $('#damage_item_select option:selected').data('name');

      if (itemId) {
        $('#damage_id').val(itemId);
        $('#damage_name').val(itemName);
        closeDamageDropdownModal();
        $('#damageModal').show();
      }
    }

    // Close damage report modal
    function closeDamageReport() {
      $('#damageModal').hide();
    }

    // Print inventory report
    function printInventoryReport() {
      var printContents = document.getElementById('inventoryRealtime').innerHTML;
      var win = window.open('', '', 'height=700,width=900');
      win.document.write('<html><head><title>Inventory Report</title>');
      win.document.write(<?= json_encode('<link rel="stylesheet" href="' . kape_asset_url('css/style.css') . '">') ?>);
      win.document.write('</head><body>');
      win.document.write('<h1>Inventory Report</h1>');
      win.document.write(printContents);
      win.document.write('</body></html>');
      win.document.close();
      win.print();
    }

    // Show/hide cup size field for cup and flat lid cover
    function toggleCupSizeField() {
      var name = $('#item_name').val().toLowerCase();
      if (name.includes('cup') || name.includes('flat lid cover') || name.includes('flat lid') || name.includes('lid cover') || name.includes('flat cover')) {
        $('#cup_size_field').show().prop('required', true);
      } else {
        $('#cup_size_field').hide().prop('required', false).val('');
      }
    }
  </script>
</body>
</html>
