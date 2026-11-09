<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../includes/db.php';
$pdo=db();$pdo->exec("SET time_zone='+08:00'");$pdo->beginTransaction();
try{
    // Generated walk-in drinks are marked take-out so the order card has a recorded service type.
    $typeUpdate=$pdo->prepare("UPDATE orders o JOIN payments p ON p.order_id=o.id SET o.service_type='take_out' WHERE o.user_id=9 AND o.service_type IS NULL AND JSON_EXTRACT(p.raw_response,'$.generated_by_cashier')=true");
    $typeUpdate->execute();$typed=$typeUpdate->rowCount();

    // The first generated completed sale used placeholder receipt/usage log pairs.
    // Convert them into real consumption, lower stock, and shift later ledger balances.
    $order=$pdo->query("SELECT id,order_code,status,inventory_deducted_at FROM orders WHERE id=3087 AND order_code='281026-02' AND user_id=9 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if(!$order||$order['status']!=='completed'||empty($order['inventory_deducted_at'])) throw new RuntimeException('Expected completed cashier order was not found.');
    $receipts=$pdo->prepare("SELECT id,inventory_id,quantity,remaining FROM inventory_logs WHERE order_id IS NULL AND change_type='restock' AND remarks=? FOR UPDATE");
    $receipts->execute(['Cashier order 281026-02 generated receipt']);$receiptRows=$receipts->fetchAll(PDO::FETCH_ASSOC);
    $usages=$pdo->prepare("SELECT id,inventory_id,quantity FROM inventory_logs WHERE order_id=? AND change_type='auto-deduct' AND remarks=? FOR UPDATE");
    $usages->execute([$order['id'],'Order#281026-02 recipe usage']);$usageRows=$usages->fetchAll(PDO::FETCH_ASSOC);
    if(count($receiptRows)!==3||count($usageRows)!==3) throw new RuntimeException('Inventory placeholder log set differs from expected; no stock correction applied.');
    $receiptByInventory=[];foreach($receiptRows as $r)$receiptByInventory[(int)$r['inventory_id']]=$r;
    $stock=$pdo->prepare('SELECT qty FROM inventory WHERE id=? FOR UPDATE');
    $adjust=$pdo->prepare('UPDATE inventory SET qty=? WHERE id=?');
    $mark=$pdo->prepare("UPDATE inventory_logs SET change_type='used',remaining=?,remarks='Completed Order#281026-02 (recipe; stock corrected)' WHERE id=?");
    $shift=$pdo->prepare('UPDATE inventory_logs SET remaining=ROUND(remaining-?,2) WHERE inventory_id=? AND id>?');
    $deleted=[];
    foreach($usageRows as $u){
        $inv=(int)$u['inventory_id'];$use=(float)$u['quantity'];$receipt=$receiptByInventory[$inv]??null;
        if(!$receipt||(float)$receipt['quantity']!=$use) throw new RuntimeException('Receipt/usage quantity mismatch; no stock correction applied.');
        $stock->execute([$inv]);$current=(float)$stock->fetchColumn();
        if($current+0.00001<$use) throw new RuntimeException('Insufficient current inventory; no stock correction applied.');
        $before=(float)$receipt['remaining']-(float)$receipt['quantity'];
        $after=round($before-$use,2);
        $adjust->execute([$after,$inv]);
        $mark->execute([$after,$u['id']]);
        $shift->execute([$use,$inv,$u['id']]);
        $deleted[]=(int)$receipt['id'];
    }
    $del=$pdo->prepare('DELETE FROM inventory_logs WHERE id=?');foreach($deleted as $id)$del->execute([$id]);
    $pdo->prepare("INSERT INTO transaction_events(order_id,actor_user_id,event_type,details_json) VALUES(3087,9,'inventory_stock_corrected',?)")
        ->execute([json_encode(['corrected_generated_receipt_logs'=>count($deleted),'result'=>'actual recipe usage deducted from on-hand stock'])]);
    $pdo->commit();
    echo "Set service type to Take-out on $typed cashier-generated orders; deducted completed order 281026-02 from stock and corrected its ledger.\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
