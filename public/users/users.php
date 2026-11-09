<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/user_utils.php';
require_once dirname(__DIR__, 2) . '/includes/management_lists.php';
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
$requestedEditId = isset($_GET['edit']) && is_numeric($_GET['edit']) ? (int) $_GET['edit'] : 0;
$reopenEditModal = $requestedEditId > 0;
$editFormState = isset($_SESSION['edit_user_form']) && is_array($_SESSION['edit_user_form'])
    ? $_SESSION['edit_user_form']
    : null;
unset($_SESSION['flash'], $_SESSION['flash_type'], $_SESSION['edit_user_form']);

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
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/management-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/management-workspace.css')) ?>">
</head>
<body class="kape-users-users compact-management-page">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content user-management-page">
  <section class="user-page-heading" aria-labelledby="user-management-title">
    <div>
      <p class="user-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> User Management</p>
      <h1 id="user-management-title">User Management</h1>
      <p class="user-page-copy">Assign Admin, Manager, and Cashier roles while protecting administrator controls.</p>
    </div>
    <div class="user-summary-pills" aria-label="User summary">
      <span><strong><?= number_format($totalUsers) ?></strong> Users</span>
      <span><strong><?= number_format($activeUsers) ?></strong> Active</span>
      <span><strong><?= number_format($inactiveUsers) ?></strong> Inactive</span>
      <span class="user-role-summary" aria-label="<?= number_format($adminUsers) ?> Admin, <?= number_format($managerUsers) ?> Manager, <?= number_format($cashierUsers) ?> Cashier">
        <span class="user-role-summary-item" aria-hidden="true">Admin <strong><?= number_format($adminUsers) ?></strong></span>
        <span class="user-role-summary-divider" aria-hidden="true">/</span>
        <span class="user-role-summary-item" aria-hidden="true">Manager <strong><?= number_format($managerUsers) ?></strong></span>
        <span class="user-role-summary-divider" aria-hidden="true">/</span>
        <span class="user-role-summary-item" aria-hidden="true">Cashier <strong><?= number_format($cashierUsers) ?></strong></span>
      </span>
    </div>
  </section>

  <?php if ($flashMessage !== '' && !$reopenCreateModal && !$reopenEditModal): ?>
    <div class="user-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
      <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <span><?= htmlspecialchars($flashMessage) ?></span>
    </div>
  <?php endif; ?>

  <section class="user-table-panel management-list-panel" data-compact-list="users" data-page-param="user_page" data-list-noun="users" data-details-dialog="userListDetails">
    <div class="user-panel-header">
      <div>
        <span class="panel-eyebrow">Access Control</span>
        <h2>User List</h2>
      </div>
      <div class="user-panel-actions">
        <span class="panel-pill"><?= number_format($totalUsers) ?> account<?= $totalUsers === 1 ? '' : 's' ?></span>
        <a href="create_user.php" class="user-primary-action js-open-create-user" aria-haspopup="dialog" aria-controls="createUserModal">
          <i class="fa fa-user-plus"></i>
          Create New User
        </a>
      </div>
    </div>

    <form method="get" class="management-list-filters" data-compact-filters>
      <label class="management-search-field" for="userListSearch"><span>Search accounts</span><input type="search" id="userListSearch" name="user_search" data-compact-search placeholder="Name, username, email or contact..." autocomplete="off" maxlength="160" aria-controls="userListTable"></label>
      <label for="userRoleFilter"><span>Role</span><select id="userRoleFilter" name="user_role" data-compact-filter="role" aria-controls="userListTable"><option value="">All roles</option><option value="admin">Admin</option><option value="manager">Manager</option><option value="cashier">Cashier</option></select></label>
      <label for="userStatusFilter"><span>Status</span><select id="userStatusFilter" name="user_status" data-compact-filter="status" aria-controls="userListTable"><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      <button type="button" class="management-reset-button" data-compact-reset>Reset</button>
    </form>
    <div class="user-table-wrap management-table-frame" role="region" aria-label="User accounts" tabindex="0">
      <table class="user-table management-compact-table" id="userListTable">
        <thead>
          <tr>
            <th scope="col">Account</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Details</th><th scope="col">Actions</th>
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
                $editNameParts = user_name_parts_from_record($u);
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
              <?php
                $userDetails = ['title'=>$displayName, 'id'=>'#' . (int)$u['id'], 'username'=>$username, 'email'=>$u['email'] ?: 'Not set', 'contact'=>$u['contact'] ?: 'Not set', 'role'=>$roleLabel, 'status'=>$statusLabel, 'registered'=>!empty($u['registered_at']) ? date('F d, Y h:i A', strtotime($u['registered_at'])) : 'Not recorded'];
                $searchText = implode(' ', [$u['id'], $displayName, $username, $u['email'], $u['contact'], $roleLabel, $statusLabel]);
              ?>
              <tr data-compact-row data-row-id="<?= (int)$u['id'] ?>" data-role="<?= htmlspecialchars(normalize_role_name($u['role']), ENT_QUOTES) ?>" data-status="<?= htmlspecialchars($statusLower, ENT_QUOTES) ?>" data-search="<?= management_list_escape($searchText) ?>">
                <td data-label="Account">
                  <div class="management-identity-cell">
                    <div class="user-avatar"><img src="<?= htmlspecialchars($avatarSrc) ?>" alt="" loading="lazy"><span><?= htmlspecialchars(user_initials($displayName, $username)) ?></span></div>
                    <div class="user-name-cell"><strong><?= htmlspecialchars($displayName) ?></strong><span>@<?= htmlspecialchars($displayUsername) ?></span></div>
                  </div>
                </td>
                <td data-label="Role"><span class="role-chip <?= htmlspecialchars(strtolower($roleLabel)) ?>"><?= htmlspecialchars($roleLabel) ?></span></td>
                <td data-label="Status"><span class="user-status <?= htmlspecialchars($statusLower) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                <td data-label="Details"><?= management_list_details($userDetails, 'userListDetails', $displayName) ?></td>
                <td data-label="Actions">
                  <div class="user-actions">
                    <?php if ($showEdit): ?>
                      <a
                        href="edit_user.php?id=<?= (int)$u['id'] ?>"
                        class="user-action-btn edit js-open-edit-user"
                        title="Edit <?= htmlspecialchars($displayName) ?>"
                        aria-label="Edit <?= htmlspecialchars($displayName) ?>"
                        aria-haspopup="dialog"
                        aria-controls="editUserModal"
                        data-user-id="<?= (int)$u['id'] ?>"
                        data-user-name="<?= htmlspecialchars($displayName, ENT_QUOTES) ?>"
                        data-last-name="<?= htmlspecialchars($editNameParts['last_name'], ENT_QUOTES) ?>"
                        data-first-name="<?= htmlspecialchars($editNameParts['first_name'], ENT_QUOTES) ?>"
                        data-middle-name="<?= htmlspecialchars($editNameParts['middle_name'], ENT_QUOTES) ?>"
                        data-email="<?= htmlspecialchars((string)$u['email'], ENT_QUOTES) ?>"
                        data-contact="<?= htmlspecialchars((string)$u['contact'], ENT_QUOTES) ?>"
                        data-role-id="<?= (int)$u['role_id'] ?>"
                        data-status="<?= htmlspecialchars($statusLower, ENT_QUOTES) ?>"
                        data-avatar="<?= htmlspecialchars($avatarSrc, ENT_QUOTES) ?>"
                      >
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
          <?php endif; ?>
          <tr data-compact-empty <?= !empty($users) ? 'hidden' : '' ?>><td colspan="5" class="user-empty">No accounts match these filters.</td></tr>
        </tbody>
      </table>
    </div>
    <?= management_list_pager('users') ?>
    <?= management_list_dialog('userListDetails', 'Account Details', ['id'=>'User ID','username'=>'Username','email'=>'Email','contact'=>'Contact','role'=>'Role','status'=>'Status','registered'=>'Registered']) ?>
  </section>
</main>

<div class="user-create-modal" id="createUserModal" aria-hidden="true">
  <section class="user-create-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="create-user-modal-title">
    <header class="user-create-modal-header">
      <div>
        <span>Account Setup</span>
        <h2 id="create-user-modal-title">Add User</h2>
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

      <div class="user-modal-form-body">
        <div class="user-modal-profile-summary">
          <div class="user-create-preview" aria-hidden="true">
            <i class="fa fa-user"></i>
            <img id="createUserPreview" alt="">
          </div>
          <div>
            <h3 id="createUserSummaryName">New User</h3>
            <p id="createUserSummaryEmail">Create a Kape Agosto team account</p>
          </div>
        </div>

        <div class="user-modal-form-row user-modal-name-row">
          <div class="user-modal-row-heading">
            <span>Full name</span>
            <small>Required</small>
          </div>
          <div class="user-modal-name-fields">
            <div class="form-group">
              <label for="create_last_name">Last name</label>
              <input id="create_last_name" type="text" name="last_name" required placeholder="Castro" autocomplete="family-name" autocapitalize="words" data-modal-capitalize-name>
            </div>
            <div class="form-group">
              <label for="create_first_name">First name</label>
              <input id="create_first_name" type="text" name="first_name" required placeholder="Renz" autocomplete="given-name" autocapitalize="words" data-modal-capitalize-name>
            </div>
            <div class="form-group">
              <label for="create_middle_name">Middle name <span>Optional</span></label>
              <input id="create_middle_name" type="text" name="middle_name" placeholder="Santos" autocomplete="additional-name" autocapitalize="words" data-modal-capitalize-name>
            </div>
          </div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="create_username">Username</label></div>
          <div class="user-modal-row-control"><input id="create_username" type="text" name="username" required placeholder="username" autocomplete="username"></div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="create_email">Email address</label></div>
          <div class="user-modal-row-control user-modal-icon-control"><i class="fa fa-envelope" aria-hidden="true"></i><input id="create_email" type="email" name="email" required placeholder="name@example.com" autocomplete="email"></div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="create_contact">Contact</label><small>Optional</small></div>
          <div class="user-modal-row-control"><input id="create_contact" type="text" name="contact" placeholder="+639123456789" autocomplete="tel"></div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="create_password">Password</label></div>
          <div class="user-modal-row-control"><input id="create_password" type="password" name="password" required autocomplete="new-password"></div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="create_user_image">Profile photo</label><small>Optional</small></div>
          <div class="user-modal-row-control user-modal-photo-control">
            <input id="create_user_image" type="file" name="user_image" accept="image/*">
            <small>JPG, PNG, GIF, or WEBP</small>
          </div>
        </div>

        <div class="user-modal-form-row user-modal-access-row">
          <div class="user-modal-access-field">
            <label for="create_role_id">Role</label>
            <select id="create_role_id" name="role_id" required>
              <?php foreach ($roles as $role): ?>
                <option value="<?= (int)$role['id'] ?>"><?= htmlspecialchars(role_display_name($role['name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="user-modal-access-field">
            <label for="create_status">Status</label>
            <select id="create_status" name="status" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <div class="user-create-modal-actions">
        <button type="button" class="user-cancel-btn js-close-create-user">Cancel</button>
        <button type="submit" class="user-submit-btn"><i class="fa fa-user-plus"></i> Create Account</button>
      </div>
    </form>
  </section>
</div>

<div class="user-create-modal user-edit-modal" id="editUserModal" aria-hidden="true">
  <section class="user-create-modal-dialog user-edit-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-user-modal-title">
    <header class="user-create-modal-header">
      <div>
        <span>Account Maintenance</span>
        <h2 id="edit-user-modal-title">Edit User</h2>
      </div>
    </header>

    <?php if ($reopenEditModal && $flashMessage !== ''): ?>
      <div class="user-create-modal-feedback <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
        <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="edit_user.php" class="user-create-modal-form user-edit-modal-form" enctype="multipart/form-data">
      <input type="hidden" name="return_to" value="users.php">

      <div class="user-modal-form-body">
        <div class="user-modal-profile-summary">
          <button type="button" class="user-edit-avatar-picker" id="editUserAvatarPicker" aria-label="Choose a new profile photo" title="Change profile photo">
            <span class="user-create-preview" aria-hidden="true">
              <i class="fa fa-user"></i>
              <img id="editUserPreview" alt="">
            </span>
            <span class="user-edit-avatar-badge" aria-hidden="true"><i class="fa fa-camera"></i></span>
          </button>
          <input id="edit_user_image" class="user-edit-avatar-input" type="file" name="image" accept="image/*" tabindex="-1" aria-hidden="true">
          <div>
            <h3 id="editUserSummaryName">User Account</h3>
            <p id="editUserSummaryEmail">Account details</p>
            <small class="user-edit-avatar-hint">Click the photo to replace it</small>
          </div>
        </div>

        <div class="user-modal-form-row user-modal-name-row">
          <div class="user-modal-row-heading">
            <span>Full name</span>
            <small>Required</small>
          </div>
          <div class="user-modal-name-fields">
            <div class="form-group">
              <label for="edit_last_name">Last name</label>
              <input id="edit_last_name" type="text" name="last_name" required autocomplete="family-name" autocapitalize="words" data-modal-capitalize-name>
            </div>
            <div class="form-group">
              <label for="edit_first_name">First name</label>
              <input id="edit_first_name" type="text" name="first_name" required autocomplete="given-name" autocapitalize="words" data-modal-capitalize-name>
            </div>
            <div class="form-group">
              <label for="edit_middle_name">Middle name <span>Optional</span></label>
              <input id="edit_middle_name" type="text" name="middle_name" autocomplete="additional-name" autocapitalize="words" data-modal-capitalize-name>
            </div>
          </div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="edit_email">Email address</label></div>
          <div class="user-modal-row-control user-modal-icon-control"><i class="fa fa-envelope" aria-hidden="true"></i><input id="edit_email" type="email" name="email" required autocomplete="email"></div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="edit_contact">Contact</label><small>Optional</small></div>
          <div class="user-modal-row-control"><input id="edit_contact" type="text" name="contact" placeholder="+639123456789" autocomplete="tel"></div>
        </div>

        <div class="user-modal-form-row">
          <div class="user-modal-row-heading"><label for="edit_password">New password</label><small>Optional</small></div>
          <div class="user-modal-row-control"><input id="edit_password" type="password" name="password" placeholder="Leave blank to keep current" autocomplete="new-password"></div>
        </div>

        <div class="user-modal-form-row user-modal-access-row">
          <div class="user-modal-access-field">
            <label for="edit_role_id">Role</label>
            <select id="edit_role_id" name="role_id" required>
              <?php foreach ($roles as $role): ?>
                <option value="<?= (int)$role['id'] ?>"><?= htmlspecialchars(role_display_name($role['name'])) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="user-modal-access-field">
            <label for="edit_status">Status</label>
            <select id="edit_status" name="status" required>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <div class="user-create-modal-actions">
        <button type="button" class="user-cancel-btn js-close-edit-user">Cancel</button>
        <button type="submit" class="user-submit-btn"><i class="fa fa-save"></i> Save Changes</button>
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
  const summaryName = document.getElementById('createUserSummaryName');
  const summaryEmail = document.getElementById('createUserSummaryEmail');
  const lastNameInput = document.getElementById('create_last_name');
  const firstNameInput = document.getElementById('create_first_name');
  const middleNameInput = document.getElementById('create_middle_name');
  const emailInput = document.getElementById('create_email');
  let lastFocus = null;
  let previewUrl = '';

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

  function updateSummary() {
    const fullName = [firstNameInput?.value, middleNameInput?.value, lastNameInput?.value]
      .map(function (part) { return String(part || '').trim(); })
      .filter(Boolean)
      .join(' ');
    summaryName.textContent = fullName || 'New User';
    summaryEmail.textContent = emailInput?.value.trim() || 'Create a Kape Agosto team account';
  }

  [lastNameInput, firstNameInput, middleNameInput, emailInput].forEach(function (input) {
    input?.addEventListener('input', updateSummary);
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
    const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]):not([type="hidden"]):not(.user-edit-avatar-input), select:not([disabled])'));
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
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = '';
    }
    const file = imageInput.files?.[0];
    if (!file) {
      preview.removeAttribute('src');
      preview.classList.remove('is-visible');
      return;
    }
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
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
      updateSummary();
    });
  });

  if (<?= $reopenCreateModal ? 'true' : 'false' ?>) {
    setModalOpen(true);
  }
})();
</script>
<script>
(function () {
  const modal = document.getElementById('editUserModal');
  const form = modal?.querySelector('form');
  const openControls = Array.from(document.querySelectorAll('.js-open-edit-user'));
  const closeControls = modal?.querySelectorAll('.js-close-edit-user') || [];
  const imageInput = document.getElementById('edit_user_image');
  const preview = document.getElementById('editUserPreview');
  const avatarPicker = document.getElementById('editUserAvatarPicker');
  const modalTitle = document.getElementById('edit-user-modal-title');
  const summaryName = document.getElementById('editUserSummaryName');
  const summaryEmail = document.getElementById('editUserSummaryEmail');
  const reopenId = <?= $requestedEditId ?>;
  const savedState = <?= json_encode($editFormState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let lastFocus = null;
  let previewUrl = '';
  let currentAvatar = 'img/CUP_BLACK.png';

  if (!modal || !form || !openControls.length) return;

  function setValue(id, value) {
    const field = document.getElementById(id);
    if (field) field.value = value == null ? '' : String(value);
  }

  function populate(control, override) {
    const data = Object.assign({
      id: control.dataset.userId,
      name: control.dataset.userName,
      last_name: control.dataset.lastName,
      first_name: control.dataset.firstName,
      middle_name: control.dataset.middleName,
      email: control.dataset.email,
      contact: control.dataset.contact,
      role_id: control.dataset.roleId,
      status: control.dataset.status,
      avatar: control.dataset.avatar
    }, override || {});

    form.action = 'edit_user.php?id=' + encodeURIComponent(data.id);
    modalTitle.textContent = 'Edit User';
    setValue('edit_last_name', data.last_name);
    setValue('edit_first_name', data.first_name);
    setValue('edit_middle_name', data.middle_name);
    setValue('edit_email', data.email);
    setValue('edit_contact', data.contact);
    setValue('edit_password', '');
    setValue('edit_role_id', data.role_id);
    setValue('edit_status', data.status);
    imageInput.value = '';
    summaryName.textContent = [data.first_name, data.middle_name, data.last_name]
      .map(function (part) { return String(part || '').trim(); })
      .filter(Boolean)
      .join(' ') || 'User Account';
    summaryEmail.textContent = data.email || 'Account details';

    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = '';
    }
    currentAvatar = data.avatar || 'img/CUP_BLACK.png';
    preview.src = currentAvatar;
    preview.classList.add('is-visible');
  }

  function setModalOpen(open) {
    const shouldOpen = Boolean(open);
    modal.classList.toggle('is-open', shouldOpen);
    modal.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    document.documentElement.classList.toggle('user-modal-lock', shouldOpen);

    if (shouldOpen) {
      window.setTimeout(function () { document.getElementById('edit_last_name')?.focus(); }, 0);
    } else if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    }
  }

  openControls.forEach(function (control) {
    control.addEventListener('click', function (event) {
      event.preventDefault();
      lastFocus = control;
      populate(control);
      setModalOpen(true);
    });
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
    const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]):not([type="hidden"]):not(.user-edit-avatar-input), select:not([disabled])'));
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
    if (previewUrl) {
      URL.revokeObjectURL(previewUrl);
      previewUrl = '';
    }
    const file = imageInput.files?.[0];
    if (!file) {
      preview.src = currentAvatar;
      preview.classList.add('is-visible');
      return;
    }
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.classList.add('is-visible');
  });

  avatarPicker?.addEventListener('click', function () {
    imageInput?.click();
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
      updateEditSummary();
    });
  });

  function updateEditSummary() {
    const fullName = [
      document.getElementById('edit_first_name')?.value,
      document.getElementById('edit_middle_name')?.value,
      document.getElementById('edit_last_name')?.value
    ]
      .map(function (part) { return String(part || '').trim(); })
      .filter(Boolean)
      .join(' ');
    summaryName.textContent = fullName || 'User Account';
    summaryEmail.textContent = document.getElementById('edit_email')?.value.trim() || 'Account details';
  }

  ['edit_last_name', 'edit_first_name', 'edit_middle_name', 'edit_email'].forEach(function (id) {
    document.getElementById(id)?.addEventListener('input', updateEditSummary);
  });

  if (reopenId > 0) {
    const control = openControls.find(function (candidate) {
      return Number(candidate.dataset.userId) === Number(reopenId);
    });
    if (control) {
      lastFocus = control;
      populate(control, savedState && Number(savedState.id) === Number(reopenId) ? savedState : null);
      setModalOpen(true);
    }
  }
})();
</script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/management-list.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/management-list.js')) ?>" defer></script>
</body>
</html>
