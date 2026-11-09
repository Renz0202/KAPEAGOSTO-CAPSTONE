<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user = current_user();
if (!$user || (int) ($user['id'] ?? 0) <= 0) {
    http_response_code(401);
    echo json_encode(['authenticated' => false, 'active' => false]);
    exit;
}

try {
    $statusStmt = db()->prepare('SELECT u.status, u.role_id, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
    $statusStmt->execute([(int) $user['id']]);
    $account = $statusStmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        logout_user();
        http_response_code(401);
        echo json_encode(['authenticated' => false, 'active' => false]);
        exit;
    }

    if (strtolower(trim((string) $account['status'])) !== 'active') {
        suspend_inactive_user_session((int) $user['id']);
        // Remember the page that was open so reactivation can return the user there.
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $refererParts = parse_url($referer);
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $refererHost = strtolower((string) ($refererParts['host'] ?? ''));
        $page = basename((string) ($refererParts['path'] ?? ''));
        $resumePages = ['dashboard.php', 'orders.php', 'order_payment.php', 'order_list.php', 'items.php', 'inventory.php', 'users.php', 'categories.php', 'reports.php', 'profile.php', 'maintenance.php', 'view_order.php'];
        if ($refererHost !== '' && $refererHost === preg_replace('/:\\d+$/', '', $requestHost) && in_array($page, $resumePages, true)) {
            $query = (string) ($refererParts['query'] ?? '');
            $_SESSION['account_resume_url'] = $page . ($query !== '' ? '?' . $query : '');
        }
        echo json_encode(['authenticated' => true, 'active' => false]);
        exit;
    }

    $_SESSION['user']['role_id'] = $account['role_id'];
    $_SESSION['user']['role'] = $account['role_name'] ?? '';
    resume_active_user_session();
    echo json_encode(['authenticated' => true, 'active' => true]);
} catch (Throwable $e) {
    error_log('Live account status check failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Account status is temporarily unavailable.']);
}
