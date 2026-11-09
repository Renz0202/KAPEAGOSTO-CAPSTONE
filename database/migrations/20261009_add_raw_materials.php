<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Additive migration: existing stock balances and recipes are preserved.
require_once dirname(__DIR__, 2) . '/includes/db.php';
$pdo = db();
$stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'material_type'");
if (!(int) $stmt->fetchColumn()) {
    $pdo->exec("ALTER TABLE inventory ADD COLUMN material_type ENUM('raw_material','packaging','other') NOT NULL DEFAULT 'raw_material' AFTER name");
    $pdo->exec("UPDATE inventory SET material_type = 'packaging' WHERE LOWER(name) REGEXP '(^|[^a-z])(cups?|lids?|covers?|straws?|stirrers?)([^a-z]|$)'");
}
echo 'Raw material inventory migration complete.' . PHP_EOL;