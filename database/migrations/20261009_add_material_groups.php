<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/material_catalog.php';
$pdo = db();
$column = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory' AND COLUMN_NAME = 'material_group'");
if (!(int) $column->fetchColumn()) {
    $pdo->exec('ALTER TABLE inventory ADD COLUMN material_group VARCHAR(40) NULL AFTER material_type, ADD INDEX idx_inventory_material_group (material_group)');
}
$pdo->beginTransaction();
try {
    $changed = inventory_material_backfill_groups($pdo);
    $pdo->commit();
    echo 'Material groups ready; ' . $changed . " existing records grouped. Stock and recipes preserved.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}