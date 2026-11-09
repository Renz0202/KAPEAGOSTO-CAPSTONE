<?php
// Runs order creation handlers using connection-local temporary tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!isset($argv[1], $argv[2])) {
    foreach (['place_order', 'save_order', 'new_order'] as $route) {
        foreach (['valid', 'shortage', 'fractional_quantity'] as $scenario) {
            $process = proc_open([PHP_BINARY, __FILE__, $route, $scenario], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
            echo stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { fwrite(STDERR, $errors); exit(1); }
        }
    }
    echo 'All 9 order entry checks passed. Real orders and stock were not changed.' . PHP_EOL;
    exit;
}
[$route, $scenario] = [$argv[1], $argv[2]];
if (!in_array($route, ['place_order', 'save_order', 'new_order'], true) || !in_array($scenario, ['valid', 'shortage', 'fractional_quantity'], true)) exit(1);
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/order_utils.php';
require_once dirname(__DIR__) . '/includes/name_utils.php';
require_once dirname(__DIR__) . '/includes/inventory_requirements.php';
$pdo = db();
foreach ([
    'inventory' => 'id INT PRIMARY KEY, name VARCHAR(100), material_type VARCHAR(20), cup_size VARCHAR(50) NULL, unit VARCHAR(50), qty DECIMAL(10,2), critical_level DECIMAL(10,2)',
    'items' => 'id INT PRIMARY KEY, name VARCHAR(100), cup_size VARCHAR(50), price DECIMAL(10,2)',
    'item_requirements' => 'id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2)',
    'orders' => 'id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, order_code VARCHAR(50) NULL, customer_name VARCHAR(100) NULL, total DECIMAL(10,2), status VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    'order_items' => 'id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, item_id INT, qty INT, price DECIMAL(10,2)',
    'inventory_logs' => 'id INT AUTO_INCREMENT PRIMARY KEY, inventory_id INT, quantity DECIMAL(10,2)',
] as $table => $columns) $pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
$pdo->exec("INSERT INTO inventory VALUES (1,'MILK','raw_material',NULL,'ml',20,0), (2,'CUP','packaging','16oz','pcs',20,0), (3,'LID','packaging','16oz','pcs',20,0), (4,'STRAW','packaging',NULL,'pcs',20,0)");
$pdo->exec("INSERT INTO items VALUES (1,'LATTE','16oz',100), (2,'MOCHA','16oz',100)");
$pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (1,1,10), (2,1,10)');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION = ['user'=>['id'=>1], 'user_id'=>1];
$qty = $scenario === 'shortage' ? 2 : ($scenario === 'fractional_quantity' ? '1.5' : 1);
$cart = [['id'=>1,'qty'=>$qty,'price'=>0.01], ['id'=>2,'qty'=>$qty,'price'=>0.01]];
$_POST = ['customer_name'=>'TEST', 'items'=>$cart, 'cart'=>$cart];
if ($route === 'new_order') $_POST['items'] = [1=>$qty, 2=>$qty];
ob_start();
register_shutdown_function(static function () use ($pdo, $route, $scenario) {
    ob_end_clean();
    $orderCount = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $stock = (float) $pdo->query('SELECT qty FROM inventory WHERE id = 1')->fetchColumn();
    $pass = $stock === 20.0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() === 0;
    if ($scenario === 'valid') {
        $pass = $pass && $orderCount === 1 && (float) $pdo->query('SELECT total FROM orders LIMIT 1')->fetchColumn() === 200.0 && (int) $pdo->query('SELECT SUM(qty) FROM order_items')->fetchColumn() === 2;
    } else {
        $pass = $pass && $orderCount === 0 && (int) $pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn() === 0;
    }
    if (!$pass) { fwrite(STDERR, "FAIL: $route $scenario\n"); exit(1); }
    echo "PASS: $route $scenario\n";
});
$source = file_get_contents(dirname(__DIR__) . '/public/orders/' . $route . '.php');
$start = strpos($source, '$pdo = db();');
$end = strpos($source, '?>', $start);
if ($start === false) throw new RuntimeException('Order route source changed.');
if ($end === false) $end = strlen($source);
eval(substr($source, $start, $end - $start));