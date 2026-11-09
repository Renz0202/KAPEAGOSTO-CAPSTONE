<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/transaction_numbers.php';
require_once __DIR__.'/../includes/inventory_service.php';
$pdo=db(); $pdo->exec("SET time_zone='+08:00'");
$cashier=(int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=9 AND LOWER(r.name)='cashier'")->fetchColumn();
if (!$cashier) throw new RuntimeException('Cashier account was not found.');
$date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$prefix=(new DateTimeImmutable($date))->format('dmy').'-';
$existing=$pdo->prepare('SELECT order_code FROM orders WHERE order_code LIKE ?'); $existing->execute([$prefix.'%']);
$next=1; foreach($existing->fetchAll(PDO::FETCH_COLUMN) as $code) if(preg_match('/^'.preg_quote($prefix,'/').'([0-9]+)$/',$code,$m)) $next=max($next,(int)$m[1]+1);
$products=$pdo->query('SELECT id,name,price FROM items WHERE price>0 AND EXISTS(SELECT 1 FROM item_requirements r WHERE r.item_id=items.id) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
if (!$products) throw new RuntimeException('No products with inventory recipes are available.');
$stockCheck=$pdo->prepare('SELECT r.inventory_id,r.quantity_needed,inv.qty FROM item_requirements r JOIN inventory inv ON inv.id=r.inventory_id WHERE r.item_id=? ORDER BY r.inventory_id');
$orderInsert=$pdo->prepare("INSERT INTO orders(order_code,user_id,customer_name,service_details,job_status,total,status,created_at) VALUES(?,?, 'WALK-IN',NULL,?,?,?,?)");
$lineInsert=$pdo->prepare('INSERT INTO order_items(order_id,item_id,qty,price) VALUES(?,?,?,?)');
$payInsert=$pdo->prepare('INSERT INTO payments(order_id,provider,amount,currency,status,raw_response,created_at,paid_at) VALUES(? ,\'manual\',?,\'php\',?,?,?,?)');
$completed=0; $voided=0; $sales=0.0; $made=[];
for($j=0;$j<10;$j++) {
    $isVoid=$j>=8; $qty=1+($j%3===2?1:0); $chosen=null;
    for($try=0;$try<count($products);$try++) {
        $candidate=$products[($j*7+$try)%count($products)];
        $stockCheck->execute([$candidate['id']]); $requirements=$stockCheck->fetchAll(PDO::FETCH_ASSOC);
        $available=(bool)$requirements;
        foreach($requirements as $r) if((float)$r['qty']+0.00001 < (float)$r['quantity_needed']*$qty) {$available=false;break;}
        if($available){$chosen=$candidate;break;}
    }
    if(!$chosen) throw new RuntimeException('No product recipe fits current stock; batch stopped.');
    $pdo->beginTransaction();
    try {
        $sequence=$next++; $code=$prefix.sprintf('%02d',$sequence);
        $time=$pdo->query('SELECT DATE_SUB(NOW(), INTERVAL '.(10-$j).' MINUTE)')->fetchColumn();
        $total=round((float)$chosen['price']*$qty,2);
        $orderInsert->execute([$code,$cashier,$isVoid?'cancelled':'queued',$total,$isVoid?'pending':'pending',$time]);
        $id=(int)$pdo->lastInsertId();
        $lineInsert->execute([$id,$chosen['id'],$qty,$chosen['price']]);
        if($isVoid) {
            $payInsert->execute([$id,$total,'cancelled',json_encode(['method'=>'cash','cancelled'=>true,'generated_by_cashier'=>true]),$time,null]);
            $pdo->prepare("UPDATE orders SET status='void',job_status='cancelled',voided_at=DATE_ADD(created_at,INTERVAL 1 MINUTE) WHERE id=?")->execute([$id]);
            $voided++;
        } else {
            $payInsert->execute([$id,$total,'succeeded',json_encode(['method'=>'cash','tendered'=>$total,'change'=>0,'generated_by_cashier'=>true]),$time,$time]);
            transaction_issue_invoice($pdo,$id);
            inventory_service_complete_order($pdo,$id,$cashier);
            $pdo->prepare("UPDATE orders SET status='completed',job_status='completed' WHERE id=?")->execute([$id]);
            $completed++; $sales+=$total;
        }
        $pdo->commit(); $made[]="$code $".number_format($total,2).($isVoid?' void':' completed');
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}
$pdo->beginTransaction();
try {
    $sum=$pdo->prepare("SELECT COUNT(*) order_count,COALESCE(SUM(total),0) sales FROM orders WHERE DATE(created_at)=? AND status='completed'"); $sum->execute([$date]); $s=$sum->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO daily_sales_reports(report_date,generated_by,total_sales,total_orders,sales_tax,notes) VALUES(?,?,?,?,?,'Updated after cashier generated orders') ON DUPLICATE KEY UPDATE total_sales=VALUES(total_sales),total_orders=VALUES(total_orders),sales_tax=VALUES(sales_tax),updated_at=NOW()")
        ->execute([$date,$cashier,$s['sales'],$s['order_count'],round((float)$s['sales']*.12,2)]);
    $rid=$pdo->prepare('SELECT id FROM daily_sales_reports WHERE report_date=?');$rid->execute([$date]);$reportId=(int)$rid->fetchColumn();
    $pdo->prepare('DELETE FROM daily_sales_items WHERE report_id=?')->execute([$reportId]);
    $pdo->prepare("INSERT INTO daily_sales_items(report_id,item_id,item_name,price,qty,subtotal) SELECT ?,i.id,i.name,oi.price,SUM(oi.qty),SUM(oi.subtotal) FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN items i ON i.id=oi.item_id WHERE DATE(o.created_at)=? AND o.status='completed' GROUP BY i.id,i.name,oi.price")->execute([$reportId,$date]);
    $pdo->commit();
} catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo "Created ".count($made)." cashier orders for $date: $completed completed, $voided void; added sales PHP ".number_format($sales,2).".\n".implode("\n",$made)."\n";
