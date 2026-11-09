<?php

require_once __DIR__ . '/item_variant.php';

function inventory_service_packaging_kind(array $inventory): ?string
{
    if (($inventory['material_type'] ?? '') !== 'packaging') {
        return null;
    }
    if (preg_match('/\bcup\s+sleeves?\b/i', (string) $inventory['name'])) return null;
    foreach (['cup' => 'cups?', 'lid' => 'lids?|covers?', 'straw' => 'straws?', 'stirrer' => 'stirrers?'] as $kind => $pattern) {
        if (preg_match('/\b(' . $pattern . ')\b/i', (string) $inventory['name'])) {
            return $kind;
        }
    }
    return null;
}

/** Ingredients are shared; packaging must fit the selected serving size and temperature. */
function inventory_service_recipe_material_matches(array $inventory, string $cupSize, string $itemName = ''): bool
{
    if (($inventory['material_type'] ?? '') !== 'packaging') {
        return true;
    }
    $selectedSize = item_variant_inventory_cup_size($cupSize);
    $materialSize = item_variant_inventory_cup_size($inventory['cup_size'] ?? null);
    $kind = inventory_service_packaging_kind($inventory);
    if ($materialSize !== null && $materialSize !== $selectedSize) {
        return false;
    }
    if (in_array($kind, ['cup', 'lid'], true) && ($materialSize === null || $selectedSize === null)) {
        return false;
    }
    $isHot = item_variant_is_hot($cupSize, $itemName);
    return !($kind === 'straw' && $isHot) && !($kind === 'stirrer' && !$isHot);
}

function inventory_service_find_packaging(PDO $pdo, string $kind, string $cupSize = '', string $itemName = ''): ?array
{
    $patterns = ['cup' => 'cups?', 'lid' => 'lids?|covers?', 'straw' => 'straws?', 'stirrer' => 'stirrers?'];
    if (!isset($patterns[$kind])) {
        throw new InvalidArgumentException('Unknown packaging type.');
    }
    $stmt = $pdo->prepare("SELECT id, name, cup_size, qty, unit, critical_level, material_type FROM inventory WHERE material_type = 'packaging' AND LOWER(name) REGEXP ? ORDER BY id");
    $stmt->execute(['(^|[^a-z])(' . $patterns[$kind] . ')([^a-z]|$)']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $inventory) {
        if (inventory_service_packaging_kind($inventory) === $kind && inventory_service_recipe_material_matches($inventory, $cupSize, $itemName)) {
            return $inventory;
        }
    }
    return null;
}

// Checkout, order cards, and completion must calculate the same combined totals.
function inventory_service_requirements(PDO $pdo, array $lines): array
{
    $requirements = [];
    $catalog = $pdo->prepare('SELECT id, name, cup_size FROM items WHERE id = ?');
    $explicit = $pdo->prepare('SELECT ir.inventory_id, ir.quantity_needed, inv.name, inv.cup_size, inv.unit, inv.qty, inv.critical_level, inv.material_type FROM item_requirements ir JOIN inventory inv ON inv.id = ir.inventory_id WHERE ir.item_id = ? ORDER BY inv.id');
    $add = static function (array $row, float $quantity, string $source) use (&$requirements): void {
        $id = (int) ($row['inventory_id'] ?? $row['id']);
        if (!isset($requirements[$id])) {
            $requirements[$id] = $row + ['inventory_id' => $id, 'quantity' => 0.0, 'source' => $source];
        }
        $requirements[$id]['quantity'] = round($requirements[$id]['quantity'] + $quantity, 2);
    };
    foreach ($lines as $line) {
        $itemId = (int) ($line['item_id'] ?? $line['id'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        $catalog->execute([$itemId]);
        $item = $catalog->fetch(PDO::FETCH_ASSOC);
        if (!$item || $qty <= 0) {
            throw new InvalidArgumentException('Invalid menu item or order quantity.');
        }
        $explicit->execute([$itemId]);
        $configuredPackaging = [];
        foreach ($explicit->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((float) $row['quantity_needed'] <= 0) {
                throw new RuntimeException('Invalid recipe quantity for ' . $item['name'] . '.');
            }
            if (!inventory_service_recipe_material_matches($row, (string) $item['cup_size'], (string) $item['name'])) {
                throw new RuntimeException('Recipe for ' . $item['name'] . ' (' . $item['cup_size'] . ') contains incompatible packaging: ' . $row['name'] . (!empty($row['cup_size']) ? ' (' . $row['cup_size'] . ')' : '') . '. Edit the item recipe to match its cup size.');
            }
            $add($row, (float) $row['quantity_needed'] * $qty, 'recipe');
            $kind = inventory_service_packaging_kind($row);
            if ($kind !== null) {
                $configuredPackaging[$kind] = true;
            }
        }
        $cupSize = item_variant_inventory_cup_size((string) $item['cup_size']);
        if ($cupSize === null) {
            continue;
        }
        $isHot = item_variant_is_hot((string) $item['cup_size'], (string) $item['name']);
        foreach (['cup', 'lid', $isHot ? 'stirrer' : 'straw'] as $kind) {
            if (isset($configuredPackaging[$kind])) {
                continue;
            }
            $inventory = inventory_service_find_packaging($pdo, $kind, (string) $item['cup_size'], (string) $item['name']);
            if (!$inventory) {
                $key = 'missing-' . $kind . '-' . $cupSize;
                if (!isset($requirements[$key])) {
                    $requirements[$key] = ['inventory_id' => null, 'name' => ucfirst($kind) . ' ' . $cupSize, 'quantity' => 0.0, 'unit' => 'pcs', 'source' => 'packaging'];
                }
                $requirements[$key]['quantity'] += $qty;
                continue;
            }
            $add($inventory, (float) $qty, 'packaging');
        }
    }
    // Lock shared inventory in the same order for every completed order.
    ksort($requirements, SORT_NATURAL);
    return array_values($requirements);
}

function inventory_service_order_requirements(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT item_id, qty FROM order_items WHERE order_id = ? ORDER BY id');
    $stmt->execute([$orderId]);
    return inventory_service_requirements($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function inventory_service_format_quantity(float $quantity): string
{
    return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
}

function inventory_service_stock_warnings(PDO $pdo, array $lines): array
{
    $requirements = inventory_service_requirements($pdo, $lines);
    $blocking = [];
    $low = [];
    foreach ($requirements as $row) {
        $label = $row['name'] . (!empty($row['cup_size']) ? ' (' . $row['cup_size'] . ')' : '');
        if (empty($row['inventory_id'])) {
            $blocking[] = $label . ' missing';
        } elseif ((float) $row['qty'] + 0.00001 < (float) $row['quantity']) {
            $blocking[] = $label . ': needs ' . inventory_service_format_quantity((float) $row['quantity']) . ' ' . $row['unit'] . ', available ' . inventory_service_format_quantity((float) $row['qty']) . ' ' . $row['unit'];
        } elseif ((float) ($row['critical_level'] ?? 0) > 0 && (float) $row['qty'] <= (float) $row['critical_level']) {
            $low[] = $label;
        }
    }
    return ['blocking' => $blocking, 'low' => $low, 'requirements' => $requirements];
}

function inventory_service_validate_recipe(PDO $pdo, array $posted, ?string $cupSize = null, string $itemName = ''): array
{
    $recipe = [];
    $exists = $pdo->prepare('SELECT id, name, cup_size, material_type FROM inventory WHERE id = ?');
    foreach ($posted as $id => $quantity) {
        if ($quantity === '' || $quantity === null || (is_numeric($quantity) && (float) $quantity === 0.0)) {
            continue;
        }
        if (!ctype_digit((string) $id) || (int) $id <= 0 || !is_numeric($quantity) || !is_finite((float) $quantity) || (float) $quantity < 0.01 || (float) $quantity > 99999999.99 || abs(round((float) $quantity, 2) - (float) $quantity) > 0.000001) {
            throw new InvalidArgumentException('Recipe quantities must be positive numbers with at most two decimal places.');
        }
        $exists->execute([(int) $id]);
        $material = $exists->fetch(PDO::FETCH_ASSOC);
        if (!$material) {
            throw new InvalidArgumentException('A recipe material no longer exists. Reload the page and try again.');
        }
        if ($cupSize !== null && !inventory_service_recipe_material_matches($material, $cupSize, $itemName)) {
            throw new InvalidArgumentException($material['name'] . (!empty($material['cup_size']) ? ' (' . $material['cup_size'] . ')' : '') . ' does not match this ' . $cupSize . ' recipe. Choose packaging for the selected size and temperature.');
        }
        $recipe[(int) $id] = round((float) $quantity, 2);
    }
    return $recipe;
}

function inventory_service_save_recipe(PDO $pdo, int $itemId, array $recipe, string $source = 'custom', ?string $notes = null): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Recipe changes require a transaction.');
    }
    if (!in_array($source, ['generated', 'custom'], true) || ($notes !== null && strlen($notes) > 500)) {
        throw new InvalidArgumentException('Invalid recipe source or notes.');
    }
    $catalog = $pdo->prepare('SELECT name, cup_size FROM items WHERE id = ? FOR UPDATE');
    $catalog->execute([$itemId]);
    $item = $catalog->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        throw new InvalidArgumentException('The menu item no longer exists.');
    }
    $recipe = inventory_service_validate_recipe($pdo, $recipe, (string) $item['cup_size'], (string) $item['name']);
    $pdo->prepare('DELETE FROM item_requirements WHERE item_id = ?')->execute([$itemId]);
    $insert = $pdo->prepare('INSERT INTO item_requirements (item_id, inventory_id, quantity_needed) VALUES (?, ?, ?)');
    foreach ($recipe as $inventoryId => $quantity) {
        $insert->execute([$itemId, $inventoryId, $quantity]);
    }
    $metadata = $pdo->prepare('INSERT INTO item_recipe_metadata (item_id,source,notes,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE source = VALUES(source), notes = VALUES(notes), updated_at = NOW()');
    $metadata->execute([$itemId, $source, $notes]);
}

function inventory_service_validate_stock_quantity($value, bool $allowZero = true): float
{
    if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < ($allowZero ? 0 : 0.01) || (float) $value > 99999999.99 || abs(round((float) $value, 2) - (float) $value) > 0.000001) {
        throw new InvalidArgumentException('Enter a valid stock quantity with at most two decimal places.');
    }
    return round((float) $value, 2);
}

function inventory_service_checkout_lines(PDO $pdo, array $postedItems): array
{
    if (!$postedItems) throw new InvalidArgumentException('Add at least one product to the order.');
    $catalog = $pdo->prepare("SELECT id, name, cup_size, price FROM items WHERE id = ? AND cup_size IN ('12oz Hot', '16oz', '22oz', '16oz Cold', '22oz Cold')");
    $lines = [];
    foreach ($postedItems as $posted) {
        if (!is_array($posted)) throw new InvalidArgumentException('Invalid order item.');
        $id = $posted['id'] ?? $posted['item_id'] ?? null;
        $qty = $posted['qty'] ?? null;
        if (!ctype_digit((string) $id) || (int) $id <= 0 || !ctype_digit((string) $qty) || (int) $qty <= 0 || (float) $qty > 2147483647) {
            throw new InvalidArgumentException('Order quantities must be positive whole numbers.');
        }
        $catalog->execute([(int) $id]);
        $item = $catalog->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new InvalidArgumentException('A selected product is unavailable. Reload the menu and try again.');
        $lines[] = ['id' => (int) $item['id'], 'name' => $item['name'], 'cup_size' => $item['cup_size'], 'qty' => (int) $qty, 'price' => (float) $item['price']];
    }
    $total = array_sum(array_map(static fn($line) => $line['qty'] * $line['price'], $lines));
    if ($total <= 0 || $total > 99999999.99) throw new InvalidArgumentException('The order total is outside the supported range.');
    return $lines;
}

function inventory_service_assert_stock(PDO $pdo, array $lines): void
{
    $warnings = inventory_service_stock_warnings($pdo, $lines);
    if (!$warnings['requirements']) throw new RuntimeException('No materials are configured for this order.');
    if ($warnings['blocking']) throw new RuntimeException('Insufficient materials: ' . implode('; ', $warnings['blocking']));
}
