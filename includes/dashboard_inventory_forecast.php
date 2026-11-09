<?php
require_once __DIR__ . '/manager_forecast.php';

function dashboard_inventory_forecast(PDO $pdo): array
{
    $rows = $pdo->query("SELECT i.id, i.name, i.cup_size, i.unit, i.qty, i.lead_time_days,
        COALESCE(SUM(CASE WHEN l.change_type IN ('used','deduct','auto-deduct','damage','spoilage')
        AND l.created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND l.created_at < CURDATE()
        THEN ABS(l.quantity) ELSE 0 END),0) AS used_14
        FROM inventory i LEFT JOIN inventory_logs l ON l.inventory_id = i.id
        GROUP BY i.id,i.name,i.cup_size,i.unit,i.qty,i.lead_time_days")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['forecast'] = manager_inventory_estimate($row);
        $row['days_left'] = $row['forecast']['daily'] > 0 ? max(0, (float)$row['qty']) / $row['forecast']['daily'] : null;
        $row['needs_restock'] = (float)$row['qty'] <= 0 || ($row['forecast']['daily'] > 0 && (float)$row['qty'] <= $row['forecast']['reorder']);
    }
    unset($row);
    usort($rows, static function ($a, $b) {
        return ($b['needs_restock'] <=> $a['needs_restock'])
            ?: (($a['days_left'] ?? PHP_FLOAT_MAX) <=> ($b['days_left'] ?? PHP_FLOAT_MAX))
            ?: strcmp($a['name'], $b['name']);
    });
    return $rows;
}

function dashboard_inventory_table(array $rows): string
{
    ob_start();
    foreach ($rows as $row) {
        $f = $row['forecast'];
        $number = static fn($value) => number_format((float)$value, 2);
        $cells = [
            $row['name'] . ($row['cup_size'] ? ' (' . $row['cup_size'] . ')' : ''),
            $number($row['qty']) . ' ' . $row['unit'],
            $f['daily'] > 0 ? $number($f['daily']) . ' / day' : 'Awaiting usage history',
            $row['days_left'] !== null ? number_format($row['days_left'], 1) . ' days' : 'Awaiting usage history',
            $f['daily'] > 0 ? $number($f['reorder']) : 'Awaiting usage history',
            $f['daily'] > 0 ? $number($f['recommended']) . ' ' . $row['unit'] : 'Awaiting usage history',
            $row['needs_restock'] ? 'Restock needed' : ($f['daily'] > 0 ? 'Stock sufficient' : 'Awaiting usage history'),
        ];
        echo '<tr data-forecast-status="' . ($row['needs_restock'] ? 'restock' : ($f['daily'] > 0 ? 'sufficient' : 'unknown')) . '">';
        foreach ($cells as $cell) echo '<td>' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="7">No inventory records available.</td></tr>';
    return ob_get_clean();
}
