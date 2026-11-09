<?php
require_once dirname(__DIR__) . '/public/partials/assets.php';

$cashierUser = $_SESSION['user'] ?? [];
$cashierName = trim((string) ($cashierUser['full_name'] ?? $cashierUser['name'] ?? $cashierUser['username'] ?? 'Cashier'));
$cashierName = $cashierName !== '' ? $cashierName : 'Cashier';
$cashierFirstName = preg_split('/\s+/', $cashierName)[0] ?? $cashierName;
$cashierEmail = trim((string) ($cashierUser['email'] ?? ''));
$cashierEmail = $cashierEmail !== '' ? $cashierEmail : 'Cashier account';
$cashierImage = (string) ($cashierUser['image'] ?? '');
$cashierImageUrl = kape_asset_url('img/CUP_BLACK.png');

if ($cashierImage !== '') {
    $cashierImageName = basename($cashierImage);
    $cashierImagePath = dirname(__DIR__) . '/public/uploads/users/' . $cashierImageName;
    if (is_file($cashierImagePath)) {
        $cashierImageUrl = kape_asset_url('uploads/users/' . rawurlencode($cashierImageName));
    }
}

$cashierPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$cashierMaintenanceMode = function_exists('system_is_maintenance') && system_is_maintenance();
$registerPages = ['orders.php', 'order_payment.php', 'payment_create.php', 'payment_success.php', 'maintenance.php'];
$orderPages = ['order_list.php', 'view_order.php', 'receipt_template.php'];
$menuPages = ['items.php'];
$cashierPendingOrders = 0;
$cashierTodayStart = date('Y-m-d') . ' 00:00:00';
$cashierTomorrowStart = date('Y-m-d', strtotime('+1 day')) . ' 00:00:00';

if (function_exists('db')) {
    try {
        $pendingStmt = db()->prepare("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND created_at >= ? AND created_at < ?");
        $pendingStmt->execute([$cashierTodayStart, $cashierTomorrowStart]);
        $cashierPendingOrders = (int) $pendingStmt->fetchColumn();
    } catch (Throwable $e) {
        $cashierPendingOrders = 0;
    }
}

$cashierConfirmPath = dirname(__DIR__) . '/public/scripts/confirm.js';
$cashierConfirmVersion = is_file($cashierConfirmPath) ? filemtime($cashierConfirmPath) : time();
$cashierPerfPath = dirname(__DIR__) . '/public/scripts/perf.js';
$cashierPerfVersion = is_file($cashierPerfPath) ? filemtime($cashierPerfPath) : time();
?>
<script>document.body.classList.add('cashier-pos-shell');</script>
<header class="cashier-pos-header" id="cashierPosHeader">
  <div class="cashier-pos-start">
    <button type="button" class="cashier-pos-menu-toggle" id="cashierPosMenuToggle"
            aria-label="Open POS navigation" aria-controls="cashierPosNav" aria-expanded="false">
      <i class="fa fa-bars" aria-hidden="true"></i>
    </button>
    <a href="orders.php" class="cashier-pos-brand" aria-label="Kape Agosto POS - Manage Order">
      <span class="cashier-pos-brand-mark">
        <img src="<?= htmlspecialchars(kape_asset_url('img/CUP_WHITE.png')) ?>" alt="">
      </span>
      <span class="cashier-pos-brand-copy">
        <strong>Kape Agosto</strong>
        <small>Point of Sale</small>
      </span>
    </a>
  </div>

  <nav class="cashier-pos-nav" id="cashierPosNav" aria-label="Cashier POS navigation">
    <a href="items.php" class="<?= in_array($cashierPage, $menuPages, true) ? 'is-active' : '' ?>">
      <i class="fa fa-mug-hot" aria-hidden="true"></i>
      <span>Item List</span>
    </a>
    <a id="cashierManageOrderLink" href="<?= $cashierMaintenanceMode ? 'maintenance.php' : 'orders.php' ?>" class="<?= in_array($cashierPage, $registerPages, true) ? 'is-active' : '' ?><?= $cashierMaintenanceMode ? ' is-maintenance' : '' ?>"<?= $cashierMaintenanceMode ? ' aria-label="Orders paused for maintenance"' : '' ?>>
      <i class="fa <?= $cashierMaintenanceMode ? 'fa-lock' : 'fa-cash-register' ?>" aria-hidden="true"></i>
      <span><?= $cashierMaintenanceMode ? 'Orders Paused' : 'Manage Order' ?></span>
    </a>
    <a href="order_list.php" class="<?= in_array($cashierPage, $orderPages, true) ? 'is-active' : '' ?>">
      <i class="fa fa-receipt" aria-hidden="true"></i>
      <span>Order list</span>
      <?php if ($cashierPendingOrders > 0): ?>
        <span class="cashier-pos-count" aria-label="<?= number_format($cashierPendingOrders) ?> pending orders today"><?= $cashierPendingOrders > 99 ? '99+' : number_format($cashierPendingOrders) ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <div class="cashier-pos-actions">
    <div class="cashier-pos-clock" aria-label="Current date and time">
      <strong id="cashierPosTime"><?= date('g:i A') ?></strong>
      <small id="cashierPosDate"><?= date('D, M j') ?></small>
    </div>
    <div class="cashier-profile-menu">
      <button type="button" class="cashier-pos-user cashier-profile-toggle"
              aria-haspopup="menu" aria-expanded="false" aria-controls="cashierProfileDropdown"
              title="Open cashier account menu">
        <img src="<?= htmlspecialchars($cashierImageUrl) ?>" alt="">
        <span>
          <small>Cashier</small>
          <strong><?= htmlspecialchars($cashierFirstName) ?></strong>
        </span>
        <i class="fa fa-chevron-down cashier-profile-chevron" aria-hidden="true"></i>
      </button>

      <div class="cashier-profile-dropdown" id="cashierProfileDropdown" role="menu" hidden>
        <div class="cashier-profile-dropdown-header">
          <img src="<?= htmlspecialchars($cashierImageUrl) ?>" alt="">
          <span>
            <strong><?= htmlspecialchars($cashierName) ?></strong>
            <small><?= htmlspecialchars($cashierEmail) ?></small>
          </span>
        </div>
        <div class="cashier-profile-dropdown-links">
          <a href="profile.php" role="menuitem">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            <span>User Profile</span>
          </a>
          <button type="button" class="kape-guide-menu-item" data-open-guide role="menuitem" aria-haspopup="dialog" aria-controls="kapeGuide"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5v15M12 5C8 2 3 3 3 3v15s5-1 9 2c4-3 9-2 9-2V3s-5-1-9 2Z"/></svg><span>Guide</span></button>
          <a href="logout.php" class="cashier-profile-logout" role="menuitem"
             data-confirm="Are you sure you want to log out of Kape Agosto?"
             data-confirm-title="Confirm Logout"
             data-confirm-type="warning">
            <i class="fa fa-arrow-right-from-bracket" aria-hidden="true"></i>
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>

<script>
(function () {
  const header = document.getElementById('cashierPosHeader');
  const nav = document.getElementById('cashierPosNav');
  const toggle = document.getElementById('cashierPosMenuToggle');
  const time = document.getElementById('cashierPosTime');
  const date = document.getElementById('cashierPosDate');
  const profileMenu = header?.querySelector('.cashier-profile-menu');
  const profileToggle = profileMenu?.querySelector('.cashier-profile-toggle');
  const profileDropdown = profileMenu?.querySelector('.cashier-profile-dropdown');
  const manageOrderLink = document.getElementById('cashierManageOrderLink');
  const manageOrderIcon = manageOrderLink?.querySelector('i');
  const manageOrderLabel = manageOrderLink?.querySelector('span');
  const cashierPage = location.pathname.split('/').pop();
  let maintenanceCheckRunning = false;
  let maintenanceRedirecting = false;

  function updateClock() {
    const now = new Date();
    if (time) time.textContent = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (date) date.textContent = now.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' });
  }

  function setMenu(open) {
    if (!nav || !toggle) return;
    nav.classList.toggle('is-open', Boolean(open));
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.setAttribute('aria-label', open ? 'Close POS navigation' : 'Open POS navigation');
    toggle.querySelector('i')?.classList.toggle('fa-xmark', Boolean(open));
    toggle.querySelector('i')?.classList.toggle('fa-bars', !open);
  }

  function profileMenuItems() {
    return profileDropdown ? Array.from(profileDropdown.querySelectorAll('[role="menuitem"]')) : [];
  }

  function setProfileMenu(open, focusTarget) {
    if (!profileMenu || !profileToggle || !profileDropdown) return;
    const shouldOpen = Boolean(open);
    profileDropdown.hidden = !shouldOpen;
    profileMenu.classList.toggle('is-open', shouldOpen);
    profileToggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

    if (shouldOpen && focusTarget) {
      const items = profileMenuItems();
      const target = focusTarget === 'last' ? items[items.length - 1] : items[0];
      target?.focus();
    }
  }

  function applyMaintenanceMode(enabled) {
    if (!manageOrderLink || !manageOrderIcon || !manageOrderLabel) return;

    manageOrderLink.href = enabled ? 'maintenance.php' : 'orders.php';
    manageOrderLink.classList.toggle('is-maintenance', enabled);
    manageOrderLabel.textContent = enabled ? 'Orders Paused' : 'Manage Order';
    manageOrderLink.setAttribute('aria-label', enabled ? 'Orders paused for maintenance' : 'Manage Order');
    manageOrderIcon.classList.toggle('fa-lock', enabled);
    manageOrderIcon.classList.toggle('fa-cash-register', !enabled);

    if (maintenanceRedirecting) return;
    if (enabled && cashierPage === 'orders.php') {
      maintenanceRedirecting = true;
      location.replace('maintenance.php');
    } else if (!enabled && cashierPage === 'maintenance.php') {
      maintenanceRedirecting = true;
      location.replace('orders.php');
    }
  }

  async function checkMaintenanceMode() {
    if (maintenanceCheckRunning || document.visibilityState === 'hidden') return;
    maintenanceCheckRunning = true;

    try {
      const response = await fetch('maintenance_status.php?t=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      if (!response.ok) return;
      const data = await response.json();
      if (typeof data.maintenance === 'boolean') applyMaintenanceMode(data.maintenance);
    } catch (_) {
      // Keep the current screen if the status check is temporarily unavailable.
    } finally {
      maintenanceCheckRunning = false;
    }
  }

  toggle?.addEventListener('click', function () {
    setProfileMenu(false);
    setMenu(!nav?.classList.contains('is-open'));
  });
  profileToggle?.addEventListener('click', function () {
    setMenu(false);
    setProfileMenu(profileDropdown?.hidden);
  });
  profileToggle?.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      setProfileMenu(true, event.key === 'ArrowUp' ? 'last' : 'first');
    }
  });
  profileDropdown?.querySelector('[data-open-guide]')?.addEventListener('click', function () {
    setProfileMenu(false);
    profileToggle?.focus();
  });
  profileDropdown?.addEventListener('keydown', function (event) {
    const items = profileMenuItems();
    const currentIndex = items.indexOf(document.activeElement);

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      const direction = event.key === 'ArrowDown' ? 1 : -1;
      const nextIndex = currentIndex < 0
        ? (direction > 0 ? 0 : items.length - 1)
        : (currentIndex + direction + items.length) % items.length;
      items[nextIndex]?.focus();
    } else if (event.key === 'Home' || event.key === 'End') {
      event.preventDefault();
      items[event.key === 'Home' ? 0 : items.length - 1]?.focus();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      setProfileMenu(false);
      profileToggle?.focus();
    }
  });
  nav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setMenu(false)));
  document.addEventListener('click', function (event) {
    if (header && !header.contains(event.target)) {
      setMenu(false);
      setProfileMenu(false);
      return;
    }
    if (profileMenu && !profileMenu.contains(event.target)) setProfileMenu(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      setMenu(false);
      setProfileMenu(false);
    }
  });

  updateClock();
  window.setInterval(updateClock, 30000);
  checkMaintenanceMode();
  window.setInterval(checkMaintenanceMode, 5000);
  document.addEventListener('visibilitychange', checkMaintenanceMode);
})();
</script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/account-status.js') . '?v=' . filemtime(dirname(__DIR__) . '/public/scripts/account-status.js')) ?>" data-status-url="<?= htmlspecialchars(kape_asset_url('account_status.php')) ?>" data-login-url="<?= htmlspecialchars(kape_asset_url('login.php')) ?>" data-paused-url="<?= htmlspecialchars(kape_asset_url('account_paused.php')) ?>"></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/live-reload.js')) ?>"></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/confirm.js') . '?v=' . $cashierConfirmVersion) ?>" defer></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/perf.js') . '?v=' . $cashierPerfVersion) ?>" defer></script>

<?php require_once dirname(__DIR__) . '/public/partials/user_guide.php'; ?>
