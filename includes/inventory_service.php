<?php

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/item_variant.php';

require_once __DIR__ . '/inventory_requirements.php';

function inventory_service_complete_order(PDO $pdo, int $orderId, ?int $userId = null): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Order completion requires a transaction.');
    }
    $orderStmt = $pdo->prepare('SELECT id, order_code, status, inventory_deducted_at FROM orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if (!empty($order['inventory_deducted_at'])) {
        return;
    }

    if ($order['status'] !== 'pending') {
        throw new RuntimeException('Only pending orders can consume inventory.');
    }

    $requirements = inventory_service_order_requirements($pdo, $orderId);
    if (!$requirements) {
        throw new RuntimeException('No inventory requirements are configured for this order.');
    }

    $locked = [];
    foreach ($requirements as $requirement) {
        if (empty($requirement['inventory_id'])) {
            throw new RuntimeException('Missing inventory item: ' . $requirement['name']);
        }
        $stmt = $pdo->prepare('SELECT id, name, cup_size, qty, unit, critical_level FROM inventory WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $requirement['inventory_id']]);
        $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
        $needed = (float) $requirement['quantity'];
        if (!$inventory || (float) $inventory['qty'] + 0.00001 < $needed) {
            throw new RuntimeException('Insufficient stock for ' . ($inventory['name'] ?? $requirement['name']) . '.');
        }
        $locked[] = [$inventory, $needed, $requirement['source'] ?? 'recipe'];
    }

    $orderLabel = trim((string) ($order['order_code'] ?? '')) !== '' ? 'Order#' . $order['order_code'] : 'Order ID ' . $orderId;
    foreach ($locked as [$inventory, $needed, $source]) {
        $remaining = round((float) $inventory['qty'] - $needed, 2);
        $pdo->prepare('UPDATE inventory SET qty = ? WHERE id = ?')->execute([$remaining, (int) $inventory['id']]);
        $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, order_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, ?, 'used', ?, ?, ?, NOW())");
        $log->execute([(int) $inventory['id'], $orderId, $userId, $needed, $remaining, 'Completed ' . $orderLabel . ' (' . $source . ')']);
        notifications_refresh_low_stock($pdo, (int) $inventory['id']);
    }

    $pdo->prepare('UPDATE orders SET inventory_deducted_at = NOW() WHERE id = ?')->execute([$orderId]);
}

function inventory_service_adjust_stock(PDO $pdo, int $inventoryId, $quantity, string $changeType, ?int $userId = null, string $remarks = ''): float
{
    if (!$pdo->inTransaction()) throw new LogicException('Stock adjustments require a transaction.');
    if (!in_array($changeType, ['restock', 'deduct', 'damage', 'spoilage'], true)) throw new InvalidArgumentException('Invalid stock movement.');
    $quantity = inventory_service_validate_stock_quantity($quantity, false);
    $stmt = $pdo->prepare('SELECT id, name, qty, unit_cost, unit_cost_source FROM inventory WHERE id = ? FOR UPDATE');
    $stmt->execute([$inventoryId]);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inventory) throw new RuntimeException('Inventory item not found.');
    $remaining = (float) $inventory['qty'] + ($changeType === 'restock' ? $quantity : -$quantity);
    if ($remaining < -0.00001) throw new RuntimeException('Insufficient stock for ' . $inventory['name'] . '.');
    $remaining = inventory_service_validate_stock_quantity(max(0, round($remaining, 2)));
    $pdo->prepare('UPDATE inventory SET qty = ? WHERE id = ?')->execute([$remaining, $inventoryId]);
    $lossUnitCost = in_array($changeType, ['damage', 'spoilage'], true) && $inventory['unit_cost'] !== null ? round((float) $inventory['unit_cost'], 2) : null;
    $lossValue = $lossUnitCost === null ? null : round($quantity * $lossUnitCost, 2);
    $lossCostSource = $lossUnitCost === null ? null : ($inventory['unit_cost_source'] ?? 'verified');
    $log = $pdo->prepare('INSERT INTO inventory_logs (inventory_id, user_id, change_type, quantity, remaining, loss_unit_cost, loss_value, loss_cost_source, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $log->execute([$inventoryId, $userId, $changeType, $quantity, $remaining, $lossUnitCost, $lossValue, $lossCostSource, $remarks]);
    notifications_refresh_low_stock($pdo, $inventoryId);
    return $remaining;
}
