<?php

require_once __DIR__ . '/notifications.php';

function inventory_service_find_packaging(PDO $pdo, string $kind, string $cupSize = ''): ?array
{
    if ($kind === 'cup') {
        $stmt = $pdo->prepare("SELECT id, name, cup_size, qty, unit, critical_level FROM inventory WHERE name LIKE '%cup%' AND cup_size = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$cupSize]);
    } elseif ($kind === 'lid') {
        $stmt = $pdo->prepare("SELECT id, name, cup_size, qty, unit, critical_level FROM inventory WHERE (name LIKE '%flat lid%' OR name LIKE '%lid cover%' OR name LIKE '%flat cover%' OR name LIKE '%lid%') AND cup_size = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$cupSize]);
    } elseif ($kind === 'straw') {
        $stmt = $pdo->query("SELECT id, name, cup_size, qty, unit, critical_level FROM inventory WHERE name LIKE '%straw%' LIMIT 1 FOR UPDATE");
    } else {
        $stmt = $pdo->query("SELECT id, name, cup_size, qty, unit, critical_level FROM inventory WHERE name LIKE '%stirrer%' LIMIT 1 FOR UPDATE");
    }
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function inventory_service_order_requirements(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT oi.item_id, oi.qty, i.name, i.cup_size FROM order_items oi JOIN items i ON i.id = oi.item_id WHERE oi.order_id = ? ORDER BY oi.id');
    $stmt->execute([$orderId]);
    $requirements = [];

    $explicit = $pdo->prepare('SELECT ir.inventory_id, ir.quantity_needed, inv.name, inv.cup_size, inv.unit, inv.critical_level FROM item_requirements ir JOIN inventory inv ON inv.id = ir.inventory_id WHERE ir.item_id = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $qty = max(1, (int) $item['qty']);
        $explicit->execute([(int) $item['item_id']]);
        $rows = $explicit->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            foreach ($rows as $row) {
                $id = (int) $row['inventory_id'];
                if (!isset($requirements[$id])) {
                    $requirements[$id] = $row + ['quantity' => 0.0, 'source' => 'recipe'];
                }
                $requirements[$id]['quantity'] += (float) $row['quantity_needed'] * $qty;
            }
            continue;
        }

        $cupSize = trim((string) $item['cup_size']);
        if ($cupSize === '' || $cupSize === '-') {
            continue;
        }
        $isHot = stripos($cupSize, 'hot') !== false || stripos((string) $item['name'], 'hot') !== false;
        foreach (['cup', 'lid', $isHot ? 'stirrer' : 'straw'] as $kind) {
            $inventory = inventory_service_find_packaging($pdo, $kind, $cupSize);
            if (!$inventory) {
                $requirements['missing-' . $kind . '-' . $cupSize] = ['inventory_id' => null, 'name' => ucfirst($kind) . ' ' . $cupSize, 'quantity' => (float) $qty, 'source' => 'packaging'];
                continue;
            }
            $id = (int) $inventory['id'];
            if (!isset($requirements[$id])) {
                $requirements[$id] = $inventory + ['inventory_id' => $id, 'quantity' => 0.0, 'source' => 'packaging'];
            }
            $requirements[$id]['quantity'] += $qty;
        }
    }
    return array_values($requirements);
}

function inventory_service_complete_order(PDO $pdo, int $orderId, ?int $userId = null): void
{
    $orderStmt = $pdo->prepare('SELECT id, order_code, inventory_deducted_at FROM orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if (!empty($order['inventory_deducted_at'])) {
        return;
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
        $remaining = (float) $inventory['qty'] - $needed;
        $pdo->prepare('UPDATE inventory SET qty = ? WHERE id = ?')->execute([$remaining, (int) $inventory['id']]);
        $log = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, order_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, ?, 'used', ?, ?, ?, NOW())");
        $log->execute([(int) $inventory['id'], $orderId, $userId, $needed, $remaining, 'Completed ' . $orderLabel . ' (' . $source . ')']);
        notifications_refresh_low_stock($pdo, (int) $inventory['id']);
    }

    $pdo->prepare('UPDATE orders SET inventory_deducted_at = NOW() WHERE id = ?')->execute([$orderId]);
}

