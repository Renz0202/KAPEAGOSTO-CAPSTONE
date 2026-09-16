<?php
// Deprecated endpoint; redirect to unified test page using includes/mailer.php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();
header('Location: test_email.php');
exit;
