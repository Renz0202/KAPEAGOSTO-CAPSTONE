<?php
// Query and render real movement-history helpers with connection-local temporary data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/inventory_history.php';
date_default_timezone_set('Asia/Manila');
$pdo = db();
$pdo->exec('CREATE TEMPORARY TABLE inventory (id INT PRIMARY KEY, sku VARCHAR(64), name VARCHAR(100), cup_size VARCHAR(50) NULL, unit VARCHAR(20), qty DECIMAL(10,2), critical_level DECIMAL(10,2) NULL) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE inventory_logs (id INT PRIMARY KEY, inventory_id INT, change_type VARCHAR(20), quantity DECIMAL(10,2), remaining DECIMAL(10,2) NULL, remarks TEXT NULL, created_at DATETIME) ENGINE=InnoDB');
$pdo->exec('ALTER TABLE inventory_logs ADD order_id INT NULL');
$pdo->exec('CREATE TEMPORARY TABLE orders (id INT PRIMARY KEY, order_code VARCHAR(50), created_at DATETIME) ENGINE=InnoDB');
$material = $pdo->prepare('INSERT INTO inventory VALUES (?,?,?,?,?,?,?)');
foreach ([[1,'INV-MILK','FRESH MILK',null,'ml',900,100],[2,'INV-CUP16','CUP','16oz','pcs',20,null],[3,'INV-CUP22','CUP','22oz','pcs',5,2],[4,'INV-PERCENT','PERCENT%_ MIX',null,'g',10,0],[5,'INV-QUOTES','<img src=x onerror=alert(1)>',null,'g',999,2],[6,'INV-TEA','TEA BASE',null,'ml',2000,0]] as $row) $material->execute($row);
$insert = $pdo->prepare('INSERT INTO inventory_logs (id,inventory_id,change_type,quantity,remaining,remarks,created_at) VALUES (?,?,?,?,?,?,?)');
$pdo->beginTransaction();
for ($id=1;$id<=600;$id++) $insert->execute([$id,1,'restock',0.5,1000+$id,null,'2026-10-09 10:00:00']);
foreach ([
 [601,2,'restock',5,25,null,'2026-10-08 23:59:59'],[602,3,'used',1,4,'POS order','2026-10-09 00:00:00'],
 [603,1,'auto_deduct',18,782,'Auto-deduct after POS Done','2026-10-09 10:00:00'],[604,2,'damage',2,18,'Cracked cups','2026-10-09 10:00:00'],
 [605,3,'adjustment',-1,4,'Count corrected','2026-10-09 10:00:00'],[606,4,'added',1,5,'First stock','2026-10-09 10:00:00'],
 [607,5,'adjustment',0.25,0.25,'<script>alert("unsafe")</script> "quoted" & remarks','2026-10-09 10:00:00'],
 [608,6,'restock',100,1900,'Auto usage after order','2026-10-09 10:00:00'],[609,6,'restock',100,2000,'Not autoXdeduct','2026-10-09 10:00:00'],
 [610,1,'restock',100,1000,null,'2026-10-10 00:00:00'],[611,1,'restock',10,null,'Legacy balance missing','2026-10-07 12:00:00']
] as $row) $insert->execute($row);
$pdo->commit();
$checks=0;
function check($pass,string $label):void {global $checks;if(!$pass)throw new RuntimeException('FAIL: '.$label);echo 'PASS: ',$label,PHP_EOL;$checks++;}
function history(array $extra=[]):array {global $pdo;return inventory_history_fetch($pdo,inventory_history_filters(array_merge(['from'=>'2026-10-09','to'=>'2026-10-09'],$extra)));}
function parseHtml(string $html):DOMXPath {$doc=new DOMDocument();@$doc->loadHTML('<!doctype html><meta charset="utf-8">'.$html);return new DOMXPath($doc);}
$before=hash('sha256',json_encode($pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll())).hash('sha256',json_encode($pdo->query('SELECT * FROM inventory_logs ORDER BY id')->fetchAll()));
$first=history();
check($first['total']===608&&$first['pages']===61&&count($first['rows'])===10,'Only ten of 608 matching movements load on each page');
check((int)$first['rows'][0]['id']===609&&(int)$first['rows'][1]['id']===608,'Equal timestamps use descending movement IDs for stable ordering');
$all=[];for($page=1;$page<=$first['pages'];$page++)foreach(history(['log_page'=>$page])['rows']as$row)$all[]=(int)$row['id'];
check(count($all)===608&&count(array_unique($all))===608&&in_array(1,$all,true),'Pagination exposes every record beyond the old 500-row limit without duplicates');
$last=history(['log_page'=>'999999']);check($last['page']===61&&count($last['rows'])===8,'Out-of-range pages clamp to the last available page');
$empty=history(['log_search'=>'No such material']);check($empty['total']===0&&$empty['page']===1&&$empty['rows']===[],'Empty searches return an empty first page');
check(history(['log_search'=>'CUP (16oz)'])['total']===1&&history(['log_search'=>'CUP (22oz)'])['total']===2,'Selecting an item suggestion matches its correct cup size');
check(history(['log_search'=>'CUP'])['total']===3&&history(['log_search'=>'INV-CUP22'])['total']===2,'Typed item names and SKU searches filter all matching movements');
check(history(['log_search'=>'%_'])['total']===1,'Percent and underscore in item names are searched literally');
check(history(['log_search'=>"' OR 1=1 --"])['total']===0,'Search input is bound as data instead of executable SQL');
check(history(['log_type'=>'used'])['total']===3,'Used filter includes legacy types and automatic-use remarks');
check(history(['log_type'=>'restock'])['total']===601,'Restock filter excludes automatic use and retains records with no remarks');
check(history(['log_type'=>'damage'])['total']===1&&history(['log_type'=>'adjustment'])['total']===2,'Damage and adjustment filters return only their movement types');
check(history(['log_search'=>'CUP (22oz)','log_type'=>'adjustment'])['total']===1,'Item, date, and type filters work together');
check(history(['from'=>'2026-10-08','to'=>'2026-10-08'])['total']===1&&history(['from'=>'2026-10-09','to'=>''])['total']===609,'Date boundaries include the selected full day and support open-ended ranges');
check(history(['from'=>'','to'=>'2026-10-08'])['total']===2,'An upper date bound can show older history');
$filters=inventory_history_filters(['from'=>'2026-02-30','to'=>[],'log_type'=>'invalid','log_page'=>'-4','log_search'=>[]]);
check($filters['from']===date('Y-m-d')&&$filters['to']===date('Y-m-d')&&$filters['log_type']===''&&$filters['log_page']===1&&$filters['log_search']==='','Invalid filter values safely fall back to defaults');
check(inventory_history_filters(['from'=>'2026-10-09','to'=>'2026-10-08'])['to']==='2026-10-09','Reversed date ranges normalize to the start date');
$html=inventory_history_render($first);$xpath=parseHtml($html);
check($xpath->query('//thead/tr/th')->length===6&&$xpath->query('//tbody/tr')->length===10,'Compact table renders six columns and ten movement rows');
check($xpath->query('//th[contains(.,"Remarks") or contains(.,"Critical")]')->length===0,'Long remarks and critical levels stay out of the compact table');
$button=$xpath->query('//button[@data-log-id="607"]')->item(0);$details=json_decode($button->getAttribute('data-log-details'),true,512,JSON_THROW_ON_ERROR);
check($details['balance']==='0.25 g'&&$details['current_stock']==='999 g','Balance After uses the historical recorded balance; current stock is separate in details');
check($details['remarks']==='<script>alert("unsafe")</script> "quoted" & remarks'&&$details['critical_level']==='2 g','Details retain complete remarks and critical levels');
check($xpath->query('//script|//img')->length===0,'Item names and remarks cannot inject active HTML into history');
$null=history(['from'=>'2026-10-07','to'=>'2026-10-07']);check(str_contains(inventory_history_render($null),'Not recorded'),'Missing historical balances are labeled instead of replaced with current stock');
$filtered=history(['log_search'=>'MILK','log_type'=>'restock','log_page'=>'3']);$links=parseHtml(inventory_history_render($filtered))->query('//a[@data-log-page]');
$query=[];parse_str(parse_url(html_entity_decode($links->item(1)->getAttribute('href')),PHP_URL_QUERY),$query);
check($query['log_page']==='4'&&$query['log_search']==='MILK'&&$query['log_type']==='restock'&&$query['from']==='2026-10-09'&&$query['tab']==='logs','Next-page links preserve every history filter and work without JavaScript');
check(parseHtml(inventory_history_render($last))->query('//button[@disabled and contains(.,"Next")]')->length===1,'The last page disables Next');
check(str_contains(inventory_history_render($empty),'Showing 0&ndash;0 of 0 movements'),'Empty history gives a clear zero-record count');
// Historical corrections must be distinguishable from fresh stock deductions.
$historical=$first;
$historical['rows']=[$first['rows'][0]];
$historical['rows'][0]['change_type']='used';
$historical['rows'][0]['original_sale_at']='2026-09-16 15:53:47';
$historical['rows'][0]['remarks']='Historical recipe estimate; order ID 63; current stock unchanged; batch example';
$past=parseHtml(inventory_history_render($historical));
check($past->query('//tbody/tr/td[3]/span[contains(.,"Past use")]')->length===1&&$past->query('//tbody/tr/td[3]/small[contains(.,"Estimate")]')->length===1,'Reconstructed usage is visibly labeled Past use and Estimate');
check($past->query('//tbody/tr/td[5]/small[contains(.,"When added")]')->length===1,'Historical balances identify the date the entry was added');
$pastDetails=json_decode($past->query('//button[@data-log-details]')->item(0)->getAttribute('data-log-details'),true,512,JSON_THROW_ON_ERROR);
check($pastDetails['type']==='Past use (estimate)'&&str_contains($pastDetails['remarks'],'Stock stayed the same')&&str_contains($pastDetails['remarks'],$historical['rows'][0]['remarks']),'Historical details explain the stock effect and retain complete audit remarks');
check($details['type']==='Adjustment'&&!str_contains($details['remarks'],'Stock stayed the same'),'Regular stock movements keep their original type and remarks');
$after=hash('sha256',json_encode($pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll())).hash('sha256',json_encode($pdo->query('SELECT * FROM inventory_logs ORDER BY id')->fetchAll()));
check($before===$after,'History browsing changes no inventory balances or movement records');
// Date queries must use the original sale date and preserve the date the repair was recorded.
$pdo->exec("INSERT INTO orders VALUES (1,'160926-01','2026-09-16 15:53:47'),(2,'081026-01','2026-10-08 00:09:02')");
$pdo->exec('UPDATE inventory_logs SET order_id=1 WHERE id=603');
$dateInsert=$pdo->prepare('INSERT INTO inventory_logs (id,inventory_id,change_type,quantity,remaining,remarks,created_at,order_id) VALUES (?,?,?,?,?,?,?,?)');
foreach ([
 [612,1,'used',50,900,'Historical recipe estimate; order ID 1; current stock unchanged','2026-10-10 06:55:51',1],
 [613,1,'used',10,900,'Historical recipe estimate; order ID 2; current stock unchanged','2026-10-10 06:55:51',2],
 [614,1,'used',10,900,'Historical recipe estimate; order ID 999; current stock unchanged','2026-10-10 06:55:51',999],
 [615,1,'restock',5,900,'Historical recipe estimate; invalid non-usage marker','2026-10-10 06:55:51',1],
] as $row) $dateInsert->execute($row);
$datesBefore=hash('sha256',json_encode([$pdo->query('SELECT * FROM inventory_logs ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll()]));
$oldSale=history(['from'=>'2026-09-16','to'=>'2026-09-16','log_type'=>'used']);
check($oldSale['total']===1&&(int)$oldSale['rows'][0]['id']===612&&$oldSale['rows'][0]['movement_at']==='2026-09-16 15:53:47','Past use filters follow the original sale date');
check($oldSale['rows'][0]['created_at']==='2026-10-10 06:55:51','The date the correction was recorded is retained');
$saleHtml=parseHtml(inventory_history_render($oldSale));
check($saleHtml->query('//tbody/tr/td[1]/time[@datetime="2026-09-16T15:53:47+08:00"]')->length===1,'The table displays the original sale timestamp');
$saleDetails=json_decode($saleHtml->query('//button[@data-log-details]')->item(0)->getAttribute('data-log-details'),true,512,JSON_THROW_ON_ERROR);
check($saleDetails['movement_at']==='September 16, 2026 03:53:47 PM'&&$saleDetails['recorded_at']==='October 10, 2026 06:55:51 AM'&&$saleDetails['order_reference']==='160926-01','Details separately show sale date, recorded date and order reference');
check(history(['from'=>'2026-10-10','to'=>'2026-10-10','log_type'=>'used'])['total']===1&&history()['total']===608,'Late entries leave the correction-day usage list while ordinary movements retain their dates');
$fallback=history(['from'=>'2026-10-10','to'=>'2026-10-10','log_type'=>'used']);
$fallbackHtml=parseHtml(inventory_history_render($fallback));
$fallbackDetails=json_decode($fallbackHtml->query('//button[@data-log-details]')->item(0)->getAttribute('data-log-details'),true,512,JSON_THROW_ON_ERROR);
check(str_contains($fallbackDetails['remarks'],'Original sale date is unavailable')&&$fallbackHtml->query('//td[1]/small[contains(.,"Sale date unknown")]')->length===1,'An orphan history record uses its recorded date and explicitly identifies the missing sale date');
$range=['from'=>'2026-09-16','to'=>'2026-10-10'];
$allDates=history($range);$dateIds=[];
for($page=1;$page<=$allDates['pages'];$page++)foreach(history(array_merge($range,['log_page'=>$page]))['rows']as$row)$dateIds[]=(int)$row['id'];
check(count($dateIds)===615&&count(array_unique($dateIds))===615&&end($dateIds)===612,'Pagination sorts by sale or movement date and returns every entry once');
check(history(['from'=>'2026-09-16','to'=>'2026-09-16','log_type'=>'restock'])['total']===0,'A non-usage entry cannot acquire an old sale date from a copied estimate marker');
$datesAfter=hash('sha256',json_encode([$pdo->query('SELECT * FROM inventory_logs ORDER BY id')->fetchAll(),$pdo->query('SELECT * FROM orders ORDER BY id')->fetchAll()]));
check($datesBefore===$datesAfter,'Date filtering and display change no saved timestamps or order records');
echo 'All ',$checks,' movement query and rendering checks passed. Real data was not changed.',PHP_EOL;
