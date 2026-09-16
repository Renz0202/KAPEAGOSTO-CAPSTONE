<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';

use PHPMailer\PHPMailer\PHPMailer;

function mail_config(): array
{
    try {
        $settings = settings_all(db());
    } catch (Throwable $e) {
        $settings = settings_defaults();
    }

    $fromEmail = settings_value($settings, 'smtp_from_email');
    if ($fromEmail === '') {
        $fromEmail = settings_value($settings, 'business_email');
    }

    $fromName = settings_value($settings, 'smtp_from_name');
    if ($fromName === '') {
        $fromName = settings_value($settings, 'business_name');
    }

    $cfg = [
        'driver' => settings_value($settings, 'smtp_enabled') === '1' ? 'smtp' : 'mail',
        'host' => settings_value($settings, 'smtp_host'),
        'port' => (int) settings_value($settings, 'smtp_port'),
        'encryption' => settings_value($settings, 'smtp_encryption'),
        'username' => settings_value($settings, 'smtp_username'),
        'password' => settings_value($settings, 'smtp_password'),
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to' => settings_value($settings, 'smtp_reply_to'),
    ];

    $cfg = mail_normalize_config($cfg);
    $GLOBALS['__mail_cfg'] = $cfg;
    return $cfg;
}

function mailer(): PHPMailer
{
    $cfg = mail_config();
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isHTML(true);

    if (($cfg['driver'] ?? 'mail') === 'smtp' && !empty($cfg['host'])) {
        $mail->isSMTP();
        $mail->Host = (string) $cfg['host'];
        $mail->Port = (int) ($cfg['port'] ?: 587);
        $mail->SMTPAuth = !empty($cfg['username']);
        $mail->Username = (string) $cfg['username'];
        $mail->Password = (string) $cfg['password'];
        $mail->Timeout = 20;

        $encryption = strtolower((string) ($cfg['encryption'] ?? 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
    } else {
        $mail->isMail();
    }

    $fromEmail = (string) ($cfg['from_email'] ?: 'no-reply@localhost');
    $fromName = (string) ($cfg['from_name'] ?: 'Kape Agosto');
    $mail->setFrom($fromEmail, $fromName);

    if (!empty($cfg['reply_to']) && filter_var($cfg['reply_to'], FILTER_VALIDATE_EMAIL)) {
        $mail->addReplyTo((string) $cfg['reply_to']);
    }

    return $mail;
}

function send_mail_simple(string $to, string $subject, string $html, ?string $alt = null, array $embeddedImages = []): array
{
    try {
        $mail = mailer();
        $cfg = $GLOBALS['__mail_cfg'] ?? mail_config();
        if (($cfg['driver'] ?? '') === 'smtp' && !empty($cfg['username']) && empty($cfg['password'])) {
            return [false, 'SMTP password is required when SMTP username is set. For Gmail, use a Google App Password.'];
        }
        $mail->clearAddresses();
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $alt ?? trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html)));

        foreach ($embeddedImages as $image) {
            $path = (string) ($image['path'] ?? '');
            $cid = (string) ($image['cid'] ?? '');
            if ($path === '' || $cid === '' || !is_file($path)) {
                continue;
            }

            $mail->addEmbeddedImage(
                $path,
                $cid,
                (string) ($image['name'] ?? basename($path)),
                'base64',
                (string) ($image['type'] ?? '')
            );
        }

        return [$mail->send(), ''];
    } catch (Throwable $e) {
        $message = mail_friendly_error($e->getMessage());
        error_log('Mail send failed: ' . $message);
        return [false, $message];
    }
}

function send_mail_resilient(string $to, string $subject, string $html, ?string $alt = null, array $embeddedImages = []): array
{
    return send_mail_simple($to, $subject, $html, $alt, $embeddedImages);
}

function mail_normalize_config(array $cfg): array
{
    $host = trim((string) ($cfg['host'] ?? ''));
    if (filter_var($host, FILTER_VALIDATE_EMAIL)) {
        $email = $host;
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $cfg['host'] = function_exists('settings_guess_smtp_host') ? settings_guess_smtp_host($domain) : ($domain ? 'smtp.' . $domain : '');

        if (empty($cfg['username'])) {
            $cfg['username'] = $email;
        }
        if (empty($cfg['from_email'])) {
            $cfg['from_email'] = $email;
        }
        if (empty($cfg['reply_to'])) {
            $cfg['reply_to'] = $email;
        }
    }

    $host = strtolower(trim((string) ($cfg['host'] ?? '')));
    $username = trim((string) ($cfg['username'] ?? ''));
    if ($host !== '' && strpos($host, 'gmail.com') !== false) {
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $cfg['from_email'] = strtolower($username);
            if (empty($cfg['reply_to'])) {
                $cfg['reply_to'] = strtolower($username);
            }
        }
        if (!empty($cfg['password'])) {
            $cfg['password'] = preg_replace('/\s+/', '', (string) $cfg['password']) ?? '';
        }
    }

    return $cfg;
}

function mail_friendly_error(string $message): string
{
    $lower = strtolower($message);
    $cfg = $GLOBALS['__mail_cfg'] ?? [];
    $host = strtolower((string) ($cfg['host'] ?? ''));

    if (strpos($lower, 'could not connect') !== false) {
        return 'SMTP Error: Could not connect to SMTP host. Check SMTP Host, Port, Encryption, firewall, and OpenSSL.';
    }

    if (strpos($host, 'gmail.com') !== false && (
        strpos($lower, 'authenticate') !== false ||
        strpos($lower, '535') !== false ||
        strpos($lower, 'badcredentials') !== false ||
        strpos($lower, 'username and password not accepted') !== false
    )) {
        return 'Gmail authentication failed. Gmail does not accept your normal account password for SMTP. Use a Google App Password and make sure 2-Step Verification is enabled.';
    }

    return $message;
}
