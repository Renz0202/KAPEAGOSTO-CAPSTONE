<?php
// Simple environment check for mail setup (do not expose secrets)
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

$checks = [];
$checks['php_version'] = PHP_VERSION;
$checks['sapi'] = PHP_SAPI;
$checks['extensions'] = [
    'openssl' => extension_loaded('openssl'),
    'zip'     => extension_loaded('zip'),
    'mbstring'=> extension_loaded('mbstring'),
];

$composerJson = dirname(__DIR__, 2) . '/composer.json';
$vendorAutoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
$checks['composer_json_exists'] = file_exists($composerJson);
$checks['vendor_autoload_exists'] = file_exists($vendorAutoload);

if ($checks['vendor_autoload_exists']) {
    require_once $vendorAutoload;
}

$checks['phpmailer_class_exists'] = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

$maskedCfg = [];

// Helper to partially mask email/username
if (!function_exists('mask_email')) {
    function mask_email(string $email): string {
        $pos = strpos($email, '@');
        if ($pos === false) return substr($email,0,1) . '***';
        $user = substr($email,0,$pos);
        $domain = substr($email,$pos);
        if (strlen($user) <= 2) return substr($user,0,1) . '***' . $domain;
        return substr($user,0,2) . '***' . $domain;
    }
}

// NEW: safely include mailer bootstrap if present
$mailerInclude = dirname(__DIR__, 2) . '/includes/mailer.php';
$checks['mailer_php_exists'] = file_exists($mailerInclude);
if ($checks['mailer_php_exists']) {
    include_once $mailerInclude;
}

if (function_exists('mail_config')) {
    $currentMailCfg = mail_config();
    foreach ($currentMailCfg as $k => $v) {
        if (in_array($k, ['password', 'username'], true)) {
            $maskedCfg[$k] = ($k === 'password') ? '***' : mask_email((string) $v);
        } else {
            $maskedCfg[$k] = is_scalar($v) ? (string) $v : json_encode($v);
        }
    }
}

$driverInfo = 'unknown';
$error = '';
try {
    // Guard against missing mailer() to avoid fatal errors
    if (function_exists('mailer')) {
        // @ suppresses warnings if mailer() echoes or triggers warnings
        $m = @mailer();
        if (is_object($m)) {
            // If PHPMailer is available and this is an instance of it, read its Mailer property
            if ($checks['phpmailer_class_exists'] && ($m instanceof \PHPMailer\PHPMailer\PHPMailer)) {
                $driverInfo = $m->Mailer ?: 'unknown';
            } elseif (property_exists($m, 'Mailer')) {
                // Safely read property without triggering notices for dynamic properties
                $vars = (array) $m; // casting reveals public props reliably
                if (array_key_exists('Mailer', $vars)) {
                    $driverInfo = (string) $vars['Mailer'];
                } else {
                    // Fallback to silenced access just in case of magic getters
                    $driverInfo = @($m->Mailer) ?: 'custom-mailer';
                }
            } else {
                $driverInfo = 'custom-mailer';
            }
        } else {
            $driverInfo = 'mailer() did not return object';
        }
    } else {
        // Not an error: includes/mailer.php is optional
        $driverInfo = $checks['phpmailer_class_exists'] ? 'PHPMailer available' : 'none';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?><!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Mail Environment Check</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-maintenance-mail-check">
<div class="card">
  <h2>Mail Environment Check</h2>
    <ul>
    <li>PHP Version: <strong><?= htmlspecialchars($checks['php_version']) ?></strong> (SAPI: <?= htmlspecialchars($checks['sapi']) ?>)</li>
    <li>Extension openssl: <strong class="<?= $checks['extensions']['openssl']?'ok':'bad' ?>"><?= $checks['extensions']['openssl']?'ENABLED':'MISSING' ?></strong></li>
    <li>Extension zip: <strong class="<?= $checks['extensions']['zip']?'ok':'bad' ?>"><?= $checks['extensions']['zip']?'ENABLED':'MISSING' ?></strong></li>
    <li>Extension mbstring: <strong class="<?= $checks['extensions']['mbstring']?'ok':'bad' ?>"><?= $checks['extensions']['mbstring']?'ENABLED':'MISSING' ?></strong></li>
    <li>composer.json present: <strong class="<?= $checks['composer_json_exists']?'ok':'bad' ?>"><?= $checks['composer_json_exists']?'YES':'NO' ?></strong></li>
    <li>vendor/autoload.php present: <strong class="<?= $checks['vendor_autoload_exists']?'ok':'bad' ?>"><?= $checks['vendor_autoload_exists']?'YES':'NO' ?></strong></li>
    <li>PHPMailer class detected: <strong class="<?= $checks['phpmailer_class_exists']?'ok':'bad' ?>"><?= $checks['phpmailer_class_exists']?'YES':'NO' ?></strong></li>
    <!-- NEW: show includes/mailer.php presence -->
    <li>includes/mailer.php present: <strong class="<?= $checks['mailer_php_exists']?'ok':'bad' ?>"><?= $checks['mailer_php_exists']?'YES':'NO' ?></strong></li>
    <li>SMTP source: <strong class="ok">System Settings</strong></li>
        <li>Mailer driver in use: <strong><?= htmlspecialchars($driverInfo) ?></strong></li>
        <?php if ($maskedCfg): ?>
            <li>Config snapshot:
                <pre style="background:#f6f8fa;padding:8px;border-radius:6px;max-height:180px;overflow:auto;font-size:12px;"><?= htmlspecialchars(json_encode($maskedCfg, JSON_PRETTY_PRINT)) ?></pre>
            </li>
        <?php endif; ?>
  </ul>
  <?php if ($error): ?>
    <div class="bad">Mailer init error: <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <p class="muted">If ZIP is missing, enable <code>extension=zip</code> in <code>C:\xampp\php\php.ini</code>. If PHPMailer is missing, run <code>composer require phpmailer/phpmailer</code>.</p>
</div>
</body>
</html>
