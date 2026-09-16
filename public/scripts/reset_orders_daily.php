<?php

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
echo 'Daily order deletion is disabled. Transaction history is retained for auditing, reporting, and forecasting.';
