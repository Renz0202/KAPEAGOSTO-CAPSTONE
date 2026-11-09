<?php
// Every write uses connection-local temporary tables, never live orders or stock.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/inventory_service.php';
require_once dirname(__DIR__).'/includes/recipe_catalog.php';
require_once dirname(__DIR__).'/includes/inventory_recipe_editor.php';
$pdo=db();
foreach([
'inventory'=>'id INT AUTO_INCREMENT PRIMARY KEY,sku VARCHAR(64) UNIQUE,name VARCHAR(100),material_type VARCHAR(20),material_group VARCHAR(40),cup_size VARCHAR(50) NULL,unit VARCHAR(50),qty DECIMAL(10,2),critical_level DECIMAL(10,2) NULL,date_added DATETIME',
'items'=>'id INT PRIMARY KEY,name VARCHAR(100),cup_size VARCHAR(50),category_id INT,price DECIMAL(10,2) DEFAULT 100',
'categories'=>'id INT PRIMARY KEY,name VARCHAR(100)',
'item_requirements'=>'id INT AUTO_INCREMENT PRIMARY KEY,item_id INT,inventory_id INT,quantity_needed DECIMAL(10,2),UNIQUE KEY(item_id,inventory_id)',
'item_recipe_metadata'=>'item_id INT PRIMARY KEY,source VARCHAR(20),notes VARCHAR(500),updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
'orders'=>'id INT PRIMARY KEY,order_code VARCHAR(50),status VARCHAR(20),inventory_deducted_at DATETIME NULL',
'order_items'=>'id INT AUTO_INCREMENT PRIMARY KEY,order_id INT,item_id INT,qty INT',
'inventory_logs'=>'id INT AUTO_INCREMENT PRIMARY KEY,inventory_id INT,order_id INT NULL,user_id INT NULL,change_type VARCHAR(20),quantity DECIMAL(10,2),remaining DECIMAL(10,2),remarks VARCHAR(255),created_at DATETIME',
'notifications'=>'id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NULL,notification_type VARCHAR(50),entity_type VARCHAR(50),entity_id INT,dedupe_key VARCHAR(150) UNIQUE,message TEXT,is_read INT,read_at DATETIME NULL,created_at DATETIME',
] as $table=>$columns)$pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
$items=json_decode(file_get_contents(__DIR__.'/fixtures/recipe_menu_20261009.json'),true,512,JSON_THROW_ON_ERROR);
$insert=$pdo->prepare('INSERT INTO items (id,name,cup_size,category_id) VALUES (?,?,?,?)');
foreach($items as $item)$insert->execute([$item['id'],$item['name'],$item['cup_size'],$item['category_id']]);
$pdo->exec("INSERT INTO categories SELECT DISTINCT category_id,CONCAT('Test Category ',category_id) FROM items");
$pdo->beginTransaction();inventory_material_seed_catalog($pdo);$pdo->commit();
$pdo->exec('UPDATE inventory SET qty=100000,critical_level=1');
$bySku=[];$byId=[];
foreach($pdo->query('SELECT * FROM inventory')->fetchAll(PDO::FETCH_ASSOC) as $row){$bySku[$row['sku']]=$row;$byId[(int)$row['id']]=$row;}
$materialId=static fn($sku)=>(int)$bySku['INV-'.$sku]['id'];
$milk=$materialId('FRESH-MILK');
$pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (37,'.$milk.',3)');
$stockBefore=$pdo->query('SELECT id,qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
$checks=0;
function recipeCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label."\n";}
function recipeRejects(callable $action):bool{try{$action();}catch(RuntimeException|InvalidArgumentException|LogicException $error){return true;}return false;}
function recipeAmounts(array $rows):array{$result=[];foreach($rows as $row)$result[(int)$row['inventory_id']]=(float)$row['quantity'];return $result;}
recipeCheck(recipeRejects(static fn()=>inventory_seed_generated_recipes($pdo)),'Generation requires an enclosing transaction');
$pdo->beginTransaction();$default=inventory_seed_generated_recipes($pdo);recipeCheck(count($default['generated'])===79 && count($default['preserved'])===1,'Default setup preserves existing recipes');$pdo->rollBack();
recipeCheck((int)$pdo->query('SELECT COUNT(*) FROM item_requirements')->fetchColumn()===1 && (int)$pdo->query('SELECT COUNT(*) FROM item_recipe_metadata')->fetchColumn()===0,'Preview rollback preserves both recipes and source metadata');
$pdo->beginTransaction();$result=inventory_seed_generated_recipes($pdo,true);$pdo->commit();
recipeCheck(count($result['generated'])===80 && !$result['unsupported'] && count(array_filter($result['generated'],static fn($entry)=>$entry['replaced_placeholder']))===1,'All 80 current variants receive recipes and only the exact legacy placeholder is replaced');
recipeCheck($stockBefore===$pdo->query('SELECT id,qty FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) && (int)$pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn()===0,'Recipe generation does not move stock or create consumption logs');
recipeCheck((int)$pdo->query("SELECT COUNT(*) FROM item_recipe_metadata WHERE source='generated' AND notes LIKE 'Generated starter estimate.%'")->fetchColumn()===80,'Every generated variant has its own persisted source and preparation notes');
$allFit=true;$hotClean=true;$teaClean=true;$blendedWide=true;
foreach($items as $item){
    $suggestion=inventory_generated_recipe($item['name'],$item['cup_size']);
    $rows=inventory_service_requirements($pdo,[['id'=>$item['id'],'qty'=>1]]);
    $amounts=recipeAmounts($rows);$kinds=[];
    foreach($rows as $row){
        if(empty($row['inventory_id'])){$allFit=false;continue;}
        $material=$byId[(int)$row['inventory_id']];
        $allFit=$allFit && inventory_service_recipe_material_matches($material,$item['cup_size'],$item['name']);
        $kind=inventory_service_packaging_kind($material);
        if($kind)$kinds[$kind]=($kinds[$kind]??0)+(float)$row['quantity'];
    }
    $allFit=$allFit && ($kinds['cup']??0)===1.0 && ($kinds['lid']??0)===1.0;
    if($item['cup_size']==='12oz Hot')$hotClean=$hotClean && !isset($amounts[$materialId('ICE')]) && !isset($kinds['straw']) && ($kinds['stirrer']??0)===1.0 && $amounts[$materialId('CUP-SLEEVES')]===1.0;
    if(in_array($suggestion['family'],['tea','soda'],true))$teaClean=$teaClean && !isset($amounts[$milk]) && !isset($amounts[$materialId('COFFEE-BEANS')]);
    if(in_array($suggestion['family'],['blended','coffee_blended'],true) || $item['name']==='NOBYEMBRE JELLO')$blendedWide=$blendedWide && ($amounts[$materialId('WIDE-STRAW')]??0)===1.0 && !isset($amounts[$materialId('STRAW')]);
}
recipeCheck($allFit,'Every current variant uses one matching cup and lid without incompatible packaging');
recipeCheck($hotClean,'Hot drinks have no ice or straws and use a stirrer and sleeve');
recipeCheck($teaClean,'Fruit teas and sodas consume neither milk nor espresso beans');
recipeCheck($blendedWide,'Blended and jelly drinks use exactly one wide straw instead of a regular straw');
$small=recipeAmounts(inventory_service_requirements($pdo,[['id'=>16,'qty'=>1]]));
$large=recipeAmounts(inventory_service_requirements($pdo,[['id'=>17,'qty'=>1]]));
recipeCheck($small[$milk]===150.0 && $large[$milk]===220.0 && $small[$materialId('COFFEE-BEANS')]===18.0 && $large[$materialId('COFFEE-BEANS')]===22.0,'16oz and 22oz recipes retain independent ingredient and espresso amounts');
$jelly=recipeAmounts(inventory_service_requirements($pdo,[['id'=>37,'qty'=>1]]));
recipeCheck($jelly[$milk]===220.0 && $jelly[$materialId('JELLO-POWDER')]===4.0,'The legacy milk-only jelly recipe becomes a complete recipe with a dry-powder allowance');
$data=inventory_recipe_editor_data($pdo);$variants=[];foreach($data['products'] as $product)foreach($product['variants'] as $variant)$variants[]=$variant;
recipeCheck(count($variants)===80 && count(array_filter($variants,static fn($variant)=>$variant['recipe_source']==='generated' && $variant['recipe_notes']!==''))===80,'Inventory editor loads all generated quantities and variant-specific notes');
$pdo->beginTransaction();$pdo->exec('UPDATE inventory SET qty=148 WHERE id='.$milk);$warning=inventory_service_stock_warnings($pdo,[['id'=>16,'qty'=>1]]);$pdo->rollBack();
recipeCheck(count($warning['blocking'])===1 && str_contains($warning['blocking'][0],'FRESH MILK') && str_contains($warning['blocking'][0],'needs 150'),'Checkout reports the actual milk shortage rather than ignoring generated ingredients');
$lines=[['id'=>16,'qty'=>2],['id'=>86,'qty'=>3]];
$requirements=inventory_service_requirements($pdo,$lines);$expected=recipeAmounts($requirements);
recipeCheck($expected[$milk]===300.0 && $expected[$materialId('TEA-BASE')]===660.0 && $expected[$materialId('BLUEBERRY-SYRUP')]===90.0,'Mixed orders multiply each recipe and aggregate shared stock');
$pdo->exec("INSERT INTO orders VALUES(1,'GENERATED-TEST','pending',NULL)");
$pdo->exec('INSERT INTO order_items(order_id,item_id,qty) VALUES(1,16,2),(1,86,3)');
$pdo->beginTransaction();inventory_service_complete_order($pdo,1);$pdo->commit();
$deducted=true;foreach($pdo->query('SELECT id,qty FROM inventory')->fetchAll(PDO::FETCH_ASSOC) as $row)$deducted=$deducted && (float)$row['qty']===100000.0-($expected[(int)$row['id']]??0);
recipeCheck($deducted && (int)$pdo->query("SELECT COUNT(*) FROM inventory_logs WHERE order_id=1 AND change_type='used'")->fetchColumn()===count($requirements),'Marking a generated-recipe order Done deducts and audits the exact ingredients and packaging');
$logs=(int)$pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn();$pdo->beginTransaction();inventory_service_complete_order($pdo,1);$pdo->commit();
recipeCheck((int)$pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn()===$logs,'Repeating Done on a generated-recipe order cannot deduct twice');
$pdo->beginTransaction();inventory_service_save_recipe($pdo,86,[$materialId('TEA-BASE')=>210,$materialId('BLUEBERRY-SYRUP')=>25,$materialId('ICE')=>180]);inventory_service_save_recipe($pdo,87,[]);$pdo->commit();
$metadata=$pdo->query('SELECT source,notes FROM item_recipe_metadata WHERE item_id=86')->fetch(PDO::FETCH_ASSOC);
recipeCheck($metadata===['source'=>'custom','notes'=>null],'Saving through the shared editor replaces generated provenance with a custom recipe');
$beforeRepeat=$pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$pdo->beginTransaction();$repeat=inventory_seed_generated_recipes($pdo,true);$pdo->commit();
recipeCheck(!$repeat['generated'] && count($repeat['preserved'])===80 && $beforeRepeat===$pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Repeating setup preserves edited recipes and recipes intentionally cleared by an administrator');
$pdo->beginTransaction();$pdo->exec("INSERT INTO items(id,name,cup_size,category_id) VALUES(999,'ABRIL VANILLA','16oz',1)");$pdo->exec("UPDATE inventory SET unit='L' WHERE id=".$milk);$rejected=recipeRejects(static fn()=>inventory_seed_generated_recipes($pdo));$pdo->rollBack();
recipeCheck($rejected && $beforeRepeat===$pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'An incompatible stock unit blocks generation without partial recipe replacement');
$pdo->beginTransaction();$pdo->exec("INSERT INTO items(id,name,cup_size,category_id) VALUES(999,'ABRIL VANILLA','16oz',1)");$pdo->exec("DELETE FROM inventory WHERE sku='INV-VANILLA-SYRUP'");$rejected=recipeRejects(static fn()=>inventory_seed_generated_recipes($pdo));$pdo->rollBack();
recipeCheck($rejected && $beforeRepeat===$pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Missing materials fail atomically instead of producing incomplete recipes');
$pdo->beginTransaction();$pdo->exec("INSERT INTO items(id,name,cup_size,category_id) VALUES(999,'HOUSE SPECIAL UNKNOWN','16oz',1)");$unknown=inventory_seed_generated_recipes($pdo);$pdo->rollBack();
recipeCheck(count($unknown['unsupported'])===1 && !$unknown['generated'] && inventory_generated_recipe('UNKNOWN','16oz')===null,'Unknown menu names are reported for a house recipe instead of guessed');
recipeCheck(inventory_generated_recipe('BISCOFFEE','16oz')===null && inventory_generated_recipe('BLUEBERRY TEA','12oz Hot')===null,'Unspecified serving styles are left for a house recipe rather than given incomplete flavor amounts');
$pdo->beginTransaction();$rejected=recipeRejects(static fn()=>inventory_service_save_recipe($pdo,86,[],'invalid'));$pdo->rollBack();
recipeCheck($rejected && $beforeRepeat===$pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'Invalid recipe provenance cannot erase an existing recipe');
echo 'All '.$checks." generated recipe checks passed. Real inventory, orders, and recipes were not changed.\n";