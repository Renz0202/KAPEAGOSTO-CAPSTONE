<?php
// Simple manual email send tool (admin-only recommended). Protect with auth in production.
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/includes/mailer.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
// If you have an auth gate:
require_admin();

$flash = '';
$error = '';
$lastDebug = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to'] ?? '');
    $subj = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid recipient email.';
    } elseif (!$subj) {
        $error = 'Subject required.';
    } elseif (!$body) {
        $error = 'Body required.';
    } else {
        try {
            $mail = mailer();
            if (method_exists($mail, 'clearAddresses')) { $mail->clearAddresses(); }
            $mail->addAddress($to);
            $mail->Subject = $subj;
            $mail->Body = nl2br(htmlspecialchars($body));
            if (property_exists($mail, 'AltBody')) {
                $mail->AltBody = $body; // plain text
            }
            $ok = $mail->send();
            if ($ok) {
                $flash = 'Email sent to ' . htmlspecialchars($to);
            } else {
                $error = 'Send failed: ' . htmlspecialchars($mail->ErrorInfo);
            }
        } catch (Throwable $e) {
            $error = 'Exception: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Manual Email Send | KAPEAGOSTO</title>
<?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-maintenance-mail-send-test">
<div class="card">
  <h2>Manual Email Send</h2>
  <?php if($flash):?><div class="flash"><?=$flash?></div><?php endif; ?>
  <?php if($error):?><div class="err"><?=$error?></div><?php endif; ?>
  <form method="post">
    <label for="to">To</label>
    <input type="email" name="to" id="to" placeholder="recipient@example.com" required value="<?=htmlspecialchars($_POST['to'] ?? '')?>">
    <label for="subject">Subject</label>
    <input type="text" name="subject" id="subject" required value="<?=htmlspecialchars($_POST['subject'] ?? '')?>">
    <label for="body">Body (HTML/Text)</label>
    <textarea name="body" id="body" rows="6" placeholder="Message" required><?=htmlspecialchars($_POST['body'] ?? '')?></textarea>
    <div style="margin-top:16px"><button type="submit">Send Email</button></div>
  </form>
  <p class="small">Configured sender: <?=htmlspecialchars(($GLOBALS['__mail_cfg']['from_email'] ?? $GLOBALS['__mail_cfg']['from'] ?? 'unknown'))?> | Driver: <?=htmlspecialchars(($GLOBALS['__mail_cfg']['driver'] ?? 'smtp'))?></p>
    <p class="small">If failure persists, open <code>mail_diag.php</code> and run the connection/send tests.</p>
</div>
</body>
</html>
