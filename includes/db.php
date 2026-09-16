<?php
require_once __DIR__ . '/../config/config.php';

function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_NAME
            );

            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // errors → exceptions
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,      // associative arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                 // native prepared statements
            ];

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
            // Ensure MySQL session uses Philippine Time (safer offset to avoid tz tables requirement)
            try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Throwable $t) { /* ignore if not supported */ }
        } catch (PDOException $e) {
            error_log("DB Connection error: " . $e->getMessage());
            die("❌ Database connection failed. Please contact administrator.");
        }
    }

    return $pdo;
}
