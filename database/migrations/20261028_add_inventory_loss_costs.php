<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/db.php';
$pdo = db();
$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
};
if (!$columnExists('inventory', 'unit_cost')) $pdo->exec('ALTER TABLE inventory ADD COLUMN unit_cost DECIMAL(10,2) DEFAULT NULL AFTER qty');
if (!$columnExists('inventory', 'unit_cost_source')) $pdo->exec("ALTER TABLE inventory ADD COLUMN unit_cost_source ENUM('estimate','verified') DEFAULT NULL AFTER unit_cost");
if (!$columnExists('inventory_logs', 'loss_unit_cost')) $pdo->exec('ALTER TABLE inventory_logs ADD COLUMN loss_unit_cost DECIMAL(10,2) DEFAULT NULL AFTER remaining');
if (!$columnExists('inventory_logs', 'loss_value')) $pdo->exec('ALTER TABLE inventory_logs ADD COLUMN loss_value DECIMAL(18,2) DEFAULT NULL AFTER loss_unit_cost');
if (!$columnExists('inventory_logs', 'loss_cost_source')) $pdo->exec("ALTER TABLE inventory_logs ADD COLUMN loss_cost_source ENUM('estimate','verified') DEFAULT NULL AFTER loss_value");
$changeType = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inventory_logs' AND COLUMN_NAME = 'change_type'")->fetchColumn();
if ($changeType !== '' && stripos($changeType, "'spoilage'") === false) {
    $pdo->exec("ALTER TABLE inventory_logs MODIFY change_type ENUM('added','restock','used','deduct','auto-deduct','damage','spoilage','adjustment') NOT NULL");
}
echo "Inventory loss tracking is ready. Existing stock is unchanged; enter unit costs to calculate future damage and spoilage values.\n";
