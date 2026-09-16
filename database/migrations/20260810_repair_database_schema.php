<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';

$pdo = db();

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

$indexExists = static function (PDO $pdo, string $table, string $index): bool {
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ');
    $stmt->execute([$table, $index]);
    return (int) $stmt->fetchColumn() > 0;
};

$addColumn = static function (string $table, string $column, string $definition) use ($pdo, $columnExists): void {
    if ($columnExists($pdo, $table, $column)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    echo "Added {$table}.{$column}" . PHP_EOL;
};

$addIndex = static function (string $table, string $index, string $definition) use ($pdo, $indexExists): void {
    if ($indexExists($pdo, $table, $index)) {
        return;
    }

    $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
    echo "Added index {$table}.{$index}" . PHP_EOL;
};

$addColumn('orders', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
$addColumn('daily_reports', 'total_sales', 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER `report_date`');
$addColumn('daily_reports', 'total_orders', 'INT NOT NULL DEFAULT 0 AFTER `total_sales`');
$addColumn('daily_reports', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
$addColumn('daily_sales_reports', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
$addColumn('password_resets', 'method', "VARCHAR(30) NOT NULL DEFAULT 'link' AFTER `user_agent`");

$addIndex('orders', 'uniq_orders_order_code', 'UNIQUE KEY `uniq_orders_order_code` (`order_code`)');
$addIndex('inventory', 'idx_inventory_name_size', 'INDEX `idx_inventory_name_size` (`name`, `cup_size`)');
$addIndex('daily_reports', 'uniq_daily_reports_date', 'UNIQUE KEY `uniq_daily_reports_date` (`report_date`)');

$changeType = (string) $pdo->query("
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_logs'
      AND COLUMN_NAME = 'change_type'
")->fetchColumn();

if ($changeType !== '' && stripos($changeType, "'adjustment'") === false) {
    $pdo->exec("
        ALTER TABLE inventory_logs
        MODIFY change_type ENUM('added','restock','used','deduct','auto-deduct','damage','adjustment') NOT NULL
    ");
    echo 'Expanded inventory_logs.change_type' . PHP_EOL;
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS item_requirements (
      id INT AUTO_INCREMENT PRIMARY KEY,
      item_id INT NOT NULL,
      inventory_id INT NOT NULL,
      quantity_needed DECIMAL(10,2) NOT NULL DEFAULT 1,
      UNIQUE KEY uniq_item_inventory_requirement (item_id, inventory_id),
      INDEX idx_requirements_inventory (inventory_id),
      CONSTRAINT fk_requirements_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_requirements_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NULL,
      message VARCHAR(255) NOT NULL,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_notifications_user_read (user_id, is_read, created_at),
      CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS system_settings (
      setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
      setting_value TEXT DEFAULT NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

echo 'Database schema repair complete.' . PHP_EOL;
