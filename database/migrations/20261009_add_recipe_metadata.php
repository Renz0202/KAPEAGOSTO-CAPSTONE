<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/db.php';
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS item_recipe_metadata (
    item_id INT NOT NULL PRIMARY KEY,
    source ENUM('generated','custom') NOT NULL DEFAULT 'custom',
    notes VARCHAR(500) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recipe_metadata_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "Recipe source metadata ready. Existing recipes and stock preserved.\n";
