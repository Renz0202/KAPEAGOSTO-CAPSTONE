<?php
// Render real templates with role/data fixtures; compare recipe previews to the POS rules.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/db.php';
require_once dirname(__DIR__).'/includes/dashboard_inventory_forecast.php';
require_once dirname(__DIR__).'/includes/inventory_requirements.php';
require_once dirname(__DIR__).'/includes/user_utils.php';
require_once dirname(__DIR__).'/includes/management_lists.php';
$auth=file_get_contents(dirname(__DIR__).'/includes/auth.php');$start=strpos($auth,'function normalize_role_name(');$end=strpos($auth,'function suspend_inactive_user_session(',$start);eval(substr($auth,$start,$end-$start));
function current_user(){return ['role'=>$GLOBALS['testRole']??'admin'];}
function is_admin(){return ($GLOBALS['testRole']??'admin')==='admin';}
function source($path){return str_replace("\r\n","\n",file_get_contents(dirname(__DIR__).'/'.$path));}
$usersSource=source('public/users/users.php');$start=strpos($usersSource,'function user_avatar_src(');$end=strpos($usersSource,'?>',$start);eval(substr($usersSource,$start,$end-$start));
$itemsSource=source('public/catalog/items.php');$start=strpos($itemsSource,'function item_image_src(');$end=strpos($itemsSource,'$editModalItems =',$start);eval(substr($itemsSource,$start,$end-$start));
$dashboardSource=source('public/pages/dashboard.php');$start=strpos($dashboardSource,'function dashboard_scalar(');$end=strpos($dashboardSource,"if (isset(\$_GET['ajax_dashboard_counts'])",$start);eval(substr($dashboardSource,$start,$end-$start));
$checks=0;
function check($value,$label){global $checks;if(!$value)throw new RuntimeException('FAIL: '.$label);echo 'PASS: ',$label,PHP_EOL;$checks++;}
function mainHtml($path,array $variables):string{$s=source($path);$s=str_replace("dirname(__DIR__) . '/partials/inventory_forecast_workspace.php'",var_export(dirname(__DIR__).'/public/partials/inventory_forecast_workspace.php',true),$s);$start=strpos($s,'<main ');$end=strpos($s,'</main>',$start)+strlen('</main>');extract($variables);ob_start();eval('?>'.substr($s,$start,$end-$start));return ob_get_clean();}
function html($value):DOMXPath{$doc=new DOMDocument();@$doc->loadHTML('<!doctype html><meta charset="utf-8">'.$value);return new DOMXPath($doc);}
function details(DOMXPath $x,$id){return json_decode($x->query('//tr[@data-row-id="'.$id.'"]//button[@data-compact-details]')->item(0)->getAttribute('data-compact-details'),true,512,JSON_THROW_ON_ERROR);}
$users=[];foreach([[1,'Admin','admin','active'],[2,'viewer','admin','active'],[3,'team','manager','active'],[4,'staff','staff','inactive'],[5,'<script>alert(1)</script>','cashier','active']]as[$id,$name,$role,$status])$users[]=['id'=>$id,'username'=>$name,'full_name'=>'Test '.$name,'last_name'=>'','first_name'=>'','middle_name'=>'','email'=>$id===5?'"<script>@test.local':'account'.$id.'@test.local','contact'=>'0917000000'.$id,'image'=>null,'role'=>$role,'role_id'=>$id,'registered_at'=>$id===5?null:'2026-10-09 11:12:13','status'=>$status];
$vars=['users'=>$users,'totalUsers'=>5,'activeUsers'=>4,'inactiveUsers'=>1,'adminUsers'=>2,'managerUsers'=>1,'cashierUsers'=>2,'currentUserId'=>2,'flashMessage'=>'','reopenCreateModal'=>false,'reopenEditModal'=>false];
$x=html(mainHtml('public/users/users.php',$vars));
check($x->query('//table/thead/tr/th')->length===5&&$x->query('//tr[@data-compact-row]')->length===5,'User table combines nine columns into five while retaining every account');
check($x->query('//tr[@data-row-id="1"]//a')->length===0&&$x->query('//tr[@data-row-id="1"]//*[contains(@class,"protected-tag")]')->length===1,'The default administrator remains protected from account actions');
check($x->query('//tr[@data-row-id="2"]//a[contains(@class,"deactivate") or contains(@class,"delete") or contains(@class,"activate")]')->length===0&&$x->query('//tr[@data-row-id="2"]//a[contains(@class,"js-open-edit-user")]')->length===1,'The current account retains Edit without deactivate or delete controls');
check($x->query('//tr[@data-row-id="3"]//a[contains(@href,"delete_user.php") and @data-confirm]')->length===1&&$x->query('//tr[@data-row-id="3"]//a[contains(@href,"delete_user_permanent.php") and @data-confirm-type="danger"]')->length===1,'Other active accounts retain confirmed deactivate and permanent-delete actions');
check($x->query('//tr[@data-row-id="4"]//a[contains(@href,"activate_user.php") and @data-confirm]')->length===1&&$x->query('//tr[@data-row-id="4"][@data-role="cashier"]')->length===1,'Inactive accounts retain Activate and legacy staff roles filter as Cashier');
check(details($x,3)['contact']==='09170000003'&&details($x,3)['email']==='account3@test.local'&&str_contains(details($x,3)['registered'],'11:12'),'Full contact information and registration time remain in Account Details');
check(details($x,5)['registered']==='Not recorded'&&$x->query('//script')->length===0,'Missing registration dates and hostile account text render safely');
check(str_contains($x->query('//tr[@data-row-id="3"]')->item(0)->getAttribute('data-search'),'account3@test.local'),'Hidden contact fields remain available to account search');
check($x->query('//tr[@data-compact-row]/td[@data-label]')->length===25&&$x->query('//dialog[@id="userListDetails"]')->length===1,'Every mobile account field has a label and details have a native dialog');
check($x->query('//a[contains(@class,"js-open-create-user")]')->length===1&&$x->query('//select[@name="user_role"]')->length===1&&$x->query('//select[@name="user_status"]')->length===1,'Create User and both new account filters are available');
$vars['users']=[];$vars['totalUsers']=0;$x=html(mainHtml('public/users/users.php',$vars));check($x->query('//tr[@data-compact-empty and not(@hidden)]')->length===1,'An empty user database displays an explicit empty state');
$pdo=db();foreach([
'inventory'=>'id INT PRIMARY KEY,name VARCHAR(100),sku VARCHAR(64),cup_size VARCHAR(50) NULL,unit VARCHAR(20),material_type VARCHAR(20),qty DECIMAL(10,2),critical_level DECIMAL(10,2) NULL',
'items'=>'id INT PRIMARY KEY,name VARCHAR(100),cup_size VARCHAR(50)',
'item_requirements'=>'item_id INT,inventory_id INT,quantity_needed DECIMAL(10,2)'
]as$table=>$columns)$pdo->exec("CREATE TEMPORARY TABLE `$table` ($columns) ENGINE=InnoDB");
$insert=$pdo->prepare('INSERT INTO inventory VALUES (?,?,?,?,?,?,?,?)');
foreach([[1,'FRESH MILK','MILK',null,'ml','raw_material'],[2,'CUP','CUP12','12oz Hot','pcs','packaging'],[3,'CUP','CUP16','16oz','pcs','packaging'],[4,'CUP','CUP22','22oz','pcs','packaging'],[5,'LID','LID12','12oz Hot','pcs','packaging'],[6,'LID','LID16','16oz','pcs','packaging'],[7,'STRAW','STRAW',null,'pcs','packaging'],[8,'WIDE STRAW','WIDE',null,'pcs','packaging'],[9,'LID','LID22','22oz','pcs','packaging'],[10,'COFFEE STIRRER','STIRRER',null,'pcs','packaging']]as$row)$insert->execute(array_merge($row,[1000,5]));
$pdo->exec("INSERT INTO items VALUES (1,'LATTE','22oz'),(2,'HOT LATTE','12oz Hot'),(3,'LATTE','16oz')");
$pdo->exec('INSERT INTO item_requirements VALUES (1,1,175.5),(2,1,110),(3,1,125)');
$inventory=$pdo->query('SELECT * FROM inventory ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
function inventorySnapshot(){global $pdo;return hash('sha256',json_encode($pdo->query('SELECT * FROM inventory ORDER BY id')->fetchAll()));}
$before=inventorySnapshot();
foreach([1,2,3]as$id){$item=$pdo->query('SELECT * FROM items WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);$recipe=[];foreach($pdo->query('SELECT * FROM item_requirements WHERE item_id='.$id)->fetchAll(PDO::FETCH_ASSOC)as$row)$recipe[(int)$row['inventory_id']]=$row['quantity_needed'];$preview=management_item_recipe($item,$inventory,$recipe);$requirements=inventory_service_requirements($pdo,[['id'=>$id,'qty'=>1]]);$matches=true;foreach($requirements as $row){$label=$row['name'].(!empty($row['cup_size'])?' ('.$row['cup_size'].')':'').': '.inventory_service_format_quantity((float)$row['quantity']).' '.$row['unit'];$matches=$matches&&str_contains($preview,$label);}check($matches,'Item '.$id.' preview matches the actual POS ingredient and packaging quantities');}
$cold=management_item_recipe(['name'=>'LATTE','cup_size'=>'22oz'],$inventory,[1=>175.5]);check(str_contains($cold,'CUP (22oz): 1 pcs (automatic)')&&str_contains($cold,'STRAW: 1 pcs (automatic)')&&!str_contains($cold,'16oz')&&!str_contains($cold,'STIRRER')&&!str_contains($cold,'WIDE STRAW'),'Cold preview uses only its size and the same default straw ID as POS');
$hot=management_item_recipe(['name'=>'HOT LATTE','cup_size'=>'12oz Hot'],$inventory,[1=>110]);check(str_contains($hot,'COFFEE STIRRER: 1 pcs (automatic)')&&!str_contains($hot,'STRAW'),'Hot preview uses a stirrer and excludes cold straws');
$override=management_item_recipe(['name'=>'LATTE','cup_size'=>'22oz'],$inventory,[1=>175.5,4=>2,8=>1]);check(str_contains($override,'CUP (22oz): 2 pcs')&&str_contains($override,'WIDE STRAW: 1 pcs')&&!str_contains($override,"\nSTRAW:")&&!str_contains($override,'CUP (22oz): 1 pcs'),'Explicit packaging quantities suppress the corresponding automatic defaults');
$wrong=management_item_recipe(['name'=>'LATTE','cup_size'=>'22oz'],$inventory,[1=>175.5,3=>1]);check(str_contains($wrong,'does not match this serving size')&&str_contains($wrong,'CUP (22oz): 1 pcs (automatic)'),'An incompatible saved material is marked for recipe review');
$missing=management_item_recipe(['name'=>'LATTE','cup_size'=>'22oz'],array_filter($inventory,fn($row)=>(int)$row['id']!==4),[1=>175.5]);check(str_contains($missing,'Cup 22oz: missing from inventory'),'Missing automatic packaging is identified explicitly');
check(str_contains(management_item_recipe(['name'=>'LATTE','cup_size'=>'16oz'],$inventory,[]),'No ingredients configured'),'An unconfigured recipe is labeled instead of appearing complete');
$items=[];foreach([['id'=>1,'name'=>'LATTE','cup_size'=>'22oz'],['id'=>2,'name'=>'HOT LATTE','cup_size'=>'12oz Hot'],['id'=>3,'name'=>'<script>alert(1)</script>','cup_size'=>'16oz']]as$row)$items[]=$row+['sku'=>'ITEM-'.$row['id'],'category'=>'Coffee','category_id'=>1,'price'=>123.45,'image'=>null,'recipe_source'=>'generated','recipe_notes'=>'<img src=x onerror=alert(1)>'];
$vars=['items'=>$items,'categories'=>[['id'=>1,'name'=>'Coffee']],'totalItems'=>3,'categoryCount'=>1,'sizes'=>['22oz'=>true,'12oz Hot'=>true,'16oz'=>true],'highestPrice'=>123.45,'flashMessage'=>'','reopenCreateModal'=>false,'reopenEditId'=>0,'is_admin'=>true,'inventoryRecords'=>$inventory,'itemRequirements'=>[1=>[1=>175.5],2=>[1=>110],3=>[1=>125]]];
$x=html(mainHtml('public/catalog/items.php',$vars));
check($x->query('//table/thead/tr/th')->length===6&&$x->query('//tr[@data-compact-row]')->length===3,'Admin item table combines eight columns into six');
check($x->query('//a[contains(@class,"js-open-edit-item")]')->length===3&&$x->query('//a[contains(@href,"delete_item.php") and @data-confirm]')->length===3,'Every admin item retains Edit and confirmed Delete');
check(details($x,1)['price']==='PHP 123.45'&&str_contains(details($x,1)['recipe'],'175.5 ml')&&str_contains(details($x,1)['source'],'Generated'),'Item Details retain exact prices, fractional recipes and generated provenance');
check($x->query('//script|//img[@onerror]')->length===0&&details($x,3)['notes']==='<img src=x onerror=alert(1)>','Item names and recipe notes cannot inject active HTML');
check($x->query('//tr[@data-compact-row]/td[@data-label]')->length===18&&$x->query('//img[@loading="lazy"]')->length===3,'Mobile item cells have labels and thumbnails load lazily');
$vars['is_admin']=false;$GLOBALS['testRole']='manager';$x=html(mainHtml('public/catalog/items.php',$vars));
check($x->query('//table/thead/tr/th')->length===5&&$x->query('//a[contains(@href,"edit_item.php") or contains(@href,"delete_item.php")]')->length===0&&$x->query('//button[contains(@class,"js-open-create-item")]')->length===0,'Read-only roles retain five columns without create/edit/delete controls');
check(!isset(details($x,1)['recipe'])&&!isset(details($x,1)['notes'])&&$x->query('//*[@data-compact-field="recipe"]')->length===0,'Read-only catalog views expose no admin recipe or inventory details');
$vars['items']=[];$vars['totalItems']=0;$x=html(mainHtml('public/catalog/items.php',$vars));check($x->query('//tr[@data-compact-empty]/td[@colspan="5"]')->length===1,'Empty read-only item tables use the correct column count');
check(inventorySnapshot()===$before,'Viewing recipe details does not change real or temporary stock');
$GLOBALS['testRole']='admin';
$start=strpos($dashboardSource,'<main ');$end=strpos($dashboardSource,'</main>',$start);$fragment=substr($dashboardSource,$start,$end-$start);preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/',$fragment,$names);$vars=array_fill_keys(array_unique($names[1]),0);
$vars=array_merge($vars,['isIntelligenceDashboard'=>true,'isManagerDashboard'=>false,'dashboardTitle'=>'Dashboard','dashboardRoleLabel'=>'Admin','dashboardContextLabel'=>'Business Intelligence','dashboardSubtitle'=>'Sales and inventory overview','smartRecommendation'=>'Test recommendation','aiModeLabel'=>'Local forecast','aiModel'=>'Test model','bestSellerName'=>'LATTE','riskName'=>'FRESH MILK','riskDays'=>'5 days','forecastAccuracy'=>null,'topItem'=>null,'recentOrders'=>[],'topItems'=>[],'lowStockItems'=>[],'movementRows'=>[],'latestInventory'=>[],'inventoryPlanning'=>[]]);
$x=html(mainHtml('public/pages/dashboard.php',$vars));
check($x->query('//div[@data-dashboard-tabs]/section[contains(@class,"dashboard-kpis")]')->length===1&&$x->query('//section[@role="tabpanel"]//section[contains(@class,"dashboard-kpis")]')->length===0,'Dashboard totals stay outside the tab panels and visible in every section');
$root=$x->query('//div[@data-dashboard-tabs]')->item(0);$children=[];foreach($root->childNodes as $child)if($child instanceof DOMElement)$children[]=$child;
check($children[0]->getAttribute('aria-label')==='Key metrics'&&$children[1]->getAttribute('role')==='tablist','Dashboard puts key totals above its section tabs');
check($x->query('//button[@role="tab"]')->length===3&&$x->query('//section[@role="tabpanel"]')->length===3,'Dashboard retains Sales & Orders, Inventory and AI Forecast sections');
check($x->query('//div[@class="if-chart"]/canvas')->length===3,'Inventory projections, lead-time coverage and readiness each have bounded chart containers');
check($x->query('//*[@id="ifMaterial" or @id="ifHorizon" or @id="ifCost" or @id="ifSearch" or @id="ifStatus"]')->length===5,'Inventory planning exposes material, horizon, cost, search and status controls');
check($x->query('//div[contains(@class,"dashboard-chart-frame")]/canvas')->length===3,'Each sales chart has its own bounded responsive frame');
check($x->query('//*[@id="dashboardMonthlyRevenue" or @id="dashboardTodayOrders" or @id="dashboardInventoryUnits" or @id="dashboardActiveUsers"]')->length===4,'All four live metric targets remain present');
check($x->query('//a[@href="items.php"]')->length>=1&&$x->query('//a[@href="order_list.php"]')->length>=1,'Compact dashboard previews retain links to complete lists');
$vars['isManagerDashboard']=true;$x=html(mainHtml('public/pages/dashboard.php',$vars));check($x->query('//a[@href="ai_settings.php" or @href="inventory.php" or @href="orders.php"]')->length===0,'Manager dashboard links continue to use permitted review/settings destinations');
check(!str_contains($dashboardSource,'LIMIT 6')&&!str_contains($dashboardSource,'array_slice($movementLive, 0, 6)'),'Initial and live dashboard previews use the same five-record limits');
echo 'All ',$checks,' management rendering and recipe checks passed. Real accounts, products and stock were not changed.',PHP_EOL;
