<?php
// Explicit, repeat-safe setup. A preview changes nothing after rollback.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__,2).'/includes/db.php';
require_once dirname(__DIR__,2).'/includes/recipe_catalog.php';
$flags=array_slice($argv,1);
if(array_diff($flags,['--apply','--replace-placeholder'])) { fwrite(STDERR,"Usage: php 20261009_seed_product_recipes.php [--apply] [--replace-placeholder]\n"); exit(1); }
$apply=in_array('--apply',$flags,true);
$pdo=db();
$pdo->beginTransaction();
try {
    $stockBefore=$pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $itemsBefore=$pdo->query('SELECT * FROM items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $ordersBefore=$pdo->query('SELECT id,status,inventory_deducted_at FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $logsBefore=(int)$pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn();
    $backup=['created_at'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Singapore')))->format(DateTimeInterface::ATOM),'reason'=>'Before user-authorized generated starter recipes','requirements'=>$pdo->query('SELECT * FROM item_requirements ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),'metadata'=>$pdo->query('SELECT * FROM item_recipe_metadata ORDER BY item_id')->fetchAll(PDO::FETCH_ASSOC)];
    $result=inventory_seed_generated_recipes($pdo,in_array('--replace-placeholder',$flags,true));
    foreach($result['generated'] as $entry){
        $requirements=inventory_service_requirements($pdo,[['id'=>$entry['item']['id'],'qty'=>2]]);
        $quantities=[];
        foreach($requirements as $row){
            if(empty($row['inventory_id']))throw new RuntimeException('Missing automatic packaging for '.$entry['item']['name']);
            $quantities[(int)$row['inventory_id']]=(float)$row['quantity'];
        }
        foreach($entry['recipe'] as $id=>$quantity)if(($quantities[$id]??null)!==$quantity*2)throw new RuntimeException('Incorrect serving multiplier.');
    }
    if($stockBefore!==$pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Recipe setup changed stock.');
    if($itemsBefore!==$pdo->query('SELECT * FROM items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Recipe setup changed catalog details.');
    if($ordersBefore!==$pdo->query('SELECT id,status,inventory_deducted_at FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Recipe setup changed orders.');
    if($logsBefore!==(int)$pdo->query('SELECT COUNT(*) FROM inventory_logs')->fetchColumn())throw new RuntimeException('Recipe setup changed movement history.');
    $backupPath=null;
    if($apply && $result['generated']!==[]){
        $backupPath=dirname(__DIR__,2).'/docs/recipe_backup_20261009_'.bin2hex(random_bytes(4)).'.json';
        $encoded=json_encode($backup,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $handle=fopen($backupPath,'x');
        if(!$handle)throw new RuntimeException('Cannot create the recipe backup.');
        $bytes=fwrite($handle,$encoded);
        fclose($handle);
        if($bytes!==strlen($encoded))throw new RuntimeException('Recipe backup is incomplete.');
    }
    if($apply)$pdo->commit();else $pdo->rollBack();
    echo ($apply?'APPLIED':'PREVIEW ONLY - rolled back').': '.count($result['generated']).' recipes generated; '.count($result['preserved']).' existing recipes preserved; '.count($result['unsupported'])." unsupported variants.\n";
    if($backupPath)echo 'Original recipe backup: '.$backupPath."\n";
    foreach($result['unsupported'] as $item)echo 'Needs a house recipe: '.$item['name'].' ('.$item['cup_size'].")\n";
    echo "Verified inventory units, per-serving multiplication, and matching packaging. Stock, catalog details, orders, and movement history preserved.\n";
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}