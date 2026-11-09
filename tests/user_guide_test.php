<?php
// Database fixtures are connection-local temporary tables only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$guideSessionDir = sys_get_temp_dir() . '/kape-guide-test-' . bin2hex(random_bytes(8));
mkdir($guideSessionDir, 0700);
session_save_path($guideSessionDir);
register_shutdown_function(static function () use ($guideSessionDir): void {
    $sessionFile = $guideSessionDir . '/sess_' . session_id();
    session_write_close();
    if (is_file($sessionFile)) unlink($sessionFile);
    rmdir($guideSessionDir);
});
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/user_guide.php';
$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, username VARCHAR(50), full_name VARCHAR(100), email VARCHAR(100), image VARCHAR(255), role_id INT) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE system_settings (setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT) ENGINE=InnoDB');
$pdo->exec("CREATE TEMPORARY TABLE user_guide_progress (user_id INT PRIMARY KEY, welcome_status VARCHAR(20), guide_version INT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB");
$pdo->exec("INSERT INTO users VALUES (1,'owner','Owner','owner@example.test',NULL,1),(2,'manager','Manager','manager@example.test',NULL,3)");
$pdo->exec("INSERT INTO system_settings VALUES ('business_name','Fixture Cafe')");
$checks = 0;
function guide_check(bool $value, string $label): void {
    global $checks;
    if (!$value) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ', $label, PHP_EOL; $checks++;
}
function guide_rejects(callable $fn, string $class): bool {
    try { $fn(); } catch (Throwable $error) { return $error instanceof $class; } return false;
}
function guide_business_snapshot(PDO $pdo): array {
    return [$pdo->query('SELECT * FROM users ORDER BY id')->fetchAll(), $pdo->query("SELECT * FROM system_settings WHERE setting_key != 'user_guide_installed_at' ORDER BY setting_key")->fetchAll()];
}
$before = guide_business_snapshot($pdo);
guide_check(user_guide_initialize_existing($pdo) === 2, 'Installation initializes existing accounts once');
guide_check(!user_guide_progress($pdo, 1)['needsWelcome'] && user_guide_progress($pdo, 1)['status'] === 'existing', 'Existing accounts keep manual guide access without an automatic welcome');
$pdo->exec("INSERT INTO users VALUES (3,'cashier','Cashier','cashier@example.test',NULL,2)");
guide_check(user_guide_progress($pdo, 3)['needsWelcome'], 'A newly created account receives the welcome');
guide_check(user_guide_initialize_existing($pdo) === 0 && user_guide_progress($pdo, 3)['needsWelcome'], 'Rerunning installation does not dismiss new users’ welcome');
guide_check(user_guide_save_progress($pdo, 3, 'completed')['status'] === 'completed', 'Welcome completion is saved for the account');
guide_check(!user_guide_progress($pdo, 3)['needsWelcome'], 'Completion survives a fresh database read');
guide_check(user_guide_save_progress($pdo, 3, 'skipped')['status'] === 'skipped', 'Skipping is remembered without preventing replay');
guide_check(user_guide_progress($pdo, 1)['status'] === 'existing', 'One account’s preference does not affect another');
guide_check(guide_rejects(fn() => user_guide_save_progress($pdo, 0, 'completed'), InvalidArgumentException::class), 'Invalid account IDs are rejected');
guide_check(guide_rejects(fn() => user_guide_save_progress($pdo, 3, 'existing'), InvalidArgumentException::class), 'The client cannot set installation-only progress');
$token = str_repeat('a', 64);
guide_check(user_guide_validate_update(['csrf'=>$token,'status'=>'completed'], $token) === 'completed', 'Valid session-bound updates are accepted');
guide_check(guide_rejects(fn()=>user_guide_validate_update(['csrf'=>'bad','status'=>'completed'], $token), DomainException::class), 'An invalid CSRF token is rejected');
guide_check(guide_rejects(fn()=>user_guide_validate_update(['csrf'=>$token,'status'=>'completed','user_id'=>1], $token), InvalidArgumentException::class), 'Updates cannot target another account');
guide_check(guide_rejects(fn()=>user_guide_validate_update(['csrf'=>$token,'status'=>['completed']], $token), InvalidArgumentException::class), 'Malformed preferences are rejected');
$ids = static fn($role) => array_column(user_guide_catalog($role), 'id');
guide_check(in_array('inventory', $ids('admin'), true) && in_array('backup', $ids('admin'), true), 'Admins receive inventory and system tool guides');
guide_check(!array_intersect(['users','inventory','orders','payment','settings','backup','ai-settings'], $ids('manager')) && in_array('reports', $ids('manager'), true), 'Managers receive only permitted review and planning topics');
guide_check(!array_intersect(['dashboard','users','inventory','reports','planning','settings','backup'], $ids('cashier')) && in_array('payment', $ids('cashier'), true), 'Cashiers receive menu, order, payment, and account topics');
guide_check($ids('business-admin') === $ids('admin') && $ids('staff') === $ids('cashier') && $ids('unknown') === [], 'Legacy role aliases are normalized and unknown roles have no topics');
guide_check($ids('admin')[0] === 'role' && $ids('manager')[0] === 'role' && $ids('cashier')[0] === 'role', 'Every assigned role has an easy-to-find task overview');
guide_check(str_contains(user_guide_role_profile('manager')['summary'], 'Review') && str_contains(user_guide_topic_access('manager', 'items'), 'Ask an admin'), 'Manager guidance separates review tasks from admin changes');
guide_check(str_contains(user_guide_topic_access('cashier', 'payment'), 'approval') && str_contains(user_guide_topic_access('admin', 'users'), 'assign roles'), 'Cashier approvals and admin role assignments have clear guidance');
guide_check(user_guide_role_profile('staff') === user_guide_role_profile('cashier') && user_guide_role_profile('unknown') === [], 'Task profiles normalize legacy roles and exclude unknown roles');
guide_check(user_guide_current_topic(user_guide_catalog('cashier'), '/KAPEAGOSTO/public/payments/order_payment.php?order_id=123') === 'payment', 'Nested routes and query strings resolve to the correct topic');
guide_check(user_guide_current_topic(user_guide_catalog('admin'), '/public/catalog/edit_item.php?id=5') === 'items', 'Product editor routes share the catalog guide');
foreach (['admin','manager','cashier'] as $role) {
    foreach (user_guide_catalog($role) as $topic) {
        guide_check($topic['access'] !== '', $role . ': ' . $topic['title'] . ' explains the account access');
        guide_check($topic['intro'] !== '' && count($topic['steps']) >= 3 && count($topic['sections']) >= 1, $role . ': ' . $topic['title'] . ' has complete instructions');
        if ($topic['page'] !== '') guide_check(!preg_match('/create|generate|delete|activate|restore_process|export|download|save|done|void/', $topic['page']), $topic['title'] . ' guide links to a viewing page');
    }
}
// Render the actual shared partial for every role, without live account or sales changes.
$_SERVER['SCRIPT_NAME'] = '/KAPEAGOSTO/public/orders/orders.php';
$_SERVER['REQUEST_URI'] = '/KAPEAGOSTO/public/orders.php';
foreach (['admin','manager','cashier'] as $role) {
    $_SESSION['user'] = ['id'=>3,'role'=>$role,'full_name'=>'<script>Account</script>'];
    $kapeGuideRendered = false;
    ob_start(); include dirname(__DIR__) . '/public/partials/user_guide.php'; $markup = ob_get_clean();
    $doc = new DOMDocument(); @$doc->loadHTML('<!doctype html><meta charset="utf-8">' . $markup);
    $x = new DOMXPath($doc);
    $data = json_decode($x->query('//*[@id="kapeGuideData"]')->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
    guide_check(count($data['topics']) === count($ids($role)) && $data['roleLabel'] === ucfirst($role), $role . ': real partial contains the correct role content');
    guide_check($x->query('//dialog[@aria-labelledby="kapeGuideTitle"]')->length === 1 && $x->query('//*[@data-guide-close and @aria-label]')->length === 1, $role . ': guide has an accessible dialog and close control');
    guide_check(str_starts_with($data['stateUrl'], '/KAPEAGOSTO/public/') && !array_filter($data['topics'], static fn($topic) => $topic['url'] !== '' && !str_starts_with($topic['url'], '/KAPEAGOSTO/public/')), $role . ': assets and routes stay rooted from nested pages');
    guide_check($x->query('//form|//script[@onload]|//script[not(@src) and not(@type="application/json")]')->length === 0, $role . ': partial has no business forms or executable inline content');
    guide_check($x->query('//*[@id="kapeGuideTitle"]')->item(0)->textContent === ucfirst($role) . ' Guide' && $data['welcome'][1][0] === 'Your role: ' . ucfirst($role), $role . ': visible heading and welcome match the assigned role');
    guide_check(!$data['needsWelcome'] && count($data['welcome']) === 3, $role . ': saved progress and three welcome steps are available');
}
$pdo->exec("DELETE FROM users WHERE id=3");
guide_check(guide_business_snapshot($pdo) === $before, 'Guide installation and preferences preserve all account details and business settings');
echo 'All ', $checks, ' guide checks passed. Real business records were not changed.', PHP_EOL;
