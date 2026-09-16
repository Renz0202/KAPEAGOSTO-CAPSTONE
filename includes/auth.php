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
    $_SESSION['last_activity'] = time();
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function normalize_role_name(?string $role): string {
    $role = strtolower(trim((string) $role));
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

function require_login(): void {
    if (!current_user()) {
        header("Location: login.php");
        exit;
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
