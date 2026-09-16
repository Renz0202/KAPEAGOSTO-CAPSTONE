<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
$pdo = db();

// Helper: lightweight event logger
$logEvent = function($pdo, $type, $userId = null, $email = null, $token = null, $message = null) {
    try {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_REAL_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')));
        if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $stmt = $pdo->prepare('INSERT INTO password_reset_events (event_type, user_id, email, token, message, ip, user_agent) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$type, $userId, $email, $token, $message, $ip, $ua]);
    } catch (Throwable $e) {
        // ignore
    }
};

// Read token from GET or POST so POST submissions still have it
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Only token-based reset is allowed (security)
$display_username = '';
$display_email_masked = '';
// Helper to mask email (first & last char keep, rest stars before @)
$maskEmail = function($email) {
    if (!$email || strpos($email, '@') === false) return '';
    [$local, $domain] = explode('@', $email, 2);
    if (strlen($local) <= 2) return substr($local, 0, 1) . '*' . '@' . $domain;
    return substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 2)) . substr($local, -1) . '@' . $domain;
};

if ($token) {
    // First fetch token row without expiry condition to differentiate reasons
    $stmt = $pdo->prepare('SELECT id, username, email, reset_expires, reset_used FROM users WHERE reset_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
        // Block reset usage for default Admin even if token somehow generated
        $isDefaultAdmin = (strcasecmp($row['username'] ?? '', (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
            || (strcasecmp($row['email'] ?? '', (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);
        if ($isDefaultAdmin) {
            $_SESSION['flash'] = 'Password reset is disabled for the default Admin account.';
            header('Location: forgot_password.php');
            exit;
        }
        $expiresAt = $row['reset_expires'] ?? null;
        $usedFlag  = (int)($row['reset_used'] ?? 0);
        $nowTs     = time();
        $expTs     = $expiresAt ? strtotime($expiresAt) : 0;
        if ($usedFlag === 1) {
            $_SESSION['flash'] = 'This reset link was already used. Please request a new one.';
            header('Location: forgot_password.php');
            exit;
        }
        if (!$expTs || $expTs < $nowTs) {
            $_SESSION['flash'] = 'This reset link has expired. Please request a new one.';
            header('Location: forgot_password.php');
            exit;
        }
        // Valid & active token
        $display_username = $row['username'];
        $display_email_masked = $maskEmail($row['email'] ?? '');
    } else {
        $_SESSION['flash'] = 'Invalid reset link. Please request a new one.';
        header('Location: forgot_password.php');
        exit;
    }
} else {
    $_SESSION['flash'] = 'Missing reset token. Please use the link from your email.';
    header('Location: forgot_password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    if (strlen($password) < 6) {
        $_SESSION['flash'] = 'Password must be at least 6 characters.';
        header('Location: reset_password.php?token=' . urlencode($token));
        exit;
    }
    if ($password !== $confirm_password) {
        $_SESSION['flash'] = 'Passwords do not match.';
        header('Location: reset_password.php?token=' . urlencode($token));
        exit;
    }
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Validate token again and update password
    $stmt = $pdo->prepare('SELECT id, username, email, reset_expires, reset_used FROM users WHERE reset_token = ? LIMIT 1');
    $stmt->execute([$token]);
    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch();
        $isDefaultAdmin = (strcasecmp($row['username'] ?? '', (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
            || (strcasecmp($row['email'] ?? '', (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);
        if ($isDefaultAdmin) {
            $_SESSION['flash'] = 'Password reset is disabled for the default Admin account.';
            header('Location: forgot_password.php');
            exit;
        }
        $expiresAt = $row['reset_expires'] ?? null;
        $usedFlag  = (int)($row['reset_used'] ?? 0);
        $expTs     = $expiresAt ? strtotime($expiresAt) : 0;
        if ($usedFlag === 1) {
            $_SESSION['flash'] = 'This reset link was already used. Please request a new one.';
            header('Location: forgot_password.php');
            exit;
        }
        if (!$expTs || $expTs < time()) {
            $_SESSION['flash'] = 'This reset link has expired. Please request a new one.';
            header('Location: forgot_password.php');
            exit;
        }
        $userId = $row ? (int)$row['id'] : null;
        $email = $row['email'] ?? null;
        $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL, reset_used = 1, status = 'active' WHERE reset_token = ?")
            ->execute([$password_hash, $token]);
        // Mark audit as used if present
        try {
            $upd = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token = ?');
            $upd->execute([$token]);
        } catch (Throwable $e) {
            // ignore audit failures
        }
        // Event log
        $logEvent($pdo, 'reset_used', $userId, $email, $token, 'password changed');
        $_SESSION['flash'] = 'Password reset successful! You may now login.';
        header('Location: login.php');
        exit;
    } else {
        $logEvent($pdo, 'reset_invalid', null, null, $token, 'invalid token on POST');
        $_SESSION['flash'] = 'Invalid reset token. Please start again.';
        header('Location: forgot_password.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reset Password</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-auth-reset-password">
<div class="wrap">
    <form class="card" method="post" id="resetForm">
        <div class="title">Reset Password</div>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <?php if ($flash): ?>
          <div class="flash"><?= htmlspecialchars($flash) ?></div>
        <?php endif; ?>
                <?php if ($display_username): ?>
            <div style="margin-bottom:16px;padding:12px 14px;border-radius:10px;background:#fff3cd;border:1px solid #ffe08a;color:#5c4700;font-weight:600;text-align:center;font-size:14px;">
                Resetting password for account: <span style="color:#c7922b;"><?= htmlspecialchars($display_username) ?></span>
                <?php if ($display_email_masked): ?>
                    <div style="margin-top:4px;font-weight:500;font-size:12px;color:#6a5505;">Email: <?= htmlspecialchars($display_email_masked) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="info-box">
            Enter a new password (minimum 6 characters). After a successful reset you can log in using this new password. Keep it secure and do not share it.
        </div>
        <label for="password">Password</label>
        <input type="password" name="password" id="password" required minlength="6" autocomplete="new-password">
        <label for="confirm_password">Confirm Password</label>
        <input type="password" name="confirm_password" id="confirm_password" required minlength="6" autocomplete="new-password">
        <button class="btn" type="submit">Reset Password</button>
        <a class="back-link" href="login.php">Back to Login</a>
        <div class="footer-note">© <?= date('Y') ?> TECH TITANS</div>
    </form>
</div>
<script>
document.getElementById('resetForm').addEventListener('submit', function(e) {
    var password = document.getElementById('password');
    var confirm = document.getElementById('confirm_password');
    if (password && password.value.length < 6) {
        password.focus();
        password.style.borderColor = '#f00';
        e.preventDefault();
        return false;
    }
    if (confirm && password.value !== confirm.value) {
        confirm.focus();
        confirm.style.borderColor = '#f00';
        window.kapeNotify('Passwords do not match.', 'error');
        e.preventDefault();
        return false;
    }
    // Remove username validation since username input is gone
});
</script>
</body>
</html>
