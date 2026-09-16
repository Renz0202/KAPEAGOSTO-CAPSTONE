<?php

function notification_create(PDO $pdo, string $type, string $message, ?int $userId = null, ?string $entityType = null, ?int $entityId = null, ?string $dedupeKey = null): void
{
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, notification_type, entity_type, entity_id, dedupe_key, message, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ON DUPLICATE KEY UPDATE message = VALUES(message), is_read = 0, read_at = NULL
    ");
    $stmt->execute([$userId, $type, $entityType, $entityId, $dedupeKey, $message]);
}

function notifications_refresh_low_stock(PDO $pdo, ?int $inventoryId = null): void
{
    $cleanupSql = "DELETE n FROM notifications n LEFT JOIN inventory i ON i.id = n.entity_id WHERE n.notification_type = 'low_stock' AND n.entity_type = 'inventory' AND (i.id IS NULL OR i.critical_level IS NULL OR i.critical_level <= 0 OR i.qty > i.critical_level)";
    if ($inventoryId !== null) {
        $cleanupSql .= ' AND n.entity_id = ' . (int) $inventoryId;
    }
    $pdo->exec($cleanupSql);

    $sql = 'SELECT id, name, cup_size, qty, unit, critical_level FROM inventory WHERE critical_level IS NOT NULL AND critical_level > 0';
    $params = [];
    if ($inventoryId !== null) {
        $sql .= ' AND id = ?';
        $params[] = $inventoryId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $dedupeKey = 'low-stock-' . (int) $item['id'];
        if ((float) $item['qty'] <= (float) $item['critical_level']) {
            $label = trim((string) $item['name'] . ' ' . (!empty($item['cup_size']) ? '(' . $item['cup_size'] . ')' : ''));
            $message = sprintf('%s is low: %s %s remaining (threshold %s).', $label, rtrim(rtrim(number_format((float) $item['qty'], 2, '.', ''), '0'), '.'), $item['unit'] ?: 'units', rtrim(rtrim(number_format((float) $item['critical_level'], 2, '.', ''), '0'), '.'));
            notification_create($pdo, 'low_stock', $message, null, 'inventory', (int) $item['id'], $dedupeKey);
        } else {
            $clear = $pdo->prepare("DELETE FROM notifications WHERE dedupe_key = ? AND notification_type = 'low_stock'");
            $clear->execute([$dedupeKey]);
        }
    }
}
