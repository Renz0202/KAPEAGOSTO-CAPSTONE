<?php
// includes/auth.php
// Ensure config (and timezone) is loaded for any script that uses auth only
@require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/session.php';
kape_start_session();

function login_user(array $user): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires' => time() + (defined('KAPE_SESSION_LIFETIME') ? KAPE_SESSION_LIFETIME : 60 * 60 * 24 * 7),
        'path' => $params['path'] ?: '/',
        'domain' => $params['domain'] ?: '',
        'secure' => (bool) $params['secure'],
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);

    $_SESSION['user'] = [
        'id'        => $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'email'     => $user['email'] ?? null,
        'role'      => $user['role_name'] ?? $user['role'] ?? 'staff',
        'role_id'   => $user['role_id'] ?? null,
        'contact'   => $user['contact'] ?? null,
        'image'     => $user['image'] ?? null,
    ];
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    unset($_SESSION['account_suspended'], $_SESSION['inactive_user_id'], $_SESSION['account_resume_url']);
    $_SESSION['last_activity'] = time();
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function normalize_role_name(?string $role): string {
    $role = strtolower(trim((string) $role));
    $role = preg_replace('/[\\s-]+/', '_', $role) ?? $role;
    return match ($role) {
        'business_admin' => 'admin',
        'staff' => 'cashier',
        default => $role,
    };
}

function current_user_role(): string {
    $u = current_user();
    return normalize_role_name($u['role'] ?? ($u['role_name'] ?? ''));
}

function role_display_name(?string $role): string {
    return match (normalize_role_name($role)) {
        'admin' => 'Admin',
        'manager' => 'Manager',
        'cashier' => 'Cashier',
        default => 'User',
    };
}

function suspend_inactive_user_session(int $userId): void {
    $_SESSION['flash'] = 'Your account is currently inactive. Please contact an administrator to review your account status.';
    $_SESSION['flash_type'] = 'inactive';
    if (($userId ?? 0) > 0) {
        $_SESSION['inactive_user_id'] = $userId;
    }
    $_SESSION['account_suspended'] = true;
}

function resume_active_user_session(): void {
    if (!empty($_SESSION['account_suspended']) && ($_SESSION['flash_type'] ?? '') === 'inactive') {
        unset($_SESSION['flash'], $_SESSION['flash_type']);
    }
    unset($_SESSION['account_suspended'], $_SESSION['inactive_user_id'], $_SESSION['account_resume_url']);
}

function deny_inactive_account(int $userId): void {
    suspend_inactive_user_session($userId);
    if (request_expects_json_response()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'code' => 'account_inactive', 'error' => 'Your account is inactive. Access will resume when an administrator reactivates it.']);
        exit;
    }

    // Resume only a viewing page. Never repeat an interrupted payment or other action.
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $page = basename($requestPath);
    $resumePages = ['dashboard.php', 'orders.php', 'order_payment.php', 'order_list.php', 'items.php', 'inventory.php', 'users.php', 'categories.php', 'reports.php', 'profile.php', 'maintenance.php', 'view_order.php'];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && in_array($page, $resumePages, true)) {
        $query = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        $_SESSION['account_resume_url'] = $page . ($query !== '' ? '?' . $query : '');
    }
    require_once dirname(__DIR__) . '/public/partials/assets.php';
    header('Location: ' . kape_asset_url('account_paused.php'));
    exit;
}

function require_login(): void {
    $user = current_user();
    if (!$user) {
        header("Location: login.php");
        exit;
    }

    $userId = (int) ($user['id'] ?? 0);
    if ($userId <= 0) {
        logout_user();
        header("Location: login.php");
        exit;
    }

    static $validatedUserIds = [];
    if (!isset($validatedUserIds[$userId])) {
        try {
            require_once __DIR__ . '/db.php';
            $statusStmt = db()->prepare('SELECT u.status, u.role_id, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1');
            $statusStmt->execute([$userId]);
            $account = $statusStmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                logout_user();
                header("Location: login.php");
                exit;
            }
            if (strtolower(trim((string) $account['status'])) !== 'active') {
                deny_inactive_account($userId);
            }
            $_SESSION['user']['role_id'] = $account['role_id'];
            $_SESSION['user']['role'] = $account['role_name'] ?? '';
            resume_active_user_session();
            $validatedUserIds[$userId] = true;
        } catch (Throwable $e) {
            error_log('Account status check failed: ' . $e->getMessage());
            http_response_code(503);
            if (request_expects_json_response()) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Unable to confirm your account status. Please try again.']);
            } else {
                echo 'Unable to confirm your account status. Please try again.';
            }
            exit;
        }
    }

    $_SESSION['last_activity'] = time();
}

function is_admin(): bool {
    return current_user_role() === 'admin';
}

function is_manager(): bool {
    return current_user_role() === 'manager';
}

function is_cashier(): bool {
    return current_user_role() === 'cashier';
}

function has_role(string ...$roles): bool {
    $currentRole = current_user_role();
    foreach ($roles as $role) {
        if ($currentRole === normalize_role_name($role)) {
            return true;
        }
    }
    return false;
}

function system_runtime_settings(): array {
    static $settings = null;

    if (is_array($settings)) {
        return $settings;
    }

    try {
        require_once __DIR__ . '/db.php';
        require_once __DIR__ . '/settings.php';
        $settings = settings_all(db());
    } catch (Throwable $e) {
        error_log('System mode check failed: ' . $e->getMessage());
        $settings = function_exists('settings_defaults') ? settings_defaults() : ['system_mode' => 'active'];
    }

    return $settings;
}

function system_is_maintenance(): bool {
    $settings = system_runtime_settings();
    return strtolower(trim((string) ($settings['system_mode'] ?? 'active'))) === 'maintenance';
}

function system_maintenance_message(): string {
    $settings = system_runtime_settings();
    $message = trim((string) ($settings['maintenance_message'] ?? ''));

    return $message !== ''
        ? $message
        : 'Transactions are temporarily unavailable while system maintenance is in progress.';
}

function request_expects_json_response(): bool {
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $page = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $jsonEndpoints = [
        'order_item_add.php',
        'order_item_cancel.php',
        'order_payment_data.php',
        'payment_manual_add.php',
        'payment_manual_remove.php',
    ];

    return str_contains($accept, 'application/json')
        || $requestedWith === 'xmlhttprequest'
        || in_array($page, $jsonEndpoints, true);
}

function deny_during_maintenance(): void {
    $message = system_maintenance_message();

    if (request_expects_json_response()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 300');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => 'maintenance_mode',
        ]);
        exit;
    }

    $_SESSION['maintenance_notice'] = $message;
    header('Location: maintenance.php');
    exit;
}

function require_transactions_available(): void {
    if (system_is_maintenance()) {
        deny_during_maintenance();
    }
}

function can(string $capability): bool {
    if ($capability === 'process_transactions' && system_is_maintenance()) {
        return false;
    }

    $role = current_user_role();
    if ($role === 'admin') {
        return true;
    }

    $permissions = [
        'manager' => [
            'view_business_intelligence',
            'view_reports',
            'generate_reports',
            'export_reports',
            'view_inventory_forecast',
            'manage_reorder_settings',
            'manage_team_performance_settings',
            'view_order_history',
            'view_catalog',
        ],
        'cashier' => [
            'process_transactions',
            'view_order_history',
            'view_catalog',
            'view_stock_availability',
        ],
    ];

    return in_array($capability, $permissions[$role] ?? [], true);
}

function deny_access(string $message = 'You do not have permission to access that page.'): void {
    $_SESSION['flash'] = $message;
    header('Location: no_access.php');
    exit;
}

function require_capability(string $capability): void {
    require_login();
    if ($capability === 'process_transactions') {
        require_transactions_available();
    }
    if (!can($capability)) {
        deny_access();
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        deny_access();
    }
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
