<?php
  require_once __DIR__ . '/db.php';
  require_once __DIR__ . '/settings.php';
  require_once dirname(__DIR__) . '/public/partials/assets.php';

  // Cashiers use the dedicated horizontal POS shell rendered by topbar.php.
  if (function_exists('is_cashier') && is_cashier()) {
    return;
  }

  // Determine current script name (without query)
  $currentPage = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

  // Map modules to all related pages so the main item stays highlighted
  $modulePages = [
    'dashboard' => ['dashboard.php'],
    'users' => [
      'users.php','create_user.php','create_user_process.php','edit_user.php',
      'delete_user.php','delete_user_permanent.php','activate_user.php',
      'auth_login.php','create_user_process.php'
    ],
    'categories' => ['categories.php','edit_category.php','delete_category.php'],
    // Inventory module and related reporting pages.
    'inventory' => [
      'inventory.php','realtime_inventory.php','inventory_warning_data.php','restock.php','deduct.php',
      'reports.php','view_daily_report.php','generate_daily_report.php'
    ],
    'items' => ['items.php','add_item.php','edit_item.php','delete_item.php'],
    'orders' => [
      'orders.php','new_order.php','order_create.php','place_order.php','save_order.php',
      'order_item_add.php','order_item_cancel.php','order_payment.php','order_payment_data.php',
      'order_void.php','order_void_data.php','update_order_status.php','view_order.php','order_done.php','maintenance.php'
    ],
    'order_list' => ['order_list.php','order_list_admin_login.php','order_list_data.php','view_order.php']
  ];


  $isActive = function(array $pages) use ($currentPage) {
    return in_array($currentPage, $pages, true) ? 'active' : '';
  };

  try {
    $sidebarSettings = settings_all(db());
  } catch (Throwable $e) {
    $sidebarSettings = settings_defaults();
  }

  $sidebarSystemMode = strtolower(settings_value($sidebarSettings, 'system_mode'));
  $sidebarMaintenance = $sidebarSystemMode === 'maintenance';
  $sidebarStatusTitle = $sidebarMaintenance ? 'Maintenance' : 'Active';
  $sidebarStatusCopy = $sidebarMaintenance
    ? settings_value($sidebarSettings, 'maintenance_message')
    : settings_value($sidebarSettings, 'sidebar_status_copy');

  $sidebarRole = function_exists('current_user_role') ? current_user_role() : strtolower((string) ($_SESSION['user']['role'] ?? 'cashier'));
  if ($sidebarRole === 'business_admin') { $sidebarRole = 'admin'; }
  if ($sidebarRole === 'staff') { $sidebarRole = 'cashier'; }
  $isSidebarAdmin = $sidebarRole === 'admin';
  $isSidebarManager = $sidebarRole === 'manager';
  $isSidebarCashier = $sidebarRole === 'cashier';
  $primarySectionLabel = function_exists('role_display_name') ? role_display_name($sidebarRole) : ucfirst($sidebarRole);
?>
<div class="sidebar-wrapper">
  <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
    <div class="sidebar-header">
      <a href="dashboard.php" class="sidebar-brand" aria-label="Kape Agosto dashboard">
        <img
          class="sidebar-brand-logo sidebar-brand-logo-full"
          src="<?= htmlspecialchars(kape_asset_url('img/NEWLOGO_WHITE.png')) ?>"
          alt=""
          aria-hidden="true"
        >
        <img
          class="sidebar-brand-logo sidebar-brand-logo-cup"
          src="<?= htmlspecialchars(kape_asset_url('img/CUP_WHITE.png')) ?>"
          alt=""
          aria-hidden="true"
        >
      </a>

      <button type="button" class="sidebar-toggle js-sidebar-toggle" aria-label="Collapse sidebar" aria-controls="sidebar" aria-expanded="true">
        <i class="fa fa-bars" aria-hidden="true"></i>
      </button>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section">
        <span class="nav-section-label"><?= htmlspecialchars($primarySectionLabel) ?></span>
        <a href="dashboard.php" data-tooltip="Dashboard" class="<?= $isActive($modulePages['dashboard']) ?>">
          <i class="fa fa-table-cells-large"></i>
          <span>Dashboard</span>
        </a>

        <?php if ($isSidebarAdmin): ?>
          <a href="users.php" data-tooltip="User Management" class="<?= $isActive($modulePages['users']) ?>">
            <i class="fa fa-user-gear"></i>
            <span>User Management</span>
          </a>
        <?php endif; ?>
      </div>

      <?php if ($isSidebarAdmin): ?>
        <div class="nav-section">
          <span class="nav-section-label">Inventory</span>
          <a href="categories.php" data-tooltip="Manage Category" class="<?= $isActive($modulePages['categories']) ?>">
            <i class="fa fa-shapes"></i>
            <span>Manage Category</span>
          </a>
          <a href="inventory.php" data-tooltip="Manage Inventory" class="<?= $isActive($modulePages['inventory']) ?>">
            <i class="fa fa-box-archive"></i>
            <span>Manage Inventory</span>
          </a>
          <a href="items.php" data-tooltip="Manage Item" class="<?= $isActive($modulePages['items']) ?>">
            <i class="fa fa-mug-saucer"></i>
            <span>Manage Item</span>
          </a>
        </div>
      <?php elseif ($isSidebarManager): ?>
        <div class="nav-section">
          <span class="nav-section-label">Business Intelligence</span>
          <a href="reports.php" data-tooltip="Sales Reports" class="<?= $isActive($modulePages['inventory']) ?>">
            <i class="fa fa-chart-line"></i>
            <span>Sales Reports</span>
          </a>
          <a href="manager_settings.php" data-tooltip="Forecast & Team Settings" class="nav-link-multiline <?= $isActive(['manager_settings.php']) ?>">
            <i class="fa fa-sliders"></i>
            <span>Forecast & Team Settings</span>
          </a>
          <a href="items.php" data-tooltip="Item List" class="<?= $isActive($modulePages['items']) ?>">
            <i class="fa fa-mug-saucer"></i>
            <span>Item List</span>
          </a>
        </div>
      <?php elseif ($isSidebarCashier): ?>
        <div class="nav-section">
          <span class="nav-section-label">Inventory</span>
          <a href="items.php" data-tooltip="Item List" class="<?= $isActive($modulePages['items']) ?>">
            <i class="fa fa-mug-saucer"></i>
            <span>Item List</span>
          </a>
        </div>
      <?php endif; ?>

      <?php if ($isSidebarAdmin || $isSidebarCashier): ?>
        <div class="nav-section">
          <span class="nav-section-label">Sales</span>
          <a href="<?= $sidebarMaintenance ? 'maintenance.php' : 'orders.php' ?>" data-tooltip="<?= $sidebarMaintenance ? 'Transactions paused for maintenance' : 'Manage Order' ?>" class="<?= $isActive($modulePages['orders']) ?><?= $sidebarMaintenance ? ' maintenance-locked' : '' ?>">
            <i class="fa <?= $sidebarMaintenance ? 'fa-lock' : 'fa-cart-shopping' ?>"></i>
            <span>Manage Order</span>
          </a>
          <a href="order_list.php" data-tooltip="Order List" class="<?= $isActive($modulePages['order_list']) ?>">
            <i class="fa fa-list-check"></i>
            <span>Order List</span>
          </a>
          <?php if ($isSidebarAdmin): ?>
          <a href="customers.php" data-tooltip="Customer History" class="<?= $isActive(['customers.php']) ?>">
            <i class="fa fa-address-book"></i>
            <span>Customer History</span>
          </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($isSidebarManager): ?>
        <div class="nav-section">
          <span class="nav-section-label">Sales Review</span>
          <a href="order_list.php" data-tooltip="Order History" class="<?= $isActive($modulePages['order_list']) ?>">
            <i class="fa fa-list-check"></i>
            <span>Order History</span>
          </a>
          <a href="customers.php" data-tooltip="Customer History" class="<?= $isActive(['customers.php']) ?>">
            <i class="fa fa-address-book"></i>
            <span>Customer History</span>
          </a>
        </div>
      <?php endif; ?>
    </nav>

    <div class="sidebar-status<?= $sidebarMaintenance ? ' is-maintenance' : '' ?>" role="status">
      <span class="sidebar-status-title"><?= htmlspecialchars($sidebarStatusTitle) ?></span>
      <span class="sidebar-status-copy"><?= htmlspecialchars($sidebarStatusCopy) ?></span>
    </div>

    <footer class="sidebar-footer-info" aria-label="Business information">
      <p>&copy;  TechTitans2025 | All rights reserved.</p>
    </footer>
  </aside>
  <button type="button" class="sidebar-edge-toggle js-sidebar-toggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false">
    <i class="fa fa-bars" aria-hidden="true"></i>
  </button>
  <button type="button" class="sidebar-backdrop js-sidebar-close" aria-label="Close navigation" tabindex="-1"></button>
</div>
