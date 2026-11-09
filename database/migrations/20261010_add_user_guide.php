<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/user_guide.php';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS user_guide_progress (
    user_id INT NOT NULL PRIMARY KEY,
    welcome_status ENUM('completed','skipped','existing') NOT NULL,
    guide_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_guide_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$count = user_guide_initialize_existing($pdo);
echo "Guide installed. Existing accounts initialized: $count. New accounts receive the welcome guide.\n";
