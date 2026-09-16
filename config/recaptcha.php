<?php
// Get keys from: https://www.google.com/recaptcha/admin/create
// Store them in the local .env/environment; never commit the secret key.
define('RECAPTCHA_SITE_KEY', getenv('RECAPTCHA_SITE_KEY') ?: '');
define('RECAPTCHA_SECRET_KEY', getenv('RECAPTCHA_SECRET_KEY') ?: '');

// Required when domain validation is disabled in the reCAPTCHA console for LAN use.
// Add stable production domains here; private LAN IPv4 addresses are checked below.
define('RECAPTCHA_ALLOWED_HOSTS', [
    'localhost',
    '127.0.0.1',
    '::1',
]);

function recaptcha_hostname_is_allowed(string $hostname): bool
{
    $hostname = strtolower(trim($hostname));
    $allowedHosts = array_map('strtolower', RECAPTCHA_ALLOWED_HOSTS);

    if (in_array($hostname, $allowedHosts, true)) {
        return true;
    }

    if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }

    $ip = ip2long($hostname);
    if ($ip === false) {
        return false;
    }

    $ip = (int) sprintf('%u', $ip);
    $privateRanges = [
        ['10.0.0.0', 8],
        ['172.16.0.0', 12],
        ['192.168.0.0', 16],
    ];

    foreach ($privateRanges as [$network, $prefix]) {
        $networkLong = (int) sprintf('%u', ip2long($network));
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        if (($ip & $mask) === ($networkLong & $mask)) {
            return true;
        }
    }

    return false;
}
