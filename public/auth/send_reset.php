<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['flash'] = 'Please use the verification-code reset form below.';
$_SESSION['flash_type'] = 'info';

header('Location: forgot_password.php');
exit;
