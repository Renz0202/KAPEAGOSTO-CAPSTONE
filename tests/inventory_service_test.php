<?php
// Run with: C:\xampp\php\php.exe tests/inventory_service_test.php
// All fixtures use connection-local TEMPORARY tables; real stock/orders are untouched.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_service.php';
$pdo = db();
foreach ([
    'inventory' => 'id INT PRIMARY KEY, name VARCHAR(100), material_type VARCHAR(20), cup_size VARCHAR(50) NULL, unit VARCHAR(50), qty DECIMAL(10,2), critical_level DECIMAL(10,2) NULL',
    'items' => 'id INT PRIMARY KEY, name VARCHAR(100), cup_size VARCHAR(50), price DECIMAL(10,2) DEFAULT 100',
    'item_recipe_metadata' => "item_id INT PRIMARY KEY, source VARCHAR(20), notes VARCHAR(500) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'item_requirements' => 'id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2), UNIQUE KEY (item_id, inventory_id)',
    'orders' => 'id INT PRIMARY KEY, order_code VARCHAR(50), status VARCHAR(20), inventory_deducted_at DATETIME NULL',
    'order_items' => 'id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, item_id INT, qty INT',
    'inventory_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY, inventory_id INT, order_id INT, user_id INT NULL, change_type VARCHAR(20), quantity DECIMAL(10,2), remaining DECIMAL(10,2), remarks VARCHAR(255), created_at DATETIME',
    'notifications' => 'id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, notification_type VARCHAR(50), entity_type VARCHAR(50), entity_id INT, dedupe_key VARCHAR(150) UNIQUE, message TEXT, is_read INT, read_at DATETIME NULL, created_at DATETIME',
] as $table => $columns) {
    $pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
}
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: $label\n";
}
function stock(PDO $pdo, int $id): float {
    $stmt = $pdo->prepare('SELECT qty FROM inventory WHERE id = ?');
    $stmt->execute([$id]);
    return (float) $stmt->fetchColumn();
}
function amounts(array $requirements): array {
    $result = [];
    foreach ($requirements as $row) $result[$row['inventory_id'] ?? $row['name']] = (float) $row['quantity'];
    return $result;
}
function rejects(callable $action): bool {
    try { $action(); } catch (RuntimeException | InvalidArgumentException | LogicException $e) { return true; }
    return false;
}
$pdo->exec("INSERT INTO inventory VALUES
    (1, 'COFFEE BEANS', 'raw_material', NULL, 'g', 1000, 980),
    (2, 'FRESH MILK', 'raw_material', NULL, 'ml', 1000, 100),
    (3, 'STRAWBERRY SYRUP', 'raw_material', NULL, 'ml', 1000, 100),
    (10, 'PLASTIC CUP', 'packaging', '16oz', 'pcs', 10, 2),
    (11, 'FLAT LID', 'packaging', '16oz', 'pcs', 10, 2),
    (12, 'STRAW', 'packaging', NULL, 'pcs', 10, 2),
    (13, 'COFFEE STIRRER', 'packaging', NULL, 'pcs', 10, 2),
    (14, 'PAPER CUP', 'packaging', '12oz Hot', 'pcs', 10, 2),
    (15, 'HOT LID', 'packaging', '12oz Hot', 'pcs', 10, 2)");
$pdo->exec("INSERT INTO items (id,name,cup_size) VALUES (1, 'LATTE', '16oz'), (2, 'MOCHA', '16oz'), (3, 'HOT LATTE', '12oz Hot')");
$pdo->exec('INSERT INTO item_requirements (item_id, inventory_id, quantity_needed) VALUES (1,1,18), (1,2,150), (2,1,18), (2,2,100), (3,1,18), (3,2,150)');
$pdo->exec("INSERT INTO orders VALUES (1,'TEST-1','pending',NULL), (2,'TEST-2','pending',NULL), (3,'TEST-3','cancelled',NULL), (4,'TEST-4','pending',NULL)");
$pdo->exec('INSERT INTO order_items (order_id,item_id,qty) VALUES (1,1,2), (1,2,1), (2,1,10), (3,1,1), (4,3,1)');
$requirements = inventory_service_order_requirements($pdo, 1);
$a = amounts($requirements);
check($a[1] === 54.0 && $a[2] === 400.0, 'Shared ingredients aggregate across quantities and products');
check($a[10] === 3.0 && $a[11] === 3.0 && $a[12] === 3.0 && !isset($a[13]), 'Ingredient-only recipes retain cold packaging');
check(stock($pdo, 1) === 1000.0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === 0, 'Checking a pending order does not deduct stock');
check(inventory_service_stock_warnings($pdo, [['id'=>1,'qty'=>1]])['blocking'] === [], 'Missing unrelated 22oz packaging does not block a 16oz drink');
$pdo->exec('UPDATE inventory SET qty = 40 WHERE id = 1');
$warnings = inventory_service_stock_warnings($pdo, [['id'=>1,'qty'=>2], ['id'=>2,'qty'=>1]]);
check(count($warnings['blocking']) === 1 && strpos($warnings['blocking'][0], '54 g') !== false, 'Checkout blocks the combined ingredient shortage with quantities and units');
$pdo->exec('UPDATE inventory SET qty = 1000 WHERE id = 1');
$pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (1,10,1)');
check(amounts(inventory_service_order_requirements($pdo, 1))[10] === 3.0, 'Explicit packaging is consumed once, without automatic duplication');
$pdo->exec('DELETE FROM inventory WHERE id = 12');
check(inventory_service_find_packaging($pdo, 'straw') === null, 'Strawberry syrup cannot be mistaken for straws');
$pdo->exec("INSERT INTO inventory VALUES (12,'STRAW','packaging',NULL,'pcs',10,2)");
$pdo->beginTransaction();
inventory_service_complete_order($pdo, 1);
$pdo->commit();
check(stock($pdo,1) === 946.0 && stock($pdo,2) === 600.0 && stock($pdo,10) === 7.0 && stock($pdo,12) === 7.0, 'Completion deducts exact ingredients and packaging');
check((int) $pdo->query('SELECT COUNT(*) FROM inventory_logs WHERE order_id = 1')->fetchColumn() === 5 && (bool) $pdo->query('SELECT inventory_deducted_at FROM orders WHERE id = 1')->fetchColumn(), 'Usage is logged against the order and completion is recorded');
check((int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE dedupe_key = 'low-stock-1'")->fetchColumn() === 1, 'Ingredient consumption refreshes low-stock notifications');
$pdo->beginTransaction();
inventory_service_complete_order($pdo, 1);
$pdo->commit();
check(stock($pdo,1) === 946.0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === 5, 'Repeated completion cannot deduct twice');
$pdo->beginTransaction();
$blocked = rejects(static fn() => inventory_service_complete_order($pdo, 2));
$pdo->rollBack();
check($blocked && stock($pdo,1) === 946.0 && stock($pdo,2) === 600.0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === 5, 'Insufficient stock prevents every deduction and log');
$pdo->beginTransaction();
$blocked = rejects(static fn() => inventory_service_complete_order($pdo, 3));
$pdo->rollBack();
check($blocked && stock($pdo,1) === 946.0, 'Cancelled orders cannot consume inventory');
$hot = amounts(inventory_service_order_requirements($pdo, 4));
check($hot[13] === 1.0 && $hot[14] === 1.0 && $hot[15] === 1.0 && !isset($hot[12]), 'Hot recipes use stirrers and hot packaging without straws');
$pdo->exec('DELETE FROM inventory WHERE id = 13');
$pdo->beginTransaction();
$blocked = rejects(static fn() => inventory_service_complete_order($pdo, 4));
$pdo->rollBack();
check($blocked && stock($pdo,1) === 946.0, 'Missing packaging blocks an ingredient recipe without partial deduction');
check(inventory_service_validate_recipe($pdo, [1=>'0.25', 2=>'', 3=>'0']) === [1=>0.25], 'Recipe validation keeps fractional quantities and skips blanks');
check(rejects(static fn() => inventory_service_validate_recipe($pdo, [1=>'-1'])) && rejects(static fn() => inventory_service_validate_recipe($pdo, [1=>'0.001'])) && rejects(static fn() => inventory_service_validate_recipe($pdo, [999=>'1'])), 'Invalid, negative, over-precision and unknown recipe materials are rejected');
$pdo->beginTransaction();
inventory_service_save_recipe($pdo, 1, [1=>20.5]);
$pdo->rollBack();
check((int) $pdo->query('SELECT COUNT(*) FROM item_requirements WHERE item_id = 1')->fetchColumn() === 3, 'Recipe replacement rolls back atomically');
check(rejects(static fn() => inventory_service_complete_order($pdo,1)), 'Completion requires an enclosing transaction');
$lines = inventory_service_checkout_lines($pdo, [['id'=>1, 'qty'=>2, 'price'=>0.01]]);
check($lines[0]['price'] === 100.0 && $lines[0]['qty'] === 2, 'Every order entry uses the catalog price');
check(rejects(static fn() => inventory_service_checkout_lines($pdo, [['id'=>1, 'qty'=>'1.5']])) && rejects(static fn() => inventory_service_checkout_lines($pdo, [['id'=>999, 'qty'=>1]])), 'Order entry rejects fractional quantities and missing products');
check(rejects(static fn() => inventory_service_assert_stock($pdo, [['id'=>1, 'qty'=>10]])), 'Shared checkout assertion blocks ingredient shortages');
$pdo->beginTransaction();
inventory_service_adjust_stock($pdo, 2, '0.25', 'restock');
inventory_service_adjust_stock($pdo, 2, '0.10', 'deduct');
$pdo->commit();
check(stock($pdo, 2) === 600.15, 'Fractional restocks and manual deductions preserve exact quantities');
$beforeLogs = (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn();
$pdo->beginTransaction();
$blocked = rejects(static fn() => inventory_service_adjust_stock($pdo, 2, 1000, 'damage'));
$pdo->rollBack();
check($blocked && stock($pdo, 2) === 600.15 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === $beforeLogs, 'Oversized adjustments leave stock and logs unchanged');
check(rejects(static fn() => inventory_service_validate_stock_quantity('0.001')) && rejects(static fn() => inventory_service_validate_stock_quantity('-1')), 'All stock adjustments reject invalid precision and negative quantities');
// Size-specific recipe regression checks. Fixtures remain connection-local.
$pdo->exec("INSERT INTO inventory VALUES
    (4, 'SHORT STRAW', 'packaging', '12oz Hot', 'pcs', 10, 2),
    (5, 'UNSIZED CUP', 'packaging', NULL, 'pcs', 10, 2),
    (13, 'COFFEE STIRRER', 'packaging', NULL, 'pcs', 10, 2),
    (16, 'PLASTIC CUP', 'packaging', '22oz Cold', 'pcs', 10, 2),
    (17, 'FLAT LID', 'packaging', '22oz', 'pcs', 10, 2),
    (18, 'SLEEVE', 'packaging', '16oz', 'pcs', 10, 2)");
$pdo->exec("INSERT INTO items (id,name,cup_size) VALUES (4,'LATTE','22oz'), (5,'HOT TEST','22oz Cold')");
$recipe22 = [1=>20.0, 2=>250.0, 16=>1.0];
check(inventory_service_validate_recipe($pdo, $recipe22, '22oz', 'LATTE') === $recipe22, '22oz recipes accept shared ingredients and matching legacy-labeled packaging');
check(rejects(static fn() => inventory_service_validate_recipe($pdo, [14=>1], '22oz')) && rejects(static fn() => inventory_service_validate_recipe($pdo, [10=>1], '22oz')), '22oz recipes reject both 12oz and 16oz cups');
check(rejects(static fn() => inventory_service_validate_recipe($pdo, [5=>1], '22oz')) && rejects(static fn() => inventory_service_validate_recipe($pdo, [18=>1], '22oz')), 'Unsized cups and other size-specific packaging cannot bypass size validation');
check(rejects(static fn() => inventory_service_validate_recipe($pdo, [12=>1], '12oz Hot')) && rejects(static fn() => inventory_service_validate_recipe($pdo, [13=>1], '22oz')), 'Hot recipes reject straws and cold recipes reject stirrers');
check(inventory_service_validate_recipe($pdo, [14=>'', 10=>'0', 2=>'250'], '22oz') === [2=>250.0], 'Blank incompatible packaging is omitted when switching cup sizes');
$pdo->beginTransaction();
inventory_service_save_recipe($pdo, 4, $recipe22);
$pdo->commit();
$size22 = amounts(inventory_service_requirements($pdo, [['id'=>4,'qty'=>1]]));
$size16 = amounts(inventory_service_requirements($pdo, [['id'=>1,'qty'=>1]]));
check($size22[2] === 250.0 && $size16[2] === 150.0, 'Each serving size keeps its own ingredient amounts');
check($size22[16] === 1.0 && $size22[17] === 1.0 && $size22[12] === 1.0 && !array_intersect([10,14,4,13], array_keys($size22)), '22oz consumption uses matching cups and lids plus a shared straw without duplication');
check((int) inventory_service_find_packaging($pdo, 'straw', '22oz')['id'] === 12, 'Automatic packaging skips an earlier straw with an incompatible size');
$legacyCold = amounts(inventory_service_requirements($pdo, [['id'=>5,'qty'=>1]]));
check($legacyCold[16] === 1.0 && $legacyCold[17] === 1.0 && $legacyCold[12] === 1.0 && !isset($legacyCold[13]), 'Explicit legacy Cold variants retain cold packaging even when the name contains HOT');
$pdo->beginTransaction();
$blocked = rejects(static fn() => inventory_service_save_recipe($pdo, 4, [14=>1]));
$pdo->rollBack();
check($blocked && amounts(inventory_service_requirements($pdo, [['id'=>4,'qty'=>1]])) === $size22, 'Saving incompatible packaging preserves the existing recipe');
$pdo->beginTransaction();
$pdo->exec("UPDATE items SET cup_size = '22oz' WHERE id = 1");
$blocked = rejects(static fn() => inventory_service_save_recipe($pdo, 1, [10=>1]));
$pdo->rollBack();
check($blocked && $pdo->query('SELECT cup_size FROM items WHERE id = 1')->fetchColumn() === '16oz', 'Changing cup size and saving a mismatched recipe rolls back the whole edit');
// Simulate a preexisting bad recipe and verify that completing its order cannot consume it.
$pdo->exec('UPDATE item_requirements SET inventory_id = 14 WHERE item_id = 4 AND inventory_id = 16');
$pdo->exec("INSERT INTO orders VALUES (5,'TEST-SIZE','pending',NULL)");
$pdo->exec('INSERT INTO order_items (order_id,item_id,qty) VALUES (5,4,1)');
$beforeStock = $pdo->query('SELECT id, qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$beforeLogs = (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn();
$pdo->beginTransaction();
$blocked = rejects(static fn() => inventory_service_complete_order($pdo, 5));
$pdo->rollBack();
check($blocked && $beforeStock === $pdo->query('SELECT id, qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) && $beforeLogs === (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() && !$pdo->query('SELECT inventory_deducted_at FROM orders WHERE id = 5')->fetchColumn(), 'A preexisting mismatched recipe blocks completion without deductions, logs, or completion timestamp');
$pdo->beginTransaction();
inventory_service_save_recipe($pdo, 4, $recipe22);
inventory_service_complete_order($pdo, 5);
$pdo->commit();
check(stock($pdo,16) === 9.0 && stock($pdo,17) === 9.0 && stock($pdo,14) === (float) $beforeStock[14] && abs(stock($pdo,2) - ((float) $beforeStock[2] - 250.0)) < 0.000001, 'Correcting a recipe completes the order using its 22oz packaging and exact ingredient amounts');

$pdo->exec("INSERT INTO inventory VALUES (19,'CUP SLEEVES','packaging',NULL,'pcs',10,2),(20,'WIDE STRAW','packaging',NULL,'pcs',10,2)");
$pdo->exec("INSERT INTO inventory VALUES (6,'CUP SLEEVE','packaging',NULL,'pcs',10,2)");
check((int) inventory_service_find_packaging($pdo, 'cup', '22oz')['id'] === 16, 'Automatic cup lookup skips cup sleeves even when they are listed first');
check(inventory_service_find_packaging($pdo, 'cup', '30oz') === null, 'Cup sleeves cannot satisfy a missing cup size');
$pdo->beginTransaction();
inventory_service_save_recipe($pdo, 4, [1=>20,2=>250,19=>1]);
$withSleeve = amounts(inventory_service_requirements($pdo, [['id'=>4,'qty'=>1]]));
check($withSleeve[16] === 1.0 && $withSleeve[19] === 1.0 && $withSleeve[12] === 1.0, 'Cup sleeves consume their own stock without replacing the required cup');
inventory_service_save_recipe($pdo, 4, [1=>20,2=>250,19=>1,20=>1]);
$withWideStraw = amounts(inventory_service_requirements($pdo, [['id'=>4,'qty'=>1]]));
check($withWideStraw[20] === 1.0 && !isset($withWideStraw[12]) && $withWideStraw[16] === 1.0, 'An explicitly configured wide straw replaces the regular straw only for its own recipe');
$pdo->rollBack();

echo "All $checks inventory checks passed. Real inventory and orders were not changed.\n";