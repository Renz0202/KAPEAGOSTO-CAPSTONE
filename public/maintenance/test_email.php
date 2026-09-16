<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/mailer.php';

require_admin();

$to = isset($_POST['to']) ? trim((string) $_POST['to']) : '';
$msg = '';
$err = '';
$mailCfg = mail_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid recipient email address.';
    } else {
        $subject = 'Kape Agosto Test Email';
        $html = '<p>This is a test email from Kape Agosto.</p><p>If you received this, SMTP is working.</p>';
        [$ok, $error] = send_mail_simple($to, $subject, $html);
        $mailCfg = $GLOBALS['__mail_cfg'] ?? $mailCfg;

        if ($ok) {
            $msg = 'Test email sent successfully to ' . $to . '.';
        } else {
            $err = 'Failed to send: ' . ($error ?: 'Unknown mailer error.');
        }
    }
}

$mask = function(string $key, $value): string {
    $value = (string) $value;
    if ($value === '') {
        return '(empty)';
    }
    if ($key === 'password') {
        return str_repeat('*', min(12, max(6, strlen($value))));
    }
    if ($key === 'username' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
        [$name, $domain] = explode('@', $value, 2);
        return substr($name, 0, 2) . '***@' . $domain;
    }
    return $value;
};

$smtpAuth = !empty($mailCfg['username']) ? 'YES' : 'NO';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Test Email | Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-maintenance-test-email">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content mail-test-main">
  <div class="mail-test-breadcrumb" aria-label="Breadcrumb">
    <span><?= htmlspecialchars(role_display_name(current_user_role())) ?></span>
    <i class="fa fa-chevron-right"></i>
    <strong>Test Email</strong>
  </div>

  <section class="mail-test-hero">
    <div>
      <span class="mail-test-kicker">Email Diagnostics</span>
      <h1>Send Test Email</h1>
      <p>Use this page after saving SMTP settings to confirm that password reset emails can be delivered.</p>
    </div>
    <a class="mail-test-back" href="settings.php">
      <i class="fa fa-arrow-left"></i>
      Back to Settings
    </a>
  </section>

  <section class="mail-test-panel">
    <div class="mail-test-form-card">
      <h2>Recipient</h2>
      <form method="post">
        <label for="to">Recipient Email</label>
        <div class="mail-test-input-row">
          <input type="email" name="to" id="to" placeholder="name@example.com" value="<?= htmlspecialchars($to) ?>" required>
          <button type="submit">
            <i class="fa fa-paper-plane"></i>
            Send
          </button>
        </div>
      </form>

      <?php if ($msg !== ''): ?>
        <div class="mail-test-alert success" role="alert">
          <i class="fa fa-circle-check"></i>
          <span><?= htmlspecialchars($msg) ?></span>
        </div>
      <?php endif; ?>

      <?php if ($err !== ''): ?>
        <div class="mail-test-alert danger" role="alert">
          <i class="fa fa-circle-exclamation"></i>
          <span><?= htmlspecialchars($err) ?></span>
        </div>
      <?php endif; ?>

      <p class="mail-test-note">
        For Gmail, use <strong>smtp.gmail.com</strong>, port <strong>587</strong>, <strong>TLS</strong>, your Gmail address as username, and a Gmail App Password.
      </p>
    </div>

    <aside class="mail-test-diagnostics">
      <h2>Mailer Diagnostics</h2>
      <dl>
        <?php foreach (['driver', 'host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name', 'reply_to'] as $key): ?>
          <div>
            <dt><?= htmlspecialchars($key) ?></dt>
            <dd><?= htmlspecialchars($mask($key, $mailCfg[$key] ?? '')) ?></dd>
          </div>
        <?php endforeach; ?>
        <div>
          <dt>SMTPAuth</dt>
          <dd><?= htmlspecialchars($smtpAuth) ?></dd>
        </div>
      </dl>
      <a class="mail-test-diag-link" href="mail_diag.php">
        <i class="fa fa-stethoscope"></i>
        Open Mail Diagnostics
      </a>
    </aside>
  </section>
</main>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
