<?php
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_capability('view_stock_availability');
$pdo = db();

// Centralized inventory status check function
function get_inventory_status($pdo) {
    $inventoryCount = $pdo->query("SELECT COUNT(*) FROM inventory")->fetchColumn();
    $required_sizes = ['12oz Hot', '16oz', '22oz'];
    $missing_cup_sizes = [];
    $missing_lid_sizes = [];
    foreach ($required_sizes as $size) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%cup%' AND cup_size = ?");
        $stmt->execute([$size]);
        if ($stmt->fetchColumn() == 0) $missing_cup_sizes[] = $size;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%flat lid%' AND cup_size = ?");
        $stmt->execute([$size]);
        if ($stmt->fetchColumn() == 0) $missing_lid_sizes[] = $size;
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE name LIKE '%stirrer%'");
    $stmt->execute();
    $missing_stirrer = ($stmt->fetchColumn() == 0);

    $low_stock_items = [];
    $inv_stmt = $pdo->query("SELECT name, cup_size, qty, unit, critical_level FROM inventory");
    foreach ($inv_stmt as $inv) {
        if ($inv['critical_level'] !== null && $inv['critical_level'] > 0 && $inv['qty'] <= $inv['critical_level']) {
            $low_stock_items[] = $inv;
        }
    }

    // Only block if missing required items or inventory is empty
    $inventory_blocked = (
        $inventoryCount == 0 ||
        !empty($missing_cup_sizes) ||
        !empty($missing_lid_sizes) ||
        $missing_stirrer
    );

    return [
        'inventoryCount' => $inventoryCount,
        'missing_cup_sizes' => $missing_cup_sizes,
        'missing_lid_sizes' => $missing_lid_sizes,
        'missing_stirrer' => $missing_stirrer,
        'low_stock_items' => $low_stock_items,
        'inventory_blocked' => $inventory_blocked
    ];
}

$status = get_inventory_status($pdo);
$inventoryCount = $status['inventoryCount'];
$missing_cup_sizes = $status['missing_cup_sizes'];
$missing_lid_sizes = $status['missing_lid_sizes'];
$missing_stirrer = $status['missing_stirrer'];
$low_stock_items = $status['low_stock_items'];
$inventory_blocked = $status['inventory_blocked'];

if ($inventory_blocked):
?>
<div class="alert-success" style="background:#f8d7da;color:#721c24;border-color:#f5c6cb;">
    ⚠️ Cannot checkout: Missing required inventory items.<br>
    <?php if ($inventoryCount == 0): ?>
      No inventory items found! You cannot checkout any orders until materials/items are added.<br>
    <?php endif; ?>
    <?php if (!empty($missing_cup_sizes)): ?>
      Missing cup sizes: <?= implode(', ', array_map('htmlspecialchars', $missing_cup_sizes)) ?><br>
    <?php endif; ?>
    <?php if (!empty($missing_lid_sizes)): ?>
      Missing flat lid cover sizes: <?= implode(', ', array_map('htmlspecialchars', $missing_lid_sizes)) ?><br>
    <?php endif; ?>
    <?php if ($missing_stirrer): ?>
      Missing required item: Coffee Stirrer<br>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($low_stock_items)): ?>
<div class="alert-success" style="background:#fff3cd;color:#856404;border-color:#ffeeba;">
    ⚠️ Warning: Low stock items (transactions still allowed, but please restock soon):
    <ul>
        <?php foreach ($low_stock_items as $item): ?>
          <li>
            <?= htmlspecialchars($item['name']) ?>
            <?= $item['cup_size'] ? '(' . htmlspecialchars($item['cup_size']) . ')' : '' ?>:
            <b><?= number_format($item['qty'],2) ?> <?= htmlspecialchars($item['unit']) ?></b>
            (Critical: <?= number_format($item['critical_level'],2) ?>)
          </li>
        <?php endforeach; ?>
    </ul>
    <div style="color:#856404; margin-top:8px;">
        <b>TIP:</b> If you have restocked, check if your item's <b>critical level</b> is set too high or if the quantity is still at/below the critical level.<br>
        <b>How to fix:</b> Go to Inventory Management, find the item, and adjust the <b>critical level</b> or restock until <b>qty > critical level</b>.
    </div>
</div>
<?php endif; ?>
