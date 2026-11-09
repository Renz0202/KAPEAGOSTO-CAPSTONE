<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/config/recaptcha.php';
require_once dirname(__DIR__, 2) . '/includes/login_security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

function flash_and_back(string $msg): void {
    $_SESSION['flash'] = $msg;
    $_SESSION['flash_type'] = 'error'; // Always set warning messages to error
    header('Location: login.php');
    exit;
}

$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$captchaResponse = is_string($_POST['g-recaptcha-response'] ?? null) ? $_POST['g-recaptcha-response'] : '';
$csrfToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';

if (empty($_SESSION['login_csrf_token']) || !hash_equals($_SESSION['login_csrf_token'], $csrfToken)) {
    flash_and_back('Your sign-in page has expired. Please try again.');
}

if ($username === '' || $password === '') {
    flash_and_back('Username and password are required.');
}

$clientAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
try {
    $retryAfter = kape_login_update_attempts($clientAddress, $username);
} catch (Throwable $e) {
    error_log('Login attempt check failed: ' . $e->getMessage());
    flash_and_back('Sign-in is temporarily unavailable. Please try again.');
}
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    flash_and_back('Too many sign-in attempts. Please wait ' . (int) ceil($retryAfter / 60) . ' minutes and try again.');
}

// Require reCAPTCHA whenever Google can be reached, including on the shop LAN.
// Only a direct local request may continue when the verification service is unreachable.
$localLogin = kape_login_is_local_request($_SERVER);
if (!$localLogin && $captchaResponse === '') {
    flash_and_back('Please complete the reCAPTCHA.');
}

$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$options = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query([
            'secret' => RECAPTCHA_SECRET_KEY,
            'response' => $captchaResponse,
            'remoteip' => $clientAddress,
        ]),
        'timeout' => 3,
    ],
];
// With no token this is a connectivity probe; a reachable service still requires CAPTCHA.
$verify = @file_get_contents($verifyUrl, false, stream_context_create($options));
if ($verify === false) {
    if (!$localLogin) {
        flash_and_back('Unable to verify reCAPTCHA. Check the server internet connection and try again.');
    }
} else {
    if ($captchaResponse === '') {
        flash_and_back('Please complete the reCAPTCHA.');
    }
    $captchaResult = json_decode($verify, true);
    if (!is_array($captchaResult) || empty($captchaResult['success'])) {
        flash_and_back('reCAPTCHA verification failed. Please reload the page and try again.');
    }
    $captchaHostname = strtolower(trim((string) ($captchaResult['hostname'] ?? '')));
    if ($captchaHostname === '' || !recaptcha_hostname_is_allowed($captchaHostname)) {
        flash_and_back('reCAPTCHA was completed on an unauthorized hostname.');
    }
}

/**
 * ✅ Check user credentials
 */
$sql = "
    SELECT u.id, u.username, u.full_name, u.email, u.contact, u.image,
           u.role_id, r.name AS role_name, u.password_hash, u.status
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.username = ?
    LIMIT 1
";
$stmt = db()->prepare($sql);
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user) {
    flash_and_back('Invalid username or password.');
}

if (strtolower($user['status']) !== 'active') {
    $_SESSION['flash'] = 'Your account is not active. Please contact the administrator.';
    $_SESSION['flash_type'] = 'inactive';
    header('Location: login.php');
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    flash_and_back('Invalid username or password.');
}

try {
    kape_login_update_attempts($clientAddress, $username, true);
} catch (Throwable $e) {
    error_log('Login attempt reset failed: ' . $e->getMessage());
}
unset($_SESSION['login_csrf_token']);

if (empty($user['role_name'])) {
    $user['role_name'] = 'cashier';
}
$user['role_name'] = normalize_role_name($user['role_name']);

login_user($user);
$_SESSION['flash'] = "Welcome, {$user['username']}!";
setcookie('kape_returning_user', '1', [
    'expires' => time() + 60 * 60 * 24 * 365,
    'path' => '/',
    'samesite' => 'Lax',
]);
$loginDestination = $user['role_name'] === 'cashier' ? 'orders.php' : 'dashboard.php';
header('Location: ' . $loginDestination);
exit;
