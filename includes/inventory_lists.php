<?php
/** Read-only paginated Current Stock and Voided / Cancelled lists. */
require_once __DIR__ . '/manager_forecast.php';

function inventory_list_escape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function inventory_list_page_number($value): int {
    return is_scalar($value) && ctype_digit((string) $value) ? max(1, min(1000000, (int) $value)) : 1;
}
function inventory_stock_filters(array $input): array {
    $text = static fn($name) => is_string($input[$name] ?? null) ? substr(trim($input[$name]), 0, 160) : '';
    $group = $text('material_group');
    if (!isset(inventory_material_groups()[$group])) $group = '';
    $category = strtolower($text('stock_category'));
    if (!in_array($category, ['', 'raw_material', 'straw', 'stirrer', 'cup', 'lid', 'others'], true)) $category = '';
    $status = strtolower($text('stock_status'));
    if (!in_array($status, ['', 'low', 'in_stock', 'out_of_stock'], true)) $status = '';
    return ['search'=>$text('search'), 'material_group'=>$group, 'stock_category'=>$category, 'stock_status'=>$status, 'stock_page'=>inventory_list_page_number($input['stock_page'] ?? null)];
}
function inventory_restock_threshold(array $item): array {
    $manual = (float) ($item['critical_level'] ?? 0);
    if ($manual > 0) return ['threshold'=>$manual, 'source'=>'manual'];

    $forecast = manager_inventory_estimate(array_replace(['used_14'=>0, 'lead_time_days'=>1], $item));
    return [
        'threshold' => $forecast['daily'] > 0 ? (float) $forecast['reorder'] : 0.0,
        'source' => $forecast['daily'] > 0 ? 'forecast' : 'unknown',
    ];
}
function inventory_needs_restock(array $item): bool {
    if ((float) ($item['qty'] ?? 0) <= 0) return true;
    $threshold = inventory_restock_threshold($item)['threshold'];
    return $threshold > 0 && (float) $item['qty'] <= $threshold;
}
function inventory_stock_page(array $inventory, array $filters): array {
    $rows = [];
    foreach (groupInventory($inventory) as $group) array_push($rows, ...$group);
    $total = count($rows);
    $pages = max(1, (int) ceil($total / 10));
    $page = min($pages, $filters['stock_page']);
    return ['rows'=>array_slice($rows, ($page-1)*10, 10), 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'filters'=>$filters, 'low_count'=>count(lowStockItems($inventory))];
}
function inventory_list_pagination(array $result, string $tab, string $pageKey, string $noun): string {
    $page = $result['page']; $total = $result['total'];
    $first = $total ? ($page-1)*10+1 : 0;
    $last = $total ? min($total, $first+count($result['rows'])-1) : 0;
    $out = '<nav class="inventory-log-pagination" aria-label="' . inventory_list_escape($noun) . ' pages"><span role="status" data-list-page-status tabindex="-1">Showing ' . $first . '&ndash;' . $last . ' of ' . number_format($total) . ' ' . inventory_list_escape($noun) . '</span><div>';
    foreach (['Previous'=>$page-1, 'Next'=>$page+1] as $label=>$destination) {
        if ($label === 'Next') $out .= '<span>Page ' . $page . ' of ' . $result['pages'] . '</span>';
        if ($destination < 1 || $destination > $result['pages']) $out .= '<button type="button" disabled>' . $label . '</button>';
        else {
            $query = http_build_query(array_merge(['tab'=>$tab], $result['filters'], [$pageKey=>$destination]));
            $out .= '<a href="inventory.php?' . inventory_list_escape($query) . '" data-list-page="' . $destination . '">' . $label . '</a>';
        }
    }
    return $out . '</div></nav>';
}
function inventory_list_details_button(int $id, array $details, string $dialog, string $label): string {
    $payload = json_encode($details, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    return '<button type="button" class="inventory-log-details-btn" data-list-id="' . $id . '" data-list-details="' . inventory_list_escape($payload) . '" aria-haspopup="dialog" aria-controls="' . $dialog . '" aria-label="View details for ' . inventory_list_escape($label) . '">View Details</button>';
}
function inventory_list_date($value): string {
    return $value ? date('F d, Y h:i:s A', strtotime($value)) : 'Not recorded';
}
function inventory_stock_render(array $result): string {
    $e = 'inventory_list_escape';
    $out = '<div data-inventory-list="stock" data-page="' . $result['page'] . '" data-total="' . $result['total'] . '">';
    if ($result['low_count']) $out .= '<div class="inventory-alert warning inventory-stock-warning">' . $result['low_count'] . ($result['low_count'] === 1 ? ' item needs' : ' items need') . ' restocking based on the manual critical level or the 14-day usage forecast.</div>';
    $out .= '<div class="inventory-panel-header compact"><div><span class="panel-eyebrow">Stock Ledger</span><h2>Current Inventory</h2></div><span class="inventory-updated">Last updated: <span id="invUpdatedAt">' . date('h:i:s A') . '</span></span></div>';
    $out .= '<div class="inventory-table-wrap inventory-log-table-frame inventory-list-table-frame" role="region" aria-label="Current stock table" tabindex="0"><table class="inventory-table log-table inventory-stock-table"><thead><tr><th>Item</th><th>Group / Type</th><th>Stock</th><th>Status</th><th>Details</th><th>Actions</th></tr></thead><tbody>';
    foreach ($result['rows'] as $row) {
        $type = getItemType($row['name'], $row['material_type']);
        $size = stripos($row['name'], 'straw') !== false ? null : normalizeCupSize($row['cup_size'] ?? null);
        $label = $row['name'] . ($size ? ' (' . $size . ')' : '');
        $qty = (float) $row['qty']; $critical = $row['critical_level'];
        $isLow = inventory_needs_restock($row);
        $status = $qty <= 0 ? 'Out of stock' : ($isLow ? 'Restock needed' : 'In stock');
        $group = inventory_material_group_label($row['material_group'] ?? null);
        $quantity = inventory_history_quantity($row['qty'], $row['unit']);
        $costMark = ($row['unit_cost_source'] ?? '') === 'estimate' ? ' (estimate)' : '';
        $thresholdInfo = inventory_restock_threshold($row);
        $thresholdLabel = $thresholdInfo['threshold'] > 0 ? inventory_history_quantity($thresholdInfo['threshold'], $row['unit']) : 'Awaiting usage history';
        $details = ['title'=>$label, 'sku'=>$row['sku'], 'type'=>$type, 'group'=>$group, 'size'=>$size ?: 'Not applicable', 'quantity'=>$quantity, 'unit'=>$row['unit'], 'unit_cost'=>$row['unit_cost'] === null ? 'Not set' : 'PHP ' . number_format((float) $row['unit_cost'], 2) . ' per ' . $row['unit'] . $costMark, 'status'=>$status, 'critical'=>$critical === null || (float)$critical <= 0 ? 'Not set' : inventory_history_quantity($critical, $row['unit']), 'reorder_threshold'=>$thresholdLabel, 'threshold_source'=>$thresholdInfo['source'] === 'manual' ? 'Manual critical level' : ($thresholdInfo['source'] === 'forecast' ? '14-day usage forecast' : 'Awaiting usage history'), 'added'=>inventory_list_date($row['date_added'] ?? null), 'restocked'=>inventory_list_date($row['last_restock_at'] ?? null)];
        $edit = ['id'=>(int)$row['id'], 'sku'=>$row['sku'], 'name'=>$row['name'], 'cup_size'=>$size ?? '', 'unit'=>$row['unit'], 'material_type'=>$row['material_type'], 'material_group'=>$row['material_group'] ?? '', 'qty'=>formatQty($qty), 'unit_cost'=>$row['unit_cost'] === null ? '' : formatQty($row['unit_cost']), 'unit_cost_source'=>$row['unit_cost_source'] ?? '', 'critical_level'=>$critical === null ? '' : formatQty($critical)];
        $out .= '<tr' . ($isLow || $qty <= 0 ? ' class="low-stock"' : '') . '><td><strong>' . $e($label) . '</strong><small>' . $e($row['sku']) . '</small></td>';
        $out .= '<td>' . $e($group) . '<small>' . $e($type) . '</small></td><td><span class="qty-pill">' . $e($quantity) . '</span></td>';
        $out .= '<td><span class="inventory-stock-status ' . ($qty <= 0 ? 'empty' : ($isLow ? 'low' : 'available')) . '">' . $status . '</span></td>';
        $out .= '<td>' . inventory_list_details_button((int)$row['id'], $details, 'inventoryStockDetails', $label) . '</td><td><div class="inventory-actions">';
        $out .= '<button type="button" class="inventory-action-btn edit js-edit-inventory" data-list-focus="edit-' . (int)$row['id'] . '" data-inventory="' . $e(json_encode($edit, JSON_INVALID_UTF8_SUBSTITUTE)) . '" title="Edit inventory" aria-label="Edit ' . $e($label) . '"><i class="fa fa-pen-to-square" aria-hidden="true"></i></button>';
        $out .= '<form method="post" class="inventory-inline-form" data-confirm="Delete inventory item ' . $e($row['name']) . '?" data-confirm-title="Delete Inventory Item" data-confirm-type="danger"><input type="hidden" name="delete_inventory" value="1"><input type="hidden" name="id" value="' . (int)$row['id'] . '"><button type="submit" class="inventory-action-btn delete" data-list-focus="delete-' . (int)$row['id'] . '" title="Delete inventory" aria-label="Delete ' . $e($label) . '"><i class="fa fa-trash" aria-hidden="true"></i></button></form></div></td></tr>';
    }
    if (!$result['rows']) $out .= '<tr><td colspan="6" class="inventory-empty">No inventory items match these filters.</td></tr>';
    return $out . '</tbody></table></div>' . inventory_list_pagination($result, 'stock', 'stock_page', 'items') . '</div>';
}
function inventory_void_filters(array $input): array {
    $dates = inventory_history_filters(['from'=>$input['void_from'] ?? '', 'to'=>$input['void_to'] ?? '']);
    $status = is_string($input['void_status'] ?? null) ? strtolower(trim($input['void_status'])) : '';
    if (!in_array($status, ['', 'voided', 'cancelled'], true)) $status = '';
    return ['void_from'=>$dates['from'], 'void_to'=>$dates['to'], 'void_status'=>$status, 'void_search'=>is_string($input['void_search'] ?? null) ? substr(trim($input['void_search']),0,160) : '', 'void_page'=>inventory_list_page_number($input['void_page'] ?? null)];
}
function inventory_void_today_count(PDO $pdo): int {
    $start = date('Y-m-d') . ' 00:00:00';
    $end = (new DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('void','voided','cancelled') AND COALESCE(voided_at,cancelled_at,created_at) >= ? AND COALESCE(voided_at,cancelled_at,created_at) < ?");
    $stmt->execute([$start,$end]);
    return (int)$stmt->fetchColumn();
}
function inventory_void_fetch(PDO $pdo, array $filters): array {
    $where = ["o.status IN ('void','voided','cancelled')"]; $params = [];
    if ($filters['void_from'] !== '') { $where[] = 'COALESCE(o.voided_at,o.cancelled_at,o.created_at) >= ?'; $params[] = $filters['void_from'] . ' 00:00:00'; }
    if ($filters['void_to'] !== '') { $where[] = 'COALESCE(o.voided_at,o.cancelled_at,o.created_at) < ?'; $params[] = (new DateTimeImmutable($filters['void_to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
    if ($filters['void_status'] === 'voided') $where[] = "o.status IN ('void','voided')";
    if ($filters['void_status'] === 'cancelled') $where[] = "o.status = 'cancelled'";
    if ($filters['void_search'] !== '') {
        $where[] = "(CAST(o.id AS CHAR) LIKE ? ESCAPE '!' OR o.order_code LIKE ? ESCAPE '!' OR o.customer_name LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!')";
        $keyword = '%' . str_replace(['!','%','_'],['!!','!%','!_'],ltrim($filters['void_search'],'#')) . '%';
        array_push($params,$keyword,$keyword,$keyword,$keyword);
    }
    $base = ' FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE ' . implode(' AND ',$where);
    $stmt = $pdo->prepare('SELECT COUNT(*)' . $base); $stmt->execute($params);
    $total = (int)$stmt->fetchColumn(); $pages = max(1,(int)ceil($total/10)); $page = min($pages,$filters['void_page']);
    $stmt = $pdo->prepare('SELECT o.id,o.order_code,o.customer_name,o.service_details,o.created_at,o.voided_at,o.cancelled_at,o.total,o.status,u.username,COALESCE(o.voided_at,o.cancelled_at,o.created_at) AS event_at' . $base . ' ORDER BY event_at DESC,o.id DESC LIMIT 10 OFFSET ' . (($page-1)*10));
    $stmt->execute($params);
    return ['rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages,'filters'=>$filters];
}
function inventory_void_render(array $result): string {
    $e = 'inventory_list_escape';
    $out = '<div data-inventory-list="voided" data-page="' . $result['page'] . '" data-total="' . $result['total'] . '"><div class="inventory-table-wrap inventory-log-table-frame inventory-list-table-frame" role="region" aria-label="Voided and cancelled orders table" tabindex="0"><table class="inventory-table log-table inventory-void-table"><thead><tr><th>Date &amp; Time</th><th>Order</th><th>Created By</th><th>Total</th><th>Status</th><th>Details</th></tr></thead><tbody>';
    foreach ($result['rows'] as $row) {
        $status = $row['status'] === 'cancelled' ? 'Cancelled' : 'Voided';
        $reference = $row['order_code'] ?: '#' . $row['id'];
        $stamp = new DateTimeImmutable($row['event_at']);
        $details = ['title'=>'Order ' . $reference, 'id'=>'#' . $row['id'], 'customer'=>$row['customer_name'] ?: 'Not recorded', 'user'=>$row['username'] ?: 'System', 'total'=>formatCurrency($row['total']), 'status'=>$status, 'event'=>empty($row['voided_at']) && empty($row['cancelled_at']) ? 'Not recorded; order created ' . inventory_list_date($row['created_at']) : inventory_list_date($row['event_at']), 'created'=>inventory_list_date($row['created_at']), 'service'=>$row['service_details'] ?: 'No service details.'];
        $out .= '<tr><td><time title="' . (empty($row['voided_at']) && empty($row['cancelled_at']) ? 'Event time not recorded; showing order creation time' : 'Voided / cancelled time') . '" datetime="' . $stamp->format('Y-m-d\TH:i:sP') . '">' . $stamp->format('M d, Y') . '<small>' . $stamp->format('h:i A') . '</small></time></td><td><strong>' . $e($reference) . '</strong><small>' . $e($row['customer_name'] ?: 'Customer not recorded') . '</small></td>';
        $out .= '<td>' . $e($row['username'] ?: 'System') . '</td><td>' . $e(formatCurrency($row['total'])) . '</td><td><span class="log-type-chip ' . ($status === 'Voided' ? 'damage' : 'adjustment') . '">' . $status . '</span></td><td>' . inventory_list_details_button((int)$row['id'],$details,'inventoryVoidDetails',$reference) . '</td></tr>';
    }
    if (!$result['rows']) $out .= '<tr><td colspan="6" class="inventory-empty">No voided or cancelled orders match these filters.</td></tr>';
    return $out . '</tbody></table></div>' . inventory_list_pagination($result,'voided','void_page','orders') . '</div>';
}
