<?php
// Record reconstructed usage from before a later stock baseline without changing that balance.
require_once __DIR__ . '/inventory_reconciliation.php';

function inventory_reconciliation_history_plan(PDO $pdo): array
{
    $preview = inventory_reconciliation_preview($pdo);
    $dates = $pdo->query("SELECT id, inventory_deducted_at FROM orders WHERE LOWER(TRIM(status)) = 'completed' ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $entries = $pdo->query("SELECT l.id, l.inventory_id, l.change_type, l.remaining, l.remarks, l.created_at FROM inventory_logs l JOIN inventory i ON i.id = l.inventory_id WHERE l.order_id IS NULL AND i.material_type = 'raw_material' ORDER BY l.created_at, l.id")->fetchAll(PDO::FETCH_ASSOC);
    $baselines = [];
    foreach ($entries as $entry) {
        $type = strtolower(trim((string) $entry['change_type']));
        $remarks = (string) ($entry['remarks'] ?? '');
        $generated = $type === 'restock' && strpos($remarks, 'Generated opening stock estimate (') === 0;
        $counted = $type === 'adjustment' && preg_match('/^Stock count adjusted from [0-9.]+ to ([0-9.]+)$/D', $remarks, $match);
        if (!$generated && !$counted) continue;
        if ($entry['remaining'] === null || !$entry['created_at']) continue;
        $units = inventory_reconciliation_units($entry['remaining']);
        if ($counted && inventory_reconciliation_units($match[1]) !== $units) continue;
        $baselines[(int) $entry['inventory_id']] = [
            'movement_id' => (int) $entry['id'], 'date' => $entry['created_at'],
            'kind' => $counted ? 'Stock count adjustment' : 'Generated opening estimate',
            'recorded_stock_units' => $units,
        ];
    }
    $issues = array_merge($preview['issues'], $preview['warnings']);
    foreach ($preview['over_recorded_usage'] as $row) {
        $issues[] = ['order_id'=>$row['order_id'], 'reason'=>'Recorded use exceeds the current recipe; review it first.'];
    }
    foreach ($preview['corrections'] as $row) {
        $baseline = $baselines[$row['inventory_id']] ?? null;
        $completed = $dates[$row['order_id']] ?? null;
        if (!$baseline || !$completed || $completed >= $baseline['date']) {
            $issues[] = [
                'order_id'=>$row['order_id'], 'inventory_id'=>$row['inventory_id'],
                'reason'=>'Missing usage is not proven to predate a later stock baseline; current-stock correction needs review.',
            ];
        }
    }
    return [
        'version'=>1, 'mode'=>'historical_usage_only', 'created_at'=>$preview['created_at'],
        'stock_changes'=>false, 'packaging_included'=>false,
        'recipe_comparison'=>$preview, 'stock_baselines'=>$baselines, 'issues'=>$issues,
        'state_fingerprint'=>hash('sha256', json_encode([$preview['state_fingerprint'], $dates, $entries], JSON_THROW_ON_ERROR)),
    ];
}

/** Owns one transaction; rejects stale or ambiguous plans before inserting any history. */
function inventory_reconciliation_record_history(PDO $pdo, string $fingerprint, ?int $userId = null): array
{
    if ($pdo->inTransaction()) throw new LogicException('Start the historical correction outside an existing transaction.');
    if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new InvalidArgumentException('A reviewed historical preview fingerprint is required.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        // Lock source rows and ranges before the first consistent read. Other writers wait.
        foreach (['orders'=>'id', 'items'=>'id', 'order_items'=>'id', 'item_requirements'=>'id', 'item_recipe_metadata'=>'item_id', 'inventory'=>'id', 'inventory_logs'=>'id'] as $table=>$key) {
            $pdo->query('SELECT ' . $key . ' FROM ' . $table . ' ORDER BY ' . $key . ' FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        }
        $plan = inventory_reconciliation_history_plan($pdo);
        if (!hash_equals($fingerprint, $plan['state_fingerprint'])) throw new RuntimeException('Records changed after the preview. Generate a fresh plan before correcting history.');
        if ($plan['issues']) throw new RuntimeException('Unresolved history or stock-baseline issues prevent correction.');
        $preview = $plan['recipe_comparison'];
        $before = $pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $stockById = array_column($before, 'qty', 'id');
        $insert = $pdo->prepare("INSERT INTO inventory_logs (inventory_id, order_id, user_id, change_type, quantity, remaining, remarks, created_at) VALUES (?, ?, ?, 'used', ?, ?, ?, NOW())");
        $batch = substr($fingerprint, 0, 16);
        foreach ($preview['corrections'] as $row) {
            $baseline = $plan['stock_baselines'][$row['inventory_id']];
            $remarks = 'Historical recipe estimate; order ID ' . $row['order_id'] . '; before stock baseline '
                . $baseline['date'] . '; current stock unchanged; balance is posting-time stock; batch ' . $batch;
            $insert->execute([$row['inventory_id'], $row['order_id'], $userId,
                inventory_reconciliation_quantity($row['missing_units']), $stockById[$row['inventory_id']], $remarks]);
        }
        $after = inventory_reconciliation_preview($pdo);
        if ($after['corrections'] || $after['issues'] || $after['warnings'] || $after['over_recorded_usage']) {
            throw new RuntimeException('Historical correction verification failed; no changes will be saved.');
        }
        if ($before !== $pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Current stock changed during historical correction; no changes will be saved.');
        }
        $pdo->commit();
        return [
            'mode'=>'historical_usage_only', 'batch'=>$batch,
            'orders_corrected'=>$preview['summary']['orders_needing_correction'],
            'ingredients'=>$preview['summary']['ingredients'],
            'usage_records_added'=>count($preview['corrections']),
            'missing_usage_remaining'=>count($after['corrections']),
            'current_stock_unchanged'=>true, 'packaging_unchanged'=>true,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function inventory_reconciliation_history_report(array $plan): string
{
    $preview = $plan['recipe_comparison'];
    $s = $preview['summary'];
    $escape = static fn($value) => str_replace(["\r","\n",'|'], [' ',' ','\|'], (string) $value);
    $q = static fn($units) => number_format($units / 100, 2, '.', ',');
    $lines = [
        '# Historical ingredient record correction', '',
        '**This plan adds missing historical usage estimates and preserves all current stock balances.**', '',
        'Every proposed entry must predate a later generated opening stock entry or stock count adjustment. Old usage is recorded separately from that later balance. No purchases, restocks, or historical stock balances are invented.', '',
        '- Completed orders reviewed: **' . $s['completed_orders'] . '**.',
        '- Orders needing historical records: **' . $s['orders_needing_correction'] . '**.',
        '- Ingredient usage records to add: **' . $s['ingredient_corrections'] . '** across **' . $s['ingredients'] . '** ingredients.',
        '- Packaging is excluded. Existing movements, orders, payments, recipes, and notifications are preserved.',
        '- New entries are marked **Historical recipe estimate**. Balance After shows the unchanged current stock when the correction is posted. The old sale\'s stock balance is unknown.',
    ];
    foreach ($preview['excluded_orders'] as $row) $lines[] = '- Excluded ' . $escape($row['status']) . ' orders: **' . $row['orders'] . '**.';
    array_push($lines, '', 'The estimates use the currently saved recipes, including generated recipes. Current setup stock remains an estimate until physically counted. The shortages in the earlier subtraction-only preview do not establish a physical shortage.', '',
        '| Ingredient | Unit | Missing historical usage | Current stock | Stock after correction | Later stock baseline |',
        '|---|---|---:|---:|---:|---|');
    foreach ($preview['balances'] as $row) {
        $baseline = $plan['stock_baselines'][$row['inventory_id']] ?? null;
        $lines[] = '| ' . $escape($row['ingredient']) . ' | ' . $escape($row['unit']) . ' | '
            . $q($row['missing_usage_units']) . ' | ' . $q($row['current_stock_units']) . ' | '
            . $q($row['current_stock_units']) . ' | ' . ($baseline ? $escape($baseline['kind'] . ': ' . $baseline['date']) : '**Needs review**') . ' |';
    }
    if ($plan['issues']) {
        array_push($lines, '', '## Records needing review', '');
        foreach ($plan['issues'] as $row) $lines[] = '- ' . (isset($row['order_id']) ? 'Order ID ' . $row['order_id'] . ': ' : '') . $escape($row['reason']);
    }
    array_push($lines, '', '## Missing usage entries', '',
        '| Order | Ingredient | Unit | Recipe total | Already recorded | Missing historical usage |',
        '|---|---|---|---:|---:|---:|');
    foreach ($preview['corrections'] as $row) {
        $lines[] = '| ' . $escape($row['order_code'] ?: 'ID ' . $row['order_id']) . ' | ' . $escape($row['ingredient']) . ' | '
            . $escape($row['unit']) . ' | ' . $q($row['expected_units']) . ' | ' . $q($row['recorded_units']) . ' | ' . $q($row['missing_units']) . ' |';
    }
    array_push($lines, '', 'State fingerprint: ' . $plan['state_fingerprint'], '');
    return implode("\n", $lines);
}
