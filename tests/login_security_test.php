<?php
// Exercise the real sign-in endpoint with connection-local tables, never real accounts.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

if (($argv[1] ?? '') === '--case') {
    $case = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    define('KAPE_LOGIN_ATTEMPTS_FILE', $case['storage']);
    require_once dirname(__DIR__) . '/includes/db.php';
    $pdo = db();
    $pdo->exec('CREATE TEMPORARY TABLE roles (id INT PRIMARY KEY, name VARCHAR(30))');
    $pdo->exec('CREATE TEMPORARY TABLE users (
        id INT PRIMARY KEY, username VARCHAR(80), full_name VARCHAR(100),
        email VARCHAR(100), contact VARCHAR(30), image VARCHAR(100),
        role_id INT, password_hash VARCHAR(255), status VARCHAR(20))');
    $pdo->exec("INSERT INTO roles VALUES (1, 'cashier')");
    $stmt = $pdo->prepare('INSERT INTO users VALUES (1, ?, ?, NULL, NULL, NULL, 1, ?, ?)');
    $stmt->execute(['fixture_cashier', 'Fixture Cashier', password_hash('Fixture!2026', PASSWORD_DEFAULT), $case['status'] ?? 'active']);

    $_SERVER = array_replace([
        'REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1',
        'SERVER_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    ], $case['server'] ?? []);
    session_save_path($case['dir']);
    require_once dirname(__DIR__) . '/includes/session.php';
    kape_start_session();
    $_SESSION['login_csrf_token'] = 'fixture-token';
    $_POST = array_replace([
        'username' => 'fixture_cashier', 'password' => 'Fixture!2026',
        'csrf_token' => 'fixture-token',
    ], $case['post'] ?? []);

    if (($case['mode'] ?? '') === 'render') {
        ob_start();
        require dirname(__DIR__) . '/public/auth/login.php';
        $html = ob_get_clean();
        session_destroy();
        echo json_encode(['html' => $html], JSON_THROW_ON_ERROR);
        exit;
    }

    // Simulate all external verification outcomes without using the internet.
    $GLOBALS['captchaCalls'] = 0;
    $GLOBALS['captchaCase'] = $case;
    class LoginTestHttps {
        public $context;
        private string $body = '';
        private int $position = 0;
        public function stream_open($path, $mode, $options, &$openedPath): bool {
            $GLOBALS['captchaCalls']++;
            $case = $GLOBALS['captchaCase'];
            if (empty($case['captcha_available']) && empty($case['captcha_success'])) { return false; }
            $options = stream_context_get_options($this->context);
            parse_str($options['http']['content'], $fields);
            $this->body = $case['captcha_body'] ?? json_encode([
                'success' => !empty($case['captcha_success']) && ($fields['response'] ?? '') !== '',
                'hostname' => $case['captcha_hostname'] ?? 'localhost',
            ]);
            return true;
        }
        public function stream_read($count): string {
            $part = substr($this->body, $this->position, $count);
            $this->position += strlen($part);
            return $part;
        }
        public function stream_eof(): bool { return $this->position >= strlen($this->body); }
        public function stream_stat(): array { return []; }
    }
    stream_wrapper_unregister('https');
    stream_wrapper_register('https', LoginTestHttps::class);
    register_shutdown_function(static function (): void {
        $result = [
            'user' => $_SESSION['user'] ?? null,
            'flash' => $_SESSION['flash'] ?? '',
            'flash_type' => $_SESSION['flash_type'] ?? '',
            'captcha_calls' => $GLOBALS['captchaCalls'],
            'csrf_cleared' => !isset($_SESSION['login_csrf_token']),
        ];
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        echo json_encode($result, JSON_THROW_ON_ERROR);
    });
    require dirname(__DIR__) . '/public/auth/auth_login.php';
    exit;
}

$testDir = sys_get_temp_dir() . '/kape-login-test-' . bin2hex(random_bytes(8));
if (!mkdir($testDir, 0700)) { throw new RuntimeException('Cannot create test directory.'); }
define('KAPE_LOGIN_ATTEMPTS_FILE', $testDir . '/limits.json');
require_once dirname(__DIR__) . '/includes/login_security.php';
$checks = 0;
function check($value, string $label): void {
    global $checks;
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
    $checks++;
    echo 'PASS: ', $label, PHP_EOL;
}
function endpoint(array $case = []): array {
    global $testDir;
    static $nextCase = 0;
    $case = array_replace(['dir' => $testDir, 'storage' => $testDir . '/endpoint-' . (++$nextCase) . '.json'], $case);
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, '--case'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start endpoint test.'); }
    fwrite($pipes[0], json_encode($case, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) { throw new RuntimeException('Endpoint exited ' . $exit . ': ' . $errors . $output); }
    try { return json_decode($output, true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable $e) { throw new RuntimeException('Invalid endpoint output: ' . $errors . $output, 0, $e); }
}

try {
    foreach (['127.0.0.1', '127.2.3.4', '10.0.0.1', '172.16.0.1', '172.31.255.255',
              '192.168.1.10', '::1', '::ffff:192.168.1.10', 'fd12::1', 'fe80::1'] as $ip) {
        check(kape_login_is_private_address($ip), 'Local address accepted: ' . $ip);
    }
    foreach (['', 'localhost', '8.8.8.8', '172.15.1.1', '172.32.1.1', '192.169.1.1',
              '0.0.0.0', '::', '2001:4860::1', '::ffff:8.8.8.8'] as $ip) {
        check(!kape_login_is_private_address($ip), 'Public/invalid address rejected: ' . $ip);
    }
    $local = ['REMOTE_ADDR' => '192.168.1.20', 'SERVER_ADDR' => '192.168.1.10', 'HTTP_HOST' => '192.168.1.10:8080'];
    check(kape_login_is_local_request($local), 'Direct shop LAN request uses local sign-in');
    check(kape_login_is_local_request(['REMOTE_ADDR' => '::1', 'SERVER_ADDR' => '::1', 'HTTP_HOST' => '[::1]:80']), 'IPv6 loopback uses local sign-in');
    foreach ([
        ['REMOTE_ADDR' => '8.8.8.8'], ['SERVER_ADDR' => '8.8.8.8'],
        ['HTTP_HOST' => 'public.example'], ['HTTP_HOST' => 'localhost.evil.example'],
        ['HTTP_HOST' => 'localhost/extra'], ['HTTP_HOST' => 'localhost@evil.example'],
        ['HTTP_HOST' => ''], ['HTTP_X_FORWARDED_FOR' => '192.168.1.20'],
        ['HTTP_FORWARDED' => 'for=127.0.0.1'], ['HTTP_CF_CONNECTING_IP' => '127.0.0.1'],
        ['HTTP_X_FORWARDED_HOST' => 'localhost'], ['HTTP_X_REAL_IP' => '127.0.0.1'],
    ] as $override) {
        check(!kape_login_is_local_request(array_replace($local, $override)), 'Unsafe request cannot enable local sign-in: ' . array_key_first($override) . '=' . reset($override));
    }

    for ($i = 0; $i < 5; $i++) {
        check(kape_login_update_attempts('192.168.1.20', 'cashier', false, 1000 + $i) === 0, 'An allowed account attempt reserves its slot');
    }
    check(kape_login_update_attempts('192.168.1.20', ' CASHIER ', false, 1005) === 895, 'A sixth attempt is blocked across sessions and username case changes');
    check(kape_login_update_attempts('192.168.1.21', 'cashier', false, 1005) === 0, 'One device lockout does not lock out another cashier device');
    check(kape_login_update_attempts('192.168.1.20', 'cashier', false, 1900) === 0, 'Expired account and address limits allow another attempt');
    kape_login_update_attempts('192.168.1.20', 'cashier', true, 1901);
    check(kape_login_update_attempts('192.168.1.20', 'cashier', false, 1902) === 0, 'Successful sign-in clears the account failure count');
    file_put_contents(KAPE_LOGIN_ATTEMPTS_FILE, '{}');
    for ($i = 0; $i < 30; $i++) { kape_login_update_attempts('192.168.1.20', 'different_' . $i, false, 2000); }
    check(kape_login_update_attempts('192.168.1.20', 'another_user', false, 2001) === 899, 'Changing usernames cannot bypass the client address limit');

    $result = endpoint();
    check(($result['user']['username'] ?? '') === 'fixture_cashier' && $result['captcha_calls'] === 1, 'Real endpoint checks Google and signs in when it is unreachable');
    check(($result['user']['role'] ?? '') === 'cashier' && $result['csrf_cleared'], 'Offline login retains the database role and rotates the form token');
    $result = endpoint(['server' => ['REMOTE_ADDR' => '192.168.1.20', 'SERVER_ADDR' => '192.168.1.10', 'HTTP_HOST' => '192.168.1.10']]);
    check($result['user'] !== null && $result['captcha_calls'] === 1, 'A second shop device can sign in over the LAN when Google is unreachable');
    $result = endpoint(['post' => ['password' => 'wrong']]);
    check($result['user'] === null && $result['flash'] === 'Invalid username or password.', 'Offline login rejects the wrong password');
    $result = endpoint(['status' => 'inactive']);
    check($result['user'] === null && $result['flash_type'] === 'inactive', 'Offline login rejects an inactive account');
    $result = endpoint(['post' => ['username' => 'unknown']]);
    check($result['user'] === null && $result['flash'] === 'Invalid username or password.', 'Offline login rejects an unknown account');
    $result = endpoint(['post' => ['csrf_token' => '']]);
    check($result['user'] === null && str_contains($result['flash'], 'expired'), 'A missing form token cannot sign in');
    $result = endpoint(['post' => ['username' => ['malformed']]]);
    check($result['user'] === null && str_contains($result['flash'], 'required'), 'Malformed credential fields are rejected without PHP errors');
    $remote = ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_HOST' => 'public.example'];
    $result = endpoint(['server' => $remote, 'post' => ['offline' => '1', 'localLogin' => 'true']]);
    check($result['user'] === null && $result['flash'] === 'Please complete the reCAPTCHA.', 'Public requests cannot bypass CAPTCHA by posting offline flags');
    $result = endpoint(['server' => ['HTTP_X_FORWARDED_FOR' => '127.0.0.1']]);
    check($result['user'] === null && $result['flash'] === 'Please complete the reCAPTCHA.', 'A proxy request still requires CAPTCHA');
    $result = endpoint(['server' => $remote, 'post' => ['g-recaptcha-response' => 'fixture-token']]);
    check($result['user'] === null && $result['captcha_calls'] === 1 && str_contains($result['flash'], 'Unable to verify'), 'Google outages do not bypass CAPTCHA for public access');
    $result = endpoint(['server' => $remote, 'post' => ['g-recaptcha-response' => 'fixture-token'], 'captcha_success' => true]);
    check($result['user'] !== null && $result['captcha_calls'] === 1, 'Normal public CAPTCHA login still works');
    $result = endpoint(['server' => $remote, 'post' => ['g-recaptcha-response' => 'fixture-token'], 'captcha_success' => true, 'captcha_hostname' => 'evil.example']);
    check($result['user'] === null && str_contains($result['flash'], 'unauthorized hostname'), 'An unauthorized CAPTCHA hostname is rejected');

    $result = endpoint(['captcha_available' => true]);
    check($result['user'] === null && $result['flash'] === 'Please complete the reCAPTCHA.' && $result['captcha_calls'] === 1, 'Online local login cannot skip reCAPTCHA');
    $result = endpoint(['captcha_available' => true, 'post' => ['offline' => '1', 'localLogin' => 'true']]);
    check($result['user'] === null && $result['flash'] === 'Please complete the reCAPTCHA.', 'Browser offline flags cannot bypass online local verification');
    $result = endpoint(['captcha_success' => true, 'post' => ['g-recaptcha-response' => 'fixture-token']]);
    check($result['user'] !== null && $result['captcha_calls'] === 1, 'Online local login verifies a completed CAPTCHA');
    $result = endpoint(['captcha_available' => true, 'post' => ['g-recaptcha-response' => 'invalid-token']]);
    check($result['user'] === null && str_contains($result['flash'], 'verification failed'), 'An invalid online CAPTCHA cannot trigger local fallback');
    $result = endpoint(['captcha_available' => true, 'captcha_body' => '{invalid', 'post' => ['g-recaptcha-response' => 'fixture-token']]);
    check($result['user'] === null && str_contains($result['flash'], 'verification failed'), 'A malformed Google response cannot trigger local fallback');
    $result = endpoint(['captcha_available' => true, 'captcha_body' => '{"success":false,"error-codes":["timeout-or-duplicate"]}', 'post' => ['g-recaptcha-response' => 'expired-token']]);
    check($result['user'] === null && str_contains($result['flash'], 'verification failed'), 'An expired online token cannot trigger local fallback');
    $result = endpoint(['captcha_success' => true, 'captcha_hostname' => 'evil.example', 'post' => ['g-recaptcha-response' => 'fixture-token']]);
    check($result['user'] === null && str_contains($result['flash'], 'unauthorized hostname'), 'Online local login still checks the CAPTCHA hostname');
    $result = endpoint(['post' => ['g-recaptcha-response' => 'old-token']]);
    check($result['user'] !== null && $result['captcha_calls'] === 1, 'An outage during token verification allows local login');

    $limitedStorage = $testDir . '/limited.json';
    for ($i = 0; $i < 5; $i++) { endpoint(['storage' => $limitedStorage, 'post' => ['password' => 'wrong']]); }
    $result = endpoint(['storage' => $limitedStorage]);
    check($result['user'] === null && str_contains($result['flash'], 'Too many sign-in attempts'), 'The real endpoint enforces failed-attempt limits in a new PHP session');
    $corruptStorage = $testDir . '/corrupt.json';
    file_put_contents($corruptStorage, 'invalid-json');
    $result = endpoint(['storage' => $corruptStorage]);
    check($result['user'] === null && str_contains($result['flash'], 'temporarily unavailable'), 'Unavailable attempt protection fails closed');

    $html = endpoint(['mode' => 'render'])['html'];
    check(str_contains($html, 'type="submit" disabled>Login</button>') && str_contains($html, 'id="recaptcha-widget"'), 'Local login renders a CAPTCHA for online verification');
    check(str_contains($html, 'name="csrf_token"') && str_contains($html, 'data-local-login="true"') && str_contains($html, 'id="loginConnectionStatus"'), 'The local form includes a session token, local eligibility and connection status');
    check(str_contains($html, 'scripts/login-connectivity.js?v='), 'The local connection controller is served with a cache version');
    check(!preg_match('/(?:href|src)="(?:https?:)?\/\//', $html), 'The local page can render without external stylesheet or script dependencies');
    $html = endpoint(['mode' => 'render', 'server' => $remote])['html'];
    check(str_contains($html, 'type="submit" disabled>Login</button>') && str_contains($html, 'id="recaptcha-widget"'), 'The public login page still renders its required CAPTCHA');
    check(str_contains($html, 'https://fonts.googleapis.com') && str_contains($html, 'alertify.min.js'), 'Public login retains its normal external assets');
    echo 'Passed ', $checks, ' login security and endpoint checks.', PHP_EOL;
} finally {
    foreach (glob($testDir . '/*') as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($testDir);
}
