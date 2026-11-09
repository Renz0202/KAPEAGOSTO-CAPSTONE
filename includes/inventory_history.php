<?php
/** Read-only movement history queries and compact server-rendered pages. */
const INVENTORY_HISTORY_USED_TYPES = ['used','use','usage','consume','consumed','consumption','deduct','deduction','auto-deduct','auto_deduct','auto deduct','auto-use','auto use','auto usage'];
const INVENTORY_HISTORY_AUTO_REMARKS = ['auto-deduct','auto deduct','auto_deduct','auto-use','auto use','auto usage'];
function inventory_history_filters(array $input): array {
    $date = static function ($value): string {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) return '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $parsed && $parsed->format('Y-m-d') === $value ? $value : '';
    };
    $from = $date($input['from'] ?? '');
    $to = $date($input['to'] ?? '');
    if ($from === '' && $to === '') $from = $to = date('Y-m-d');
    if ($from !== '' && $to !== '' && $to < $from) $to = $from;
    $type = is_string($input['log_type'] ?? null) ? strtolower(trim($input['log_type'])) : '';
    if (!in_array($type, ['', 'added', 'restock', 'used', 'damage', 'spoilage', 'adjustment'], true)) $type = '';
    $search = is_string($input['log_search'] ?? null) ? substr(trim($input['log_search']), 0, 160) : '';
    $page = is_scalar($input['log_page'] ?? null) && ctype_digit((string) $input['log_page']) ? max(1, min(1000000, (int) $input['log_page'])) : 1;
    return ['from' => $from, 'to' => $to, 'log_type' => $type, 'log_search' => $search, 'log_page' => $page];
}

function inventory_history_type(array $row): string {
    $type = strtolower(trim((string) ($row['change_type'] ?? '')));
    $remarks = strtolower((string) ($row['remarks'] ?? ''));
    foreach (INVENTORY_HISTORY_AUTO_REMARKS as $marker) {
        if (strpos($remarks, $marker) !== false) return 'used';
    }
    return in_array($type, INVENTORY_HISTORY_USED_TYPES, true) ? 'used' : $type;
}

function inventory_history_item_label(array $row): string {
    $size = trim((string) ($row['cup_size'] ?? ''));
    return (string) $row['name'] . ($size !== '' && $size !== '-' ? ' (' . $size . ')' : '');
}

function inventory_history_fetch(PDO $pdo, array $filters): array {
    $where = []; $params = [];
    $usedTypes = "'" . implode("','", INVENTORY_HISTORY_USED_TYPES) . "'";
    $historicalSql = "(LEFT(COALESCE(l.remarks,''), 27) = 'Historical recipe estimate;' AND LOWER(TRIM(l.change_type)) IN (" . $usedTypes . '))';
    // Late history follows the receipt/order date while created_at remains the audit timestamp.
    $dateSql = '(CASE WHEN ' . $historicalSql . ' THEN COALESCE(o.created_at, l.created_at) ELSE l.created_at END)';
    if ($filters['from'] !== '') { $where[] = $dateSql . ' >= ?'; $params[] = $filters['from'] . ' 00:00:00'; }
    if ($filters['to'] !== '') {
        $where[] = $dateSql . ' < ?';
        $params[] = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    }
    if ($filters['log_search'] !== '') {
        // A datalist selection matches its full label; typed text can match name, size, or SKU.
        $where[] = "(i.name LIKE ? OR i.sku LIKE ? OR i.cup_size LIKE ? OR CONCAT(i.name, CASE WHEN COALESCE(i.cup_size,'') NOT IN ('','-') THEN CONCAT(' (',i.cup_size,')') ELSE '' END) LIKE ?)";
        $keyword = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $filters['log_search']) . '%';
        $where[count($where)-1] = str_replace('LIKE ?', "LIKE ? ESCAPE '!'", $where[count($where)-1]);
        array_push($params, $keyword, $keyword, $keyword, $keyword);
    }
    $usedPredicates = ["LOWER(TRIM(l.change_type)) IN (" . $usedTypes . ')'];
    foreach (INVENTORY_HISTORY_AUTO_REMARKS as $marker) $usedPredicates[] = "LOCATE('" . $marker . "', LOWER(COALESCE(l.remarks,''))) > 0";
    $usedSql = '(' . implode(' OR ', $usedPredicates) . ')';
    if ($filters['log_type'] === 'used') $where[] = $usedSql;
    elseif ($filters['log_type'] !== '') {
        $where[] = 'LOWER(TRIM(l.change_type)) = ?'; $params[] = $filters['log_type'];
        $where[] = 'NOT ' . $usedSql;
    }
    $base = ' FROM inventory_logs l JOIN inventory i ON i.id = l.inventory_id LEFT JOIN orders o ON o.id = l.order_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $count = $pdo->prepare('SELECT COUNT(*)' . $base); $count->execute($params);
    $total = (int) $count->fetchColumn();
    $lossTotals = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN LOWER(TRIM(l.change_type)) IN ('damage','spoilage') THEN l.loss_value ELSE 0 END),0), COALESCE(SUM(CASE WHEN LOWER(TRIM(l.change_type)) IN ('damage','spoilage') AND l.loss_value IS NULL THEN 1 ELSE 0 END),0), COALESCE(SUM(CASE WHEN LOWER(TRIM(l.change_type)) IN ('damage','spoilage') AND l.loss_cost_source = 'estimate' THEN 1 ELSE 0 END),0)" . $base);
    $lossTotals->execute($params);
    [$lossTotal, $unpricedLosses, $estimatedLosses] = $lossTotals->fetch(PDO::FETCH_NUM);
    $pages = max(1, (int) ceil($total / 10));
    $page = min($pages, max(1, (int) $filters['log_page']));
    $offset = ($page - 1) * 10;
    $stmt = $pdo->prepare('SELECT l.id, l.inventory_id, i.sku, i.name, i.cup_size, i.unit, l.change_type, l.quantity, l.remaining, l.loss_unit_cost, l.loss_value, l.loss_cost_source, i.qty AS current_qty, i.critical_level, l.remarks, l.created_at, o.created_at AS original_sale_at, o.order_code AS order_reference, ' . $dateSql . ' AS movement_at' . $base . ' ORDER BY movement_at DESC, l.id DESC LIMIT 10 OFFSET ' . $offset);
    $stmt->execute($params);
    return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'loss_total' => (float) $lossTotal, 'unpriced_losses' => (int) $unpricedLosses, 'estimated_losses' => (int) $estimatedLosses, 'page' => $page, 'pages' => $pages, 'page_size' => 10, 'filters' => $filters];
}

function inventory_history_quantity($quantity, string $unit): string {
    if ($quantity === null) return 'Not recorded';
    $number = rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    return $number . ($unit !== '' ? ' ' . $unit : '');
}

function inventory_history_render(array $result): string {
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $total = $result['total']; $page = $result['page']; $pages = $result['pages'];
    $first = $total ? (($page - 1) * 10 + 1) : 0;
    $last = $total ? min($total, $first + count($result['rows']) - 1) : 0;
    $out = '<div class="inventory-log-view" data-log-view data-page="' . $page . '" data-total="' . $total . '">';
    if (in_array($result['filters']['log_type'], ['', 'damage', 'spoilage'], true)) {
        $out .= '<p class="inventory-loss-summary">Damage and spoilage value in these results: <strong>PHP ' . number_format($result['loss_total'], 2) . '</strong>';
        if ($result['estimated_losses']) $out .= ' <span>' . number_format($result['estimated_losses']) . ' loss record(s) use estimated costs.</span>';
        if ($result['unpriced_losses']) $out .= ' <span>' . number_format($result['unpriced_losses']) . ' loss record(s) need a saved cost per unit to calculate.</span>';
        $out .= '</p>';
    }
    $out .= '<div class="inventory-table-wrap inventory-log-table-frame" role="region" aria-label="Movement history table" tabindex="0"><table class="inventory-table log-table"><thead><tr><th>Date &amp; Time</th><th>Item</th><th>Type</th><th>Quantity</th><th title="Stock balance when this record was added">Balance After</th><th>Details</th></tr></thead><tbody>';
    foreach ($result['rows'] as $row) {
        $type = inventory_history_type($row);
        $historical = $type === 'used' && strpos((string) ($row['remarks'] ?? ''), 'Historical recipe estimate;') === 0;
        $typeLabel = $historical ? 'Past use (estimate)' : ucfirst($type);
        $hasSaleDate = $historical && !empty($row['original_sale_at']);
        $historyNote = 'Estimate for an older order. ' . ($hasSaleDate ? 'Sale date matches the original order. ' : 'Original sale date is unavailable; the date shown is when this entry was recorded. ') . 'Stock stayed the same when this record was added. Balance After shows stock on the Recorded On date.';
        $label = inventory_history_item_label($row);
        $unit = (string) ($row['unit'] ?? '');
        $movementAt = $hasSaleDate ? $row['original_sale_at'] : ($row['created_at'] ?? null);
        $stamp = $movementAt ? new DateTimeImmutable($movementAt) : null;
        $recorded = !empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null;
        $quantity = inventory_history_quantity($row['quantity'], $unit);
        $balance = inventory_history_quantity($row['remaining'], $unit);
        $costMark = ($row['loss_cost_source'] ?? '') === 'estimate' ? ' (estimate)' : '';
        $lossValue = $row['loss_value'] === null ? null : 'PHP ' . number_format((float) $row['loss_value'], 2) . $costMark;
        $details = ['title' => 'Movement #' . (int) $row['id'], 'item' => $label, 'sku' => $row['sku'] ?? '-', 'type' => $typeLabel,
            'quantity' => $quantity, 'balance' => $balance, 'current_stock' => inventory_history_quantity($row['current_qty'], $unit),
            'loss_value' => $lossValue ?? 'Unit cost not recorded', 'loss_unit_cost' => $row['loss_unit_cost'] === null ? 'Not recorded' : 'PHP ' . number_format((float) $row['loss_unit_cost'], 2) . ' per ' . $unit . $costMark,
            'critical_level' => $row['critical_level'] === null ? 'Not set' : inventory_history_quantity($row['critical_level'], $unit),
            'movement_at' => $stamp ? $stamp->format('F d, Y h:i:s A') : 'Not recorded',
            'recorded_at' => $recorded ? $recorded->format('F d, Y h:i:s A') : 'Not recorded',
            'order_reference' => $row['order_reference'] ?? '-', 'remarks' => ($historical ? $historyNote . "\n\n" : '') . ($row['remarks'] ?: 'No remarks.')];
        $payload = $escape(json_encode($details, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE));
        $out .= '<tr' . (in_array($type, ['used','damage','spoilage'], true) ? ' class="log-used"' : '') . '>';
        $out .= '<td>' . ($stamp ? '<time datetime="' . $stamp->format('Y-m-d\TH:i:sP') . '">' . $stamp->format('M d, Y') . '<small>' . $stamp->format('h:i A') . '</small></time>' : '-') . ($historical && !$hasSaleDate ? '<small>Sale date unknown</small>' : '') . '</td>';
        $out .= '<td><strong>' . $escape($label) . '</strong><small>' . $escape($row['sku'] ?? '-') . '</small></td>';
        $chip = in_array($type, ['used','damage','spoilage','added','restock','adjustment'], true) ? $type : '';
        $out .= '<td><span class="log-type-chip ' . $chip . '">' . $escape($historical ? 'Past use' : $typeLabel) . '</span>' . ($historical ? '<small>Estimate</small>' : '') . '</td>';
        $out .= '<td>' . $escape($quantity) . (($type === 'damage' || $type === 'spoilage') ? '<small>' . $escape($lossValue ?? 'Cost not set') . '</small>' : '') . '</td><td>' . $escape($balance) . ($historical ? '<small>When added</small>' : '') . '</td>';
        $out .= '<td><button type="button" class="inventory-log-details-btn" data-log-id="' . (int) $row['id'] . '" data-log-details="' . $payload . '" aria-haspopup="dialog" aria-controls="inventoryMovementDetails" aria-label="View movement ' . (int) $row['id'] . ' details for ' . $escape($label) . '">View Details</button></td></tr>';
    }
    if (!$result['rows']) $out .= '<tr><td colspan="6" class="inventory-empty">No movements match these filters.</td></tr>';
    $out .= '</tbody></table></div><nav class="inventory-log-pagination" aria-label="Movement history pages"><span role="status" data-log-page-status tabindex="-1">Showing ' . $first . '&ndash;' . $last . ' of ' . number_format($total) . ' movements</span><div>';
    foreach (['Previous' => $page - 1, 'Next' => $page + 1] as $label => $destination) {
        if ($label === 'Next') $out .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
        if ($destination < 1 || $destination > $pages) $out .= '<button type="button" disabled>' . $label . '</button>';
        else {
            $query = http_build_query(array_merge(['tab'=>'logs'], $result['filters'], ['log_page'=>$destination]));
            $out .= '<a href="inventory.php?' . $escape($query) . '" data-log-page="' . $destination . '">' . $label . '</a>';
        }
    }
    return $out . '</div></nav></div>';
}
