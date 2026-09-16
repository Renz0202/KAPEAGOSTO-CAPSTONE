<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';

require_login();
http_response_code(403);

$user = current_user() ?? [];
$displayName = trim((string) ($user['full_name'] ?? $user['username'] ?? 'User'));
$displayName = $displayName !== '' ? $displayName : 'User';
$role = trim((string) ($user['role'] ?? 'cashier'));
$roleLabel = role_display_name($role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex,nofollow">
  <title>Access Denied - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-access-denied">
  <main class="access-denied-shell">
    <section class="access-denied-card" aria-labelledby="access-denied-title">
      <div class="access-denied-visual" aria-hidden="true">
        <div class="access-brand"><img src="img/NEWLOGO_WHITE.png" alt=""></div>
        <div class="access-shield"><i class="fa-solid fa-shield-halved"></i><span><i class="fa-solid fa-lock"></i></span></div>
        <div class="access-code"><span>Error</span><strong>403</strong></div>
      </div>

      <div class="access-denied-content">
        <img src="img/CUP_BLACK.png" alt="Kape Agosto" class="access-logo">
        <div class="access-status-pill"><i class="fa-solid fa-circle-exclamation"></i> Restricted area</div>
        <p class="access-eyebrow">Permission required</p>
        <h1 id="access-denied-title">Access Denied</h1>
        <p class="access-message">Your account does not have permission to open this page. Your session is still active and no account information was changed.</p>

        <div class="access-user-card">
          <span class="access-user-icon"><i class="fa-solid fa-user-shield"></i></span>
          <span><small>Signed in as</small><strong><?= htmlspecialchars($displayName) ?></strong></span>
          <span class="access-role-chip"><?= htmlspecialchars($roleLabel) ?></span>
        </div>

        <p class="access-help">If you need access to this feature, please contact an administrator to review your account role.</p>
        <div class="access-actions">
          <a href="dashboard.php" class="access-primary-action"><i class="fa-solid fa-house"></i> Back to Dashboard</a>
          <a href="profile.php" class="access-secondary-action"><i class="fa-regular fa-user"></i> View Profile</a>
        </div>
      </div>
    </section>

    <p class="access-page-note"><i class="fa-solid fa-lock"></i> Protected by Kape Agosto account permissions</p>
  </main>
</body>
</html>
