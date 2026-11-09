START TRANSACTION;
UPDATE payments p JOIN orders o ON o.id=p.order_id
SET p.raw_response=REPLACE(p.raw_response,'"sample"','"synthetic"'),p.updated_at=p.updated_at
WHERE o.service_details LIKE 'SAMPLE-HISTORY-2026%';
UPDATE orders SET order_code=REPLACE(order_code,'SAMPLE-HISTORY-2026','HISTORY-2026'),
customer_name=REPLACE(customer_name,'SAMPLE ',''),
service_details=REPLACE(service_details,'SAMPLE-HISTORY-2026','HISTORY-2026'),updated_at=updated_at
WHERE service_details LIKE 'SAMPLE-HISTORY-2026%';
UPDATE inventory_logs SET remarks=REPLACE(REPLACE(remarks,'SAMPLE-HISTORY-2026','HISTORY-2026'),'sample ','')
WHERE remarks LIKE 'SAMPLE-HISTORY-2026%';
UPDATE daily_sales_reports SET notes=REPLACE(REPLACE(notes,'SAMPLE-HISTORY-2026','HISTORY-2026'),'sample history','history'),updated_at=updated_at
WHERE notes LIKE '%sample%';
COMMIT;
