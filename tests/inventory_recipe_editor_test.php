<?php
// Exercise the real Inventory recipe POST handler using connection-local temporary tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$scenarios = ['save_22oz', 'wrong_size', 'hot_straw', 'invalid_quantity', 'unknown_item', 'malformed_recipe', 'invalid_id', 'clear_recipe', 'save_tea', 'remove_milk'];
if (!isset($argv[1])) {
    foreach ($scenarios as $scenario) {
        $process = proc_open([PHP_BINARY, __FILE__, $scenario], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        echo stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { fwrite(STDERR, $errors); exit(1); }
    }
    echo 'All ' . count($scenarios) . ' Inventory recipe handler checks passed. Real recipes and stock were not changed.' . PHP_EOL;
    exit;
}
$scenario = $argv[1];
if (!in_array($scenario, $scenarios, true)) exit(1);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_service.php';
require_once dirname(__DIR__) . '/includes/inventory_recipe_editor.php';
$pdo = db();
foreach ([
    'inventory' => 'id INT PRIMARY KEY, sku VARCHAR(64), name VARCHAR(100), material_type VARCHAR(20), material_group VARCHAR(40) NULL, cup_size VARCHAR(50) NULL, unit VARCHAR(50), qty DECIMAL(10,2), critical_level DECIMAL(10,2) NULL',
    'items' => 'id INT PRIMARY KEY, name VARCHAR(100), category_id INT, cup_size VARCHAR(50)',
    'categories' => 'id INT PRIMARY KEY, name VARCHAR(100)',
    'item_recipe_metadata' => "item_id INT PRIMARY KEY, source VARCHAR(20), notes VARCHAR(500) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    'item_requirements' => 'id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2), UNIQUE KEY(item_id,inventory_id)',
    'inventory_logs' => 'id INT PRIMARY KEY',
] as $table => $columns) {
    $pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
}
$pdo->exec("INSERT INTO inventory (id,sku,name,material_type,cup_size,unit,qty,critical_level) VALUES
    (1,'BEANS','COFFEE BEANS','raw_material',NULL,'g',1000,100),
    (2,'MILK','FRESH MILK','raw_material',NULL,'ml',1000,100),
    (3,'SYRUP','BLUEBERRY SYRUP','raw_material',NULL,'ml',1000,100),
    (10,'CUP12','PAPER CUP','packaging','12oz Hot','pcs',10,2),
    (11,'CUP16','PLASTIC CUP','packaging','16oz','pcs',10,2),
    (12,'CUP22','PLASTIC CUP','packaging','22oz','pcs',10,2),
    (13,'LID22','FLAT LID','packaging','22oz','pcs',10,2),
    (14,'STRAW','STRAW','packaging',NULL,'pcs',10,2),
    (15,'LID16','FLAT LID','packaging','16oz','pcs',10,2)");
$pdo->exec("INSERT INTO categories VALUES (1,'Coffee'), (2,'Fruit Tea')");
$pdo->exec("INSERT INTO items VALUES (1,'LATTE',1,'16oz'), (2,'LATTE',1,'22oz'), (3,'HOT LATTE',1,'12oz Hot'), (4,'BLUEBERRY TEA',2,'16oz')");
$pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (1,1,18),(1,2,150),(2,1,20),(2,2,250),(3,1,18)');
$pdo->exec("INSERT INTO item_recipe_metadata (item_id,source,notes) VALUES (2,'generated','Generated starter estimate. Review the house recipe.')");
$beforeRecipes = $pdo->query('SELECT item_id,inventory_id,quantity_needed FROM item_requirements ORDER BY item_id, inventory_id')->fetchAll(PDO::FETCH_ASSOC);
$beforeStock = $pdo->query('SELECT id, qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
inventory_material_backfill_groups($pdo);
$data = inventory_recipe_editor_data($pdo);
if ($data['material_groups'] !== inventory_material_groups()) throw new RuntimeException('Material groups are missing from the recipe data.');
$latte = array_values(array_filter($data['products'], static fn($product) => $product['name'] === 'LATTE'))[0];
if ($latte['category_id'] !== 1 || count($latte['variants']) !== 2 || $latte['variants'][0]['id'] !== 1 || $latte['variants'][1]['id'] !== 2 || (float) $latte['variants'][1]['requirements'][2] !== 250.0) throw new RuntimeException('Recipe data did not group sizes and preserve existing recipes.');
$tea = array_values(array_filter($data['products'], static fn($product) => $product['name'] === 'BLUEBERRY TEA'))[0];
if ($tea['category_id'] !== 2 || $tea['category'] !== 'Fruit Tea' || $tea['variants'][0]['requirements'] !== []) throw new RuntimeException('Category filtering data assigned ingredients to an unconfigured tea.');
$cup = array_values(array_filter($data['materials'], static fn($material) => $material['id'] === 12))[0];
if ($cup['material_group'] !== 'packaging' || $cup['recipe_kind'] !== 'cup' || $cup['recipe_size'] !== '22oz') throw new RuntimeException('Recipe packaging metadata is missing.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = [];
// Hidden size and name are deliberately forged: the server must use the actual selected catalog item.
$_POST = ['save_recipe'=>1, 'item_id'=>'2', 'cup_size'=>'12oz Hot', 'name'=>'HOT FAKE', 'requirements'=>[1=>'21.5',2=>'200.25',12=>'1']];
switch ($scenario) {
    case 'wrong_size': $_POST['requirements'] = [2=>'200',10=>'1']; break;
    case 'hot_straw': $_POST['item_id'] = '3'; $_POST['requirements'] = [14=>'1']; break;
    case 'invalid_quantity': $_POST['requirements'] = [2=>'0.001']; break;
    case 'unknown_item': $_POST['item_id'] = '999'; break;
    case 'malformed_recipe': $_POST['requirements'] = 'invalid'; break;
    case 'invalid_id': $_POST['item_id'] = '2.5'; break;
    case 'clear_recipe': $_POST['requirements'] = []; break;
    case 'save_tea': $_POST['item_id'] = '4'; $_POST['requirements'] = [3=>'30']; break;
    case 'remove_milk': $_POST['requirements'] = [1=>'20']; break;
}
register_shutdown_function(static function () use ($pdo, $scenario, $beforeRecipes, $beforeStock) {
    $after = $pdo->query('SELECT item_id,inventory_id,quantity_needed FROM item_requirements ORDER BY item_id, inventory_id')->fetchAll(PDO::FETCH_ASSOC);
    $notice = $_SESSION['inventory_recipe_notice'] ?? [];
    $untouched = $beforeStock === $pdo->query('SELECT id, qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === 0;
    if ($scenario === 'save_22oz') {
        $recipe = $pdo->query('SELECT inventory_id, quantity_needed FROM item_requirements WHERE item_id = 2 ORDER BY inventory_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pass = array_map('floatval', $recipe) === [1=>21.5,2=>200.25,12=>1.0] && ($notice['type'] ?? '') === 'success' && !isset($_SESSION['inventory_recipe_draft']);
        $requirements = inventory_service_requirements($pdo, [['id'=>2,'qty'=>2]]);
        $quantities = [];
        foreach ($requirements as $row) $quantities[$row['inventory_id']] = (float) $row['quantity'];
        $pass = $pass && $quantities[2] === 400.5 && $quantities[12] === 2.0 && $quantities[13] === 2.0 && $quantities[14] === 2.0 && !isset($quantities[10]);
    } elseif (in_array($scenario, ['save_tea', 'remove_milk'], true)) {
        $itemId = $scenario === 'save_tea' ? 4 : 2;
        $recipe = $pdo->query('SELECT inventory_id,quantity_needed FROM item_requirements WHERE item_id = ' . $itemId . ' ORDER BY inventory_id')->fetchAll(PDO::FETCH_KEY_PAIR);
        $expected = $scenario === 'save_tea' ? [3=>30.0] : [1=>20.0];
        $pass = array_map('floatval', $recipe) === $expected && ($notice['type'] ?? '') === 'success';
        $requirements = inventory_service_requirements($pdo, [['id'=>$itemId,'qty'=>2]]);
        $quantities = [];
        foreach ($requirements as $row) $quantities[$row['inventory_id']] = (float) $row['quantity'];
        $ingredientId = $scenario === 'save_tea' ? 3 : 1;
        $cupId = $scenario === 'save_tea' ? 11 : 12;
        $lidId = $scenario === 'save_tea' ? 15 : 13;
        $pass = $pass && !isset($quantities[2]) && $quantities[$ingredientId] === $expected[$ingredientId] * 2 && $quantities[$cupId] === 2.0 && $quantities[$lidId] === 2.0 && $quantities[14] === 2.0;
    } elseif ($scenario === 'clear_recipe') {
        $pass = (int) $pdo->query('SELECT COUNT(*) FROM item_requirements WHERE item_id = 2')->fetchColumn() === 0 && ($notice['type'] ?? '') === 'success';
    } else {
        $pass = $beforeRecipes === $after && ($notice['type'] ?? '') === 'error' && isset($_SESSION['inventory_recipe_draft']);
    }
    // Saving one size must leave the other size and catalog details intact.
    $other = $pdo->query('SELECT inventory_id,quantity_needed FROM item_requirements WHERE item_id = 1 ORDER BY inventory_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pass = $pass && array_map('floatval', $other) === [1=>18.0,2=>150.0] && $pdo->query('SELECT cup_size FROM items WHERE id = 2')->fetchColumn() === '22oz';
    $metadataSource = $pdo->query('SELECT source FROM item_recipe_metadata WHERE item_id = 2')->fetchColumn();
    $expectedSource = in_array($scenario, ['save_22oz','clear_recipe','remove_milk'], true) ? 'custom' : 'generated';
    $pass = $pass && $metadataSource === $expectedSource;
    if (!$pass || !$untouched || $pdo->inTransaction()) { fwrite(STDERR, 'FAIL: ' . $scenario . PHP_EOL); exit(1); }
    echo 'PASS: ' . $scenario . PHP_EOL;
});
$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/public/inventory_module/inventory.php'));
$start = strpos($source, 'function formatQty(');
$handler = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
$end = strpos($source, '$stockFilters = inventory_stock_filters($_GET);', $handler);
if ($start === false || $handler === false || $end === false) throw new RuntimeException('Inventory form source changed.');
eval(substr($source, $start, $handler - $start));
eval(substr($source, $handler, $end - $handler));