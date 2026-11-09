<?php
// CLI only. Synthetic history; current stock is preserved by matched receipts/usage.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/db.php';
$pdo = db();
$start = new DateTimeImmutable('2026-01-01');
$end = new DateTimeImmutable('2026-10-17');
$tag = 'HISTORY-2026';
$items = $pdo->query('SELECT id, price FROM items WHERE price > 0 AND id IN (SELECT item_id FROM item_requirements) ORDER BY id')->fetchAll();
$user = $pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
if (!$items || !$user) { throw new RuntimeException('Products, recipes and a user are required.'); }
$stock = $pdo->query('SELECT id, qty FROM inventory')->fetchAll(PDO::FETCH_KEY_PAIR);
$recipes = [];
foreach ($pdo->query('SELECT item_id, inventory_id, quantity_needed FROM item_requirements') as $r) {
    $recipes[$r['item_id']][] = $r;
}
$order = $pdo->prepare("INSERT INTO orders (order_code,user_id,customer_name,service_details,job_status,total,status,created_at,updated_at,inventory_deducted_at) VALUES (?,?,'WALK-IN',?,'completed',?,'completed',?,?,?)");
$line = $pdo->prepare('INSERT INTO order_items (order_id,item_id,qty,price) VALUES (?,?,?,?)');
$pay = $pdo->prepare("INSERT INTO payments (order_id,provider,amount,currency,status,raw_response,created_at,paid_at) VALUES (?,'manual',?,'php','succeeded',?,?,?)");
$log = $pdo->prepare('INSERT INTO inventory_logs (inventory_id,order_id,user_id,change_type,quantity,remaining,remarks,created_at) VALUES (?,?,?,?,?,?,?,?)');
$exists = $pdo->prepare('SELECT id FROM orders WHERE order_code=? OR order_code=?');
$count = 0; $logs = 0;
$pdo->beginTransaction();
try {
    for ($day=$start; $day <= $end; $day=$day->modify('+1 day')) {
        $date=$day->format('Y-m-d');
        $n=(int)$day->format('z');
        $pending=[]; $usage=[];
        for ($j=0; $j<6+($n%9); $j++) {
            $code=$tag.'-'.$day->format('md').'-'.sprintf('%02d',$j+1);
            $exists->execute([$code,$day->format('dmy').'-'.sprintf('%02d',$j+1)]); if ($exists->fetchColumn()) { continue; }
            $time=$date.' '.sprintf('%02d:%02d:00',8+($j%10),($n+$j*7)%60);
            $selected=[]; $total=0;
            for ($k=0; $k<1+(($n+$j)%3); $k++) {
                $item=$items[($n*13+$j*7+$k*17)%count($items)];
                $qty=1+(($n+$j+$k)%2);
                $selected[]=[$item,$qty]; $total+=(int)round($item['price']*100)*$qty;
            }
            $order->execute([$code,$user,null,$total/100,$time,$time,$time]);
            $id=$pdo->lastInsertId();
            foreach ($selected as [$item,$qty]) {
                $line->execute([$id,$item['id'],$qty,$item['price']]);
                foreach ($recipes[$item['id']] as $r) {
                    $amount=round($r['quantity_needed']*$qty,2);
                    if ($amount<=0) { continue; }
                    $inv=$r['inventory_id'];
                    $usage[$inv]=($usage[$inv]??0)+$amount;
                    $pending[]=[$inv,$id,$amount,$time];
                }
            }
            $pay->execute([$id,$total/100,json_encode(['method'=>'cash','tendered'=>$total/100,'change'=>0,'synthetic'=>true]),$time,$time]);
            $count++;
        }
        // Synthetic supplies exactly cover synthetic consumption, preserving actual stock.
        $balance=$stock;
        foreach ($usage as $inv=>$amount) {
            $balance[$inv]+=$amount;
            $log->execute([$inv,null,$user,'restock',$amount,$balance[$inv],$tag.' receipt',$date.' 07:00:00']); $logs++;
        }
        foreach ($pending as [$inv,$id,$amount,$time]) {
            $balance[$inv]-=$amount;
            $log->execute([$inv,$id,$user,'auto-deduct',$amount,round($balance[$inv],2),$tag.' recipe usage',$time]); $logs++;
        }
        if (!$pending) { continue; }
        $summary=$pdo->prepare("SELECT COUNT(*) AS orders, COALESCE(SUM(total),0) AS sales FROM orders WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY) AND status='completed'");
        $summary->execute([$date,$date]); $s=$summary->fetch();
        $report=$pdo->prepare("INSERT INTO daily_sales_reports (report_date,generated_by,total_sales,total_orders,sales_tax,notes) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE total_sales=VALUES(total_sales),total_orders=VALUES(total_orders),sales_tax=VALUES(sales_tax),notes=CONCAT(COALESCE(notes,''),' | Includes history')");
        $report->execute([$date,$user,$s['sales'],$s['orders'],round($s['sales']*.12,2),$tag.' includes synthetic sales']);
        $rid=$pdo->prepare('SELECT id FROM daily_sales_reports WHERE report_date=?'); $rid->execute([$date]); $reportId=$rid->fetchColumn();
        $pdo->prepare('DELETE FROM daily_sales_items WHERE report_id=?')->execute([$reportId]);
        $pdo->prepare("INSERT INTO daily_sales_items (report_id,item_id,item_name,price,qty,subtotal) SELECT ?,i.id,i.name,oi.price,SUM(oi.qty),SUM(oi.subtotal) FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN items i ON i.id=oi.item_id WHERE o.created_at >= ? AND o.created_at < DATE_ADD(?,INTERVAL 1 DAY) AND o.status='completed' GROUP BY i.id,i.name,oi.price")->execute([$reportId,$date,$date]);
    }
    $bad=$pdo->query("SELECT COUNT(*) FROM orders o LEFT JOIN (SELECT order_id,SUM(subtotal) total FROM order_items GROUP BY order_id) l ON l.order_id=o.id LEFT JOIN (SELECT order_id,SUM(amount) total FROM payments WHERE status='succeeded' GROUP BY order_id) p ON p.order_id=o.id WHERE o.order_code LIKE 'HISTORY-2026-%' AND (l.total IS NULL OR p.total IS NULL OR o.total<>l.total OR o.total<>p.total)")->fetchColumn();
    if ($bad) { throw new RuntimeException('Sample totals do not reconcile.'); }
    if ($stock != $pdo->query('SELECT id,qty FROM inventory')->fetchAll(PDO::FETCH_KEY_PAIR)) { throw new RuntimeException('Stock changed unexpectedly.'); }
    require_once __DIR__ . '/generated_invoice_backfill.php';
    generated_invoice_backfill($pdo);
    $pdo->commit();
    echo "Added $count sample orders/payments and $logs inventory logs. Totals verified; stock unchanged.\n";
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }


