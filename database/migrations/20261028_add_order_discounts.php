<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/db.php';
$pdo = db();
$addColumn = static function (string $column, string $definition) use ($pdo): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = ?");
    $stmt->execute([$column]);
    if (!(int) $stmt->fetchColumn()) $pdo->exec('ALTER TABLE orders ADD COLUMN `' . $column . '` ' . $definition);
};
$addColumn('subtotal', 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `total`');
$addColumn('discount_type', "ENUM('none','promo','senior','pwd') NOT NULL DEFAULT 'none' AFTER `subtotal`");
$addColumn('discount_rate', 'DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `discount_type`');
$addColumn('promo_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `discount_rate`');
$addColumn('discount_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `discount_rate`');
$addColumn('vat_exempt_amount', 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `discount_amount`');
$addColumn('discount_beneficiary_type', "ENUM('senior','pwd') DEFAULT NULL AFTER `vat_exempt_amount`");
$addColumn('discount_customer_name', 'VARCHAR(100) DEFAULT NULL AFTER `discount_beneficiary_type`');
$addColumn('discount_customer_tin', 'VARCHAR(32) DEFAULT NULL AFTER `discount_customer_name`');
$addColumn('discount_id_number', 'VARCHAR(64) DEFAULT NULL AFTER `discount_customer_name`');
$addColumn('discount_applied_by', 'INT DEFAULT NULL AFTER `discount_id_number`');
$addColumn('exclusive_use_confirmed', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `discount_applied_by`');
$pdo->exec('UPDATE orders SET subtotal = total WHERE subtotal = 0 AND total <> 0');
echo "Customer discount fields are ready. Existing order totals are preserved.\n";
