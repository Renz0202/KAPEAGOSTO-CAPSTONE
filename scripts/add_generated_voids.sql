SET time_zone='+08:00';
START TRANSACTION;
CREATE TEMPORARY TABLE generated_void_targets AS
SELECT MIN(o.id) id,DATE(o.created_at) report_date
FROM orders o
WHERE o.created_at >= '2026-01-01' AND o.created_at < '2026-10-18'
AND MOD(DATEDIFF(DATE(o.created_at),'2026-01-01'),7)=0
AND EXISTS (SELECT 1 FROM inventory_logs l WHERE l.order_id=o.id AND l.remarks='HISTORY-2026 recipe usage')
GROUP BY DATE(o.created_at);
UPDATE orders o JOIN generated_void_targets t ON t.id=o.id
SET o.status='void',o.job_status='cancelled',o.voided_at=DATE_ADD(o.created_at,INTERVAL 15 MINUTE),
o.updated_at=DATE_ADD(o.created_at,INTERVAL 15 MINUTE);
UPDATE payments p JOIN generated_void_targets t ON t.id=p.order_id
SET p.status='cancelled',p.paid_at=NULL,p.updated_at=DATE_ADD(p.created_at,INTERVAL 15 MINUTE);
UPDATE daily_sales_reports r JOIN generated_void_targets t ON t.report_date=r.report_date
SET r.total_orders=(SELECT COUNT(*) FROM orders o WHERE DATE(o.created_at)=r.report_date AND o.status='completed'),
r.total_sales=(SELECT COALESCE(SUM(o.total),0) FROM orders o WHERE DATE(o.created_at)=r.report_date AND o.status='completed'),
r.sales_tax=ROUND(r.total_sales*0.12,2);
DELETE d FROM daily_sales_items d JOIN daily_sales_reports r ON r.id=d.report_id
JOIN generated_void_targets t ON t.report_date=r.report_date;
INSERT INTO daily_sales_items(report_id,item_id,item_name,price,qty,subtotal)
SELECT r.id,i.id,i.name,oi.price,SUM(oi.qty),SUM(oi.subtotal)
FROM generated_void_targets t JOIN daily_sales_reports r ON r.report_date=t.report_date
JOIN orders o ON DATE(o.created_at)=t.report_date AND o.status='completed'
JOIN order_items oi ON oi.order_id=o.id JOIN items i ON i.id=oi.item_id
GROUP BY r.id,i.id,i.name,oi.price;
SELECT COUNT(*) generated_voided_orders FROM generated_void_targets;
SELECT COUNT(*) report_mismatches FROM daily_sales_reports r
JOIN generated_void_targets t ON t.report_date=r.report_date
WHERE r.total_sales<>(SELECT COALESCE(SUM(d.subtotal),0) FROM daily_sales_items d WHERE d.report_id=r.id);
COMMIT;
