<?php
require_once dirname(__DIR__, 2) . '/includes/session.php';
kape_start_session();
require_once dirname(__DIR__, 2) . '/config/recaptcha.php';
require_once dirname(__DIR__, 2) . '/includes/login_security.php';
$localLogin = kape_login_is_local_request($_SERVER);
if (empty($_SESSION['login_csrf_token'])) {
  $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}
if (!empty($_SESSION['user']) && !empty($_SESSION['account_suspended'])) {
  require_once dirname(__DIR__) . '/partials/assets.php';
  header('Location: ' . kape_asset_url('account_paused.php'));
  exit;
}
$flash = $_SESSION['flash'] ?? null;
$flash_type = $_SESSION['flash_type'] ?? null; // <-- Add this line
$inactiveAccountReactivated = false;
if ($flash_type === 'inactive') {
  $inactiveUserId = (int) ($_SESSION['inactive_user_id'] ?? 0);
  if ($inactiveUserId > 0) {
    try {
      require_once dirname(__DIR__, 2) . '/includes/db.php';
      $inactiveStatusStmt = db()->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
      $inactiveStatusStmt->execute([$inactiveUserId]);
      $inactiveAccountReactivated = strtolower(trim((string) $inactiveStatusStmt->fetchColumn())) === 'active';
    } catch (Throwable $e) {
      error_log('Reactivated account check failed: ' . $e->getMessage());
    }
    unset($_SESSION['inactive_user_id']);
  }

  if ($inactiveAccountReactivated) {
    $flash = 'Your account is active again. Please sign in to continue.';
    $flash_type = 'success';
  } else {
    http_response_code(403);
  }
}
unset($_SESSION['flash']);
unset($_SESSION['flash_type']); // <-- Add this line

$isReturningUser = ($_COOKIE['kape_returning_user'] ?? '') === '1';
$loginGreeting = $isReturningUser ? 'Welcome Back' : 'WELCOME';
$loginSubtitle = $isReturningUser
  ? 'Log in again to continue your account.'
  : 'Log in to access your account.';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $flash_type === 'inactive' ? 'Account Inactive - Kape Agosto' : 'Kape Agosto - Login' ?></title>
<?php if (!$localLogin): ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preconnect" href="https://www.google.com" crossorigin>
<link rel="preconnect" href="https://www.gstatic.com" crossorigin>
<link rel="dns-prefetch" href="//fonts.gstatic.com">
<link rel="dns-prefetch" href="//www.google.com">
<link rel="dns-prefetch" href="//www.gstatic.com">
<?php endif; ?>
<link rel="preload" href="img/bg.jpg.png" as="image">
<?php
$kapeLocalAssetsOnly = $localLogin;
include dirname(__DIR__) . '/partials/stylesheets.php';
?>
</head>

<body class="<?= $flash_type === 'inactive' ? 'kape-access-denied' : 'kape-auth-login' ?>">
<?php if ($flash_type === 'inactive'): ?>
  <main class="access-denied-shell">
    <section class="access-denied-card" aria-labelledby="inactive-account-title">
      <div class="access-denied-visual" aria-hidden="true">
        <div class="access-brand"><img src="img/NEWLOGO_WHITE.png" alt=""></div>
        <div class="access-shield"><i class="fa-solid fa-user-slash"></i><span><i class="fa-solid fa-lock"></i></span></div>
        <div class="access-code"><span>Account</span><strong>403</strong></div>
      </div>

      <div class="access-denied-content">
        <img src="img/CUP_BLACK.png" alt="Kape Agosto" class="access-logo">
        <div class="access-status-pill"><i class="fa-solid fa-circle-exclamation"></i> Account inactive</div>
        <p class="access-eyebrow">Sign-in unavailable</p>
        <h1 id="inactive-account-title">Account Inactive</h1>
        <p class="access-message">Your account is currently inactive, so you cannot sign in. Please contact an administrator to review your account status.</p>

        <div class="access-user-card">
          <span class="access-user-icon"><i class="fa-solid fa-user-lock"></i></span>
          <span><small>Account status</small><strong>Inactive</strong></span>
          <span class="access-role-chip">Sign-in blocked</span>
        </div>

        <p class="access-help">If you believe this is a mistake, an administrator can help restore your access.</p>
        <div class="access-actions">
          <a href="login.php" class="access-primary-action"><i class="fa-solid fa-arrow-left"></i> Return to Login</a>
          <a href="forgot_password.php" class="access-secondary-action"><i class="fa-regular fa-circle-question"></i> Forgot Password?</a>
        </div>
      </div>
    </section>

    <p class="access-page-note"><i class="fa-solid fa-lock"></i> Kape Agosto account status</p>
  </main>
<?php else: ?>
<div class="wrap">
  <form class="card" method="post" action="auth_login.php" novalidate id="loginForm"
        data-local-login="<?= $localLogin ? 'true' : 'false' ?>"
        data-recaptcha-site-key="<?= htmlspecialchars(RECAPTCHA_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['login_csrf_token']) ?>">
    <div class="brand">
      <img src="img/NEWLOGO_BLACK.png" alt="Kape Agosto logo">
      <h1 class="title" id="loginGreeting"><?= htmlspecialchars($loginGreeting) ?></h1>
      <p class="subtitle" id="loginSubtitle"><?= htmlspecialchars($loginSubtitle) ?></p>
    </div>

    <?php if ($flash): ?>
      <div class="flash <?= $flash_type === 'error' ? 'error' : ($flash_type === 'success' ? 'success' : '') ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <div class="row">
      <div class="field">
        <label class="sr-only" for="username">Username</label>
        <div class="input-wrap">
          <input id="username" name="username" type="text" required autofocus autocomplete="username" placeholder="Username">
        </div>
      </div>
      <div class="field">
        <label class="sr-only" for="password">Password</label>
        <div class="input-wrap">
          <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Password">
          <button type="button" class="toggle-pass" id="togglePass" aria-controls="password" aria-label="Show password">show</button>
        </div>
      </div>
    </div>

    <div class="links">
      <a href="forgot_password.php">Forgot Password?</a>
    </div>

    <div class="recap" id="recap-container">
      <div id="recaptcha-widget"></div>
    </div>
    <p class="local-login-note" id="loginConnectionStatus" role="status" aria-live="polite">Loading reCAPTCHA...</p>

    <button class="btn" id="loginButton" type="submit" disabled>Login</button>

      </form>
</div>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/login-connectivity.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/login-connectivity.js')) ?>" defer></script>
<script src="scripts/live-reload.js"></script>
<script src="scripts/perf.js" defer></script>
<?php endif; ?>
</body>
</html>
