<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require_once dirname(__DIR__, 2) . "/includes/db.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";

// Replace unconditional ob_clean with conditional
if (ob_get_level()) { ob_clean(); }

// Always respond as JSON
header('Content-Type: application/json');

// Enforce POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$username = isset($_POST['admin_username']) ? trim($_POST['admin_username']) : '';
$password = isset($_POST['admin_password']) ? trim($_POST['admin_password']) : '';

if ($username && $password) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.full_name, u.email, u.role_id, u.password_hash, u.status, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.username = ? LIMIT 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $role = normalize_role_name($user['role_name'] ?? '');

    // Fastest admin login condition (add status isset guard)
    if (
        $user &&
        $role === 'admin' &&
        isset($user['status']) &&
        strtolower($user['status']) === 'active' &&
        password_verify($password, $user['password_hash'])
    ) {
        // Short-lived admin override for staff action
        $_SESSION['admin_override'] = [
            'id' => $user['id'],
            'username' => $user['username'],
            'expires' => time() + 300 // 5 minutes
        ];

        echo json_encode(['success' => true, 'message' => 'Admin override granted']);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Incorrect Admin credentials or inactive account']);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing credentials']);
    exit;
}
?>
