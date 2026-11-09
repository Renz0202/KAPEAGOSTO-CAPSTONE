<?php
// Run with: C:\xampp\php\php.exe tests/inventory_reconciliation_test.php
// Connection-local temporary tables isolate every fixture from live data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_reconciliation.php';
$pdo = db();
$tables = [
    'inventory' => 'id INT PRIMARY KEY, sku VARCHAR(50), name VARCHAR(100), material_type VARCHAR(20), unit VARCHAR(10), qty DECIMAL(10,2)',
    'items' => 'id INT PRIMARY KEY, name VARCHAR(100), cup_size VARCHAR(50)',
    'item_requirements' => 'id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2)',
    'item_recipe_metadata' => 'item_id INT PRIMARY KEY, source VARCHAR(20), notes VARCHAR(100), updated_at DATETIME',
    'orders' => 'id INT PRIMARY KEY, order_code VARCHAR(50), status VARCHAR(20), created_at DATETIME, inventory_deducted_at DATETIME NULL',
    'order_items' => 'id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, item_id INT, qty INT',
    'inventory_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY, inventory_id INT, order_id INT NULL, change_type VARCHAR(30), quantity DECIMAL(10,2), remaining DECIMAL(10,2), remarks VARCHAR(255), created_at DATETIME',
    'payments' => 'id INT PRIMARY KEY, order_id INT, amount DECIMAL(10,2)',
    'notifications' => 'id INT PRIMARY KEY, message VARCHAR(100)',
];
foreach ($tables as $table => $columns) {
    $pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' (' . $columns . ') ENGINE=InnoDB');
}
$checks = 0;
function check_reconciliation(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: $label\n";
}
function snapshot_reconciliation(PDO $pdo, array $tables): string {
    $rows = [];
    foreach ($tables as $table) $rows[$table] = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
}
function preview_reconciliation(PDO $pdo): array {
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try { return inventory_reconciliation_preview($pdo); }
    finally { $pdo->rollBack(); }
}
function rejects_reconciliation(callable $action): bool {
    try { $action(); } catch (RuntimeException $error) { return true; }
    return false;
}
$pdo->exec("INSERT INTO inventory VALUES
    (1,'BEAN','COFFEE BEANS','raw_material','g',1000),
    (2,'MILK','FRESH MILK','raw_material','ml',100),
    (3,'SYRUP','VANILLA SYRUP','raw_material','ml',10),
    (4,'OLD','OLD INGREDIENT','raw_material','g',20),
    (10,'CUP','CUP','packaging','pcs',2),
    (11,'LID','LID','packaging','pcs',2),
    (12,'STRAW','STRAW','packaging','pcs',2),
    (13,'NAPKIN','NAPKIN','packaging','pcs',2)");
$pdo->exec("INSERT INTO items VALUES (1,'LATTE','16oz'), (2,'LATTE','22oz')");
$pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES
    (1,1,18.25),(1,2,150),(1,3,0.25),(1,10,1),(1,11,1),(1,12,1),(1,13,1),
    (2,1,22),(2,2,250),(2,3,0.50)');
$pdo->exec("INSERT INTO item_recipe_metadata VALUES
    (1,'generated','Starter estimate','2026-10-09 18:00:00'),
    (2,'custom','House recipe','2026-10-09 18:00:00')");
$pdo->exec("INSERT INTO orders VALUES
    (1,'TEST-PARTIAL','completed','2026-09-16 09:00:00','2026-09-16 09:01:00'),
    (2,'TEST-FULL','Completed','2026-09-17 09:00:00','2026-09-17 09:01:00'),
    (3,'TEST-PENDING','pending','2026-09-18 09:00:00',NULL),
    (4,'TEST-VOID','void','2026-09-18 10:00:00',NULL),
    (5,'TEST-CANCELLED','cancelled','2026-09-18 11:00:00',NULL),
    (6,'TEST-OVER','completed','2026-09-19 09:00:00','2026-09-19 09:01:00'),
    (7,'TEST-LEGACY',' completed ','2026-09-20 09:00:00',NULL)");
$pdo->exec('INSERT INTO order_items (order_id,item_id,qty) VALUES
    (1,1,2),(1,2,1),(2,1,1),(3,1,999),(4,1,999),(5,1,999),(6,1,1),(7,2,1)');
$pdo->exec("INSERT INTO inventory_logs (inventory_id,order_id,change_type,quantity,remaining,remarks,created_at) VALUES
    (1,1,'used',10,990,'Completed order','2026-09-16 09:01:00'),
    (1,1,'consumed',8.25,981.75,'Completed order','2026-09-16 09:01:01'),
    (2,1,'used',200,100,'Completed order','2026-09-16 09:01:00'),
    (3,1,'used',0.25,10,'Completed order','2026-09-16 09:01:00'),
    (10,1,'used',3,2,'Completed order','2026-09-16 09:01:00'),
    (11,1,'used',3,2,'Completed order','2026-09-16 09:01:00'),
    (12,1,'used',3,2,'Completed order','2026-09-16 09:01:00'),
    (13,1,'used',3,2,'Completed order','2026-09-16 09:01:00'),
    (1,2,'used',18.25,900,'Completed order','2026-09-17 09:01:00'),
    (2,2,'used',150,100,'Completed order','2026-09-17 09:01:00'),
    (3,2,'used',0.25,10,'Completed order','2026-09-17 09:01:00'),
    (1,6,'used',20,880,'Older recipe','2026-09-19 09:01:00'),
    (2,6,'used',150,100,'Completed order','2026-09-19 09:01:00'),
    (3,6,'used',0.25,10,'Completed order','2026-09-19 09:01:00'),
    (4,6,'used',1,20,'Older recipe','2026-09-19 09:01:00'),
    (2,NULL,'restock',100,100,'Opening stock','2026-10-09 18:00:00')");
$pdo->exec('INSERT INTO payments VALUES (1,1,300), (2,2,100)');
$pdo->exec("INSERT INTO notifications VALUES (1,'Stock reminder')");
$before = snapshot_reconciliation($pdo, array_keys($tables));
$preview = preview_reconciliation($pdo);
$byIngredient = array_column($preview['balances'], null, 'inventory_id');
$byOrder = [];
foreach ($preview['corrections'] as $row) $byOrder[$row['order_id']][$row['inventory_id']] = $row;
check_reconciliation($preview['summary']['completed_orders'] === 4, 'Only completed orders are reviewed, including normalized legacy statuses');
check_reconciliation(array_column($preview['excluded_orders'], 'orders', 'status') == ['cancelled'=>1,'pending'=>1,'void'=>1], 'Pending, void and cancelled orders are excluded');
check_reconciliation($preview['summary']['orders_needing_correction'] === 2 && $preview['summary']['ingredient_corrections'] === 6, 'Only orders with positive missing use need correction');
check_reconciliation($preview['summary']['orders_with_recorded_ingredient_usage'] === 3, 'Existing ingredient usage is detected separately from packaging');
check_reconciliation($byOrder[1][1]['expected_units'] === 5850 && $byOrder[1][1]['recorded_units'] === 1825 && $byOrder[1][1]['missing_units'] === 4025, 'Recipe variants and quantities aggregate; split historical deductions count once');
check_reconciliation($byOrder[1][2]['missing_units'] === 35000, 'Partially recorded milk is subtracted from the recipe total');
check_reconciliation($byOrder[1][3]['missing_units'] === 75 && $byIngredient[3]['missing_usage_units'] === 125, 'Fractional recipe and recorded quantities remain exact');
check_reconciliation(!isset($byOrder[2]) && !isset($byOrder[6]), 'Fully recorded and over-recorded orders receive no duplicate deduction');
check_reconciliation(array_keys($byIngredient) === [1,2,3] && $preview['packaging_included'] === false, 'All packaging is excluded even when explicitly saved in the recipe');
check_reconciliation($byIngredient[1]['missing_usage_units'] === 6225 && $byIngredient[1]['proposed_stock_units'] === 93775, 'Missing usage and proposed stock are calculated across orders');
check_reconciliation($byIngredient[2]['missing_usage_units'] === 60000 && $byIngredient[2]['proposed_stock_units'] === -50000 && $byIngredient[2]['shortage_units'] === 50000, 'Shortages remain visible without clamping or adding a restock');
check_reconciliation($preview['summary']['ingredients_with_shortages'] === 1, 'Only insufficient balances are flagged');
check_reconciliation(count($preview['over_recorded_usage']) === 1 && $preview['over_recorded_usage'][0]['recorded_units'] === 2000, 'Over-recorded usage is reported without returning stock');
check_reconciliation(count($preview['warnings']) === 1 && $preview['warnings'][0]['inventory_id'] === 4, 'Previously used ingredients removed from a recipe are preserved for review');
check_reconciliation($preview['issues'] === [] && $preview['recipe_sources'] === ['generated'=>1,'custom'=>1], 'Recipe provenance is included without treating restocks as consumption');
check_reconciliation(isset($byOrder[7]) && $preview['order_ids'] === [1,2,6,7], 'A legacy completion without a deduction marker is still reviewed');
$repeat = preview_reconciliation($pdo);
check_reconciliation($repeat['state_fingerprint'] === $preview['state_fingerprint'] && $repeat['balances'] === $preview['balances'] && $repeat['corrections'] === $preview['corrections'], 'Repeating a preview gives the same result without deducting stock');
check_reconciliation(snapshot_reconciliation($pdo, array_keys($tables)) === $before, 'Stock, movements, orders, recipes, payments and notifications remain byte-for-byte unchanged');
$report = inventory_reconciliation_report($preview);
check_reconciliation(strpos($report, '-500.00') !== false && strpos($report, 'No inventory, orders, or movement records have been changed') !== false, 'The human-readable report shows negative balances and preview-only status');
check_reconciliation(inventory_reconciliation_units('0.01') === 1 && inventory_reconciliation_units('99999999.99') === 9999999999 && inventory_reconciliation_quantity(-125) === '-1.25', 'Decimal parsing and formatting preserve the supported range');
foreach (['-1','0.001','1e3','100000000','garbage'] as $bad) {
    check_reconciliation(rejects_reconciliation(static fn() => inventory_reconciliation_units($bad)), 'Invalid quantity is rejected: ' . $bad);
}
check_reconciliation(rejects_reconciliation(static fn() => inventory_reconciliation_add(PHP_INT_MAX, 1)), 'Usage totals cannot silently overflow');

// Ambiguous and incomplete history must be visible in the preview.
$pdo->exec("INSERT INTO items VALUES (3,'NO RECIPE','16oz')");
$pdo->exec("INSERT INTO orders VALUES
    (8,'NO-LINES','completed','2026-09-21 09:00:00',NULL),
    (9,'NO-RECIPE','completed','2026-09-21 10:00:00',NULL),
    (10,'NO-ITEM','completed','2026-09-21 11:00:00',NULL),
    (11,'BAD-QTY','completed','2026-09-21 12:00:00',NULL)");
$pdo->exec('INSERT INTO order_items (order_id,item_id,qty) VALUES (9,3,1),(10,999,1),(11,1,0)');
$pdo->exec("INSERT INTO inventory_logs (inventory_id,order_id,change_type,quantity,remaining,remarks,created_at) VALUES
    (2,NULL,'used',1,99,'Unlinked old use','2026-09-21 09:00:00'),
    (2,1,'adjustment',1,99,'Manual correction','2026-09-21 09:00:00')");
$before = snapshot_reconciliation($pdo, array_keys($tables));
$ambiguous = preview_reconciliation($pdo);
check_reconciliation(count($ambiguous['issues']) === 5, 'Missing recipes, menu items, quantities, order lines and unlinked use are flagged');
check_reconciliation(count($ambiguous['warnings']) === 2, 'Other linked movements require review instead of being silently netted off');
check_reconciliation($ambiguous['balances'] === $preview['balances'], 'Unassigned usage and invalid order lines do not invent corrections');
check_reconciliation($ambiguous['state_fingerprint'] !== $preview['state_fingerprint'], 'Source changes invalidate the preview fingerprint');
check_reconciliation(snapshot_reconciliation($pdo, array_keys($tables)) === $before, 'Ambiguous-data preview also leaves every table unchanged');
$pdo->exec('UPDATE item_requirements SET quantity_needed = 0 WHERE item_id = 1 AND inventory_id = 1');
check_reconciliation(rejects_reconciliation(static fn() => preview_reconciliation($pdo)), 'A zero ingredient recipe is rejected before a misleading preview is returned');
$pdo->exec("UPDATE orders SET status = 'pending'");
$empty = preview_reconciliation($pdo);
check_reconciliation($empty['summary']['completed_orders'] === 0 && $empty['balances'] === [] && $empty['corrections'] === [], 'No completed orders produces an empty correction plan');
echo "All $checks reconciliation checks passed. Live data was not modified.\n";
