<?php
/** Shared read-only presentation for compact management lists. */
function management_list_escape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function management_list_details(array $details, string $dialog, string $name): string {
    $json = json_encode($details, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    return '<button type="button" class="management-details-button" data-compact-details="' . management_list_escape($json) . '" aria-haspopup="dialog" aria-controls="' . management_list_escape($dialog) . '" aria-label="View details for ' . management_list_escape($name) . '">View Details</button>';
}
function management_list_pager(string $noun): string {
    return '<nav class="management-pagination" aria-label="' . management_list_escape($noun) . ' pages" data-compact-pagination hidden><span role="status" tabindex="-1" data-compact-range></span><div><button type="button" data-compact-previous disabled>Previous</button><span data-compact-page-label></span><button type="button" data-compact-next disabled>Next</button></div></nav>';
}
function management_list_dialog(string $id, string $title, array $fields): string {
    $e = 'management_list_escape';
    $out = '<dialog id="' . $e($id) . '" class="management-details-dialog" aria-labelledby="' . $e($id) . 'Title"><header><h2 id="' . $e($id) . 'Title" data-compact-field="title">' . $e($title) . '</h2><button type="button" class="management-dialog-close" data-compact-close autofocus>Close</button></header><dl>';
    foreach ($fields as $key => $label) {
        $out .= '<div' . (in_array($key, ['recipe','notes'], true) ? ' class="management-detail-wide"' : '') . '><dt>' . $e($label) . '</dt><dd data-compact-field="' . $e($key) . '"></dd></div>';
    }
    return $out . '</dl></dialog>';
}
/** A display-only recipe preview; use shared size/temperature rules and default packaging IDs. */
function management_item_recipe(array $item, array $inventory, array $recipe): string {
    $materials = []; $configured = []; $lines = []; $ingredients = 0;
    foreach ($inventory as $material) $materials[(int)$material['id']] = $material;
    foreach ($recipe as $id => $quantity) {
        if ((float)$quantity <= 0) continue;
        if (!isset($materials[(int)$id])) { $lines[] = 'Material #' . (int)$id . ': unavailable; review this recipe.'; continue; }
        $material = $materials[(int)$id];
        $label = $material['name'] . (!empty($material['cup_size']) ? ' (' . $material['cup_size'] . ')' : '');
        $line = $label . ': ' . inventory_service_format_quantity((float)$quantity) . ' ' . $material['unit'];
        if (!inventory_service_recipe_material_matches($material, (string)$item['cup_size'], (string)$item['name'])) {
            $lines[] = $line . ' (does not match this serving size; review recipe)';
            continue;
        }
        $lines[] = $line;
        $kind = inventory_service_packaging_kind($material);
        if ($kind !== null) $configured[$kind] = true;
        if (($material['material_type'] ?? '') === 'raw_material') $ingredients++;
    }
    if (!$ingredients) array_unshift($lines, 'No ingredients configured. Review the recipe before selling.');
    $size = item_variant_inventory_cup_size((string)$item['cup_size']);
    if ($size !== null) {
        ksort($materials, SORT_NUMERIC);
        $isHot = item_variant_is_hot((string)$item['cup_size'], (string)$item['name']);
        foreach (['cup','lid',$isHot ? 'stirrer' : 'straw'] as $kind) {
            if (isset($configured[$kind])) continue;
            $selected = null;
            foreach ($materials as $material) {
                if (inventory_service_packaging_kind($material) === $kind && inventory_service_recipe_material_matches($material, (string)$item['cup_size'], (string)$item['name'])) { $selected = $material; break; }
            }
            if ($selected) $lines[] = $selected['name'] . (!empty($selected['cup_size']) ? ' (' . $selected['cup_size'] . ')' : '') . ': 1 ' . $selected['unit'] . ' (automatic)';
            else $lines[] = ucfirst($kind) . ' ' . $size . ': missing from inventory';
        }
    }
    return implode("\n", $lines);
}
