<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once __DIR__ . '/partials/assets.php';

if (!current_user()) {
    header('Location: ' . kape_asset_url('login.php'));
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$resumePath = $_SESSION['account_resume_url'] ?? (is_cashier() ? 'orders.php' : 'dashboard.php');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Account Paused - Kape Agosto</title>
  <?php include __DIR__ . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-maintenance-mode-page">
<main class="maintenance-mode-main account-paused-main">
  <section class="maintenance-mode-card" aria-labelledby="account-paused-title">
    <span class="maintenance-mode-icon" aria-hidden="true"><i class="fa fa-user-slash"></i></span>
    <p class="maintenance-mode-eyebrow">Account notice</p>
    <h1 id="account-paused-title">Your account is paused</h1>
    <p class="maintenance-mode-message">Access will resume when an administrator reactivates your account. You can stay on this page without signing in again.</p>
    <div class="maintenance-mode-actions"><a href="<?= htmlspecialchars(kape_asset_url('logout.php')) ?>">Sign out</a></div>
  </section>
</main>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/account-status.js') . '?v=' . filemtime(__DIR__ . '/scripts/account-status.js')) ?>" data-status-url="<?= htmlspecialchars(kape_asset_url('account_status.php')) ?>" data-login-url="<?= htmlspecialchars(kape_asset_url('login.php')) ?>" data-resume-url="<?= htmlspecialchars(kape_asset_url($resumePath)) ?>"></script>
</body>
</html>
