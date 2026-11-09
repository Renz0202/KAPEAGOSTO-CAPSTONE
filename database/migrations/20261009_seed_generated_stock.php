<?php
// Opt-in generated opening balances. Default execution previews and rolls back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_service.php';

$arguments = array_slice($argv, 1);
if ($arguments !== [] && $arguments !== ['--apply']) {
    fwrite(STDERR, "Usage: php 20261009_seed_generated_stock.php [--apply]\n");
    exit(1);
}
$apply = $arguments === ['--apply'];
// Quantities are setup estimates, not a physical stock count or a sales forecast.
// Package multiples below are assumptions; purchased brands may use other sizes.
$plan = [
    'INV-COFFEE-BEANS' => [5000, 'g', 'Five 1 kg bags'],
    'INV-CEREMONIAL-MATCHA-POWDER' => [500, 'g', 'Five 100 g packs'],
    'INV-TEA-BASE' => [5000, 'ml', '5 L usable tea base estimate'],
    'INV-OAT-MILK' => [6000, 'ml', 'Six 1 L cartons'],
    'INV-CONDENSED-MILK' => [2000, 'ml', '2 L usable volume estimate'],
    'INV-HEAVY-CREAM' => [2000, 'ml', 'Two 1 L cartons'],
    'INV-CREAM-CHEESE' => [1000, 'g', '1 kg total'],
    'INV-CARAMEL-SYRUP' => [3000, 'ml', 'Four 750 ml bottles'],
    'INV-VANILLA-SYRUP' => [1500, 'ml', 'Two 750 ml bottles'],
    'INV-DARK-CHOCOLATE-SAUCE' => [2000, 'ml', '2 L usable volume estimate'],
    'INV-WHITE-CHOCOLATE-SAUCE' => [2000, 'ml', '2 L usable volume estimate'],
    'INV-UBE-FLAVOR' => [1500, 'ml', 'Two 750 ml bottles'],
    'INV-MUSCOVADO-SUGAR' => [2000, 'g', 'Two 1 kg packs'],
    'INV-BLUE-CURACAO-SYRUP' => [750, 'ml', 'One 750 ml bottle'],
    'INV-GREEN-APPLE-SYRUP' => [750, 'ml', 'One 750 ml bottle'],
    'INV-BLUEBERRY-SYRUP' => [1500, 'ml', 'Two 750 ml bottles'],
    'INV-STRAWBERRY-SYRUP' => [2250, 'ml', 'Three 750 ml bottles'],
    'INV-KIWI-SYRUP' => [750, 'ml', 'One 750 ml bottle'],
    'INV-PASSION-FRUIT-SYRUP' => [750, 'ml', 'One 750 ml bottle'],
    'INV-PEACH-SYRUP' => [750, 'ml', 'One 750 ml bottle'],
    'INV-HONEY' => [1000, 'ml', '1 L usable volume estimate'],
    'INV-MILO-POWDER' => [2000, 'g', 'Two 1 kg packs'],
    'INV-FRAPPE-BASE-POWDER' => [2000, 'g', 'Two 1 kg packs'],
    'INV-JELLO-POWDER' => [500, 'g', '500 g total'],
    'INV-SODA-WATER' => [12000, 'ml', 'Twelve 1 L bottles'],
    'INV-LOTUS-BISCOFF-BISCUITS' => [1000, 'g', '1 kg total'],
    'INV-BISCOFF-SPREAD' => [1600, 'g', 'Four 400 g jars'],
    'INV-OREO-BISCUITS' => [1000, 'g', '1 kg total'],
    'INV-GRAHAM-CRACKERS' => [1000, 'g', '1 kg total'],
    'INV-ICE' => [20000, 'g', '20 kg working stock estimate'],
    'INV-WIDE-STRAW' => [500, 'pcs', 'Five packs of 100'],
    'INV-NAPKINS' => [1000, 'pcs', 'Ten packs of 100'],
    'INV-CUP-SLEEVES' => [200, 'pcs', 'Two packs of 100'],
];
$pdo = db();
$remarks = 'Generated opening stock estimate (2026-10-09); not a physical count.';
$pdo->beginTransaction();
try {
    $before = $pdo->query('SELECT * FROM inventory ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $bySku = [];
    foreach ($before as $row) $bySku[$row['sku']] = $row;
    $recipesBefore = $pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $logCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn();
    $hasHistory = $pdo->prepare('SELECT COUNT(*) FROM inventory_logs WHERE inventory_id = ?');
    $changed = [];
    foreach ($plan as $sku => [$quantity, $unit, $assumption]) {
        $row = $bySku[$sku] ?? null;
        if (!$row) throw new RuntimeException('Missing inventory record: ' . $sku);
        if ($row['unit'] !== $unit) throw new RuntimeException('Unit changed for ' . $sku . '; review the estimate first.');
        $hasHistory->execute([$row['id']]);
        // Never overwrite counted stock or replenish a previously initialized item.
        if ((float) $row['qty'] !== 0.0 || (int) $hasHistory->fetchColumn() !== 0) continue;
        $remaining = inventory_service_adjust_stock($pdo, (int) $row['id'], $quantity, 'restock', null, $remarks);
        if ($remaining !== (float) $quantity) throw new RuntimeException('Unexpected balance for ' . $sku);
        $changed[(int) $row['id']] = ['name' => $row['name'], 'quantity' => $quantity, 'unit' => $unit, 'assumption' => $assumption];
    }
    $after = $pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($after as $index => $row) {
        $expected = $before[$index];
        if (isset($changed[(int) $row['id']])) {
            $expected['qty'] = number_format($changed[(int) $row['id']]['quantity'], 2, '.', '');
            // This timestamp is maintained by the database on any stock movement.
            if (array_key_exists('updated_at', $expected)) $expected['updated_at'] = $row['updated_at'];
        }
        if ($row !== $expected) throw new RuntimeException('Unexpected inventory change for ID ' . $row['id']);
    }
    if ($recipesBefore !== $pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)) throw new RuntimeException('Recipes changed during setup.');
    if ((int) $pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn() !== $logCountBefore + count($changed)) throw new RuntimeException('Unexpected movement log count.');
    if ($apply) $pdo->commit(); else $pdo->rollBack();
    echo ($apply ? 'APPLIED' : 'PREVIEW ONLY - rolled back') . ': ' . count($changed) . ' generated opening balances; ' . (count($before) - count($changed)) . " existing balances preserved.\n";
    foreach ($changed as $row) echo $row['name'] . ': ' . $row['quantity'] . ' ' . $row['unit'] . ' (' . $row['assumption'] . ")\n";
    echo "Recipes and inventory metadata preserved; one labeled restock log per addition.\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
