<?php
require_once __DIR__ . '/inventory_requirements.php';
require_once __DIR__ . '/material_catalog.php';

/** The inventory workspace and its refresh endpoint use the same recipe records as Edit Item. */
function inventory_recipe_editor_data(PDO $pdo): array
{
    $recipes = [];
    foreach ($pdo->query('SELECT item_id, inventory_id, quantity_needed FROM item_requirements')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recipes[(int) $row['item_id']][(int) $row['inventory_id']] = $row['quantity_needed'];
    }
    $products = [];
    $items = $pdo->query("SELECT i.id, i.name, i.category_id, i.cup_size, c.name AS category, rm.source AS recipe_source, rm.notes AS recipe_notes FROM items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN item_recipe_metadata rm ON rm.item_id = i.id WHERE i.cup_size IN ('12oz Hot', '16oz', '22oz', '16oz Cold', '22oz Cold') ORDER BY c.name, i.name, FIELD(i.cup_size, '12oz Hot', '16oz', '16oz Cold', '22oz', '22oz Cold'), i.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $key = $item['category_id'] . '-' . hash('sha256', $item['name']);
        if (!isset($products[$key])) {
            $products[$key] = ['key' => $key, 'name' => $item['name'], 'category_id' => (int) ($item['category_id'] ?? 0), 'category' => $item['category'] ?? 'Other', 'variants' => []];
        }
        $products[$key]['variants'][] = ['id' => (int) $item['id'], 'cup_size' => $item['cup_size'], 'requirements' => $recipes[(int) $item['id']] ?? [], 'recipe_source' => $item['recipe_source'] ?? 'custom', 'recipe_notes' => $item['recipe_notes']];
    }
    $materials = $pdo->query('SELECT id, sku, name, cup_size, unit, material_type, material_group FROM inventory ORDER BY material_type, name, cup_size, id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($materials as &$material) {
        $material['id'] = (int) $material['id'];
        $material['material_group_label'] = inventory_material_group_label($material['material_group']);
        $material['recipe_size'] = item_variant_inventory_cup_size($material['cup_size']) ?? '';
        $material['recipe_kind'] = inventory_service_packaging_kind($material) ?? '';
    }
    unset($material);
    return ['products' => array_values($products), 'materials' => $materials, 'material_groups' => inventory_material_groups()];
}