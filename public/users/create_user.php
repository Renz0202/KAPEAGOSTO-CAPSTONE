<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_admin();

$pdo = db();
$roles = $pdo->query("SELECT id, name FROM roles WHERE LOWER(name) != 'guest' ORDER BY id ASC")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create User - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-users-create-user">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content user-form-page">
  <section class="user-page-heading" aria-labelledby="create-user-title">
    <div>
      <p class="user-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> User Management</p>
      <h1 id="create-user-title">Create User</h1>
      <p class="user-page-copy">Set up Admin, Manager, or Cashier access for Kape Agosto operations.</p>
    </div>
    <a href="users.php" class="user-secondary-action">
      <i class="fa fa-arrow-left"></i>
      Back to Users
    </a>
  </section>

  <?php if (!empty($_SESSION['flash'])): ?>
    <?php $flashType = $_SESSION['flash_type'] ?? 'success'; ?>
    <div class="user-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
      <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
      <span><?= htmlspecialchars($_SESSION['flash']) ?></span>
    </div>
    <?php unset($_SESSION['flash'], $_SESSION['flash_type']); ?>
  <?php endif; ?>

  <section class="user-form-panel">
    <div class="user-form-aside">
      <div class="user-preview">
        <i id="defaultIcon" class="fa fa-user-circle"></i>
        <img id="preview" alt="Profile preview">
      </div>
      <h2>Account Profile</h2>
      <p>Upload a clear profile photo so the team can identify accounts quickly.</p>
    </div>

    <form method="post" action="create_user_process.php" class="user-form" enctype="multipart/form-data">
      <div class="form-group full">
        <label for="user_image"><i class="fa fa-image"></i> Profile Image</label>
        <input id="user_image" type="file" name="user_image" accept="image/*" onchange="previewImage(event)">
      </div>

      <div class="form-group">
        <label for="last_name"><i class="fa fa-id-card"></i> Last Name</label>
        <input id="last_name" type="text" name="last_name" required placeholder="Castro" autocomplete="family-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="first_name"><i class="fa fa-id-card"></i> First Name</label>
        <input id="first_name" type="text" name="first_name" required placeholder="Renz" autocomplete="given-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="middle_name"><i class="fa fa-id-card"></i> Middle Name <span class="optional-label">Optional</span></label>
        <input id="middle_name" type="text" name="middle_name" placeholder="Santos" autocomplete="additional-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="username"><i class="fa fa-user"></i> Username</label>
        <input id="username" type="text" name="username" required placeholder="username">
      </div>

      <div class="form-group">
        <label for="email"><i class="fa fa-envelope"></i> Email</label>
        <input id="email" type="email" name="email" required placeholder="name@example.com">
      </div>

      <div class="form-group">
        <label for="contact"><i class="fa fa-phone"></i> Contact</label>
        <input id="contact" type="text" name="contact" placeholder="+639123456789">
      </div>

      <div class="form-group">
        <label for="password"><i class="fa fa-lock"></i> Password</label>
        <input id="password" type="password" name="password" required>
      </div>

      <div class="form-group">
        <label for="role_id"><i class="fa fa-user-shield"></i> Role</label>
        <select id="role_id" name="role_id" required>
          <?php foreach ($roles as $role): ?>
            <option value="<?= (int)$role['id'] ?>"><?= htmlspecialchars(role_display_name($role['name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="status"><i class="fa fa-toggle-on"></i> Status</label>
        <select id="status" name="status" required>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <div class="form-actions full">
        <button type="submit" class="user-submit-btn"><i class="fa fa-save"></i> Create Account</button>
        <a href="users.php" class="user-cancel-btn"><i class="fa fa-xmark"></i> Cancel</a>
      </div>
    </form>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
function previewImage(event) {
  const file = event.target.files[0];
  const preview = document.getElementById('preview');
  const defaultIcon = document.getElementById('defaultIcon');

  if (file) {
    preview.src = URL.createObjectURL(file);
    preview.classList.add('is-visible');
    defaultIcon.classList.add('is-hidden');
    return;
  }

  preview.removeAttribute('src');
  preview.classList.remove('is-visible');
  defaultIcon.classList.remove('is-hidden');
}

document.querySelectorAll('[data-auto-capitalize-name]').forEach((input) => {
  input.addEventListener('blur', () => {
    input.value = input.value
      .trim()
      .replace(/\s+/g, ' ')
      .toLocaleLowerCase()
      .replace(/(^|[\s,'-])([a-z])/g, (_, separator, letter) => separator + letter.toLocaleUpperCase());
  });
});
</script>
</body>
</html>
