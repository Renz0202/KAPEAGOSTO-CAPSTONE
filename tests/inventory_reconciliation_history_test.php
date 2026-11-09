<?php
// Only connection-local temporary tables are mutated by this test.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_reconciliation_history.php';
$pdo=db();
$tables=[
 'inventory'=>'id INT PRIMARY KEY, sku VARCHAR(50), name VARCHAR(100), material_type VARCHAR(20), unit VARCHAR(10), qty DECIMAL(10,2)',
 'items'=>'id INT PRIMARY KEY, name VARCHAR(100), cup_size VARCHAR(50)',
 'orders'=>'id INT PRIMARY KEY, order_code VARCHAR(50), status VARCHAR(20), created_at DATETIME, inventory_deducted_at DATETIME NULL',
 'order_items'=>'id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, item_id INT, qty INT',
 'item_requirements'=>'id INT AUTO_INCREMENT PRIMARY KEY, item_id INT, inventory_id INT, quantity_needed DECIMAL(10,2)',
 'item_recipe_metadata'=>'item_id INT PRIMARY KEY, source VARCHAR(20), notes VARCHAR(100), updated_at DATETIME',
 'inventory_logs'=>'id INT AUTO_INCREMENT PRIMARY KEY, inventory_id INT, order_id INT NULL, user_id INT NULL, change_type VARCHAR(30), quantity DECIMAL(10,2), remaining DECIMAL(10,2) NOT NULL, remarks VARCHAR(255), created_at DATETIME',
 'payments'=>'id INT PRIMARY KEY, order_id INT, amount DECIMAL(10,2)',
 'notifications'=>'id INT PRIMARY KEY, message VARCHAR(100)',
];
foreach($tables as $table=>$columns) $pdo->exec('CREATE TEMPORARY TABLE '.$table.' ('.$columns.') ENGINE=InnoDB');
$checks=0;
function history_check(bool $condition,string $label):void {
 global $checks;
 if(!$condition) throw new RuntimeException('FAIL: '.$label);
 $checks++;echo "PASS: $label\n";
}
function history_snapshot(PDO $pdo,array $tables):array {
 $result=[];foreach($tables as $table) $result[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
 return $result;
}
function history_rejects(callable $action):bool {
 try{$action();}catch(Throwable $error){return true;}return false;
}
$pdo->exec("INSERT INTO inventory VALUES
 (1,'BEAN','COFFEE BEANS','raw_material','g',500),
 (2,'MILK','FRESH MILK','raw_material','ml',100),
 (3,'SYRUP','VANILLA SYRUP','raw_material','ml',10),
 (10,'CUP','CUP','packaging','pcs',2),
 (11,'LID','LID','packaging','pcs',2),
 (12,'STRAW','STRAW','packaging','pcs',2)");
$pdo->exec("INSERT INTO items VALUES (1,'LATTE','16oz'),(2,'LATTE','22oz')");
$pdo->exec("INSERT INTO orders VALUES
 (1,'PARTIAL','completed','2026-09-16 09:00:00','2026-09-16 09:01:00'),
 (2,'FULL','completed','2026-09-17 09:00:00','2026-09-17 09:01:00'),
 (3,'NONE','completed','2026-09-18 09:00:00','2026-09-18 09:01:00'),
 (4,'PENDING','pending','2026-09-18 10:00:00',NULL),
 (5,'VOID','void','2026-09-18 11:00:00',NULL)");
$pdo->exec('INSERT INTO order_items (order_id,item_id,qty) VALUES (1,1,2),(1,2,1),(2,1,1),(3,2,1),(4,1,999),(5,1,999)');
$pdo->exec('INSERT INTO item_requirements (item_id,inventory_id,quantity_needed) VALUES (1,1,18.25),(1,2,150),(1,3,0.25),(1,10,1),(1,11,1),(1,12,1),(2,1,22),(2,2,250),(2,3,0.50)');
$pdo->exec("INSERT INTO item_recipe_metadata VALUES (1,'generated','Estimate','2026-10-09 17:00:00'),(2,'custom','House recipe','2026-10-09 17:00:00')");
$pdo->exec("INSERT INTO inventory_logs (inventory_id,order_id,change_type,quantity,remaining,remarks,created_at) VALUES
 (1,1,'used',8.25,500,'Old use','2026-09-16 09:01:00'),
 (2,1,'used',100,100,'Old use','2026-09-16 09:01:00'),
 (3,1,'used',0.25,10,'Old use','2026-09-16 09:01:00'),
 (1,2,'used',18.25,500,'Old use','2026-09-17 09:01:00'),
 (2,2,'used',150,100,'Old use','2026-09-17 09:01:00'),
 (3,2,'used',0.25,10,'Old use','2026-09-17 09:01:00'),
 (10,1,'used',3,2,'Old packaging','2026-09-16 09:01:00'),
 (11,1,'used',3,2,'Old packaging','2026-09-16 09:01:00'),
 (12,1,'used',3,2,'Old packaging','2026-09-16 09:01:00'),
 (1,NULL,'restock',500,500,'Generated opening stock estimate (2026-10-09); not a physical count.','2026-10-09 17:36:07'),
 (2,NULL,'adjustment',100,100,'Stock count adjusted from 0 to 100','2026-10-09 20:38:41'),
 (3,NULL,'restock',10,10,'Generated opening stock estimate (2026-10-09); not a physical count.','2026-10-09 17:36:07')");
$pdo->exec('INSERT INTO payments VALUES (1,1,300),(2,2,100)');
$pdo->exec("INSERT INTO notifications VALUES (1,'Stock reminder')");
$before=history_snapshot($pdo,array_keys($tables));
$plan=inventory_reconciliation_history_plan($pdo);
history_check($plan['mode']==='historical_usage_only' && $plan['stock_changes']===false && !$plan['issues'],'Valid pre-baseline use produces a history-only plan');
history_check($plan['recipe_comparison']['summary']['ingredient_corrections']===6,'Plan subtracts existing usage and excludes full, pending and void orders');
history_check($plan['stock_baselines'][1]['kind']==='Generated opening estimate' && $plan['stock_baselines'][2]['kind']==='Stock count adjustment','Generated setup and later stock count baselines are identified');
history_check(strpos(inventory_reconciliation_history_report($plan),'100.00 | 100.00')!==false,'Report preserves the current milk balance despite old missing use');
history_check(history_snapshot($pdo,array_keys($tables))===$before,'Planning does not mutate any records');
history_check(history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,'invalid')),'Apply requires a valid reviewed fingerprint');
$pdo->exec('UPDATE inventory SET qty=501 WHERE id=1');
$changed=history_snapshot($pdo,array_keys($tables));
history_check(history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$plan['state_fingerprint'])) && history_snapshot($pdo,array_keys($tables))===$changed,'A stock change invalidates the reviewed plan without partial writes');
$pdo->exec('UPDATE inventory SET qty=500 WHERE id=1');
$pdo->exec("UPDATE orders SET inventory_deducted_at='2026-10-10 09:00:00' WHERE id=1");
$late=inventory_reconciliation_history_plan($pdo);
$changed=history_snapshot($pdo,array_keys($tables));
history_check(count($late['issues'])===3 && history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$late['state_fingerprint'])) && history_snapshot($pdo,array_keys($tables))===$changed,'Usage after a baseline cannot bypass a current-stock deduction');
$pdo->exec('UPDATE orders SET inventory_deducted_at=NULL WHERE id=1');
$unknown=inventory_reconciliation_history_plan($pdo);
history_check(count($unknown['issues'])===3 && history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$unknown['state_fingerprint'])),'Unknown completion times require review');
$pdo->exec("UPDATE orders SET inventory_deducted_at='2026-09-16 09:01:00' WHERE id=1");
$pdo->exec("UPDATE inventory_logs SET remarks='Unknown opening' WHERE id=12");
$unknown=inventory_reconciliation_history_plan($pdo);
history_check(count($unknown['issues'])===2 && history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$unknown['state_fingerprint'])),'An unproven baseline prevents all historical corrections');
$pdo->exec("UPDATE inventory_logs SET remarks='Generated opening stock estimate (2026-10-09); not a physical count.' WHERE id=12");
$plan=inventory_reconciliation_history_plan($pdo);
$pdo->beginTransaction();
history_check(history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$plan['state_fingerprint'])),'The correction requires its own fresh transaction');
$pdo->rollBack();
$max=(int)$pdo->query('SELECT MAX(id) FROM inventory_logs')->fetchColumn();
$result=inventory_reconciliation_record_history($pdo,$plan['state_fingerprint'],7);
$after=history_snapshot($pdo,array_keys($tables));
history_check($result['orders_corrected']===2 && $result['usage_records_added']===6 && $result['missing_usage_remaining']===0,'All missing ingredient records are committed and verified');
foreach(array_diff(array_keys($tables),['inventory_logs']) as $table) {
 history_check($before[$table]===$after[$table],$table.' remains unchanged');
}
$old=array_values(array_filter($after['inventory_logs'],static fn($row)=>(int)$row['id']<=$max));
$new=array_values(array_filter($after['inventory_logs'],static fn($row)=>(int)$row['id']>$max));
history_check($old===$before['inventory_logs'],'Existing movement records are preserved');
$stock=array_column($before['inventory'],'qty','id');
$quantities=[];
foreach($new as $row) {
 history_check((int)$row['inventory_id']<10 && in_array((int)$row['order_id'],[1,3],true) && (int)$row['user_id']===7 && $row['remaining']===$stock[$row['inventory_id']] && strpos($row['remarks'],'Historical recipe estimate;')===0,'Added entry is linked, labeled, ingredient-only and records unchanged posting-time stock');
 $quantities[$row['inventory_id']]=($quantities[$row['inventory_id']]??0)+inventory_reconciliation_units($row['quantity']);
}
history_check($quantities===[1=>7225,2=>70000,3=>125],'Exactly the missing fractional ingredient amounts are recorded');
history_check(history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$plan['state_fingerprint'])) && history_snapshot($pdo,array_keys($tables))===$after,'Reapplying an old plan cannot add duplicates');
$repeat=inventory_reconciliation_history_plan($pdo);
$nothing=inventory_reconciliation_record_history($pdo,$repeat['state_fingerprint']);
history_check($nothing['usage_records_added']===0 && history_snapshot($pdo,array_keys($tables))===$after,'A fresh repeated correction is a no-op');

// Fail the second new movement to prove the first new movement rolls back too.
$pdo->exec('DELETE FROM inventory_logs WHERE id>'.$max);
$pdo->exec("ALTER TABLE inventory_logs ADD CONSTRAINT fixture_usage_limit CHECK (change_type <> 'used' OR quantity < 450)");
$beforeFailure=history_snapshot($pdo,array_keys($tables));
$failedPlan=inventory_reconciliation_history_plan($pdo);
history_check(history_rejects(static fn()=>inventory_reconciliation_record_history($pdo,$failedPlan['state_fingerprint'])),'A mid-batch database failure is surfaced');
history_check(!$pdo->inTransaction() && history_snapshot($pdo,array_keys($tables))===$beforeFailure,'A mid-batch failure rolls back every new record and releases the transaction');
echo "All $checks historical reconciliation checks passed. Live data was not modified.\n";
