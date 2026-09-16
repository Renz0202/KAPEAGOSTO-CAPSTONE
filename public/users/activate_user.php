<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_login();

function user_action_flash(string $message, string $type = 'error'): void {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: users.php');
    exit;
}

$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_admin()) {
    user_action_flash('Only administrators can activate user accounts.');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    user_action_flash('Invalid user selected.');
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, username, email, role_id, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        user_action_flash('User account was not found.');
    }

    $isDefaultAdminIdentity = (strcasecmp((string) ($target['username'] ?? ''), (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
        || (strcasecmp((string) ($target['email'] ?? ''), (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);

    if ($isDefaultAdminIdentity) {
        user_action_flash('The default Admin account is protected and cannot be modified.');
    }

    if (strtolower((string) $target['status']) === 'active') {
        user_action_flash('User account is already active.', 'success');
    }

    $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    $stmt->execute([$id]);

    user_action_flash('User account activated successfully.', 'success');
} catch (Throwable $e) {
    error_log('Activate user failed: ' . $e->getMessage());
    user_action_flash('Unable to activate the user account at this time. Please try again.');
}
?>
