<?php
// Ensure all PHP date/time functions use Philippine Time
date_default_timezone_set('Asia/Manila');
// Adjust to your server
define('DB_HOST', 'localhost');
define('DB_NAME', 'kape_agosto');
define('DB_USER', 'root');
define('DB_PASS', ''); 

// App settings
define('APP_NAME', 'Kape Agosto');
define('BASE_URL', 'kamps'); // change if in a subfolder or virtual host
define('APP_ENV', 'development'); // change to 'production' in production environment

// Default Admin identity (used to protect seed admin account)
if (!defined('DEFAULT_ADMIN_USERNAME')) {
	define('DEFAULT_ADMIN_USERNAME', 'Admin');
}
if (!defined('DEFAULT_ADMIN_EMAIL')) {
	define('DEFAULT_ADMIN_EMAIL', 'admin@kapeagosto.local');
}