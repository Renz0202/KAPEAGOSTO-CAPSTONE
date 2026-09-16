<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/user_utils.php';
require_admin();

$pdo = db();

$currentUser = $_SESSION['user'] ?? [];
$currentUserId = (int)($currentUser['id'] ?? 0);
$currentUserRole = strtolower($currentUser['role'] ?? '');

$stmt = $pdo->query("
  SELECT u.id, u.username, u.full_name, u.last_name, u.first_name, u.middle_name,
         u.email, u.contact, u.image,
         r.name AS role,
         u.role_id,
         u.registered_at,
         u.status
  FROM users u
  LEFT JOIN roles r ON u.role_id = r.id
  WHERE (r.name IS NULL OR r.name != 'guest')
  ORDER BY u.id DESC
");
$users = $stmt->fetchAll();
$roles = $pdo->query("SELECT id, name FROM roles WHERE LOWER(name) != 'guest' ORDER BY id ASC")->fetchAll();
$flashMessage = (string) ($_SESSION['flash'] ?? '');
$flashType = (string) ($_SESSION['flash_type'] ?? 'success');
$reopenCreateModal = ($_GET['create'] ?? '') === '1';
unset($_SESSION['flash'], $_SESSION['flash_type']);

$totalUsers = count($users);
$activeUsers = 0;
$inactiveUsers = 0;
$adminUsers = 0;
$managerUsers = 0;
$cashierUsers = 0;

foreach ($users as $userRow) {
    $status = strtolower($userRow['status'] ?? '');
    $role = normalize_role_name($userRow['role'] ?? '');
    if ($status === 'active') {
        $activeUsers++;
    } else {
        $inactiveUsers++;
    }
    if ($role === 'admin') {
        $adminUsers++;
    }
    if ($role === 'manager') { $managerUsers++; }
    if (in_array($role, ['staff', 'cashier'], true)) { $cashierUsers++; }
}

function user_avatar_src(?string $image): string {
    if (!empty($image)) {
        $fileName = basename($image);
        $absolutePath = dirname(__DIR__) . '/uploads/users/' . $fileName;
        if (is_file($absolutePath)) {
            return 'uploads/users/' . rawurlencode($fileName);
        }
    }

    return 'img/CUP_BLACK.png';
}

function user_initials(string $name, string $username): string {
    $source = trim($name) !== '' ? $name : $username;
    $parts = preg_split('/\s+/', trim($source));
    $first = strtoupper(substr($parts[0] ?? 'U', 0, 1));
    $second = strtoupper(substr($parts[1] ?? '', 0, 1));
    return $first . $second;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Management - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-users-users">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content user-management-page">
  <section class="user-page-heading" aria-labelledby="user-management-title">
    <div>
      <p class="user-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> User Management</p>
      <h1 id="user-management-title">User Management</h1>
      <p class="user-page-copy">Assign Admin, Manager, and Cashier roles while protecting administrator controls.</p>
    </div>
    <a href="create_user.php" class="user-primary-action js-open-create-user" aria-haspopup="dialog">
      <i class="fa fa-user-plus"></i>
      Create New User
    </a>
  </section>

  <?php if ($flashMessage !== '' && !$reopenCreateModal): ?>
    <div class="user-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
      <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
  <?php endif; ?>

  <section class="user-summary-grid" aria-label="User summary">
    <article class="user-summary-card">
      <span class="summary-icon"><i class="fa fa-users"></i></span>
      <span class="summary-label">Total Users</span>
      <strong><?= number_format($totalUsers) ?></strong>
    </article>
    <article class="user-summary-card">
      <span class="summary-icon"><i class="fa fa-user-check"></i></span>
      <span class="summary-label">Active</span>
      <strong><?= number_format($activeUsers) ?></strong>
    </article>
    <article class="user-summary-card">
      <span class="summary-icon"><i class="fa fa-user-clock"></i></span>
      <span class="summary-label">Inactive</span>
      <strong><?= number_format($inactiveUsers) ?></strong>
    </article>
    <article class="user-summary-card">
      <span class="summary-icon"><i class="fa fa-shield-halved"></i></span>
      <span class="summary-label">Admin / Manager / Cashier</span>
      <strong><?= number_format($adminUsers) ?> / <?= number_format($managerUsers) ?> / <?= number_format($cashierUsers) ?></strong>
    </article>
  </section>

  <section class="user-table-panel">
    <div class="user-panel-header">
      <div>
        <span class="panel-eyebrow">Access Control</span>
        <h2>User List</h2>
      </div>
      <span class="panel-pill"><?= number_format($totalUsers) ?> account<?= $totalUsers === 1 ? '' : 's' ?></span>
    </div>

    <div class="user-table-wrap">
      <table class="user-table">
        <thead>
          <tr>
            <th>User ID</th>
            <th>Profile</th>
            <th>Name</th>
            <th>Email</th>
            <th>Contact</th>
            <th>Role</th>
            <th>Status</th>
            <th>Registered</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($users)): ?>
            <?php foreach ($users as $u): ?>
              <?php
                $roleLabel = role_display_name($u['role'] ?? '');
                $statusLower = strtolower($u['status'] ?? '');
                $statusLabel = user_title_case($statusLower, 'Unknown');
                $avatarSrc = user_avatar_src($u['image'] ?? null);
                $username = trim((string)($u['username'] ?? ''));
                $displayName = user_formatted_name_from_record($u, user_username_display($username));
                $displayUsername = user_username_display($username);
                $isDefaultAdmin = (strcasecmp($username, (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
                  || (strcasecmp($u['email'] ?? '', (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);
                $isSelf = ((int)$u['id'] === $currentUserId);
                $isViewerAdmin = is_admin();
                $isProtectedDefaultAdmin = $isDefaultAdmin;
                $showEdit = !$isProtectedDefaultAdmin && !($isDefaultAdmin && $isSelf);
                $canActOnUser = $isViewerAdmin && !$isSelf && !$isDefaultAdmin && !$isProtectedDefaultAdmin;
                $showDeactivate = $canActOnUser && ($statusLower === 'active');
                $showActivate = $canActOnUser && ($statusLower !== 'active');
                $showDelete = $canActOnUser;
                $confirmDeactivate = "Deactivate user '" . $displayName . "'?";
                $confirmActivate = "Activate user '" . $displayName . "'?";
                $confirmDelete = "This action is permanent and cannot be undone. Permanently delete user '" . $displayName . "' (ID " . (int)$u['id'] . ")?";
              ?>
              <tr>
                <td class="user-id">#<?= (int)$u['id'] ?></td>
                <td>
                  <div class="user-avatar">
                    <img src="<?= htmlspecialchars($avatarSrc) ?>" alt="<?= htmlspecialchars($displayName) ?> profile">
                    <span><?= htmlspecialchars(user_initials($displayName, $username)) ?></span>
                  </div>
                </td>
                <td>
                  <div class="user-name-cell">
                    <strong><?= htmlspecialchars($displayName) ?></strong>
                    <span>@<?= htmlspecialchars($displayUsername) ?></span>
                  </div>
                </td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars($u['contact'] ?: 'Not set') ?></td>
                <td><span class="role-chip <?= htmlspecialchars(strtolower($roleLabel)) ?>"><?= htmlspecialchars($roleLabel) ?></span></td>
                <td><span class="user-status <?= htmlspecialchars($statusLower) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                <td><?= htmlspecialchars(date('M d, Y', strtotime($u['registered_at']))) ?></td>
                <td>
                  <div class="user-actions">
                    <?php if ($showEdit): ?>
                      <a href="edit_user.php?id=<?= (int)$u['id'] ?>" class="user-action-btn edit" title="Edit">
                        <i class="fa fa-pen-to-square"></i>
                      </a>
                    <?php endif; ?>
                    <?php if ($isProtectedDefaultAdmin): ?>
                      <span class="protected-tag">Protected</span>
                    <?php endif; ?>
                    <?php if ($showDeactivate): ?>
                      <a href="delete_user.php?id=<?= (int)$u['id'] ?>" class="user-action-btn deactivate" title="Deactivate" data-confirm="<?= htmlspecialchars($confirmDeactivate, ENT_QUOTES) ?>" data-confirm-title="Deactivate User" data-confirm-type="warning">
                        <i class="fa fa-user-slash"></i>
                      </a>
                    <?php endif; ?>
                    <?php if ($showActivate): ?>
                      <a href="activate_user.php?id=<?= (int)$u['id'] ?>" class="user-action-btn activate" title="Activate" data-confirm="<?= htmlspecialchars($confirmActivate, ENT_QUOTES) ?>" data-confirm-title="Activate User" data-confirm-type="success">
                        <i class="fa fa-user-check"></i>
                      </a>
                    <?php endif; ?>
                    <?php if ($showDelete): ?>
                      <a href="delete_user_permanent.php?id=<?= (int)$u['id'] ?>" class="user-action-btn delete" title="Delete Permanently" data-confirm="<?= htmlspecialchars($confirmDelete, ENT_QUOTES) ?>" data-confirm-title="Delete User Permanently" data-confirm-type="danger">
                        <i class="fa fa-trash"></i>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="user-empty">No users found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<div class="user-create-modal" id="createUserModal" aria-hidden="true">
  <section class="user-create-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="create-user-modal-title">
    <header class="user-create-modal-header">
      <div>
        <span>Account Setup</span>
        <h2 id="create-user-modal-title">Create New User</h2>
      </div>
    </header>

    <?php if ($reopenCreateModal && $flashMessage !== ''): ?>
      <div class="user-create-modal-feedback <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
        <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="create_user_process.php" class="user-create-modal-form" enctype="multipart/form-data">
      <input type="hidden" name="return_to" value="users.php">

      <div class="user-create-photo-field">
        <div class="user-create-preview" aria-hidden="true">
          <i class="fa fa-user"></i>
          <img id="createUserPreview" alt="">
        </div>
        <div>
          <label for="create_user_image"><i class="fa fa-image"></i> Profile Image</label>
          <input id="create_user_image" type="file" name="user_image" accept="image/*">
          <small>Optional. Upload JPG, PNG, GIF, or WEBP.</small>
        </div>
      </div>

      <div class="form-group">
        <label for="create_last_name"><i class="fa fa-id-card"></i> Last Name</label>
        <input id="create_last_name" type="text" name="last_name" required placeholder="Castro" autocomplete="family-name" autocapitalize="words" data-modal-capitalize-name>
      </div>

      <div class="form-group">
        <label for="create_first_name"><i class="fa fa-id-card"></i> First Name</label>
        <input id="create_first_name" type="text" name="first_name" required placeholder="Renz" autocomplete="given-name" autocapitalize="words" data-modal-capitalize-name>
      </div>

      <div class="form-group">
        <label for="create_middle_name"><i class="fa fa-id-card"></i> Middle Name <span class="optional-label">Optional</span></label>
        <input id="create_middle_name" type="text" name="middle_name" placeholder="Santos" autocomplete="additional-name" autocapitalize="words" data-modal-capitalize-name>
      </div>

      <div class="form-group">
        <label for="create_username"><i class="fa fa-user"></i> Username</label>
        <input id="create_username" type="text" name="username" required placeholder="username" autocomplete="username">
      </div>

      <div class="form-group">
        <label for="create_email"><i class="fa fa-envelope"></i> Email</label>
        <input id="create_email" type="email" name="email" required placeholder="name@example.com" autocomplete="email">
      </div>

      <div class="form-group">
        <label for="create_contact"><i class="fa fa-phone"></i> Contact</label>
        <input id="create_contact" type="text" name="contact" placeholder="+639123456789" autocomplete="tel">
      </div>

      <div class="form-group">
        <label for="create_password"><i class="fa fa-lock"></i> Password</label>
        <input id="create_password" type="password" name="password" required autocomplete="new-password">
      </div>

      <div class="form-group">
        <label for="create_role_id"><i class="fa fa-user-shield"></i> Role</label>
        <select id="create_role_id" name="role_id" required>
          <?php foreach ($roles as $role): ?>
            <option value="<?= (int)$role['id'] ?>"><?= htmlspecialchars(role_display_name($role['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="create_status"><i class="fa fa-toggle-on"></i> Status</label>
        <select id="create_status" name="status" required>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="user-create-modal-actions">
        <button type="button" class="user-cancel-btn js-close-create-user">Cancel</button>
        <button type="submit" class="user-submit-btn"><i class="fa fa-user-plus"></i> Create Account</button>
      </div>
    </form>
  </section>
</div>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
<script>
(function () {
  const modal = document.getElementById('createUserModal');
  const openControl = document.querySelector('.js-open-create-user');
  const closeControls = modal?.querySelectorAll('.js-close-create-user') || [];
  const form = modal?.querySelector('form');
  const imageInput = document.getElementById('create_user_image');
  const preview = document.getElementById('createUserPreview');
  let lastFocus = null;

  if (!modal || !openControl || !form) return;

  function setModalOpen(open) {
    const shouldOpen = Boolean(open);
    modal.classList.toggle('is-open', shouldOpen);
    modal.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    document.documentElement.classList.toggle('user-modal-lock', shouldOpen);

    if (shouldOpen) {
      lastFocus = document.activeElement;
      window.setTimeout(function () {
        modal.querySelector('input:not([type="hidden"])')?.focus();
      }, 0);
    } else if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    }
  }

  openControl.addEventListener('click', function (event) {
    event.preventDefault();
    setModalOpen(true);
  });

  closeControls.forEach(function (control) {
    control.addEventListener('click', function () { setModalOpen(false); });
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) setModalOpen(false);
  });

  modal.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      setModalOpen(false);
      return;
    }

    if (event.key !== 'Tab') return;
    const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled])'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  imageInput?.addEventListener('change', function () {
    const file = imageInput.files?.[0];
    if (!file) {
      preview.removeAttribute('src');
      preview.classList.remove('is-visible');
      return;
    }
    preview.src = URL.createObjectURL(file);
    preview.classList.add('is-visible');
  });

  modal.querySelectorAll('[data-modal-capitalize-name]').forEach(function (input) {
    input.addEventListener('blur', function () {
      input.value = input.value
        .trim()
        .replace(/\s+/g, ' ')
        .toLocaleLowerCase()
        .replace(/(^|[\s,'-])([a-z])/g, function (_, separator, letter) {
          return separator + letter.toLocaleUpperCase();
        });
    });
  });

  if (<?= $reopenCreateModal ? 'true' : 'false' ?>) {
    setModalOpen(true);
  }
})();
</script>
</body>
</html>
