<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/customer_history.php';
require_capability('view_order_history');
if (!is_admin() && !is_manager()) deny_access();
$pdo = db();
$search = is_string($_GET['search'] ?? '') ? trim(substr($_GET['search'] ?? '', 0, 160)) : '';
$config = customer_history_config();
$activeTab = is_string($_GET['history_tab'] ?? null) && isset($config[$_GET['history_tab']]) ? $_GET['history_tab'] : 'customers';
$workspace = customer_history_workspace($pdo, $search, customer_history_request_pages($_GET));
if (isset($_GET['ajax_customer_history'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($workspace, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer History - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/management-workspace.css')) ?>?v=<?= filemtime(dirname(__DIR__) . '/css/management-workspace.css') ?>">
</head>
<body class="kape-reports-module-reports compact-management-page customer-history-page">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>
<main class="content reports-page" id="customerHistoryWorkspace" data-active-tab="<?= management_list_escape($activeTab) ?>">
  <section class="reports-hero"><div><p class="reports-breadcrumb"><?= management_list_escape(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right" aria-hidden="true"></i> Customer History</p><h1>Customers &amp; Job Orders</h1><p>Review customer history, walk-ins, pending orders and voided records.</p></div><a class="reports-action muted" href="order_list.php?scope=all"><i class="fa fa-arrow-left" aria-hidden="true"></i> Full Order History</a></section>
  <section class="reports-toolbar customer-history-toolbar">
    <form method="get" class="management-list-filters customer-history-filters" id="customerHistoryFilters">
      <input type="hidden" name="history_tab" id="customerHistoryTabInput" value="<?= management_list_escape($activeTab) ?>">
      <label class="management-search-field" for="customer-search"><span>Search history</span><input id="customer-search" type="search" name="search" value="<?= management_list_escape($search) ?>" maxlength="160" placeholder="Customer, order code, Dine-in or Take-out..." autocomplete="off" aria-controls="customerRecordsPanel walkInRecordsPanel pendingRecordsPanel voidRecordsPanel"></label>
      <button class="reports-action primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i> Search</button>
      <button class="management-reset-button" id="customerHistoryReset" type="button">Reset</button>
    </form>
    <small id="customerHistoryUpdated" role="status">Auto-updates every 5 seconds</small>
  </section>
  <div class="customer-history-tabs" role="tablist" aria-label="Customer history records">
    <?php foreach ($config as $key=>$section): $active = $key === $activeTab; ?>
    <button class="customer-history-tab<?= $active ? ' is-active' : '' ?>" id="<?= $section['tab'] ?>" type="button" role="tab" aria-selected="<?= $active ? 'true' : 'false' ?>" aria-controls="<?= $section['panel'] ?>" data-history-tab="<?= $key ?>" tabindex="<?= $active ? '0' : '-1' ?>"><?= $section['title'] ?> <span data-history-count="<?= $key ?>"><?= number_format($workspace['groups'][$key]['total']) ?></span></button>
    <?php endforeach; ?>
  </div>
  <noscript><nav class="management-pagination" aria-label="History sections"><?php foreach ($config as $key=>$section): ?><a href="?<?= management_list_escape(http_build_query(['search'=>$search,'history_tab'=>$key])) ?>"><?= $section['title'] ?></a><?php endforeach; ?></nav></noscript>
  <div class="customer-history-panels">
    <?php foreach ($config as $key=>$section): $group = $workspace['groups'][$key]; ?>
    <div id="<?= $section['panel'] ?>" class="customer-history-panel" role="tabpanel" aria-labelledby="<?= $section['tab'] ?>" <?= $key !== $activeTab ? 'hidden' : '' ?>>
      <section class="reports-table-panel management-list-panel" data-customer-list="<?= $key ?>" data-page-param="<?= $section['page_param'] ?>" data-list-noun="<?= $section['noun'] ?>">
        <div class="reports-panel-heading"><div><span class="reports-eyebrow">History</span><h2><?= $section['title'] ?></h2><small><?= $section['hint'] ?></small></div><div><span class="reports-count-pill"><span data-history-count="<?= $key ?>"><?= number_format($group['total']) ?></span>&nbsp;<?= $section['noun'] ?></span><?php if ($key === 'customers'): ?><small class="customer-history-order-total"><span id="customerHistoryOrderTotal"><?= number_format($workspace['orderTotal']) ?></span> orders tracked</small><?php endif; ?></div></div>
        <div class="reports-table-wrap management-table-frame" tabindex="0" role="region" aria-label="<?= $section['title'] ?>">
          <table class="reports-table management-compact-table <?= $key === 'customers' ? 'customer-records-table' : 'customer-orders-table' ?>">
            <thead><tr><?php foreach ($key === 'customers' ? ['Customer','Orders','Completed Sales','Last Order','Details','History'] : ['Order',$key === 'void' ? 'Voided At' : 'Date & Time','Status','Total','Details','History'] as $heading): ?><th scope="col"><?= management_list_escape($heading) ?></th><?php endforeach; ?></tr></thead>
            <tbody id="<?= $section['body'] ?>"><?= $group['html'] ?></tbody>
          </table>
        </div>
        <?= management_list_pager($section['title']) ?>
        <noscript><nav class="management-pagination" aria-label="<?= $section['title'] ?> pages"><span>Page <?= $group['page'] ?> of <?= $group['pages'] ?></span><?php foreach ([-1=>'Previous',1=>'Next'] as $offset=>$label): $target = $group['page']+$offset; if ($target < 1 || $target > $group['pages']) continue; $query = ['search'=>$search,'history_tab'=>$key,$section['page_param']=>$target]; ?><a href="?<?= management_list_escape(http_build_query($query)) ?>"><?= $label ?></a><?php endforeach; ?></nav></noscript>
      </section>
    </div>
    <?php endforeach; ?>
  </div>
  <?= management_list_dialog('customerHistoryDetails', 'Customer Details', ['code'=>'Customer code','type'=>'Customer type','contact'=>'Phone / contact','email'=>'Email','created'=>'Registered / first recorded','orders'=>'Orders','sales'=>'Completed sales','last'=>'Last order']) ?>
  <?= management_list_dialog('customerOrderDetails', 'Order Details', ['code'=>'Order code','customer'=>'Customer','status'=>'Status','total'=>'Total','created'=>'Created at','voided'=>'Voided at']) ?>
  <script id="customerHistoryState" type="application/json"><?= json_encode($workspace, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/customer-history.js')) ?>?v=<?= filemtime(dirname(__DIR__) . '/scripts/customer-history.js') ?>" defer></script>
</body></html>
