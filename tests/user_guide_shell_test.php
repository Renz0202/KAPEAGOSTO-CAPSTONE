<?php
// Render both actual header layouts using connection-local fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$role = $argv[1] ?? 'admin';
if (!in_array($role, ['admin','manager','cashier'], true)) exit(1);
$sessionDir = sys_get_temp_dir() . '/kape-guide-shell-' . bin2hex(random_bytes(8));
mkdir($sessionDir, 0700);
session_save_path($sessionDir);
register_shutdown_function(static function () use ($sessionDir): void {
    $path = $sessionDir . '/sess_' . session_id();
    session_write_close();
    if (is_file($path)) unlink($path);
    rmdir($sessionDir);
});
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
$pdo = db();
foreach ([
    'roles' => 'id INT PRIMARY KEY, name VARCHAR(30)',
    'users' => 'id INT PRIMARY KEY, username VARCHAR(50), full_name VARCHAR(100), email VARCHAR(100), image VARCHAR(100), role_id INT, status VARCHAR(20)',
    'notifications' => 'id INT, user_id INT NULL, notification_type VARCHAR(20), entity_type VARCHAR(20), entity_id INT, message VARCHAR(255), is_read INT, created_at DATETIME',
    'orders' => 'id INT, status VARCHAR(20), created_at DATETIME',
    'system_settings' => 'setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    'user_guide_progress' => 'user_id INT PRIMARY KEY, welcome_status VARCHAR(20), guide_version INT',
] as $table => $columns) $pdo->exec('CREATE TEMPORARY TABLE ' . $table . ' (' . $columns . ') ENGINE=InnoDB');
$pdo->prepare('INSERT INTO roles VALUES (1, ?)')->execute([$role]);
$pdo->exec("INSERT INTO users VALUES (1,'fixture','Fixture User','fixture@example.test',NULL,1,'active')");
$_SESSION['user'] = ['id'=>1,'role'=>$role,'full_name'=>'Fixture User','username'=>'fixture'];
$_SERVER['SCRIPT_NAME'] = '/KAPEAGOSTO/public/' . ($role === 'cashier' ? 'orders/orders.php' : 'pages/dashboard.php');
$_SERVER['REQUEST_URI'] = '/KAPEAGOSTO/public/' . ($role === 'cashier' ? 'orders.php' : 'dashboard.php');
// Page authentication must refresh a stale session role from the assigned database role.
$_SESSION['user']['role'] = $role === 'admin' ? 'cashier' : 'admin';
require_login();
$kapeLocalAssetsOnly = true;
ob_start();
include dirname(__DIR__) . '/public/partials/stylesheets.php';
include dirname(__DIR__) . '/includes/topbar.php';
$markup = ob_get_clean();
$doc = new DOMDocument(); @$doc->loadHTML('<!doctype html><meta charset="utf-8"><body>' . $markup);
$x = new DOMXPath($doc);
$checks = [
    'one account-dropdown Guide button' => $x->query('//button[@data-open-guide and @aria-controls="kapeGuide"]')->length === 1,
    'Guide is a dropdown menu item' => $x->query('//div[@role="menu"]//button[@data-open-guide and @role="menuitem"]')->length === 1,
    'one shared guide dialog' => $x->query('//dialog[@id="kapeGuide"]')->length === 1,
    'one guide controller' => $x->query('//script[contains(@src,"scripts/user-guide.js")]')->length === 1,
    'local guide styling' => $x->query('//link[contains(@href,"/KAPEAGOSTO/public/css/user-guide.css")]')->length === 1,
    'correct account shell' => $role === 'cashier'
        ? $x->query('//header[@id="cashierPosHeader"]')->length === 1 && $x->query('//div[@class="topbar"]')->length === 0
        : $x->query('//div[@class="topbar"]')->length === 1 && $x->query('//header[@id="cashierPosHeader"]')->length === 0,
];
$data = json_decode($x->query('//*[@id="kapeGuideData"]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
$checks['guide follows the database-assigned role'] = $data['roleLabel'] === ucfirst($role) && $data['topics'][0]['id'] === 'role';
$checks['new-account welcome enabled'] = $data['needsWelcome'] === true;
$checks['current page selected'] = $data['currentTopic'] === ($role === 'cashier' ? 'orders' : 'dashboard');
foreach ($checks as $label => $ok) {
    if (!$ok) throw new RuntimeException('FAIL: ' . $role . ': ' . $label);
    echo 'PASS: ', $role, ': ', $label, PHP_EOL;
}
echo 'All ', count($checks), ' ', $role, ' shell checks passed.', PHP_EOL;
