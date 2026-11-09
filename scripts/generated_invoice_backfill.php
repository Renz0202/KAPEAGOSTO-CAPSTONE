<?php
/** Assign invoice numbers to fully paid generated sales within the caller's transaction. */
function generated_invoice_backfill(PDO $pdo): int {
    if (!$pdo->inTransaction()) { throw new LogicException('A transaction is required.'); }
    $counter=$pdo->query("SELECT last_number FROM document_sequences WHERE sequence_key='sales_invoice' FOR UPDATE")->fetchColumn();
    if ($counter===false) { throw new RuntimeException('Invoice sequence is not configured.'); }
    $max=(int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING(sales_invoice_no,4) AS UNSIGNED)),0) FROM orders WHERE sales_invoice_no REGEXP '^SI-[0-9]+$'")->fetchColumn();
    $number=max((int)$counter,$max);
    $orders=$pdo->query("SELECT o.id FROM orders o WHERE o.status='completed' AND o.total>0 AND o.sales_invoice_no IS NULL
        AND EXISTS (SELECT 1 FROM payments p WHERE p.order_id=o.id AND JSON_EXTRACT(p.raw_response,'$.synthetic')=true)
        AND (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.order_id=o.id AND p.status='succeeded')>=o.total
        ORDER BY o.created_at,o.id FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
    $update=$pdo->prepare('UPDATE orders SET sales_invoice_no=?,invoiced_at=NOW(),updated_at=updated_at WHERE id=? AND sales_invoice_no IS NULL');
    foreach ($orders as $id) {
        $update->execute(['SI-'.str_pad((string)++$number,8,'0',STR_PAD_LEFT),$id]);
    }
    $pdo->prepare("UPDATE document_sequences SET last_number=? WHERE sequence_key='sales_invoice'")->execute([$number]);
    return count($orders);
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    require_once __DIR__.'/../includes/db.php';
    $pdo=db(); $pdo->beginTransaction();
    try { $count=generated_invoice_backfill($pdo); $pdo->commit(); echo "Assigned $count generated sales invoices.\n"; }
    catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
