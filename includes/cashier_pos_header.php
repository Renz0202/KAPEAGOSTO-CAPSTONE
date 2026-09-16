<?php
require_once dirname(__DIR__) . '/public/partials/assets.php';

$cashierUser = $_SESSION['user'] ?? [];
$cashierName = trim((string) ($cashierUser['full_name'] ?? $cashierUser['name'] ?? $cashierUser['username'] ?? 'Cashier'));
$cashierName = $cashierName !== '' ? $cashierName : 'Cashier';
$cashierFirstName = preg_split('/\s+/', $cashierName)[0] ?? $cashierName;
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

if (function_exists('db')) {
    try {
        $cashierPendingOrders = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
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
  <a href="orders.php" class="cashier-pos-brand" aria-label="Kape Agosto POS - Manage Order">
    <span class="cashier-pos-brand-mark">
      <img src="<?= htmlspecialchars(kape_asset_url('img/CUP_WHITE.png')) ?>" alt="">
    </span>
    <span class="cashier-pos-brand-copy">
      <strong>Kape Agosto</strong>
      <small>Point of Sale</small>
    </span>
  </a>

  <nav class="cashier-pos-nav" id="cashierPosNav" aria-label="Cashier POS navigation">
    <a href="items.php" class="<?= in_array($cashierPage, $menuPages, true) ? 'is-active' : '' ?>">
      <i class="fa fa-mug-hot" aria-hidden="true"></i>
      <span>Item List</span>
    </a>
    <a href="<?= $cashierMaintenanceMode ? 'maintenance.php' : 'orders.php' ?>" class="<?= in_array($cashierPage, $registerPages, true) ? 'is-active' : '' ?><?= $cashierMaintenanceMode ? ' is-maintenance' : '' ?>">
      <i class="fa <?= $cashierMaintenanceMode ? 'fa-lock' : 'fa-cash-register' ?>" aria-hidden="true"></i>
      <span>Manage Order</span>
    </a>
    <a href="order_list.php" class="<?= in_array($cashierPage, $orderPages, true) ? 'is-active' : '' ?>">
      <i class="fa fa-receipt" aria-hidden="true"></i>
      <span>Order list</span>
      <?php if ($cashierPendingOrders > 0): ?>
        <span class="cashier-pos-count" aria-label="<?= number_format($cashierPendingOrders) ?> pending orders"><?= $cashierPendingOrders > 99 ? '99+' : number_format($cashierPendingOrders) ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <div class="cashier-pos-actions">
    <div class="cashier-pos-clock" aria-label="Current date and time">
      <strong id="cashierPosTime"><?= date('g:i A') ?></strong>
      <small id="cashierPosDate"><?= date('D, M j') ?></small>
    </div>
    <a href="profile.php" class="cashier-pos-user" title="Cashier profile">
      <img src="<?= htmlspecialchars($cashierImageUrl) ?>" alt="">
      <span>
        <small>Cashier</small>
        <strong><?= htmlspecialchars($cashierFirstName) ?></strong>
      </span>
    </a>
    <a href="logout.php" class="cashier-pos-logout" title="Logout"
       data-confirm="Are you sure you want to log out of Kape Agosto?"
       data-confirm-title="Confirm Logout"
       data-confirm-type="warning">
      <i class="fa fa-arrow-right-from-bracket" aria-hidden="true"></i>
      <span>Logout</span>
    </a>
    <button type="button" class="cashier-pos-menu-toggle" id="cashierPosMenuToggle"
            aria-label="Open POS navigation" aria-controls="cashierPosNav" aria-expanded="false">
      <i class="fa fa-bars" aria-hidden="true"></i>
    </button>
  </div>
</header>

<script>
(function () {
  const header = document.getElementById('cashierPosHeader');
  const nav = document.getElementById('cashierPosNav');
  const toggle = document.getElementById('cashierPosMenuToggle');
  const time = document.getElementById('cashierPosTime');
  const date = document.getElementById('cashierPosDate');

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

  toggle?.addEventListener('click', function () {
    setMenu(!nav?.classList.contains('is-open'));
  });
  nav?.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setMenu(false)));
  document.addEventListener('click', function (event) {
    if (header && !header.contains(event.target)) setMenu(false);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') setMenu(false);
  });

  updateClock();
  window.setInterval(updateClock, 30000);
})();
</script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/live-reload.js')) ?>"></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/confirm.js') . '?v=' . $cashierConfirmVersion) ?>" defer></script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/perf.js') . '?v=' . $cashierPerfVersion) ?>" defer></script>
