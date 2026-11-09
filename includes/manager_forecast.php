<?php

function manager_inventory_estimate(array $item): array
{
    $daily = max(0, (float) $item['used_14']) / 14;
    $lead = max(1, min(365, (int) $item['lead_time_days']));
    $buffer = ceil($daily * 2);
    $reorder = ceil($daily * $lead + $buffer);
    $target = ceil($daily * ($lead + 7) + $buffer);
    $qty = (float) $item['qty'];
    return [
        'daily' => $daily, 'lead' => $lead, 'buffer' => $buffer,
        'reorder' => $reorder, 'target' => $target,
        'recommended' => $daily > 0 && $qty <= $reorder ? max(0, ceil($target - $qty)) : 0,
    ];
}

function manager_inventory_display(array $item, array $forecast): array
{
    $hasHistory = $forecast['daily'] > 0;
    $display = [
        'qty' => manager_number($item['qty']),
        'daysLeft' => $hasHistory ? number_format(max(0, (float) $item['qty']) / $forecast['daily'], 1) . ' days remaining' : 'Awaiting usage history',
        'recommended' => $hasHistory ? manager_number($forecast['recommended']) : 'Awaiting usage history',
        'lead' => (string) $forecast['lead'],
    ];
    foreach (['reorder', 'buffer', 'target'] as $key) {
        $display[$key] = $hasHistory ? manager_number($forecast[$key]) : 'Awaiting usage history';
    }
    return $display;
}
