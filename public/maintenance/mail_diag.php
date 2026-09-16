<?php
// Mail diagnostics: shows sanitized config, attempts SMTP connection & optional test send.
// WARNING: Do NOT deploy publicly without auth; restrict or delete after use.
ini_set('display_errors', 0);
error_reporting(E_ALL);
session_start();

require_once dirname(__DIR__, 2) . '/includes/mailer.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

$cfg = isset($GLOBALS['__mail_cfg']) ? $GLOBALS['__mail_cfg'] : [];

function mask($v) {
    if ($v === '' || $v === null) return '';
    if (strpos($v, '@') !== false) {
        [$u,$d] = explode('@',$v,2); return substr($u,0,2) . '***@' . $d;
    }
    return substr($v,0,2) . '***';
}

$sanitized = [
  'driver'    => $cfg['driver'] ?? '',
  'host'      => $cfg['host'] ?? '',
  'port'      => $cfg['port'] ?? '',
  'encryption'=> $cfg['encryption'] ?? '',
  'username'  => mask($cfg['username'] ?? ''),
  'from_email'=> mask(($cfg['from_email'] ?? $cfg['from'] ?? '')),
  'from_name' => $cfg['from_name'] ?? '',
  'auth_type' => $cfg['auth_type'] ?? '',
  'smtp_debug'=> $cfg['smtp_debug'] ?? 0,
];

$connectionResult = null;
$sendResult = null;
$errorDetail = null;
$attemptedFrom = null;
$envelopeSender = null;

if (isset($_GET['test'])) {
    try {
        $m = mailer();
        $attemptedFrom = method_exists($m,'getFrom') ? json_encode($m->getFrom()) : ($m->From ?? '');
  // Envelope sender (if PHPMailer). In fallback wrapper it may not exist.
  if (is_object($m) && isset($m->Sender)) { $envelopeSender = $m->Sender; }
        if (method_exists($m,'smtpConnect')) {
            // Force connection to detect auth issues early
            $m->smtpConnect();
            $connectionResult = 'Connected OK';
        } else {
            $connectionResult = 'No direct smtpConnect() method (fallback mail)';
        }
        if (!empty($_GET['rcpt'])) {
            $rcpt = trim($_GET['rcpt']);
            if (filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                $m->clearAddresses();
                $m->Subject = 'Diagnostic Test Email';
                $m->Body = '<p>This is a diagnostic email.</p><p>Time: '.date('Y-m-d H:i:s').'</p>';
                if (property_exists($m,'AltBody')) $m->AltBody = 'Diagnostic email';
                $m->addAddress($rcpt);
                $ok = $m->send();
                $sendResult = $ok ? 'SEND SUCCESS' : ('SEND FAILED: '.($m->ErrorInfo ?? 'unknown'));
            } else {
                $sendResult = 'Invalid rcpt parameter';
            }
        }
    } catch (Throwable $e) {
        $errorDetail = $e->getMessage();
        if (!$connectionResult) $connectionResult = 'FAILED';
    }
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Mail Diagnostics</title>
<?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?></head><body class="kape-maintenance-mail-diag">
<div class="panel">
<h2>Mail Diagnostics</h2>
<h3>Sanitized Config</h3>
<pre><?=htmlspecialchars(json_encode($sanitized, JSON_PRETTY_PRINT))?></pre>
<h3>Actions</h3>
<p><a class="btn" href="mail_diag.php?test=1">Test Connection Only</a></p>
<form method="get" class="inline">
  <input type="hidden" name="test" value="1">
  <label for="rcpt" style="font-weight:600">Send test to:</label>
  <input type="email" name="rcpt" id="rcpt" placeholder="you@example.com" required>
  <button type="submit">Send Test Email</button>
</form>
<?php if($connectionResult !== null): ?>
  <h3>Connection Result</h3>
  <p class="<?=strpos($connectionResult,'OK')!==false?'ok':'bad'?>"><?=htmlspecialchars($connectionResult)?></p>
<?php endif; ?>
<?php if($sendResult !== null): ?>
  <h3>Send Result</h3>
  <p class="<?=strpos($sendResult,'SUCCESS')!==false?'ok':'bad'?>"><?=htmlspecialchars($sendResult)?></p>
<?php endif; ?>
<?php if($attemptedFrom !== null): ?>
  <p>From used: <code><?=htmlspecialchars($attemptedFrom)?></code></p>
<?php endif; ?>
<?php if($envelopeSender !== null): ?>
  <p>Envelope Sender: <code><?=htmlspecialchars($envelopeSender)?></code></p>
<?php endif; ?>
<?php if($errorDetail): ?>
  <h3>Error Detail</h3>
  <pre><?=htmlspecialchars($errorDetail)?></pre>
<?php endif; ?>
<h3>Debug Log Snapshot</h3>
<pre>Debug file logging is disabled in this build.</pre>
<div class="note">
<strong>Troubleshooting Tips:</strong><br>
1. For Gmail: Use an App Password (account must have 2FA).<br>
2. From must match the authenticated Gmail user.<br>
3. If you see 530 or 534 codes, verify credentials and App Password.<br>
4. Port 587 + STARTTLS (tls) is recommended; port 465 requires MAIL_ENCRYPTION=ssl.<br>
5. If connection times out, outbound SMTP may be blocked by firewall/ISP.<br>
6. Debug file logging is disabled here; use the "Send Test Email" above to see PHPMailer errors.
</div>
</div>
</body></html>
