<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/config/recaptcha.php';

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

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$captchaResponse = $_POST['g-recaptcha-response'] ?? '';

if ($username === '' || $password === '') {
    flash_and_back('Username and password are required.');
}

// reCAPTCHA is mandatory for every login attempt.
if (!$captchaResponse) {
    flash_and_back('Please complete the reCAPTCHA.');
}

    /**
     * ✅ Verify reCAPTCHA
     */
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$data = [
    'secret'   => RECAPTCHA_SECRET_KEY,
    'response' => $captchaResponse,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
];

$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($data),
        'timeout' => 5,
    ]
];
$context = stream_context_create($options);
$verify = @file_get_contents($verifyUrl, false, $context);

if ($verify === false) {
    flash_and_back('Unable to verify reCAPTCHA. Check the server internet connection and try again.');
}

$captchaResult = json_decode($verify, true);
if (!is_array($captchaResult) || empty($captchaResult['success'])) {
    flash_and_back('reCAPTCHA verification failed. Please reload the page and try again.');
}

$captchaHostname = strtolower(trim((string) ($captchaResult['hostname'] ?? '')));
if ($captchaHostname === '' || !recaptcha_hostname_is_allowed($captchaHostname)) {
    flash_and_back('reCAPTCHA was completed on an unauthorized hostname.');
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
    flash_and_back('Your account is not active. Please contact the administrator.');
}

if (!password_verify($password, $user['password_hash'])) {
    flash_and_back('Invalid username or password.');
}

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
