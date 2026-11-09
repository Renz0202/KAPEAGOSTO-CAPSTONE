<?php
// Exercises the real inventory POST handlers against temporary tables only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$scenarios = ['create_raw', 'fractional_restock', 'unit_mismatch', 'all_sizes_rollback', 'linked_unit_change', 'linked_delete', 'negative_stock', 'invalid_type', 'fractional_damage', 'insufficient_damage', 'grouped_material', 'invalid_group', 'update_group', 'cup_sleeve'];
if (!isset($argv[1])) {
    foreach ($scenarios as $scenario) {
        $process = proc_open([PHP_BINARY, __FILE__, $scenario], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        echo stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { fwrite(STDERR, $errors); exit(1); }
    }
    echo 'All ' . count($scenarios) . ' inventory form checks passed. Real stock was not changed.' . PHP_EOL;
    exit;
}
$scenario = $argv[1];
if (!in_array($scenario, $scenarios, true)) exit(1);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_service.php';
require_once dirname(__DIR__) . '/includes/material_catalog.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
$pdo = db();
foreach ([
    'inventory' => 'id INT AUTO_INCREMENT PRIMARY KEY, sku VARCHAR(64) UNIQUE, name VARCHAR(100), material_type VARCHAR(20), material_group VARCHAR(40) NULL, cup_size VARCHAR(50) NULL, unit VARCHAR(50), qty DECIMAL(10,2), critical_level DECIMAL(10,2) NULL, date_added DATETIME',
    'inventory_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY, inventory_id INT, order_id INT NULL, user_id INT NULL, change_type VARCHAR(20), quantity DECIMAL(10,2), remaining DECIMAL(10,2), remarks VARCHAR(255), created_at DATETIME',
    'item_requirements' => 'id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2), UNIQUE KEY (item_id,inventory_id)',
    'notifications' => 'id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, notification_type VARCHAR(50), entity_type VARCHAR(50), entity_id INT, dedupe_key VARCHAR(150) UNIQUE, message TEXT, is_read INT, read_at DATETIME NULL, created_at DATETIME',
] as $table => $columns) {
    $pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['user'=>['id'=>null]];
$_POST = ['add_inventory'=>1, 'name'=>'STRAWBERRY SYRUP', 'material_type'=>'raw_material', 'unit'=>'ml', 'quantity'=>'1250.50', 'critical_level'=>'100'];
if (in_array($scenario, ['fractional_restock', 'unit_mismatch', 'linked_unit_change', 'linked_delete', 'fractional_damage', 'insufficient_damage', 'update_group'], true)) {
    $pdo->exec("INSERT INTO inventory (id,sku,name,material_type,unit,qty,critical_level) VALUES (1,'TEST-BEANS','COFFEE BEANS','raw_material','g',500,100)");
}
switch ($scenario) {
    case 'grouped_material': $_POST['name'] = 'CUSTOM MIX'; $_POST['material_group'] = 'powders'; break;
    case 'invalid_group': $_POST['material_group'] = 'invalid'; break;
    case 'update_group': $_POST = ['update_inventory'=>1,'id'=>1,'sku'=>'TEST-BEANS','name'=>'COFFEE BEANS','material_type'=>'raw_material','material_group'=>'powders','unit'=>'g','quantity'=>'500','critical_level'=>'100']; break;
    case 'cup_sleeve': $_POST = ['add_inventory'=>1,'name'=>'CUP SLEEVES','material_type'=>'packaging','unit'=>'pcs','quantity'=>'10']; break;
    case 'fractional_damage': $_POST = ['report_damage'=>1, 'id'=>1, 'quantity'=>'0.25', 'remarks'=>'Spillage']; break;
    case 'insufficient_damage': $_POST = ['report_damage'=>1, 'id'=>1, 'quantity'=>'600', 'remarks'=>'Spillage']; break;
    case 'fractional_restock': $_POST = array_merge($_POST,['name'=>'COFFEE BEANS','unit'=>'g','quantity'=>'150.25']); break;
    case 'unit_mismatch': $_POST = array_merge($_POST,['name'=>'COFFEE BEANS','unit'=>'kg','quantity'=>'1']); break;
    case 'all_sizes_rollback':
        $pdo->exec("INSERT INTO inventory (sku,name,material_type,unit,qty) VALUES ('TEST-16OZ','EXISTING SUPPLY','other','pcs',1)");
        $_POST = array_merge($_POST,['name'=>'CUPS','sku'=>'TEST','material_type'=>'packaging','cup_size'=>'all','unit'=>'pcs','quantity'=>'10']);
        break;
    case 'linked_unit_change':
        $pdo->exec('UPDATE inventory SET qty = 0 WHERE id = 1');
        $pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (1,1,18)');
        $_POST = ['update_inventory'=>1,'id'=>1,'sku'=>'TEST-BEANS','name'=>'COFFEE BEANS','material_type'=>'raw_material','unit'=>'ml','quantity'=>'300','critical_level'=>'100'];
        break;
    case 'linked_delete':
        $pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (1,1,18)');
        $_POST = ['delete_inventory'=>1,'id'=>1];
        break;
    case 'negative_stock': $_POST['quantity'] = '-10'; break;
    case 'invalid_type': $_POST['material_type'] = 'unknown'; break;
}
register_shutdown_function(static function () use ($pdo, $scenario) {
    $rows = $pdo->query('SELECT name, material_type, material_group, cup_size, unit, qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $logs = (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn();
    $pass = false;
    switch ($scenario) {
        case 'grouped_material': $pass = count($rows) === 1 && $rows[0]['material_group'] === 'powders' && $logs === 1; break;
        case 'invalid_group': $pass = !$rows && $logs === 0; break;
        case 'update_group': $pass = count($rows) === 1 && $rows[0]['material_group'] === 'powders' && (float) $rows[0]['qty'] === 500.0 && $logs === 1; break;
        case 'cup_sleeve': $pass = count($rows) === 1 && $rows[0]['material_group'] === 'packaging' && $rows[0]['cup_size'] === null && (float) $rows[0]['qty'] === 10.0; break;
        case 'fractional_damage': $pass = count($rows) === 1 && (float) $rows[0]['qty'] === 499.75 && $logs === 1; break;
        case 'insufficient_damage': $pass = count($rows) === 1 && (float) $rows[0]['qty'] === 500.0 && $logs === 0; break;
        case 'create_raw': $pass = count($rows) === 1 && $rows[0]['material_type'] === 'raw_material' && $rows[0]['cup_size'] === null && $rows[0]['unit'] === 'ml' && (float) $rows[0]['qty'] === 1250.5 && $logs === 1; break;
        case 'fractional_restock': $pass = count($rows) === 1 && (float) $rows[0]['qty'] === 650.25 && $logs === 1; break;
        case 'unit_mismatch': $pass = count($rows) === 1 && $rows[0]['unit'] === 'g' && (float) $rows[0]['qty'] === 500.0 && $logs === 0; break;
        case 'all_sizes_rollback': $pass = count($rows) === 1 && $rows[0]['name'] === 'EXISTING SUPPLY' && $logs === 0; break;
        case 'linked_unit_change': $pass = count($rows) === 1 && $rows[0]['unit'] === 'g' && (float) $rows[0]['qty'] === 0.0 && $logs === 0; break;
        case 'linked_delete': $pass = count($rows) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM item_requirements')->fetchColumn() === 1; break;
        case 'negative_stock': case 'invalid_type': $pass = !$rows && $logs === 0; break;
    }
    if (!$pass) { fwrite(STDERR, 'FAIL: ' . $scenario . PHP_EOL); exit(1); }
    echo 'PASS: ' . $scenario . PHP_EOL;
});
$source = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/public/inventory_module/inventory.php'));
$start = strpos($source, 'function formatQty(');
$handler = strpos($source, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
$end = strpos($source, '$stockFilters = inventory_stock_filters($_GET);', $handler);
if ($start === false || $handler === false || $end === false) throw new RuntimeException('Inventory form source changed.');
eval(substr($source, $start, $handler - $start));
eval(substr($source, $handler, $end - $handler));