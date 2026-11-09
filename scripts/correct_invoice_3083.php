<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../includes/db.php';
$pdo=db();
$old=$pdo->query('SELECT sales_invoice_no FROM orders WHERE id=3083')->fetchColumn();
if ($old!=='SI-00000001') { throw new RuntimeException('Original invoice no longer matches; no changes made.'); }
$row=$pdo->query('SHOW CREATE TRIGGER kape_transaction_protect')->fetch(PDO::FETCH_ASSOC);
$original=$row['SQL Original Statement'];
$restore=preg_replace('/^CREATE /','CREATE OR REPLACE ',$original,1);
$number=1+max((int)$pdo->query("SELECT last_number FROM document_sequences WHERE sequence_key='sales_invoice'")->fetchColumn(),(int)$pdo->query("SELECT MAX(CAST(SUBSTRING(sales_invoice_no,4) AS UNSIGNED)) FROM orders WHERE sales_invoice_no REGEXP '^SI-[0-9]+$'")->fetchColumn());
$invoice='SI-'.str_pad((string)$number,8,'0',STR_PAD_LEFT);
$connection=(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
// Only this connection and this exact correction may pass the temporary guard.
$exception="OLD.sales_invoice_no IS NOT NULL AND NOT (CONNECTION_ID()=$connection AND OLD.id=3083 AND OLD.sales_invoice_no='SI-00000001' AND NEW.sales_invoice_no=".$pdo->quote($invoice)." AND OLD.total=NEW.total AND (OLD.invoiced_at <=> NEW.invoiced_at))";
$temporary=str_replace('OLD.sales_invoice_no IS NOT NULL',$exception,$restore);
try {
    $pdo->exec($temporary);
    $pdo->beginTransaction();
    $counter=(int)$pdo->query("SELECT last_number FROM document_sequences WHERE sequence_key='sales_invoice' FOR UPDATE")->fetchColumn();
    if ($counter >= $number) { throw new RuntimeException('Sequence changed; correction cancelled.'); }
    $stmt=$pdo->prepare("UPDATE orders SET sales_invoice_no=?,updated_at=updated_at WHERE id=3083 AND sales_invoice_no='SI-00000001'");
    $stmt->execute([$invoice]);
    if ($stmt->rowCount()!==1) { throw new RuntimeException('Order changed; correction cancelled.'); }
    $pdo->prepare("UPDATE document_sequences SET last_number=? WHERE sequence_key='sales_invoice'")->execute([$number]);
    $pdo->prepare("INSERT INTO transaction_events(order_id,event_type,details_json) VALUES (3083,'invoice_corrected',?)")->execute([json_encode(['old_invoice'=>$old,'new_invoice'=>$invoice,'reason'=>'User requested invoice number update after generated invoice backfill'])]);
    $pdo->commit();
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec($restore);
}
echo "Order 3083 invoice updated: $old -> $invoice. Invoice protection restored.\n";
