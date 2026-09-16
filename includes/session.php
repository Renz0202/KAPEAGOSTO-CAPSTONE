<?php
if (!defined('KAPE_SESSION_LIFETIME')) {
    define('KAPE_SESSION_LIFETIME', 60 * 60 * 24 * 7);
}

function kape_start_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => KAPE_SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.gc_maxlifetime', (string) KAPE_SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', (string) KAPE_SESSION_LIFETIME);

    session_start();
}
?>
