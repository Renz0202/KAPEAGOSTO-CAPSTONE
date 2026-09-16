<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';

$pdo = db();

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
};

try {
    $roles = $pdo->query('SELECT id, LOWER(name) AS name FROM roles')->fetchAll(PDO::FETCH_KEY_PAIR);
    $staffId = array_search('staff', $roles, true);
    $cashierId = array_search('cashier', $roles, true);

    if ($staffId !== false && $cashierId === false) {
        $stmt = $pdo->prepare("UPDATE roles SET name = 'cashier' WHERE id = ?");
        $stmt->execute([(int) $staffId]);
    } elseif ($staffId !== false && $cashierId !== false) {
        $stmt = $pdo->prepare('UPDATE users SET role_id = ? WHERE role_id = ?');
        $stmt->execute([(int) $cashierId, (int) $staffId]);
        $stmt = $pdo->prepare('DELETE FROM roles WHERE id = ?');
        $stmt->execute([(int) $staffId]);
    } elseif ($cashierId === false) {
        $pdo->exec("INSERT INTO roles (name) VALUES ('cashier')");
    }

    $pdo->exec("INSERT IGNORE INTO roles (name) VALUES ('manager')");

    if (!$columnExists($pdo, 'inventory', 'safety_stock')) {
        $pdo->exec('ALTER TABLE inventory ADD COLUMN safety_stock DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER critical_level');
    }
    if (!$columnExists($pdo, 'inventory', 'lead_time_days')) {
        $pdo->exec('ALTER TABLE inventory ADD COLUMN lead_time_days INT NOT NULL DEFAULT 1 AFTER safety_stock');
    }
    if (!$columnExists($pdo, 'inventory', 'target_stock')) {
        $pdo->exec('ALTER TABLE inventory ADD COLUMN target_stock DECIMAL(10,2) DEFAULT NULL AFTER lead_time_days');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
          setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
          setting_value TEXT DEFAULT NULL,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $settingStmt = $pdo->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ([
        'sales_target_daily' => '0',
        'sales_target_weekly' => '0',
        'sales_target_monthly' => '0',
        'performance_period_days' => '30',
    ] as $key => $value) {
        $settingStmt->execute([$key, $value]);
    }

    echo 'Manager access migration complete.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Manager access migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
