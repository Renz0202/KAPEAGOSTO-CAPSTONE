<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/material_catalog.php';
require_once dirname(__DIR__) . '/includes/inventory_requirements.php';
$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE inventory (id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(64) UNIQUE, name VARCHAR(100), material_type VARCHAR(20), material_group VARCHAR(40) NULL, cup_size VARCHAR(50) NULL, unit VARCHAR(50), qty DECIMAL(10,2), critical_level DECIMAL(10,2) NULL, date_added DATETIME) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE item_requirements (item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2)) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE inventory_logs (id INT PRIMARY KEY, quantity DECIMAL(10,2)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO inventory (id,sku,name,material_type,material_group,cup_size,unit,qty) VALUES
(1,'EXISTING-MILK','FULL CREAM MILK','raw_material',NULL,NULL,'L',148),
(2,'EXISTING-CUP','PLASTIC CUP','packaging',NULL,'16oz Cold','pcs',132),
(3,'CUSTOM','CUSTOM SUPPLY','other','dairy',NULL,'pcs',7),
(4,'INV-COFFEE-BEANS','UNRELATED SUPPLY','other',NULL,NULL,'pcs',5)");
$pdo->exec('INSERT INTO item_requirements VALUES (1,1,3)');
$pdo->exec('INSERT INTO inventory_logs VALUES (1,10)');
$before = $pdo->query('SELECT id,sku,name,cup_size,unit,qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$recipes = $pdo->query('SELECT * FROM item_requirements')->fetchAll(PDO::FETCH_ASSOC);
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo 'PASS: ' . $label . PHP_EOL;
}
$rejected = false;
try { inventory_material_seed_catalog($pdo); } catch (LogicException $error) { $rejected = true; }
check($rejected, 'Catalog setup requires an enclosing transaction');
$pdo->beginTransaction();
$grouped = inventory_material_backfill_groups($pdo);
$result = inventory_material_seed_catalog($pdo);
$pdo->commit();
check($grouped === 2 && $pdo->query('SELECT material_group FROM inventory WHERE id = 1')->fetchColumn() === 'dairy', 'Existing milk and packaging are classified without guessing unknown supplies');
$after = $pdo->query('SELECT id,sku,name,cup_size,unit,qty FROM inventory WHERE id <= 4 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check($before === $after, 'Existing names, SKUs, units, sizes, and stock balances are preserved');
check(count($result['added']) === count(inventory_material_catalog()) - 2 && count($result['reused']) === 2, 'Aliases and legacy cup-size labels reuse existing inventory');
check((int) $pdo->query('SELECT COUNT(*) FROM inventory WHERE id > 4 AND qty <> 0')->fetchColumn() === 0, 'Every newly created material starts at zero stock');
check($recipes === $pdo->query('SELECT * FROM item_requirements')->fetchAll(PDO::FETCH_ASSOC) && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === 1, 'Catalog setup leaves recipes and movement history untouched');
check($pdo->query("SELECT sku FROM inventory WHERE name = 'COFFEE BEANS'")->fetchColumn() === 'INV-COFFEE-BEANS-2', 'A conflicting SKU cannot overwrite an unrelated material');
check($pdo->query('SELECT material_group FROM inventory WHERE id = 3')->fetchColumn() === 'dairy', 'Previously assigned material groups are preserved');
$groups = $pdo->query('SELECT DISTINCT material_group FROM inventory WHERE id > 4')->fetchAll(PDO::FETCH_COLUMN);
check(count($groups) === 7 && !array_diff($groups, array_keys(inventory_material_groups())), 'New catalog entries cover all seven supplied material groups');
$pdo->beginTransaction();
$repeat = inventory_material_seed_catalog($pdo);
$regrouped = inventory_material_backfill_groups($pdo);
$pdo->commit();
check($repeat['added'] === [] && count($repeat['reused']) === count(inventory_material_catalog()) && $regrouped === 0, 'Repeating setup adds no duplicates and preserves classification');
$rejected = false;
try { inventory_material_group_from_post('unknown', 'MILK', 'raw_material'); } catch (InvalidArgumentException $error) { $rejected = true; }
check($rejected && inventory_material_group_from_post('', 'FRESH MILK', 'raw_material') === 'dairy' && inventory_material_group_from_post('', 'CUP', 'packaging') === 'packaging', 'Material groups validate server-side and common materials receive their correct group');
check(inventory_service_packaging_kind(['material_type'=>'packaging','name'=>'CUP SLEEVES']) === null && inventory_service_recipe_material_matches(['material_type'=>'packaging','name'=>'CUP SLEEVES','cup_size'=>null], '22oz'), 'Cup sleeves remain optional packaging and cannot masquerade as cups');
$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/public/inventory_module/inventory.php'));
$start = strpos($source, 'function formatQty(');
$end = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
if ($start === false || $end === false) throw new RuntimeException('Inventory helpers changed.');
eval(substr($source, $start, $end - $start));
$rows = $pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$filtered = filterInventory($rows, 'raw_material', 'in_stock', 'dairy');
check(count($filtered) === 1 && (int) $filtered[0]['id'] === 1, 'Stock filters combine material group, inventory type, and availability');
$filtered = filterInventory($rows, '', 'out_of_stock', 'flavorings');
check(count($filtered) === 14 && count(array_filter($filtered, static fn($row) => $row['material_group'] !== 'flavorings' || (float) $row['qty'] !== 0.0)) === 0, 'A material group filter returns only its own zero-stock items');

echo 'All ' . $checks . ' material catalog checks passed. Real inventory was not changed.' . PHP_EOL;