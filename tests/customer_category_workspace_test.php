<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/customer_history.php';
function role_display_name($role) { return ucfirst($role); }
function current_user_role() { return 'admin'; }
function source($file) { return str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__).'/'.$file)); }
function html($value) { $doc=new DOMDocument(); @$doc->loadHTML('<!doctype html><meta charset="utf-8">'.$value); return new DOMXPath($doc); }
function mainHtml($file, $variables) { $s=source($file); $start=strpos($s,'<main '); $end=strpos($s,'</main>',$start)+7; extract($variables); ob_start(); eval('?>'.substr($s,$start,$end-$start)); return ob_get_clean(); }
$checks=0;
function check($value, $label) { global $checks; if (!$value) throw new RuntimeException('FAIL: '.$label); $checks++; echo 'PASS: ',$label,PHP_EOL; }
function detail($x) { return json_decode($x->query('//button[@data-compact-details]')->item(0)->getAttribute('data-compact-details'),true,512,JSON_THROW_ON_ERROR); }
$pdo=db();
$pdo->exec('CREATE TEMPORARY TABLE customers (id INT PRIMARY KEY,customer_code VARCHAR(32),name VARCHAR(100),contact VARCHAR(30),email VARCHAR(150),created_at DATETIME) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE orders (id INT PRIMARY KEY,order_code VARCHAR(50),customer_id INT NULL,customer_name VARCHAR(100),total DECIMAL(10,2),status VARCHAR(30),created_at DATETIME,voided_at DATETIME NULL) ENGINE=InnoDB');
$insert=$pdo->prepare('INSERT INTO customers VALUES (?,?,?,?,?,?)');
foreach ([[1,'C001','Ada','09171234567','ada@test.local'],[2,'C002','Ben',null,null],[3,'C003','Guest',null,null],[4,'C004',' ',null,null],[5,'C005','<script>alert(1)</script>','0917', '"<img>@test.local']] as $row) $insert->execute(array_merge($row,['2026-10-01 09:00:00']));
$insert=$pdo->prepare('INSERT INTO orders VALUES (?,?,?,?,?,?,?,?)');
foreach ([[1,'ORD-ADA',1,'Ada',100,'completed',null],[2,'ORD-ADA-P',1,'Ada',50,'pending',null],[3,'ORD-ADA-V',1,'Ada',75,'voided','2026-10-02 10:00:00'],[4,'NAMED-01',null,'Legacy Name',80,'completed',null],[5,'NAMED-02',null,'Legacy Name',20,'pending',null],[6,'DROPPED',999,'Deleted Profile',60,'completed',null]] as [$id,$code,$customer,$name,$total,$status,$voided]) $insert->execute([$id,$code,$customer,$name,$total,$status,'2026-10-02 09:00:00',$voided]);
foreach ([['WALK',100,40,'completed'],['PEND',200,23,'pending'],['VOID',300,17,'voided']] as [$prefix,$start,$count,$status]) for ($i=1;$i<=$count;$i++) $insert->execute([$start+$i,$prefix.'-'.$i,null,$i%2?'--':'',125.5,$status,'2026-10-03 '.sprintf('%02d:00:00',($i%20)+1),$prefix==='VOID'&&$i!==17?'2026-10-04 10:00:00':null]);
$pdo->exec("ALTER TABLE orders ADD COLUMN service_type VARCHAR(20) NULL");
$pdo->exec("UPDATE orders SET service_type = CASE WHEN MOD(id, 2) = 0 THEN 'dine_in' ELSE 'take_out' END");
$before=hash('sha256',json_encode([$pdo->query('SELECT * FROM customers ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll()]));
$customers=customer_history_rows($pdo,'');
check(count($customers)===5,'Registered, legacy named and deleted-profile customers remain grouped; placeholder profiles are excluded');
$ada=array_values(array_filter($customers,fn($c)=>$c['customer_code']==='C001'))[0];
check((int)$ada['order_count']===3&&(float)$ada['lifetime_sales']===100.0,'Customer totals retain all order counts and completed-only sales');
check(count(customer_history_rows($pdo,'ada@test.local'))===1&&count(customer_history_rows($pdo,'09171234567'))===1,'Hidden email and phone values remain searchable');
check(count(customer_history_rows($pdo,'NAMED-01'))===1&&customer_history_rows($pdo,'NAMED-01')[0]['name']==='Legacy Name','Legacy named orders remain searchable by order code');
$walk=customer_walk_in_history($pdo,'');
check($walk['total']===40&&count($walk['orders'])===40,'Walk-in records continue to exclude pending and voided orders');
check(customer_walk_in_history($pdo,'guest')['total']===40&&customer_walk_in_history($pdo,'WALK-40')['total']===1,'Walk-in aliases and individual order codes remain searchable');
check(customer_status_history($pdo,'pending','')['total']===25&&customer_status_history($pdo,'void','')['total']===18,'Pending and void tabs include registered and walk-in orders');
$workspace=customer_history_workspace($pdo,'',['customers'=>1,'walkIn'=>3,'pending'=>2,'void'=>2]);
check($workspace['groups']['walkIn']['page']===3&&$workspace['groups']['pending']['page']===2&&$workspace['groups']['void']['page']===2,'Each customer-history tab keeps its independent requested page');
check($workspace['orderTotal']===46,'Orders tracked retains customer counts plus eligible walk-in totals');
foreach ($workspace['groups'] as $key=>$group) check(html($group['html'])->query('//tr[not(@data-compact-empty)]')->length<=10,$key.': response contains at most ten rows');
$visited=[]; for ($page=1;$page<=4;$page++) { $group=customer_history_page($walk['orders'],$page,'walkIn'); $x=html($group['html']); foreach ($x->query('//tr/td[1]//strong') as $code) $visited[]=$code->textContent; }
check(count($visited)===40&&count(array_unique($visited))===40,'All forty walk-in orders are reachable without duplicate page entries');
$clamped=customer_history_page($walk['orders'],999,'walkIn'); check($clamped['page']===4&&$clamped['pages']===4,'Excessive page numbers clamp to the final page');
$pages=customer_history_request_pages(['customers_page'=>['1'],'walkin_page'=>'-2','pending_page'=>'1000000','void_page'=>'bad']);
check($pages===['customers'=>1,'walkIn'=>1,'pending'=>1000000,'void'=>1],'Malformed and array page parameters are handled safely');
$x=html(customer_history_record_html($ada,'customers')); $d=detail($x);
check($x->query('//td')->length===6&&$x->query('//td[@data-label]')->length===6,'Customer rows combine seven columns into six labeled fields');
check($d['contact']==='09171234567'&&$d['email']==='ada@test.local'&&$d['sales']==='PHP 100.00','Customer Details retain full contacts and exact completed sales');
check($x->query('//a[contains(@href,"search=C001")]')->length===1,'Registered customer links retain their customer-code search');
$legacy=array_values(array_filter($customers,fn($c)=>$c['name']==='Legacy Name'))[0]; $x=html(customer_history_record_html($legacy,'customers'));
check(str_contains($x->query('//a')->item(0)->getAttribute('href'),'Legacy+Name')&&detail($x)['type']==='Named order customer (unregistered)','Legacy customers retain name-based history and their registration distinction');
$hostile=array_values(array_filter($customers,fn($c)=>$c['customer_code']==='C005'))[0]; $x=html(customer_history_record_html($hostile,'customers'));
check($x->query('//script|//img')->length===0&&detail($x)['title']==='<script>alert(1)</script>','Customer names and contact values cannot inject active markup');
$void=$pdo->query('SELECT * FROM orders WHERE id=317')->fetch(); $x=html(customer_history_record_html($void,'void')); $d=detail($x);
check($d['voided']==='Not recorded'&&$d['created']!=='Not recorded'&&str_contains($x->query('//td[2]')->item(0)->textContent,'Created:'),'Missing void timestamps are explicitly labeled with a separate creation-date fallback');
$void['order_code']='';$x=html(customer_history_record_html($void,'void'));
check($x->query('//td[1]//strong')->item(0)->textContent==='#317'&&str_contains($x->query('//a')->item(0)->getAttribute('href'),'search=317'),'Missing order codes retain ID labels and working order-history links');
$empty=customer_history_workspace($pdo,'no-such-record',[]);check(array_sum(array_column($empty['groups'],'total'))===0&&html($empty['groups']['customers']['html'])->query('//td[@colspan="6"]')->length===1,'Empty filtered tabs provide correctly sized empty states');
$config=customer_history_config();$activeTab='pending';$search='';$x=html(mainHtml('public/orders/customers.php',compact('config','activeTab','search','workspace')));
check($x->query('//div[@role="tabpanel"]')->length===4&&$x->query('//div[@role="tabpanel" and not(@hidden)]/@id')->item(0)->nodeValue==='pendingRecordsPanel','Initial rendering restores the active tab from the URL');
check($x->query('//nav[@data-compact-pagination]')->length===4&&$x->query('//table/thead/tr/th')->length===24,'All four history tabs have their own pager and six compact columns');
check($x->query('//dialog')->length===2&&$x->query('//noscript//a')->length>=4,'Customer and order details have native dialogs and pagination also has a no-JavaScript fallback');
$state=json_decode($x->query('//script[@id="customerHistoryState"]')->item(0)->textContent,true,512,JSON_THROW_ON_ERROR);
check($state['groups']['walkIn']['page']===3&&$state['groups']['pending']['page']===2,'Initial client state matches the server-rendered pages');
$source=source('public/catalog/categories.php');$start=strpos($source,'function category_image_src(');$end=strpos($source,'?>',$start);eval(substr($source,$start,$end-$start));
$categories=[];for($id=1;$id<=12;$id++)$categories[]=['id'=>$id,'name'=>$id===12?'"<script>bad</script>':'Category '.$id,'image'=>null,'item_count'=>$id%2?4:0];
$totalCategories=12;$totalLinkedItems=24;$flashMessage='';$reopenCreateModal=false;
$x=html(mainHtml('public/catalog/categories.php',compact('categories','totalCategories','totalLinkedItems','flashMessage','reopenCreateModal')));
check($x->query('//table/thead/tr/th')->length===3&&$x->query('//tr[@data-compact-row]')->length===12,'Category list combines five columns into three and retains every category');
check($x->query('//tr[@data-usage="linked"]')->length===6&&$x->query('//tr[@data-usage="empty"]')->length===6,'Category usage filters distinguish linked and empty categories');
check($x->query('//a[@href="items.php?item_category=7"]')->length===1,'Linked-item counts open Manage Item with the matching category filter');
check($x->query('//a[contains(@href,"edit_category.php")]')->length===12&&$x->query('//a[contains(@href,"delete_category.php") and @data-confirm-type="danger"]')->length===12,'Category Edit and confirmed Delete remain available for every row');
check($x->query('//tr[@data-compact-row]/td[@data-label]')->length===36&&$x->query('//img[@loading="lazy"]')->length===12&&$x->query('//script')->length===0,'Category mobile fields have labels and hostile names render as text');
$categories=[];$x=html(mainHtml('public/catalog/categories.php',compact('categories','totalCategories','totalLinkedItems','flashMessage','reopenCreateModal')));check($x->query('//tr[@data-compact-empty and not(@hidden)]/td[@colspan="3"]')->length===1,'Empty category lists keep a clear three-column empty state');
check(str_contains($source,'name="csrf"')&&str_contains($source,'action="add_category.php"')&&str_contains($source,'require_admin();'),'Existing category creation CSRF and administrator authorization are retained');
$source=source('public/orders/customers.php');check(str_contains($source,"require_capability('view_order_history');")&&str_contains($source,'if (!is_admin() && !is_manager()) deny_access();'),'Customer history retains its existing administrator/manager authorization');
$after=hash('sha256',json_encode([$pdo->query('SELECT * FROM customers ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll()]));check($before===$after,'Rendering and pagination never change customer or order records');
check(order_customer_display('Walk-in','dine_in')==='Dine-in' && order_customer_display('', 'take_out')==='Take-out', 'Unnamed orders display the service type instead of Walk-in');
check(order_customer_display('Ana','dine_in')==='Ana', 'Real customer names remain separate from service type');
$typeResults=customer_walk_in_history($pdo,'dine in');
check($typeResults['total']===20 && count(array_filter($typeResults['orders'],static fn($row)=>$row['service_type']!=='dine_in'))===0, 'Dine-in search returns only matching unnamed orders');
$typeHtml=customer_history_record_html(['id'=>999,'order_code'=>'TYPE-TEST','customer_name'=>'Walk-in','service_type'=>'take_out','total'=>115,'status'=>'completed','created_at'=>'2026-10-24 09:00:00'], 'walkIn');
check(str_contains($typeHtml,'Take-out') && !str_contains($typeHtml,'Walk-in'), 'Customer History renders service type for anonymous orders');
echo 'All ',$checks,' category/customer rendering and query checks passed. Only connection-local temporary tables were used.',PHP_EOL;
