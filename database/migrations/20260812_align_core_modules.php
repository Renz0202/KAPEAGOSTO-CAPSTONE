<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';

$pdo = db();

$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$indexExists = static function (string $table, string $index) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};

$constraintExists = static function (string $table, string $constraint) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $stmt->execute([$table, $constraint]);
    return (int) $stmt->fetchColumn() > 0;
};

$addColumn = static function (string $table, string $column, string $definition) use ($pdo, $columnExists): void {
    if (!$columnExists($table, $column)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "Added {$table}.{$column}" . PHP_EOL;
    }
};

$addIndex = static function (string $table, string $index, string $definition) use ($pdo, $indexExists): void {
    if (!$indexExists($table, $index)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
        echo "Added index {$table}.{$index}" . PHP_EOL;
    }
};

$pdo->exec("
    CREATE TABLE IF NOT EXISTS customers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      customer_code VARCHAR(32) NOT NULL,
      name VARCHAR(100) NOT NULL,
      contact VARCHAR(30) DEFAULT NULL,
      email VARCHAR(150) DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_customers_code (customer_code),
      INDEX idx_customers_name (name),
      INDEX idx_customers_contact (contact),
      INDEX idx_customers_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$addColumn('items', 'sku', 'VARCHAR(64) DEFAULT NULL AFTER `id`');
$pdo->exec("UPDATE items SET sku = CONCAT('ITEM-', LPAD(id, 6, '0')) WHERE sku IS NULL OR TRIM(sku) = ''");
$addIndex('items', 'uniq_items_sku', 'UNIQUE KEY `uniq_items_sku` (`sku`)');
$pdo->exec('ALTER TABLE items MODIFY sku VARCHAR(64) NOT NULL');

$addColumn('inventory', 'sku', 'VARCHAR(64) DEFAULT NULL AFTER `id`');
$pdo->exec("UPDATE inventory SET sku = CONCAT('INV-', LPAD(id, 6, '0')) WHERE sku IS NULL OR TRIM(sku) = ''");
$addIndex('inventory', 'uniq_inventory_sku', 'UNIQUE KEY `uniq_inventory_sku` (`sku`)');
$pdo->exec('ALTER TABLE inventory MODIFY sku VARCHAR(64) NOT NULL');

$addColumn('orders', 'customer_id', 'INT NULL AFTER `user_id`');
$addColumn('orders', 'service_details', 'TEXT DEFAULT NULL AFTER `customer_name`');
$addColumn('orders', 'job_status', "ENUM('queued','in_progress','ready','completed','cancelled') NOT NULL DEFAULT 'queued' AFTER `service_details`");
$addColumn('orders', 'inventory_deducted_at', 'DATETIME DEFAULT NULL AFTER `cancelled_at`');
$addIndex('orders', 'idx_orders_customer', 'INDEX `idx_orders_customer` (`customer_id`)');
$addIndex('orders', 'idx_orders_job_status', 'INDEX `idx_orders_job_status` (`job_status`, `created_at`)');
if (!$constraintExists('orders', 'fk_orders_customer')) {
    $pdo->exec('ALTER TABLE orders ADD CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL ON UPDATE CASCADE');
}

$addColumn('inventory_logs', 'order_id', 'INT NULL AFTER `inventory_id`');
$addColumn('inventory_logs', 'user_id', 'INT NULL AFTER `order_id`');
$addIndex('inventory_logs', 'idx_inventory_logs_order', 'INDEX `idx_inventory_logs_order` (`order_id`)');
if (!$constraintExists('inventory_logs', 'fk_inventory_logs_order')) {
    $pdo->exec('ALTER TABLE inventory_logs ADD CONSTRAINT fk_inventory_logs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE');
}
if (!$constraintExists('inventory_logs', 'fk_inventory_logs_user')) {
    $pdo->exec('ALTER TABLE inventory_logs ADD CONSTRAINT fk_inventory_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE');
}

$addColumn('notifications', 'notification_type', "VARCHAR(50) NOT NULL DEFAULT 'general' AFTER `user_id`");
$addColumn('notifications', 'entity_type', 'VARCHAR(50) DEFAULT NULL AFTER `notification_type`');
$addColumn('notifications', 'entity_id', 'INT DEFAULT NULL AFTER `entity_type`');
$addColumn('notifications', 'dedupe_key', 'VARCHAR(150) DEFAULT NULL AFTER `entity_id`');
$addColumn('notifications', 'read_at', 'DATETIME DEFAULT NULL AFTER `is_read`');
$addIndex('notifications', 'uniq_notifications_dedupe', 'UNIQUE KEY `uniq_notifications_dedupe` (`dedupe_key`)');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS forecast_snapshots (
      id INT AUTO_INCREMENT PRIMARY KEY,
      forecast_date DATE NOT NULL,
      metric_type ENUM('orders','revenue','item_demand') NOT NULL,
      subject_key VARCHAR(80) NOT NULL DEFAULT 'overall',
      item_id INT DEFAULT NULL,
      predicted_value DECIMAL(14,4) NOT NULL DEFAULT 0,
      actual_value DECIMAL(14,4) DEFAULT NULL,
      accuracy_percent DECIMAL(6,2) DEFAULT NULL,
      method VARCHAR(80) NOT NULL DEFAULT 'weighted_moving_average',
      generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      evaluated_at DATETIME DEFAULT NULL,
      UNIQUE KEY uniq_forecast_metric (forecast_date, metric_type, subject_key),
      INDEX idx_forecast_item (item_id),
      CONSTRAINT fk_forecast_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Core module alignment migration complete.' . PHP_EOL;
