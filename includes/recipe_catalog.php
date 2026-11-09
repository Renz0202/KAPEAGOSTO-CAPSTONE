<?php
require_once __DIR__ . '/material_catalog.php';
require_once __DIR__ . '/inventory_requirements.php';

/** Quantities are dry weight or usable liquid volume per serving, never purchase packs. */
function inventory_generated_recipe(string $name, string $cupSize): ?array
{
    $size = item_variant_inventory_cup_size($cupSize);
    $index = array_search($size, ['12oz Hot','16oz','22oz'], true);
    if ($index === false) return null;
    $name = strtoupper(trim(preg_replace('/^HOT\s+/i', '', trim($name))));
    $profiles = [
        'ENERO BRUSKO' => ['americano', [], 'Assumed black Americano. Add brewing/dilution water separately; water is not inventory-tracked.'],
        'PEBRERO CARAMELO' => ['latte', ['CARAMEL-SYRUP'=>[20,25,35]], 'Caramel latte.'],
        'MARSO MACCHIATO' => ['latte', ['VANILLA-SYRUP'=>[10,15,20],'CARAMEL-SYRUP'=>[10,15,20]], 'Vanilla milk with espresso and caramel drizzle.'],
        'ABRIL VANILLA' => ['latte', ['VANILLA-SYRUP'=>[20,25,35]], 'Vanilla latte.'],
        'HULYO MATCHA FUSION' => ['latte', ['CEREMONIAL-MATCHA-POWDER'=>[2,3,4],'VANILLA-SYRUP'=>[10,10,15]], 'Espresso and matcha fusion, following its Espresso Based category.'],
        'AGOSTO DARK MOCHA' => ['latte', ['DARK-CHOCOLATE-SAUCE'=>[25,30,40]], 'Dark chocolate mocha.'],
        'AGOSTO WHITE MOCHA' => ['latte', ['WHITE-CHOCOLATE-SAUCE'=>[25,30,40]], 'White chocolate mocha.'],
        'OKTUBRE ESPANYOL' => ['latte', ['CONDENSED-MILK'=>[25,30,40]], 'Spanish latte with condensed milk.'],
        'NOBYEMBRE JELLO' => ['latte', ['JELLO-POWDER'=>[0,3,4],'CONDENSED-MILK'=>[0,15,20]], 'Jello grams are dry powder allocated from a prepared jelly batch, not finished jelly weight. Review the actual brand yield.'],
        'MAYO MILO' => ['milk', ['MILO-POWDER'=>[30,35,50]], 'Milo milk drink; the stocked Milo formulation determines final sweetness.'],
        'HUNYO MATCHA LATTE' => ['milk', ['CEREMONIAL-MATCHA-POWDER'=>[3,4,5],'VANILLA-SYRUP'=>[10,10,15]], 'Pure matcha powder; whisk with preparation water before adding milk.'],
        "SEPTEMBERRIES N' CREAM" => ['milk', ['STRAWBERRY-SYRUP'=>[0,30,45],'HEAVY-CREAM'=>[0,25,35]], 'Strawberry milk with cream; heavy cream is measured before whipping.'],
        'DECEMBERRY MATCHA' => ['milk', ['CEREMONIAL-MATCHA-POWDER'=>[0,4,5],'STRAWBERRY-SYRUP'=>[0,25,35]], 'Assumed strawberry matcha; confirm this signature berry flavor with the cafe.'],
        'SUMMER UBE LATTE' => ['milk', ['UBE-FLAVOR'=>[25,30,40]], 'Assumes liquid ube flavor in ml; jam or powder needs a separate recipe and inventory unit.'],
        'DIRTY SUMMER UBE' => ['latte', ['UBE-FLAVOR'=>[25,30,40]], 'Ube milk with espresso. Assumes liquid ube flavor in ml.'],
        'MIDNIGHT MUSCOVADO' => ['latte', ['MUSCOVADO-SUGAR'=>[15,20,30]], 'Muscovado is dry sugar in g, not prepared syrup volume.'],
        'COFFEE CARAMEL' => ['coffee_blended', ['CARAMEL-SYRUP'=>[0,0,35]], 'Coffee caramel frappe.'],
        'CHOCO BLANCO' => ['coffee_blended', ['WHITE-CHOCOLATE-SAUCE'=>[0,0,35]], 'Coffee white chocolate frappe, following its Coffee category.'],
        'BISCOFFEE' => ['coffee_blended', ['BISCOFF-SPREAD'=>[0,0,30],'LOTUS-BISCOFF-BISCUITS'=>[0,0,8]], 'Coffee Biscoff frappe; biscuits and spread are separate materials.'],
        'DARK JAVA' => ['blended', ['DARK-CHOCOLATE-SAUCE'=>[0,0,45]], 'Chocolate frappe without espresso, following its current Non-Coffee category; confirm the house recipe.'],
        'MATCHA LAVA' => ['blended', ['CEREMONIAL-MATCHA-POWDER'=>[0,0,5],'WHITE-CHOCOLATE-SAUCE'=>[0,0,20]], 'Assumed matcha and white chocolate frappe.'],
        'MILOSAUR' => ['blended', ['MILO-POWDER'=>[0,0,60]], 'Milo frappe; powder includes the topping allowance.'],
        'BISCOFF CARAMEL' => ['blended', ['BISCOFF-SPREAD'=>[0,0,25],'LOTUS-BISCOFF-BISCUITS'=>[0,0,8],'CARAMEL-SYRUP'=>[0,0,20]], 'Biscoff caramel frappe without espresso.'],
        'VANILLA OREO' => ['blended', ['VANILLA-SYRUP'=>[0,0,20],'OREO-BISCUITS'=>[0,0,25]], 'Vanilla cookies and cream frappe.'],
        'OREO MATCHA' => ['blended', ['CEREMONIAL-MATCHA-POWDER'=>[0,0,5],'OREO-BISCUITS'=>[0,0,25]], 'Matcha cookies and cream frappe.'],
        'STRAWBERRY MILKSHAKE' => ['blended', ['STRAWBERRY-SYRUP'=>[0,0,40],'VANILLA-SYRUP'=>[0,0,10]], 'Strawberry milk blend using the available frappe base; no unstocked ice cream is assumed.'],
        'BLUEBERRY CHEESEBAR' => ['blended', ['BLUEBERRY-SYRUP'=>[0,0,35],'CREAM-CHEESE'=>[0,0,25],'GRAHAM-CRACKERS'=>[0,0,15]], 'Blueberry cheesecake blend; assumes actual cream cheese in g, not cheese foam powder.'],
        'WINTER BLUE SODA' => ['soda', ['BLUE-CURACAO-SYRUP'=>[0,30,45]], 'Blue-flavored soda.'],
        'SPRING KIWI SODA' => ['soda', ['KIWI-SYRUP'=>[0,30,45]], 'Kiwi soda.'],
        'SUNNY PASSION SODA' => ['soda', ['PASSION-FRUIT-SYRUP'=>[0,30,45]], 'Passion fruit soda.'],
        'BERRY SEASON FIZ' => ['soda', ['STRAWBERRY-SYRUP'=>[0,20,30],'BLUEBERRY-SYRUP'=>[0,10,15]], 'Assumed strawberry and blueberry soda blend.'],
        'APPLE BOREALIS FIZ' => ['soda', ['GREEN-APPLE-SYRUP'=>[0,30,45]], 'Green apple soda.'],
        'PEACH MIST SPRITZ' => ['soda', ['PEACH-SYRUP'=>[0,30,45]], 'Peach soda.'],
        'GREEN APPLE TEA' => ['tea', ['GREEN-APPLE-SYRUP'=>[0,30,45]], 'Green apple fruit tea.'],
        'BLUEBERRY TEA' => ['tea', ['BLUEBERRY-SYRUP'=>[0,30,45]], 'Blueberry fruit tea.'],
        'STRAWBERRY TEA' => ['tea', ['STRAWBERRY-SYRUP'=>[0,30,45]], 'Strawberry fruit tea.'],
        'KIWI FRUIT TEA' => ['tea', ['KIWI-SYRUP'=>[0,30,45]], 'Kiwi fruit tea.'],
        'PASSION FRUIT TEA' => ['tea', ['PASSION-FRUIT-SYRUP'=>[0,30,45]], 'Passion fruit tea.'],
        'HONEY PEACH TEA' => ['tea', ['PEACH-SYRUP'=>[0,20,30],'HONEY'=>[0,10,15]], 'Peach and honey fruit tea.'],
    ];
    if (!isset($profiles[$name])) return null;
    [$family,$flavors,$note] = $profiles[$name];
    if ($index === 0 && (in_array($family,['tea','soda','blended','coffee_blended'],true) || isset($flavors['JELLO-POWDER']) || in_array($name,["SEPTEMBERRIES N' CREAM",'DECEMBERRY MATCHA'],true))) return null;
    if (in_array($family,['blended','coffee_blended'],true) && $index!==2) return null;
    // Independent serving formulas: espresso is not scaled with cup volume.
    $base = match ($family) {
        'americano' => ['COFFEE-BEANS'=>[18,18,22],'ICE'=>[0,160,220]],
        'latte' => ['COFFEE-BEANS'=>[18,18,22],'FRESH-MILK'=>[240,150,220],'ICE'=>[0,160,220]],
        'milk' => ['FRESH-MILK'=>[260,180,260],'ICE'=>[0,160,220]],
        'tea' => ['TEA-BASE'=>[0,220,320],'ICE'=>[0,180,240]],
        'soda' => ['SODA-WATER'=>[0,230,330],'ICE'=>[0,180,240]],
        'coffee_blended' => ['COFFEE-BEANS'=>[0,18,22],'FRESH-MILK'=>[0,150,160],'FRAPPE-BASE-POWDER'=>[0,20,30],'ICE'=>[0,180,250],'HEAVY-CREAM'=>[0,15,20]],
        'blended' => ['FRESH-MILK'=>[0,180,200],'FRAPPE-BASE-POWDER'=>[0,20,30],'ICE'=>[0,180,250],'HEAVY-CREAM'=>[0,15,20]],
    };
    $materials = [];
    foreach (array_merge($base,$flavors) as $sku=>$quantities) {
        if ($quantities[$index]>0) $materials['INV-'.$sku]=(float)$quantities[$index];
    }
    $materials['INV-NAPKINS']=1.0;
    if ($index===0) $materials['INV-CUP-SLEEVES']=1.0;
    elseif (in_array($family,['blended','coffee_blended'],true) || $name==='NOBYEMBRE JELLO') $materials['INV-WIDE-STRAW']=1.0;
    $note = 'Generated starter estimate. '.$note;
    if ($family==='tea') $note.=' Tea base means ready-to-serve brewed tea, not concentrate.';
    if (isset($base['COFFEE-BEANS'])) $note.=' Coffee quantity is dry beans, not espresso output.';
    if ($family!=='tea' && $family!=='soda' && $family!=='americano') $note.=' Preparation water is not inventory-tracked.';
    return ['materials'=>$materials,'notes'=>$note,'family'=>$family];
}

/** Populate missing recipes and the explicitly requested legacy placeholder only. */
function inventory_seed_generated_recipes(PDO $pdo, bool $replacePlaceholder=false): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Recipe generation requires a transaction.');
    $items=$pdo->query('SELECT id,name,cup_size FROM items ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $inventory=$pdo->query('SELECT id,sku,name,unit,material_type,cup_size FROM inventory ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $bySku=[];
    foreach($inventory as $material) $bySku[$material['sku']]=$material;
    $saved=[];
    foreach($pdo->query('SELECT item_id,inventory_id,quantity_needed FROM item_requirements')->fetchAll(PDO::FETCH_ASSOC) as $row) $saved[(int)$row['item_id']][(int)$row['inventory_id']]=(float)$row['quantity_needed'];
    $sources=$pdo->query('SELECT item_id,source FROM item_recipe_metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
    $units=[];
    foreach(inventory_material_catalog() as $material) $units['INV-'.str_replace(' ','-',inventory_material_name_key($material['name']))]=$material['unit'];
    $pending=[]; $preserved=[]; $unsupported=[];
    foreach($items as $item) {
        $id=(int)$item['id'];
        $suggestion=inventory_generated_recipe($item['name'],$item['cup_size']);
        if ($suggestion===null) { $unsupported[]=$item; continue; }
        $current=$saved[$id]??[];
        $milkId=(int)($bySku['INV-FRESH-MILK']['id']??0);
        $placeholder=$replacePlaceholder && !isset($sources[$id]) && $item['name']==='NOBYEMBRE JELLO' && $item['cup_size']==='22oz' && $current===[$milkId=>3.0];
        if ((isset($sources[$id]) || $current!==[]) && !$placeholder) { $preserved[]=$item; continue; }
        $recipe=[];
        foreach($suggestion['materials'] as $sku=>$quantity) {
            $material=$bySku[$sku]??null;
            if (!$material) throw new RuntimeException('Missing recipe material: '.$sku);
            if ($material['unit']!==($units[$sku]??'')) throw new RuntimeException('Review the inventory unit for '.$material['name'].' before generating recipes.');
            $recipe[(int)$material['id']]=$quantity;
        }
        $recipe=inventory_service_validate_recipe($pdo,$recipe,$item['cup_size'],$item['name']);
        $pending[]=['item'=>$item,'recipe'=>$recipe,'notes'=>$suggestion['notes'],'replaced_placeholder'=>$placeholder];
    }
    foreach($pending as $entry) inventory_service_save_recipe($pdo,(int)$entry['item']['id'],$entry['recipe'],'generated',$entry['notes']);
    return ['generated'=>$pending,'preserved'=>$preserved,'unsupported'=>$unsupported];
}