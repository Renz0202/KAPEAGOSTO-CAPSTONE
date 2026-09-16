<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/mailer.php';
require_once dirname(__DIR__, 2) . '/includes/settings.php';
require_once dirname(__DIR__, 2) . '/includes/email_templates.php';

$pdo = db();
$settings = settings_all($pdo);
$businessName = settings_value($settings, 'business_name');
$supportEmail = settings_value($settings, 'business_email');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrfToken = $_SESSION['csrf_token'];

$flash = $_SESSION['flash'] ?? '';
$flashType = $_SESSION['flash_type'] ?? 'info';
unset($_SESSION['flash'], $_SESSION['flash_type']);

$flowState = $_SESSION['reset_flow_state'] ?? 'identify';
$identifierValue = $_SESSION['reset_identifier'] ?? '';
$maskedEmail = $_SESSION['reset_email_masked'] ?? '';

$maskEmail = function(string $email): string {
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 2) {
        return substr($local, 0, 1) . '***@' . $domain;
    }
    return substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 3)) . substr($local, -1) . '@' . $domain;
};

$getIp = function(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $value = (string) $_SERVER[$key];
            return $key === 'HTTP_X_FORWARDED_FOR' ? trim(explode(',', $value)[0]) : $value;
        }
    }
    return '0.0.0.0';
};

$logEvent = function(string $type, ?int $userId = null, ?string $email = null, ?string $token = null, ?string $message = null) use ($pdo, $getIp): void {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO password_reset_events (event_type, user_id, email, token, message, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $type,
            $userId,
            $email,
            $token,
            $message,
            $getIp(),
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        // Audit logging should not block account recovery.
    }
};

$setFlash = function(string $message, string $type = 'info'): void {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type;
};

$findUser = function(string $identifier) use ($pdo): ?array {
    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
    $stmt = $pdo->prepare($isEmail
        ? 'SELECT id, username, full_name, email, status, reset_token, reset_expires, reset_used FROM users WHERE email = ? LIMIT 1'
        : 'SELECT id, username, full_name, email, status, reset_token, reset_expires, reset_used FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([$identifier]);
    $user = $stmt->fetch();
    return $user ?: null;
};

$redirectSelf = function() {
    header('Location: forgot_password.php');
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $csrf)) {
        $setFlash('Invalid request. Please try again.', 'danger');
        $redirectSelf();
    }

    $action = (string) ($_POST['action'] ?? 'request_code');

    if ($action === 'request_code') {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        if ($identifier === '') {
            $setFlash('Please enter your username or email.', 'danger');
            $redirectSelf();
        }

        try {
            $limit = max(1, (int) (getenv('RESET_LIMIT_MAX') ?: 8));
            $windowMinutes = max(1, (int) (getenv('RESET_LIMIT_WINDOW_MIN') ?: 10));
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS c
                FROM password_reset_events
                WHERE ip = ?
                  AND created_at > (NOW() - INTERVAL ' . $windowMinutes . ' MINUTE)
            ');
            $stmt->execute([$getIp()]);
            if ((int) ($stmt->fetch()['c'] ?? 0) >= $limit) {
                $logEvent('rate_limited', null, null, null, 'Too many reset attempts from IP');
                $setFlash('Too many reset attempts. Please wait and try again later.', 'danger');
                $redirectSelf();
            }
        } catch (Throwable $e) {
            // Fail open if the audit table is temporarily unavailable.
        }

        $user = $findUser($identifier);
        if (!$user) {
            $logEvent('request_unknown', null, filter_var($identifier, FILTER_VALIDATE_EMAIL) ? $identifier : null, null, 'identifier not found');
            $setFlash('If the account is active and has an email address, a verification code will be sent.', 'success');
            $redirectSelf();
        }

        $isDefaultAdmin = (strcasecmp($user['username'] ?? '', defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin') === 0)
            || (strcasecmp($user['email'] ?? '', defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local') === 0);
        if ($isDefaultAdmin) {
            $logEvent('request_blocked', (int) $user['id'], $user['email'] ?? null, null, 'default admin reset blocked');
            $setFlash('Password reset is disabled for the default Admin account.', 'danger');
            $redirectSelf();
        }

        if (strtolower(trim((string) ($user['status'] ?? ''))) !== 'active') {
            $logEvent('request_inactive', (int) $user['id'], $user['email'] ?? null, null, 'inactive account');
            $setFlash('Your account is inactive or disabled. Please contact the administrator.', 'danger');
            $redirectSelf();
        }

        $to = trim((string) ($user['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $logEvent('email_missing', (int) $user['id'], $to ?: null, null, 'account email missing or invalid');
            $setFlash('This account does not have a valid email address. Please contact the administrator.', 'danger');
            $redirectSelf();
        }

        $code = (string) random_int(100000, 999999);
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        $auditToken = hash('sha256', $code . '|' . random_bytes(16));
        $expires = date('Y-m-d H:i:s', time() + 15 * 60);

        $pdo->prepare('
            UPDATE users
            SET reset_token = ?, reset_expires = ?, reset_sent_at = NOW(), reset_used = 0
            WHERE id = ?
        ')->execute([$codeHash, $expires, (int) $user['id']]);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO password_resets (user_id, token, requested_at, expires_at, request_ip, user_agent, method)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                (int) $user['id'],
                $auditToken,
                date('Y-m-d H:i:s'),
                $expires,
                $getIp(),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'email_code',
            ]);
        } catch (Throwable $e) {
            // Best-effort audit only.
        }

        $recipientName = trim((string) ($user['full_name'] ?: $user['username']));
        $subject = $businessName . ' password reset code';
        $html = password_reset_email_html($recipientName, $code, $businessName, $supportEmail);
        $alt = "Password Reset Code\n\nHello {$recipientName},\n\nYour {$businessName} password reset code is: {$code}\n\nThis code expires in 15 minutes. If you did not request this, you can safely ignore this email.\n\nFacebook: https://www.facebook.com/kapeagosto\nInstagram: https://www.instagram.com/kapeAgosto\nTikTok: https://www.tiktok.com/@KapeAgosto";
        $logoPath = dirname(__DIR__) . '/img/NEWLOGO_BLACK.png';
        $embeddedImages = [[
            'path' => $logoPath,
            'cid' => 'kape-agosto-logo',
            'name' => 'kape-agosto-logo.png',
            'type' => 'image/png',
        ]];

        [$ok, $error] = send_mail_resilient($to, $subject, $html, $alt, $embeddedImages);
        $logEvent($ok ? 'code_sent' : 'code_failed', (int) $user['id'], $to, $auditToken, $ok ? 'reset code dispatched' : $error);

        $_SESSION['reset_flow_state'] = 'verify_code';
        $_SESSION['reset_identifier'] = $identifier;
        $_SESSION['reset_email_masked'] = $maskEmail($to);

        if ($ok) {
            $setFlash('Verification code sent to ' . $maskEmail($to) . '.', 'success');
        } else {
            $env = strtolower((string) (getenv('APP_ENV') ?: 'development'));
            $message = 'Mail send failed: ' . ($error ?: 'unknown error') . '. Please check System Settings > Email & SMTP.';
            if ($env !== 'production') {
                $message .= ' Development code: ' . $code;
            }
            $setFlash($message, 'danger');
        }
        $redirectSelf();
    }

    if ($action === 'verify_code') {
        $identifier = trim((string) ($_POST['identifier'] ?? ($_SESSION['reset_identifier'] ?? '')));
        $code = preg_replace('/\D+/', '', (string) ($_POST['reset_code'] ?? ''));
        $user = $identifier !== '' ? $findUser($identifier) : null;

        if (!$user || $code === '') {
            $setFlash('Please enter the verification code sent to your email.', 'danger');
            $_SESSION['reset_flow_state'] = 'verify_code';
            $redirectSelf();
        }

        $expiresAt = $user['reset_expires'] ?? null;
        $usedFlag = (int) ($user['reset_used'] ?? 0);
        $expTs = $expiresAt ? strtotime($expiresAt) : 0;
        $codeHash = (string) ($user['reset_token'] ?? '');

        if ($usedFlag === 1 || !$expTs || $expTs < time()) {
            $logEvent('code_expired', (int) $user['id'], $user['email'] ?? null, null, 'expired or used code');
            $setFlash('This verification code has expired. Please request a new one.', 'danger');
            $_SESSION['reset_flow_state'] = 'identify';
            $redirectSelf();
        }

        if ($codeHash === '' || !password_verify($code, $codeHash)) {
            $logEvent('code_invalid', (int) $user['id'], $user['email'] ?? null, null, 'invalid code');
            $setFlash('Invalid verification code. Please try again.', 'danger');
            $_SESSION['reset_flow_state'] = 'verify_code';
            $redirectSelf();
        }

        $_SESSION['reset_flow_state'] = 'set_password';
        $_SESSION['reset_verified_user_id'] = (int) $user['id'];
        $_SESSION['reset_identifier'] = $identifier;
        $_SESSION['reset_email_masked'] = $maskEmail((string) ($user['email'] ?? ''));
        $logEvent('code_verified', (int) $user['id'], $user['email'] ?? null, null, 'reset code verified');
        $setFlash('Code verified. Create your new password.', 'success');
        $redirectSelf();
    }

    if ($action === 'reset_password') {
        $userId = (int) ($_SESSION['reset_verified_user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        if ($userId <= 0) {
            $setFlash('Please verify your code before creating a new password.', 'danger');
            $_SESSION['reset_flow_state'] = 'identify';
            $redirectSelf();
        }
        if (strlen($password) < 6) {
            $setFlash('Password must be at least 6 characters.', 'danger');
            $_SESSION['reset_flow_state'] = 'set_password';
            $redirectSelf();
        }
        if ($password !== $confirm) {
            $setFlash('Passwords do not match.', 'danger');
            $_SESSION['reset_flow_state'] = 'set_password';
            $redirectSelf();
        }

        $stmt = $pdo->prepare('SELECT id, username, email, reset_expires, reset_used FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        $expiresAt = $user['reset_expires'] ?? null;
        $expTs = $expiresAt ? strtotime($expiresAt) : 0;

        if (!$user || (int) ($user['reset_used'] ?? 0) === 1 || !$expTs || $expTs < time()) {
            $setFlash('Your reset session expired. Please request a new code.', 'danger');
            $_SESSION['reset_flow_state'] = 'identify';
            unset($_SESSION['reset_verified_user_id']);
            $redirectSelf();
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('
            UPDATE users
            SET password_hash = ?, reset_token = NULL, reset_expires = NULL, reset_used = 1, status = "active"
            WHERE id = ?
        ')->execute([$passwordHash, $userId]);

        try {
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL ORDER BY id DESC LIMIT 1')->execute([$userId]);
        } catch (Throwable $e) {
            // Best-effort audit only.
        }

        $logEvent('reset_used', $userId, $user['email'] ?? null, null, 'password changed with code');
        unset($_SESSION['reset_flow_state'], $_SESSION['reset_identifier'], $_SESSION['reset_email_masked'], $_SESSION['reset_verified_user_id']);
        $_SESSION['flash'] = 'Password reset successful! You may now login.';
        header('Location: login.php');
        exit;
    }
}

if (!in_array($flowState, ['identify', 'verify_code', 'set_password'], true)) {
    $flowState = 'identify';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>Forgot Password - <?= htmlspecialchars($businessName) ?></title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-auth-forgot-password">
  <main class="wrap">
    <form class="card" action="forgot_password.php" method="post" novalidate id="forgotForm">
      <div class="brand">
        <img src="img/NEWLOGO_BLACK.png" alt="<?= htmlspecialchars($businessName) ?> Logo" onerror="this.style.display='none'">
      </div>

      <h1 class="title">Forgot Password</h1>
      <p class="subtitle">
        <?php if ($flowState === 'identify'): ?>
          Enter your username or email and we will send a 6-digit verification code.
        <?php elseif ($flowState === 'verify_code'): ?>
          Enter the verification code sent to <?= htmlspecialchars($maskedEmail ?: 'your email') ?>.
        <?php else: ?>
          Create a new password for your account.
        <?php endif; ?>
      </p>

      <div class="forgot-stepper" aria-label="Password reset progress">
        <span class="<?= $flowState === 'identify' ? 'active' : 'done' ?>">1</span>
        <span class="<?= $flowState === 'verify_code' ? 'active' : ($flowState === 'set_password' ? 'done' : '') ?>">2</span>
        <span class="<?= $flowState === 'set_password' ? 'active' : '' ?>">3</span>
      </div>

      <?php if ($flash !== ''): ?>
        <div class="flash <?= htmlspecialchars($flashType) ?>" role="alert"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <div class="flash" id="clientMessage" role="alert" hidden></div>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

      <?php if ($flowState === 'identify'): ?>
        <input type="hidden" name="action" value="request_code">
        <label class="field" for="identifier">
          <span>Username or Email</span>
          <div class="input-wrap">
            <input type="text" name="identifier" id="identifier" required autofocus autocomplete="username" placeholder="username or you@example.com" value="<?= htmlspecialchars($identifierValue) ?>">
          </div>
        </label>
        <button class="btn" type="submit" id="submitBtn">
          <span id="btnText">Send Verification Code</span>
          <span id="spinner" class="spinner" hidden><i class="fa fa-circle-notch fa-spin"></i></span>
        </button>
      <?php elseif ($flowState === 'verify_code'): ?>
        <input type="hidden" name="action" value="verify_code">
        <input type="hidden" name="identifier" value="<?= htmlspecialchars($identifierValue) ?>">
        <label class="field" for="reset_code">
          <span>Verification Code</span>
          <div class="input-wrap">
            <input class="reset-code-input" type="text" name="reset_code" id="reset_code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus placeholder="000000">
          </div>
        </label>
        <button class="btn" type="submit" id="submitBtn">
          <span id="btnText">Verify Code</span>
          <span id="spinner" class="spinner" hidden><i class="fa fa-circle-notch fa-spin"></i></span>
        </button>
        <button class="link-button" type="submit" name="action" value="request_code">Send New Code</button>
      <?php else: ?>
        <input type="hidden" name="action" value="reset_password">
        <label class="field" for="password">
          <span>New Password</span>
          <div class="input-wrap">
            <input type="password" name="password" id="password" required minlength="6" autocomplete="new-password" placeholder="New password">
          </div>
        </label>
        <label class="field" for="confirm_password">
          <span>Confirm Password</span>
          <div class="input-wrap">
            <input type="password" name="confirm_password" id="confirm_password" required minlength="6" autocomplete="new-password" placeholder="Confirm password">
          </div>
        </label>
        <button class="btn" type="submit" id="submitBtn">
          <span id="btnText">Create New Password</span>
          <span id="spinner" class="spinner" hidden><i class="fa fa-circle-notch fa-spin"></i></span>
        </button>
      <?php endif; ?>

      <a class="back-link" href="login.php">
        <i class="fa fa-arrow-left"></i>
        Back to Login
      </a>

      <?php if ($supportEmail !== ''): ?>
        <p class="support-note">Need help? Contact <?= htmlspecialchars($supportEmail) ?></p>
      <?php endif; ?>
    </form>
  </main>

<script>
var form = document.getElementById('forgotForm');
var clientMsg = document.getElementById('clientMessage');
var submitBtn = document.getElementById('submitBtn');
var btnText = document.getElementById('btnText');
var spinner = document.getElementById('spinner');

function setMessage(message) {
  clientMsg.textContent = message;
  clientMsg.hidden = false;
}

function clearMessage() {
  clientMsg.textContent = '';
  clientMsg.hidden = true;
}

document.querySelectorAll('.input-wrap input').forEach(function (input) {
  input.addEventListener('input', function () {
    this.closest('.input-wrap').classList.remove('invalid');
    clearMessage();
  });
});

form.addEventListener('submit', function (event) {
  clearMessage();
  var action = (event.submitter && event.submitter.name === 'action') ? event.submitter.value : (form.querySelector('input[name="action"]') || {}).value;

  if (action === 'request_code') {
    var identifier = document.getElementById('identifier');
    if (identifier && !identifier.value.trim()) {
      event.preventDefault();
      identifier.focus();
      identifier.closest('.input-wrap').classList.add('invalid');
      setMessage('Please enter your username or email.');
      return;
    }
  }

  if (action === 'verify_code') {
    var code = document.getElementById('reset_code');
    if (code && !/^\d{6}$/.test(code.value.trim())) {
      event.preventDefault();
      code.focus();
      code.closest('.input-wrap').classList.add('invalid');
      setMessage('Please enter the 6-digit verification code.');
      return;
    }
  }

  if (action === 'reset_password') {
    var password = document.getElementById('password');
    var confirmPassword = document.getElementById('confirm_password');
    if (password && password.value.length < 6) {
      event.preventDefault();
      password.focus();
      password.closest('.input-wrap').classList.add('invalid');
      setMessage('Password must be at least 6 characters.');
      return;
    }
    if (confirmPassword && password.value !== confirmPassword.value) {
      event.preventDefault();
      confirmPassword.focus();
      confirmPassword.closest('.input-wrap').classList.add('invalid');
      setMessage('Passwords do not match.');
      return;
    }
  }

  if (submitBtn && btnText && spinner) {
    submitBtn.disabled = true;
    btnText.textContent = action === 'reset_password' ? 'Saving...' : 'Processing...';
    spinner.hidden = false;
  }
});
</script>
</body>
</html>
