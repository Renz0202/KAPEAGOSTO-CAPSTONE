<?php
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

if (function_exists('is_cashier') && is_cashier()) {
    include __DIR__ . '/cashier_pos_header.php';
    return;
}

$userName = null;
$userEmail = null;
$userRole = null;
$userImage = '';
$notificationCount = 0;

if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $userName = $_SESSION['user']['name']
              ?? $_SESSION['user']['full_name']
              ?? $_SESSION['user']['username']
              ?? null;
    $userEmail = $_SESSION['user']['email'] ?? null;
    $userRole = $_SESSION['user']['role'] ?? null;
    $userImage = $_SESSION['user']['image'] ?? '';

    $userId = (int)($_SESSION['user']['id'] ?? 0);
    if ($userId > 0 && function_exists('db')) {
        try {
            $stmt = db()->prepare("
                SELECT u.username, u.full_name, u.email, u.image, r.name AS role_name
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.id = ?
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $freshUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($freshUser) {
                $userName = $freshUser['full_name']
                         ?: ($freshUser['username'] ?: $userName);
                $userEmail = $freshUser['email'] ?: $userEmail;
                $userRole = $freshUser['role_name'] ?: $userRole;
                $userImage = $freshUser['image'] ?: $userImage;

                $_SESSION['user']['username'] = $freshUser['username'];
                $_SESSION['user']['full_name'] = $freshUser['full_name'];
                $_SESSION['user']['email'] = $freshUser['email'];
                $_SESSION['user']['role'] = $userRole;
                $_SESSION['user']['image'] = $userImage;
            }
            $notificationStmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0');
            $notificationStmt->execute([$userId]);
            $notificationCount = (int) $notificationStmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('Topbar user refresh failed: ' . $e->getMessage());
        }
    }
}

$imgSrc = '';

if (!empty($userImage)) {
    $imgPath = dirname(__DIR__) . '/public/uploads/users/' . basename($userImage);
    if (file_exists($imgPath)) {
        $imgSrc = '/KAPEAGOSTO/public/uploads/users/' . rawurlencode(basename($userImage));
    } else {
        $imgSrc = '/KAPEAGOSTO/public/img/CUP_BLACK.png';
    }
} else {
    $imgSrc = '/KAPEAGOSTO/public/img/CUP_BLACK.png';
}

$displayName = trim((string) $userName) !== '' ? $userName : 'User';
$firstName = preg_split('/\s+/', trim($displayName))[0] ?? $displayName;
$firstName = rtrim($firstName, ',');
$displayEmail = trim((string) $userEmail) !== '' ? $userEmail : 'admin@kapeagosto.com';
$displayRole = function_exists('role_display_name')
    ? strtoupper(role_display_name($userRole))
    : (trim((string) $userRole) !== '' ? strtoupper(str_replace('_', ' ', $userRole)) : 'USER');
$normalizedTopbarRole = strtolower(trim((string) $userRole));
if ($normalizedTopbarRole === 'business_admin') {
    $normalizedTopbarRole = 'admin';
} elseif ($normalizedTopbarRole === 'staff') {
    $normalizedTopbarRole = 'cashier';
}
$settingsHref = $normalizedTopbarRole === 'manager' ? 'manager_settings.php' : 'settings.php';
$showSettingsLink = in_array($normalizedTopbarRole, ['admin', 'manager'], true);
$systemMaintenanceMode = function_exists('system_is_maintenance') && system_is_maintenance();

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
$scriptsBase = '/KAPEAGOSTO/public/scripts/';
$confirmScriptPath = dirname(__DIR__) . '/public/scripts/confirm.js';
$confirmScriptVersion = is_file($confirmScriptPath) ? filemtime($confirmScriptPath) : time();
$perfScriptPath = dirname(__DIR__) . '/public/scripts/perf.js';
$perfScriptVersion = is_file($perfScriptPath) ? filemtime($perfScriptPath) : time();
?>
<div class="topbar">
  <div class="topbar-left">
    <button type="button" class="hamburger js-sidebar-toggle" aria-label="Open navigation" aria-controls="sidebar">
      <i class="fa fa-bars"></i>
    </button>

    <div class="topbar-heading">
      <span class="topbar-kicker">Workspace</span>
      <h1 class="topbar-title"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($firstName) ?></h1>
    </div>
  </div>

  <div class="topbar-center">
    <?php if ($systemMaintenanceMode): ?>
      <a class="topbar-maintenance-status" href="maintenance.php" aria-label="Maintenance mode: transactions are paused">
        <i class="fa fa-screwdriver-wrench" aria-hidden="true"></i>
        <span><strong>Maintenance Mode</strong><small>Transactions paused</small></span>
      </a>
    <?php else: ?>
      <div class="topbar-search" role="search">
        <i class="fa fa-magnifying-glass" aria-hidden="true"></i>
        <input type="search" aria-label="Search" placeholder="Search orders, items, or users...">
      </div>
    <?php endif; ?>
  </div>

  <div class="topbar-right">
    <a href="dashboard.php" class="topbar-icon-btn" title="Dashboard" aria-label="Dashboard">
      <i class="fa fa-gauge-high"></i>
    </a>
    <a href="notifications.php" class="topbar-icon-btn topbar-notification" title="Notifications" aria-label="Notifications<?= $notificationCount ? ' (' . $notificationCount . ' unread)' : '' ?>">
      <i class="fa fa-bell"></i>
      <?php if ($notificationCount > 0): ?><span class="topbar-notification-count"><?= $notificationCount > 99 ? '99+' : $notificationCount ?></span><?php endif; ?>
    </a>

    <div class="topbar-profile-menu">
      <button
        type="button"
        class="btn-update-profile js-profile-menu-toggle"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="topbar-profile-dropdown"
        title="Open account menu"
      >
        <span class="topbar-user-meta">
          <span class="topbar-user-role"><?= htmlspecialchars($displayRole) ?></span>
          <span class="topbar-user-email"><?= htmlspecialchars($displayEmail) ?></span>
        </span>
        <img src="<?= htmlspecialchars($imgSrc) ?>" alt="" class="topbar-profile-image">
        <i class="fa fa-chevron-down topbar-chevron" aria-hidden="true"></i>
      </button>

      <div class="topbar-profile-dropdown" id="topbar-profile-dropdown" role="menu" hidden>
        <div class="topbar-profile-dropdown-header">
          <img src="<?= htmlspecialchars($imgSrc) ?>" alt="" class="topbar-dropdown-avatar">
          <span>
            <strong><?= htmlspecialchars($displayName) ?></strong>
            <small><?= htmlspecialchars($displayEmail) ?></small>
          </span>
        </div>
        <div class="topbar-profile-dropdown-links">
          <a href="profile.php" role="menuitem">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            <span>User Profile</span>
          </a>
          <?php if ($showSettingsLink): ?>
            <a href="<?= htmlspecialchars($settingsHref) ?>" role="menuitem">
              <i class="fa fa-gear" aria-hidden="true"></i>
              <span>Settings</span>
            </a>
          <?php endif; ?>
          <a href="logout.php" class="topbar-dropdown-logout" role="menuitem"
             data-confirm="Are you sure you want to log out of Kape Agosto?"
             data-confirm-title="Confirm Logout"
             data-confirm-type="warning">
            <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i>
            <span>Logout</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
  (function () {
    const menu = document.querySelector('.topbar-profile-menu');
    const toggle = menu?.querySelector('.js-profile-menu-toggle');
    const dropdown = menu?.querySelector('.topbar-profile-dropdown');

    if (!menu || !toggle || !dropdown) return;

    function menuItems() {
      return Array.from(dropdown.querySelectorAll('[role="menuitem"]'));
    }

    function setMenuOpen(open, focusTarget) {
      const shouldOpen = Boolean(open);
      dropdown.hidden = !shouldOpen;
      menu.classList.toggle('is-open', shouldOpen);
      toggle.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');

      if (shouldOpen && focusTarget) {
        const items = menuItems();
        const target = focusTarget === 'last' ? items[items.length - 1] : items[0];
        target?.focus();
      }
    }

    toggle.addEventListener('click', function () {
      setMenuOpen(dropdown.hidden);
    });

    toggle.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        setMenuOpen(true, event.key === 'ArrowUp' ? 'last' : 'first');
      }
    });

    dropdown.addEventListener('keydown', function (event) {
      const items = menuItems();
      const currentIndex = items.indexOf(document.activeElement);

      if (event.key === 'Escape') {
        event.preventDefault();
        setMenuOpen(false);
        toggle.focus();
        return;
      }

      if (event.key === 'Tab') {
        setMenuOpen(false);
        return;
      }

      if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();

      let nextIndex = 0;
      if (event.key === 'End') {
        nextIndex = items.length - 1;
      } else if (event.key === 'ArrowDown') {
        nextIndex = currentIndex < 0 ? 0 : (currentIndex + 1) % items.length;
      } else if (event.key === 'ArrowUp') {
        nextIndex = currentIndex <= 0 ? items.length - 1 : currentIndex - 1;
      }
      items[nextIndex]?.focus();
    });

    document.addEventListener('click', function (event) {
      if (!menu.contains(event.target)) setMenuOpen(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !dropdown.hidden) {
        setMenuOpen(false);
        toggle.focus();
      }
    });
  })();

  (function () {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.querySelector('.js-sidebar-toggle');
    const closeControls = document.querySelectorAll('.js-sidebar-close');
    const tabletQuery = window.matchMedia('(max-width: 1100px)');
    const collapseStorageKey = 'kapeSidebarCollapsed';
    const toggleIcon = toggle?.querySelector('i');

    if (!sidebar || !toggle) return;

    function savedCollapsedState() {
      try {
        return window.localStorage.getItem(collapseStorageKey) === '1';
      } catch (error) {
        return false;
      }
    }

    function storeCollapsedState(collapsed) {
      try {
        window.localStorage.setItem(collapseStorageKey, collapsed ? '1' : '0');
      } catch (error) {
        // The sidebar still works when storage is unavailable.
      }
    }

    function updateToggleState() {
      if (tabletQuery.matches) {
        const isOpen = sidebar.classList.contains('active');
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        toggle.title = isOpen ? 'Close navigation' : 'Open navigation';
        if (toggleIcon) toggleIcon.className = isOpen ? 'fa fa-xmark' : 'fa fa-bars';
        return;
      }

      const isCollapsed = document.body.classList.contains('sidebar-collapsed');
      toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
      toggle.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
      toggle.title = isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
      if (toggleIcon) toggleIcon.className = isCollapsed ? 'fa fa-angles-right' : 'fa fa-bars';

      sidebar.querySelectorAll('a[data-tooltip]').forEach(function (link) {
        const label = link.dataset.tooltip || '';
        if (label && !link.hasAttribute('aria-label')) link.setAttribute('aria-label', label);
        link.title = isCollapsed ? label : '';
      });
    }

    function setSidebarCollapsed(collapsed, persist) {
      document.body.classList.toggle('sidebar-collapsed', Boolean(collapsed));
      if (persist !== false) storeCollapsedState(Boolean(collapsed));
      updateToggleState();
    }

    function setSidebarOpen(open) {
      const shouldOpen = Boolean(open) && tabletQuery.matches;
      sidebar.classList.toggle('active', shouldOpen);
      document.body.classList.toggle('sidebar-open', shouldOpen);
      updateToggleState();
    }

    setSidebarCollapsed(savedCollapsedState(), false);
    toggle.addEventListener('click', function () {
      if (tabletQuery.matches) {
        setSidebarOpen(!sidebar.classList.contains('active'));
      } else {
        setSidebarCollapsed(!document.body.classList.contains('sidebar-collapsed'));
      }
    });

    closeControls.forEach(function (control) {
      control.addEventListener('click', function () { setSidebarOpen(false); });
    });

    function comparableSidebarUrl(value) {
      const url = new URL(value, window.location.href);
      url.hash = '';
      url.searchParams.delete('_lr');

      const sortedParams = Array.from(url.searchParams.entries()).sort(function (left, right) {
        return left[0].localeCompare(right[0]) || left[1].localeCompare(right[1]);
      });

      return url.origin.toLowerCase()
        + url.pathname.replace(/\/+$/, '').toLowerCase()
        + (sortedParams.length ? '?' + new URLSearchParams(sortedParams).toString() : '');
    }

    const currentSidebarUrl = comparableSidebarUrl(window.location.href);

    sidebar.querySelectorAll('a').forEach(function (link) {
      const isCurrentPage = link.classList.contains('active')
        && comparableSidebarUrl(link.href) === currentSidebarUrl;

      if (isCurrentPage) {
        link.setAttribute('aria-current', 'page');
      }

      link.addEventListener('click', function (event) {
        const isPlainActivation = !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;

        if (isCurrentPage && isPlainActivation) {
          event.preventDefault();
          if (tabletQuery.matches) setSidebarOpen(false);
          return;
        }

        if (tabletQuery.matches) setSidebarOpen(false);
      });
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') setSidebarOpen(false);
    });

    if (typeof tabletQuery.addEventListener === 'function') {
      tabletQuery.addEventListener('change', function () {
        setSidebarOpen(false);
        updateToggleState();
      });
    } else if (typeof tabletQuery.addListener === 'function') {
      tabletQuery.addListener(function () {
        setSidebarOpen(false);
        updateToggleState();
      });
    }
  })();
</script>
<script src="<?= htmlspecialchars($scriptsBase . 'live-reload.js') ?>"></script>
<script src="<?= htmlspecialchars($scriptsBase . 'confirm.js?v=' . $confirmScriptVersion) ?>" defer></script>
<script src="<?= htmlspecialchars($scriptsBase . 'perf.js?v=' . $perfScriptVersion) ?>" defer></script>
