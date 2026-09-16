<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_login();

function user_delete_flash(string $message, string $type = 'error'): void {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: users.php');
    exit;
}

$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || !is_admin()) {
    user_delete_flash('Only administrators can delete user accounts.');
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    user_delete_flash('Invalid user selected.');
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT u.id, u.username, u.email, u.role_id, u.status, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        user_delete_flash('User account was not found.');
    }

    if ($id === (int) ($currentUser['id'] ?? 0)) {
        user_delete_flash('You cannot delete your own account.');
    }

    $isDefaultAdminIdentity = (strcasecmp((string) ($target['username'] ?? ''), (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
        || (strcasecmp((string) ($target['email'] ?? ''), (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);

    if ($isDefaultAdminIdentity) {
        user_delete_flash('The default Admin account is protected and cannot be deleted.');
    }

    if (normalize_role_name($target['role_name'] ?? '') === 'admin') {
        $countStmt = $pdo->query("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE LOWER(r.name) IN ('business_admin','admin') AND u.status = 'active'");
        $countActiveAdmins = (int) $countStmt->fetchColumn();

        if ($countActiveAdmins <= 1 && strtolower((string) $target['status']) === 'active') {
            user_delete_flash('At least one active administrator account is required.');
        }
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);

    user_delete_flash('User account deleted successfully.', 'success');
} catch (Throwable $e) {
    error_log('Delete user failed: ' . $e->getMessage());
    user_delete_flash('Unable to delete the user account at this time. Please try again.');
}
?>
