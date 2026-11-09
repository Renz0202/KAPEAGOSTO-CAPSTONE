<?php

/** Map a menu variant (for example, "16oz Hot") to the stocked cup size. */
function item_variant_inventory_cup_size(?string $cupSize): ?string
{
    $cupSize = trim((string) $cupSize);
    if ($cupSize === '' || $cupSize === '-') {
        return null;
    }

    if (preg_match('/(?:^|\b)12\s*oz\b/i', $cupSize)) {
        // Inventory is currently tracked under the legacy "12oz Hot" label.
        return '12oz Hot';
    }
    if (preg_match('/(?:^|\b)16\s*oz\b/i', $cupSize)) {
        return '16oz';
    }
    if (preg_match('/(?:^|\b)22\s*oz\b/i', $cupSize)) {
        return '22oz';
    }

    return $cupSize;
}

/** Resolve temperature while preserving the behavior of existing menu items. */
function item_variant_is_hot(?string $cupSize, ?string $itemName = null): bool
{
    $cupSize = trim((string) $cupSize);
    if (preg_match('/\bcold\b/i', $cupSize)) {
        return false;
    }
    if (preg_match('/\bhot\b/i', $cupSize)) {
        return true;
    }

    return stripos((string) $itemName, 'hot') !== false;
}
