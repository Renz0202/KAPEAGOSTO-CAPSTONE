<?php
// Read-only reconstruction of missing ingredient use. Packaging is never included.
require_once __DIR__ . '/inventory_history.php';

function inventory_reconciliation_units($value): int
{
    $text = trim((string) $value);
    if (!preg_match('/^([0-9]{1,8})(?:\.([0-9]{1,2}))?$/D', $text, $match)) {
        throw new RuntimeException('Invalid inventory quantity: ' . $text);
    }
    return (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
}

function inventory_reconciliation_quantity(int $units): string
{
    $sign = $units < 0 ? '-' : '';
    $units = abs($units);
    $fraction = rtrim(str_pad((string) ($units % 100), 2, '0', STR_PAD_LEFT), '0');
    return $sign . intdiv($units, 100) . ($fraction !== '' ? '.' . $fraction : '');
}

function inventory_reconciliation_add(int $left, int $right): int
{
    if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
        throw new RuntimeException('Ingredient usage exceeds the supported quantity range.');
    }
    return $left + $right;
}

/**
 * All database operations in this function are SELECTs.
 * Quantity values ending in _units are hundredths of the ingredient's own g/ml/pcs unit.
 */
function inventory_reconciliation_preview(PDO $pdo): array
{
    $orders = $pdo->query("SELECT id, order_code, status, created_at, inventory_deducted_at FROM orders WHERE LOWER(TRIM(status)) = 'completed' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $excluded = $pdo->query("SELECT status, COUNT(*) AS orders FROM orders WHERE LOWER(TRIM(status)) <> 'completed' GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
    $materials = $pdo->query('SELECT id, sku, name, material_type, unit, qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $byMaterial = [];
    foreach ($materials as $material) {
        $byMaterial[(int) $material['id']] = $material;
    }
    $ids = array_map(static fn($order) => (int) $order['id'], $orders);
    $lines = $items = $recipes = $metadata = $logs = [];
    if ($ids) {
        $list = implode(',', $ids); // IDs come exclusively from the completed-order SELECT.
        $lines = $pdo->query('SELECT id, order_id, item_id, qty FROM order_items WHERE order_id IN (' . $list . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $items = $pdo->query('SELECT DISTINCT i.id, i.name, i.cup_size FROM items i JOIN order_items oi ON oi.item_id = i.id WHERE oi.order_id IN (' . $list . ') ORDER BY i.id')->fetchAll(PDO::FETCH_ASSOC);
        $recipes = $pdo->query("SELECT ir.item_id, ir.inventory_id, ir.quantity_needed FROM item_requirements ir JOIN inventory i ON i.id = ir.inventory_id WHERE i.material_type = 'raw_material' AND ir.item_id IN (SELECT item_id FROM order_items WHERE order_id IN (" . $list . ')) ORDER BY ir.item_id, ir.inventory_id')->fetchAll(PDO::FETCH_ASSOC);
        $metadata = $pdo->query('SELECT item_id, source, notes, updated_at FROM item_recipe_metadata WHERE item_id IN (SELECT item_id FROM order_items WHERE order_id IN (' . $list . ')) ORDER BY item_id')->fetchAll(PDO::FETCH_ASSOC);
        $logs = $pdo->query('SELECT id, inventory_id, order_id, change_type, quantity, remaining, remarks, created_at FROM inventory_logs WHERE order_id IN (' . $list . ') ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    $unlinked = $pdo->query("SELECT l.id, l.change_type, l.quantity, l.remarks FROM inventory_logs l JOIN inventory i ON i.id = l.inventory_id WHERE l.order_id IS NULL AND i.material_type = 'raw_material' ORDER BY l.id")->fetchAll(PDO::FETCH_ASSOC);

    $byItem = $byRecipe = $byOrder = $lineCounts = $expected = $recorded = [];
    $issues = $warnings = [];
    foreach ($items as $item) {
        $byItem[(int) $item['id']] = $item;
    }
    foreach ($orders as $order) {
        $byOrder[(int) $order['id']] = $order;
    }
    foreach ($recipes as $recipe) {
        $amount = inventory_reconciliation_units($recipe['quantity_needed']);
        if ($amount === 0) {
            throw new RuntimeException('A saved ingredient recipe has a zero quantity.');
        }
        $byRecipe[(int) $recipe['item_id']][] = [
            'inventory_id' => (int) $recipe['inventory_id'], 'amount' => $amount,
        ];
    }
    foreach ($lines as $line) {
        $orderId = (int) $line['order_id'];
        $itemId = (int) $line['item_id'];
        $quantity = (int) $line['qty'];
        $lineCounts[$orderId] = ($lineCounts[$orderId] ?? 0) + 1;
        if ($quantity <= 0 || !isset($byItem[$itemId])) {
            $issues[] = ['order_id' => $orderId, 'item_id' => $itemId, 'reason' => 'Missing menu item or invalid order quantity.'];
            continue;
        }
        if (empty($byRecipe[$itemId])) {
            $issues[] = ['order_id' => $orderId, 'item_id' => $itemId, 'reason' => 'No saved ingredient recipe for ' . $byItem[$itemId]['name'] . '.'];
            continue;
        }
        foreach ($byRecipe[$itemId] as $recipe) {
            if ($recipe['amount'] > intdiv(PHP_INT_MAX, $quantity)) {
                throw new RuntimeException('Order ingredient usage exceeds the supported quantity range.');
            }
            $inventoryId = $recipe['inventory_id'];
            $expected[$orderId][$inventoryId] = inventory_reconciliation_add(
                $expected[$orderId][$inventoryId] ?? 0, $recipe['amount'] * $quantity
            );
        }
    }
    foreach ($orders as $order) {
        if (empty($lineCounts[(int) $order['id']])) {
            $issues[] = ['order_id' => (int) $order['id'], 'reason' => 'Completed order has no saved order items.'];
        }
    }
    foreach ($logs as $log) {
        $inventoryId = (int) $log['inventory_id'];
        if (($byMaterial[$inventoryId]['material_type'] ?? '') !== 'raw_material') {
            continue;
        }
        if (inventory_history_type($log) === 'used') {
            $orderId = (int) $log['order_id'];
            $recorded[$orderId][$inventoryId] = inventory_reconciliation_add(
                $recorded[$orderId][$inventoryId] ?? 0, inventory_reconciliation_units($log['quantity'])
            );
        } else {
            $warnings[] = ['order_id' => (int) $log['order_id'], 'inventory_id' => $inventoryId, 'reason' => 'Other ingredient movement is linked to this order; review before correcting it.'];
        }
    }
    foreach ($unlinked as $log) {
        if (inventory_history_type($log) === 'used') {
            $issues[] = ['movement_id' => (int) $log['id'], 'reason' => 'Ingredient use has no order link; it cannot safely be assigned to an old order.'];
        }
    }

    $corrections = $totals = $affected = $overRecorded = [];
    $ordersWithRecordedUse = count($recorded);
    foreach ($expected as $orderId => $requirements) {
        foreach ($requirements as $inventoryId => $needed) {
            $used = $recorded[$orderId][$inventoryId] ?? 0;
            if ($used > $needed) {
                $overRecorded[] = ['order_id' => $orderId, 'inventory_id' => $inventoryId, 'expected_units' => $needed, 'recorded_units' => $used];
            }
            $missing = max(0, $needed - $used);
            if ($missing === 0) continue;
            $material = $byMaterial[$inventoryId];
            $corrections[] = [
                'order_id' => $orderId, 'order_code' => $byOrder[$orderId]['order_code'],
                'order_created_at' => $byOrder[$orderId]['created_at'],
                'inventory_id' => $inventoryId, 'ingredient' => $material['name'], 'unit' => $material['unit'],
                'expected_units' => $needed, 'recorded_units' => $used, 'missing_units' => $missing,
            ];
            $totals[$inventoryId] = inventory_reconciliation_add($totals[$inventoryId] ?? 0, $missing);
            $affected[$orderId] = true;
        }
    }
    foreach ($recorded as $orderId => $usage) {
        foreach ($usage as $inventoryId => $used) {
            if ($used > 0 && !isset($expected[$orderId][$inventoryId])) {
                $warnings[] = ['order_id' => $orderId, 'inventory_id' => $inventoryId, 'reason' => 'Previously used ingredient is absent from the current recipe; existing usage is preserved.'];
            }
        }
    }
    $balances = [];
    foreach ($totals as $inventoryId => $missing) {
        $material = $byMaterial[$inventoryId];
        $stock = inventory_reconciliation_units($material['qty']);
        $balances[] = [
            'inventory_id' => $inventoryId, 'ingredient' => $material['name'], 'unit' => $material['unit'],
            'current_stock_units' => $stock, 'missing_usage_units' => $missing,
            'proposed_stock_units' => $stock - $missing, 'shortage_units' => max(0, $missing - $stock),
        ];
    }
    usort($balances, static fn($a, $b) => strcmp($a['ingredient'], $b['ingredient']));
    $sourceCounts = [];
    foreach ($metadata as $row) {
        $sourceCounts[$row['source']] = ($sourceCounts[$row['source']] ?? 0) + 1;
    }
    $dates = array_column($orders, 'created_at');
    return [
        'version' => 1, 'mode' => 'preview_only', 'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Singapore')))->format(DateTimeInterface::ATOM),
        'summary' => [
            'completed_orders' => count($orders), 'orders_needing_correction' => count($affected),
            'orders_with_recorded_ingredient_usage' => $ordersWithRecordedUse,
            'ingredient_corrections' => count($corrections), 'ingredients' => count($balances),
            'ingredients_with_shortages' => count(array_filter($balances, static fn($row) => $row['shortage_units'] > 0)),
            'earliest_order_created_at' => $dates ? min($dates) : null,
            'latest_order_created_at' => $dates ? max($dates) : null,
        ],
        'order_ids' => $ids, 'excluded_orders' => $excluded, 'recipe_sources' => $sourceCounts,
        'quantity_scale' => 100, 'packaging_included' => false,
        'balances' => $balances, 'corrections' => $corrections,
        'issues' => $issues, 'warnings' => $warnings, 'over_recorded_usage' => $overRecorded,
        'state_fingerprint' => hash('sha256', json_encode([$orders, $lines, $items, $recipes, $metadata, $materials, $logs, $unlinked], JSON_THROW_ON_ERROR)),
    ];
}

function inventory_reconciliation_report(array $preview): string
{
    $escape = static fn($value) => str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], (string) $value);
    $quantity = static fn($units) => number_format($units / 100, 2, '.', ',');
    $s = $preview['summary'];
    $out = [
        '# Missing ingredient usage - preview',
        '',
        '**No inventory, orders, or movement records have been changed.**',
        '',
        'This preview compares completed orders with the currently saved ingredient recipes, subtracts ingredient usage already recorded for each order, and shows only the positive missing amounts.',
        '',
        '- Completed orders reviewed: **' . $s['completed_orders'] . '**.',
        '- Orders with missing ingredient usage: **' . $s['orders_needing_correction'] . '**.',
        '- Ingredient entries that would be added: **' . $s['ingredient_corrections'] . '**.',
        '- Ingredients affected: **' . $s['ingredients'] . '**; ingredients with insufficient recorded stock: **' . $s['ingredients_with_shortages'] . '**.',
        '- Order creation dates: ' . ($s['earliest_order_created_at'] ?? 'None') . ' to ' . ($s['latest_order_created_at'] ?? 'None') . '.',
        '- All packaging is excluded, including cups, lids, straws, stirrers, napkins, and sleeves.',
    ];
    foreach ($preview['excluded_orders'] as $row) {
        $out[] = '- Excluded ' . $escape($row['status']) . ' orders: **' . $row['orders'] . '**.';
    }
    array_push($out, '',
        'The current recipes include generated estimates. These figures estimate past usage; they do not establish a physical stock count. Stock entered after older sales also needs a confirmed starting date before those sales can be deducted from it.',
        '',
        '## Proposed ingredient balances',
        '',
        'Negative proposed balances are shortages for review. They have not been saved or replaced with zero, and no restock has been invented.',
        '',
        '| Ingredient | Unit | Current stock | Missing usage | Proposed stock | Review |',
        '|---|---|---:|---:|---:|---|'
    );
    foreach ($preview['balances'] as $row) {
        $out[] = '| ' . $escape($row['ingredient']) . ' | ' . $escape($row['unit']) . ' | '
            . $quantity($row['current_stock_units']) . ' | ' . $quantity($row['missing_usage_units']) . ' | '
            . $quantity($row['proposed_stock_units']) . ' | '
            . ($row['shortage_units'] > 0 ? '**Short by ' . $quantity($row['shortage_units']) . ' ' . $escape($row['unit']) . '**' : 'Enough recorded stock') . ' |';
    }
    if ($preview['issues'] || $preview['warnings'] || $preview['over_recorded_usage']) {
        array_push($out, '', '## Records needing review', '');
        foreach (array_merge($preview['issues'], $preview['warnings']) as $row) {
            $out[] = '- ' . (isset($row['order_id']) ? 'Order ID ' . $row['order_id'] . ': ' : '')
                . (isset($row['movement_id']) ? 'Movement ID ' . $row['movement_id'] . ': ' : '')
                . $escape($row['reason']);
        }
        foreach ($preview['over_recorded_usage'] as $row) {
            $out[] = '- Order ID ' . $row['order_id'] . ', ingredient ID ' . $row['inventory_id']
                . ': recorded usage exceeds the current recipe. No automatic return to stock is proposed.';
        }
    }
    array_push($out, '', '## Missing usage by order and ingredient', '',
        "Quantities are in each ingredient's stated unit. Already-recorded ingredient use is counted once, even when spread across several movements.",
        '',
        '| Order | Order date | Ingredient | Unit | Recipe total | Already recorded | Missing usage |',
        '|---|---|---|---|---:|---:|---:|'
    );
    foreach ($preview['corrections'] as $row) {
        $label = trim((string) $row['order_code']) !== '' ? $row['order_code'] : 'ID ' . $row['order_id'];
        $out[] = '| ' . $escape($label) . ' | ' . $escape($row['order_created_at']) . ' | ' . $escape($row['ingredient'])
            . ' | ' . $escape($row['unit']) . ' | ' . $quantity($row['expected_units']) . ' | '
            . $quantity($row['recorded_units']) . ' | ' . $quantity($row['missing_units']) . ' |';
    }
    array_push($out, '', '## Next step', '',
        'Review the recipe amounts, stock starting date, and the shortages above before applying corrections. Existing orders keep their status, payment, completion marker, and existing movement history.',
        '',
        'State fingerprint: ' . $preview['state_fingerprint'], ''
    );
    return implode("\n", $out);
}
