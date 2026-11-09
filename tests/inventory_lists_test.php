<?php
// Read-only list helpers exercised against connection-local temporary tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_service.php';
require_once dirname(__DIR__) . '/includes/material_catalog.php';
require_once dirname(__DIR__) . '/includes/inventory_history.php';
require_once dirname(__DIR__) . '/includes/inventory_lists.php';
date_default_timezone_set('Asia/Manila');
$source = file_get_contents(dirname(__DIR__) . '/public/inventory_module/inventory.php');
$start = strpos($source,'function formatQty('); $end = strpos($source,'function unitFromPost(', $start);
eval(substr($source,$start,$end-$start));
$pdo = db();
foreach ([
 'inventory'=>'id INT PRIMARY KEY, sku VARCHAR(64), name VARCHAR(100), material_type VARCHAR(20), material_group VARCHAR(40), cup_size VARCHAR(50) NULL, unit VARCHAR(20), qty DECIMAL(10,2), critical_level DECIMAL(10,2) NULL, date_added DATETIME',
 'inventory_logs'=>'id INT PRIMARY KEY, inventory_id INT, change_type VARCHAR(20), created_at DATETIME',
 'users'=>'id INT PRIMARY KEY, username VARCHAR(100)',
 'orders'=>'id INT PRIMARY KEY, order_code VARCHAR(50) NULL, user_id INT NULL, customer_name VARCHAR(100) NULL, service_details TEXT NULL, total DECIMAL(10,2), status VARCHAR(20), created_at DATETIME, voided_at DATETIME NULL, cancelled_at DATETIME NULL'
] as $table=>$columns) $pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
$insert=$pdo->prepare('INSERT INTO inventory VALUES (?,?,?,?,?,?,?,?,?,?)');
for($i=1;$i<=25;$i++) $insert->execute([$i,'TEST-'.$i,'MATERIAL '.sprintf('%02d',$i),'raw_material',$i<=15?'dairy':'powders',null,'ml',$i===1?0:($i===2?5:100.25),10,'2026-10-08 11:12:13']);
$insert->execute([26,'CUP16','CUP','packaging','packaging','16oz','pcs',50,null,'2026-10-08 11:12:13']);
$insert->execute([27,'CUP22','CUP','packaging','packaging','22oz','pcs',60,null,'2026-10-08 11:12:13']);
$insert->execute([28,'PERCENT','<script>%_ unsafe</script>','raw_material','powders',null,'g',1,0,'2026-10-08 11:12:13']);
$pdo->exec("INSERT INTO inventory_logs VALUES (1,1,'restock','2026-10-09 12:34:56')");
$pdo->exec("INSERT INTO users VALUES (1,'TEST CASHIER')");
$insert=$pdo->prepare('INSERT INTO orders VALUES (?,?,?,?,?,?,?,?,?,?)');
$today=date('Y-m-d');$yesterday=(new DateTimeImmutable('yesterday'))->format('Y-m-d');$tomorrow=(new DateTimeImmutable('tomorrow'))->format('Y-m-d');
for($i=1;$i<=35;$i++)$insert->execute([$i,'TEST-ORDER-'.$i,1,'Customer '.$i,'<script>Text only</script>',123.45,$i%2?'void':'cancelled',$yesterday.' 09:00:00',$i%2?$today.' 10:00:00':null,$i%2?null:$today.' 10:00:00']);
$insert->execute([36,'OLDER',null,null,null,99,'voided',$yesterday.' 11:00:00',null,null]);
$insert->execute([37,'NEXT-DAY',1,'Tomorrow','',1,'cancelled',$today.' 08:00:00',null,$tomorrow.' 00:00:00']);
$insert->execute([38,'COMPLETED',1,'Normal','',1,'completed',$today.' 10:00:00',null,null]);
$insert->execute([39,'QUOTE%_',1,'Literal %_ customer','',1,'voided',$today.' 00:00:00',null,null]);
$insert->execute([40,'<img src=x onerror=alert(1)>',null,'<script>customer</script>','<script>service</script>',1,'voided',$today.' 12:00:00',$today.' 12:00:00',null]);
$checks=0;
function check($pass,$label){global $checks;if(!$pass)throw new RuntimeException('FAIL: '.$label);echo 'PASS: ',$label,PHP_EOL;$checks++;}
function html(string $value):DOMXPath {$doc=new DOMDocument();@$doc->loadHTML('<!doctype html><meta charset="utf-8">'.$value);return new DOMXPath($doc);}
function stock(array $extra=[]):array {global $pdo;$filters=inventory_stock_filters($extra);return inventory_stock_page(filterInventory(fetchInventory($pdo,$filters['search']),$filters['stock_category'],$filters['stock_status'],$filters['material_group']),$filters);}
function exceptions(array $extra=[]):array {global $pdo;return inventory_void_fetch($pdo,inventory_void_filters($extra));}
function snapshot():string {global $pdo;$rows=[];foreach(['inventory','inventory_logs','orders']as$table)$rows[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);return hash('sha256',json_encode($rows));}
$before=snapshot();$first=stock();
check($first['total']===28&&$first['pages']===3&&count($first['rows'])===10,'Current Stock renders ten of 28 records per page');
$ids=[];for($i=1;$i<=3;$i++)foreach(stock(['stock_page'=>$i])['rows']as$row)$ids[]=(int)$row['id'];
check(count(array_unique($ids))===28&&array_slice($ids,-2)===[26,27],'Stock grouping is stable across pages and includes every cup size');
check(stock(['stock_page'=>'999'])['page']===3&&count(stock(['stock_page'=>3])['rows'])===8,'Current Stock clamps excessive page numbers');
check(stock(['material_group'=>'dairy','stock_status'=>'low','stock_category'=>'raw_material'])['total']===1,'Material group, type and low-stock filters combine');
check(stock(['stock_status'=>'out_of_stock'])['total']===1&&stock(['stock_status'=>'in_stock'])['total']===26,'Zero stock is separate from low stock and available stock');
check(stock(['search'=>'CUP','stock_category'=>'cup'])['total']===2&&stock(['search'=>'CUP22'])['total']===1,'Stock search supports item names and exact size SKUs');
check(stock(['search'=>'%_'])['total']===1&&stock(['search'=>"' OR 1=1 --"])['total']===0,'Stock searches treat wildcard characters literally and bind SQL input');
$invalid=inventory_stock_filters(['search'=>[],'stock_category'=>[],'material_group'=>[],'stock_status'=>'invalid','stock_page'=>'-2']);
check($invalid['search']===''&&$invalid['stock_category']===''&&$invalid['stock_status']===''&&$invalid['stock_page']===1,'Malformed stock filters fall back safely');
$x=html(inventory_stock_render($first));
check($x->query('//thead/tr/th')->length===6&&$x->query('//tbody/tr')->length===10,'Stock table has six compact columns and ten rows');
check($x->query('//ul')->length===0&&$first['low_count']===2,'Stock alert summarizes critical items without an unbounded list');
check($x->query('//button[contains(@class,"js-edit-inventory")]')->length===10&&$x->query('//form[input[@name="delete_inventory"]]')->length===10,'Each stock row retains Edit and confirmed Delete actions');
$button=$x->query('//button[@data-list-id="1"]')->item(0);$detail=json_decode($button->getAttribute('data-list-details'),true);
check($detail['status']==='Out of stock'&&$detail['critical']==='10 ml'&&str_contains($detail['restocked'],'12:34:56'),'Stock details show critical level, status and the exact latest restock');
$button=$x->query('//button[@data-list-id="2"]')->item(0);$detail=json_decode($button->getAttribute('data-list-details'),true);
check($detail['status']==='Low stock'&&$detail['restocked']==='Not recorded','Low stock and missing restock dates are represented accurately');
$x=html(inventory_stock_render(stock(['search'=>'CUP22'])));$edit=json_decode($x->query('//button[@data-inventory]')->item(0)->getAttribute('data-inventory'),true);
check($edit['cup_size']==='22oz'&&$edit['qty']===60&&$edit['material_type']==='packaging','Edit payload retains the correct size, quantity and material type');
$x=html(inventory_stock_render(stock(['search'=>'%_'])));check($x->query('//script|//img')->length===0,'Stock names cannot inject active HTML');
$x=html(inventory_stock_render(stock(['material_group'=>'dairy','stock_page'=>1])));$link=$x->query('//a[@data-list-page="2"]')->item(0);parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
check($params['material_group']==='dairy'&&$params['stock_page']==='2'&&$params['tab']==='stock','Stock pagination fallback preserves selected filters');
check(stock(['search'=>'missing'])['rows']===[]&&str_contains(inventory_stock_render(stock(['search'=>'missing'])),'Showing 0&ndash;0 of 0 items'),'Empty stock results report a clear zero count');
$first=exceptions();
check($first['total']===37&&$first['pages']===4&&count($first['rows'])===10,'Voided / Cancelled defaults to today and ten records per page');
check((int)$first['rows'][0]['id']===40&&(int)$first['rows'][1]['id']===35,'Exception ordering is stable for matching event times');
$ids=[];for($i=1;$i<=4;$i++)foreach(exceptions(['void_page'=>$i])['rows']as$row)$ids[]=(int)$row['id'];
check(count(array_unique($ids))===37&&!in_array(38,$ids,true)&&!in_array(37,$ids,true),'Every matching exception is available without completed or next-day orders');
check(exceptions(['void_status'=>'voided'])['total']===20&&exceptions(['void_status'=>'cancelled'])['total']===17,'Voided filter includes legacy void and voided statuses; Cancelled is separate');
check(exceptions(['void_from'=>$yesterday,'void_to'=>$yesterday])['total']===1&&exceptions(['void_from'=>$tomorrow,'void_to'=>$tomorrow])['total']===1,'Event-date filters include whole selected days and legacy timestamp fallback');
check(exceptions(['void_from'=>'','void_to'=>$yesterday])['total']===1,'Exception dates support an open-ended historical range');
check(exceptions(['void_search'=>'TEST-ORDER-35','void_status'=>'voided'])['total']===1&&exceptions(['void_search'=>'TEST CASHIER'])['total']===36,'Order reference and user searches combine with status');
check(exceptions(['void_search'=>'%_'])['total']===1&&exceptions(['void_search'=>"' OR 1=1 --"])['total']===0,'Exception search uses literal wildcards and bound parameters');
check(exceptions(['void_search'=>'#40'])['total']===1&&exceptions(['void_search'=>'Customer 20'])['total']===1,'Exceptions can be found by numeric order ID or customer');
check(exceptions(['void_page'=>'999'])['page']===4&&count(exceptions(['void_page'=>'999'])['rows'])===7,'Exception pages clamp after result counts shrink');
$invalid=inventory_void_filters(['void_from'=>'2026-02-30','void_to'=>[],'void_status'=>[],'void_search'=>[],'void_page'=>[]]);
check($invalid['void_from']===$today&&$invalid['void_to']===$today&&$invalid['void_search']===''&&$invalid['void_status']===''&&$invalid['void_page']===1,'Invalid exception filters safely return to today');
check(inventory_void_filters(['void_from'=>$today,'void_to'=>$yesterday])['void_to']===$today,'Exception date ranges normalize reversed dates');
check(inventory_void_today_count($pdo)===37,'The tab badge counts all exceptions today independently of selected filters');
$x=html(inventory_void_render($first));
check($x->query('//thead/tr/th')->length===6&&$x->query('//tbody/tr')->length===10,'Exception table has six columns and ten rows');
$detail=json_decode($x->query('//button[@data-list-id="40"]')->item(0)->getAttribute('data-list-details'),true);
check($detail['customer']==='<script>customer</script>'&&$detail['service']==='<script>service</script>'&&$detail['user']==='System','Details retain full customer and service text and handle missing users');
check($x->query('//script|//img')->length===0,'Order and customer text cannot inject active HTML');
$x=html(inventory_void_render(exceptions(['void_status'=>'voided'])));$link=$x->query('//a[@data-list-page="2"]')->item(0);parse_str(parse_url($link->getAttribute('href'),PHP_URL_QUERY),$params);
check($params['void_status']==='voided'&&$params['void_from']===$today&&$params['void_page']==='2'&&$params['tab']==='voided','Exception fallback links preserve dates, status and page');
check(str_contains(inventory_void_render(exceptions(['void_search'=>'missing'])),'Showing 0&ndash;0 of 0 orders'),'Empty exception filters return a useful zero-record message');
$legacy=html(inventory_void_render(exceptions(['void_search'=>'OLDER','void_from'=>$yesterday,'void_to'=>$yesterday])));
$detail=json_decode($legacy->query('//button[@data-list-id="36"]')->item(0)->getAttribute('data-list-details'),true);
check(str_starts_with($detail['event'],'Not recorded; order created')&&str_contains($legacy->query('//time')->item(0)->getAttribute('title'),'creation time'),'Legacy event dates explicitly identify the creation-time fallback');
$decimal=html(inventory_stock_render(stock(['search'=>'TEST-3'])));
$detail=json_decode($decimal->query('//button[@data-list-id="3"]')->item(0)->getAttribute('data-list-details'),true);
check($detail['quantity']==='100.25 ml','Fractional stock quantities and their units remain exact');
function renderFragment(string $anchor, array $variables):string {
    global $source;
    $start=strpos($source,$anchor);$end=strpos($source,'</form>',$start)+strlen('</form>');
    extract($variables);ob_start();eval('?>'.substr($source,$start,$end-$start));return ob_get_clean();
}
$markup=renderFragment('<form method="get" class="inventory-search-form inventory-list-filters"', ['search'=>'"<script>','stockMaterialGroup'=>'dairy','stockCategory'=>'raw_material','stockStatus'=>'low']);
$x=html($markup);
check($x->query('//form[@id="inventoryStockFilters"]//label[span and (input or select)]')->length===4&&$x->query('//script')->length===0,'The real stock filter form groups its four labeled fields and escapes user input');
$markup=renderFragment('<form method="get" class="inventory-filter-form inventory-list-filters"', ['voidFilters'=>inventory_void_filters(['void_status'=>'cancelled','void_search'=>'"<script>'])]);
$x=html($markup);
check($x->query('//form[@id="inventoryVoidFilters"]//label[span and (input or select)]')->length===4&&$x->query('//option[@value="cancelled" and @selected]')->length===1&&$x->query('//script')->length===0,'The real exception form groups its four fields and retains selected status safely');
check(snapshot()===$before,'Browsing the lists changes no stock, orders or movement records');
echo 'All ',$checks,' stock and exception query checks passed. Real data was not changed.',PHP_EOL;
