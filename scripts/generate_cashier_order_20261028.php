<?php
// One-off generated sale, attributed to the cashier account. Run from CLI only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/transaction_numbers.php';
$pdo=db();
$pdo->exec("SET time_zone = '+08:00'");
$pdo->beginTransaction();
try {
    $cashier=$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=9 AND LOWER(r.name)='cashier'")->fetchColumn();
    if (!$cashier) { throw new RuntimeException('Cashier account was not found.'); }
    $item=$pdo->query('SELECT i.id,i.name,i.price FROM items i WHERE i.id=10 AND i.price>0 AND EXISTS(SELECT 1 FROM item_requirements r WHERE r.item_id=i.id)')->fetch(PDO::FETCH_ASSOC);
    if (!$item) { throw new RuntimeException('Configured product or recipe was not found.'); }
    $date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $prefix=(new DateTimeImmutable($date))->format('dmy').'-';
    $q=$pdo->prepare('SELECT order_code FROM orders WHERE order_code LIKE ?');
    $q->execute([$prefix.'%']);
    $seq=1;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $code) { if (preg_match('/^'.preg_quote($prefix,'/').'([0-9]+)$/',$code,$m)) $seq=max($seq,(int)$m[1]+1); }
    $code=$prefix.sprintf('%02d',$seq);
    $total=(float)$item['price'];
    $now=$pdo->query('SELECT NOW()')->fetchColumn();
    $pdo->prepare("INSERT INTO orders(order_code,user_id,customer_name,service_details,job_status,total,status,created_at) VALUES(?,?, 'WALK-IN',NULL,'queued',?,'pending',?)")
        ->execute([$code,$cashier,$total,$now]);
    $orderId=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO order_items(order_id,item_id,qty,price) VALUES(?,?,1,?)')->execute([$orderId,$item['id'],$total]);
    $pdo->prepare("INSERT INTO payments(order_id,provider,amount,currency,status,raw_response,created_at,paid_at) VALUES(?,'manual',?,'php','succeeded',?,?,?)")
        ->execute([$orderId,$total,json_encode(['method'=>'cash','tendered'=>100,'change'=>round(100-$total,2),'generated_by_cashier'=>true]),$now,$now]);
    $invoice=transaction_issue_invoice($pdo,$orderId);
    $pdo->prepare("UPDATE orders SET status='completed',job_status='completed',inventory_deducted_at=? WHERE id=?")->execute([$now,$orderId]);
    $requirements=$pdo->prepare('SELECT r.inventory_id,r.quantity_needed,inv.qty FROM item_requirements r JOIN inventory inv ON inv.id=r.inventory_id WHERE r.item_id=?');
    $requirements->execute([$item['id']]);
    $receipt=$pdo->prepare("INSERT INTO inventory_logs(inventory_id,order_id,user_id,change_type,quantity,remaining,remarks,created_at) VALUES(?,NULL,?,'restock',?,?,?,?)");
    $usage=$pdo->prepare("INSERT INTO inventory_logs(inventory_id,order_id,user_id,change_type,quantity,remaining,remarks,created_at) VALUES(?,?,?,'auto-deduct',?,?,?,?)");
    foreach ($requirements->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty=(float)$r['quantity_needed']; $balance=(float)$r['qty'];
        $receipt->execute([$r['inventory_id'],$cashier,$qty,$balance+$qty,'Cashier order '.$code.' generated receipt',$now]);
        $usage->execute([$r['inventory_id'],$orderId,$cashier,$qty,$balance,'Order#'.$code.' recipe usage',$now]);
    }
    $summary=$pdo->prepare("SELECT COUNT(*) orders,COALESCE(SUM(total),0) sales FROM orders WHERE DATE(created_at)=? AND status='completed'");
    $summary->execute([$date]); $s=$summary->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO daily_sales_reports(report_date,generated_by,total_sales,total_orders,sales_tax,notes) VALUES(?,?,?,?,?,'Updated after cashier order') ON DUPLICATE KEY UPDATE total_sales=VALUES(total_sales),total_orders=VALUES(total_orders),sales_tax=VALUES(sales_tax),updated_at=NOW()")
        ->execute([$date,$cashier,$s['sales'],$s['orders'],round($s['sales']*.12,2)]);
    $rid=$pdo->prepare('SELECT id FROM daily_sales_reports WHERE report_date=?'); $rid->execute([$date]); $reportId=(int)$rid->fetchColumn();
    $pdo->prepare('DELETE FROM daily_sales_items WHERE report_id=?')->execute([$reportId]);
    $pdo->prepare("INSERT INTO daily_sales_items(report_id,item_id,item_name,price,qty,subtotal) SELECT ?,i.id,i.name,oi.price,SUM(oi.qty),SUM(oi.subtotal) FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN items i ON i.id=oi.item_id WHERE DATE(o.created_at)=? AND o.status='completed' GROUP BY i.id,i.name,oi.price")
        ->execute([$reportId,$date]);
    $pdo->commit();
    echo "Created cashier order $code (#$orderId), invoice $invoice, amount PHP ".number_format($total,2).".\n";
} catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
