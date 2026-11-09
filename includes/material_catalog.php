<?php
require_once __DIR__ . '/item_variant.php';

function inventory_material_groups(): array
{
    return [
        'coffee_tea' => 'Coffee & Tea Bases',
        'dairy' => 'Dairy, Milk & Alternative Bases',
        'flavorings' => 'Syrups, Sauces & Flavorings',
        'powders' => 'Powders & Mixers',
        'soda' => 'Soda & Carbonation',
        'toppings' => 'Toppings, Mix-ins & Biscuits',
        'packaging' => 'Packaging & Consumables',
    ];
}

/** Initial names and units can be edited before restocking or assigning a recipe. */
function inventory_material_catalog(): array
{
    $definitions = [
        ['coffee_tea', 'COFFEE BEANS', 'g', ['ESPRESSO BEANS', 'GROUND COFFEE']],
        ['coffee_tea', 'CEREMONIAL MATCHA POWDER', 'g', ['MATCHA POWDER']],
        ['coffee_tea', 'TEA BASE', 'ml', ['TEA EXTRACT', 'GREEN TEA BASE', 'BLACK TEA BASE', 'JASMINE TEA BASE']],
        ['dairy', 'FRESH MILK', 'ml', ['FULL CREAM MILK', 'FRESH / FULL CREAM MILK']],
        ['dairy', 'OAT MILK', 'ml', []],
        ['dairy', 'CONDENSED MILK', 'ml', ['SWEETENED CREAMER']],
        ['dairy', 'HEAVY CREAM', 'ml', ['WHIPPING CREAM', 'CREAMER']],
        ['dairy', 'CREAM CHEESE', 'g', ['CHEESE FOAM POWDER']],
        ['flavorings', 'CARAMEL SYRUP', 'ml', ['CARAMEL SAUCE']],
        ['flavorings', 'VANILLA SYRUP', 'ml', ['VANILLA EXTRACT']],
        ['flavorings', 'DARK CHOCOLATE SAUCE', 'ml', ['MOCHA SAUCE', 'DARK CHOCOLATE', 'CHOCOLATE SAUCE']],
        ['flavorings', 'WHITE CHOCOLATE SAUCE', 'ml', ['WHITE CHOCOLATE']],
        ['flavorings', 'UBE FLAVOR', 'ml', ['UBE SYRUP', 'UBE JAM']],
        ['flavorings', 'MUSCOVADO SUGAR', 'g', ['MUSCOVADO SYRUP']],
        ['flavorings', 'BLUE CURACAO SYRUP', 'ml', ['BLUE RASPBERRY SYRUP', 'BLUE CURACAO']],
        ['flavorings', 'GREEN APPLE SYRUP', 'ml', []],
        ['flavorings', 'BLUEBERRY SYRUP', 'ml', ['BLUEBERRY JAM']],
        ['flavorings', 'STRAWBERRY SYRUP', 'ml', ['STRAWBERRY JAM', 'STRAWBERRY PUREE']],
        ['flavorings', 'KIWI SYRUP', 'ml', ['KIWI CONCENTRATE']],
        ['flavorings', 'PASSION FRUIT SYRUP', 'ml', ['PASSION FRUIT PUREE']],
        ['flavorings', 'PEACH SYRUP', 'ml', ['PEACH PUREE']],
        ['flavorings', 'HONEY', 'ml', []],
        ['powders', 'MILO POWDER', 'g', ['MILO']],
        ['powders', 'FRAPPE BASE POWDER', 'g', ['VANILLA POWDER']],
        ['powders', 'JELLO POWDER', 'g', ['GELATIN POWDER', 'PREPPED JELLY', 'JELLY']],
        ['soda', 'SODA WATER', 'ml', ['CARBONATED WATER', 'SPARKLING WATER']],
        ['toppings', 'LOTUS BISCOFF BISCUITS', 'g', ['BISCOFF BISCUITS']],
        ['toppings', 'BISCOFF SPREAD', 'g', []],
        ['toppings', 'OREO BISCUITS', 'g', ['CRUSHED OREOS', 'OREOS']],
        ['toppings', 'GRAHAM CRACKERS', 'g', ['CRUSHED GRAHAMS']],
        ['toppings', 'ICE', 'g', []],
        ['packaging', 'STRAW', 'pcs', ['REGULAR STRAW', 'REGULAR STRAWS']],
        ['packaging', 'WIDE STRAW', 'pcs', ['BOBA STRAW', 'WIDE STRAWS', 'BOBA STRAWS']],
        ['packaging', 'NAPKINS', 'pcs', ['NAPKIN']],
        ['packaging', 'CUP SLEEVES', 'pcs', ['CUP SLEEVE']],
        ['packaging', 'COFFEE STIRRER', 'pcs', ['STIRRER']],
    ];
    $catalog = [];
    foreach ($definitions as [$group, $name, $unit, $aliases]) {
        $catalog[] = ['name' => $name, 'unit' => $unit, 'material_group' => $group, 'material_type' => $group === 'packaging' ? 'packaging' : 'raw_material', 'cup_size' => null, 'aliases' => $aliases];
    }
    foreach (['CUP' => ['CUPS', 'HOT CUP', 'COLD CUP', 'PAPER CUP', 'PLASTIC CUP'], 'LID' => ['LIDS', 'HOT LID', 'COLD LID', 'FLAT LID', 'CUP LID']] as $name => $aliases) {
        foreach (['12oz Hot', '16oz', '22oz'] as $size) {
            $catalog[] = ['name' => $name, 'unit' => 'pcs', 'material_group' => 'packaging', 'material_type' => 'packaging', 'cup_size' => $size, 'aliases' => $aliases];
        }
    }
    return $catalog;
}

function inventory_material_name_key(string $name): string
{
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(trim($name))));
}

function inventory_material_group_for_name(string $name, string $type): ?string
{
    if ($type === 'packaging') return 'packaging';
    $key = inventory_material_name_key($name);
    foreach (inventory_material_catalog() as $material) {
        if ($material['material_type'] === 'packaging') continue;
        foreach (array_merge([$material['name']], $material['aliases']) as $alias) {
            if ($key === inventory_material_name_key($alias)) return $material['material_group'];
        }
    }
    return null;
}

function inventory_material_group_from_post($posted, string $name, string $type): ?string
{
    if (!is_scalar($posted) && $posted !== null) throw new InvalidArgumentException('Choose a valid material group.');
    $group = trim((string) $posted);
    if ($group !== '' && !isset(inventory_material_groups()[$group])) throw new InvalidArgumentException('Choose a valid material group.');
    if ($type === 'packaging') return 'packaging';
    if ($group === 'packaging') throw new InvalidArgumentException('Choose an ingredient group, or select Packaging as the inventory type.');
    return $group !== '' ? $group : inventory_material_group_for_name($name, $type);
}

function inventory_material_group_label(?string $group): string
{
    return inventory_material_groups()[$group ?? ''] ?? 'Other / Ungrouped';
}

function inventory_material_backfill_groups(PDO $pdo): int
{
    $changed = 0;
    $update = $pdo->prepare('UPDATE inventory SET material_group = ? WHERE id = ? AND material_group IS NULL');
    foreach ($pdo->query('SELECT id,name,material_type FROM inventory WHERE material_group IS NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $group = inventory_material_group_for_name($row['name'], $row['material_type']);
        if ($group !== null) {
            $update->execute([$group, $row['id']]);
            $changed += $update->rowCount();
        }
    }
    return $changed;
}

/** Add missing catalog entries at zero stock. No existing balances, units, names, or recipes are changed. */
function inventory_material_seed_catalog(PDO $pdo): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Material setup requires a transaction.');
    $existing = $pdo->query('SELECT id,sku,name,material_type,cup_size FROM inventory ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $skus = array_fill_keys(array_map(static fn($row) => strtoupper($row['sku']), $existing), true);
    $insert = $pdo->prepare('INSERT INTO inventory (sku,name,material_type,material_group,cup_size,unit,qty,critical_level,date_added) VALUES (?,?,?,?,?,?,0,NULL,NOW())');
    $added = [];
    $reused = [];
    foreach (inventory_material_catalog() as $material) {
        $names = array_map('inventory_material_name_key', array_merge([$material['name']], $material['aliases']));
        $found = null;
        foreach ($existing as $row) {
            if ($row['material_type'] === $material['material_type'] && item_variant_inventory_cup_size($row['cup_size']) === $material['cup_size'] && in_array(inventory_material_name_key($row['name']), $names, true)) {
                $found = $row;
                break;
            }
        }
        if ($found) { $reused[] = (int) $found['id']; continue; }
        $base = 'INV-' . str_replace(' ', '-', inventory_material_name_key($material['name']));
        if ($material['cup_size'] !== null) $base .= '-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($material['cup_size']));
        $sku = substr($base, 0, 64);
        for ($suffix = 2; isset($skus[strtoupper($sku)]); $suffix++) $sku = substr($base, 0, 58) . '-' . $suffix;
        $insert->execute([$sku, $material['name'], $material['material_type'], $material['material_group'], $material['cup_size'], $material['unit']]);
        $row = ['id' => (int) $pdo->lastInsertId(), 'sku' => $sku] + $material;
        $existing[] = $row;
        $skus[strtoupper($sku)] = true;
        $added[] = $row;
    }
    return ['added' => $added, 'reused' => $reused];
}