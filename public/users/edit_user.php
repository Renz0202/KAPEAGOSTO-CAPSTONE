<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/user_utils.php';
require_admin();

$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    header('Location: users.php');
    exit;
}

$pdo = db();

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.last_name, u.first_name, u.middle_name,
           u.email, u.contact, u.image,
           u.status, u.role_id, r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: users.php');
    exit;
}

$isDefaultAdminTarget = (strcasecmp($user['username'] ?? '', (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
  || (strcasecmp($user['email'] ?? '', (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);

if ($isDefaultAdminTarget) {
    $_SESSION['flash'] = 'The default Admin account is protected and cannot be edited.';
    header('Location: users.php');
    exit;
}

$rolesStmt = $pdo->query("SELECT id, name FROM roles WHERE LOWER(name) != 'guest' ORDER BY id ASC");
$roles = $rolesStmt->fetchAll();
$errors = [];
$currentStatus = strtolower(trim((string)($user['status'] ?? '')));
$nameParts = user_name_parts_from_record($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $last_name = user_title_case($_POST['last_name'] ?? '');
    $first_name = user_title_case($_POST['first_name'] ?? '');
    $middle_name = user_title_case($_POST['middle_name'] ?? '');
    $full_name = user_build_full_name($last_name, $first_name, $middle_name);
    $email = strtolower(trim($_POST['email'] ?? ''));
    $contact = trim($_POST['contact'] ?? '');
    $role_id = $_POST['role_id'] ?? '';
    $status = $_POST['status'] ?? 'inactive';

    if ($last_name === '') {
        $errors[] = 'Last name is required.';
    }
    if ($first_name === '') {
        $errors[] = 'First name is required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors[] = 'Invalid account status.';
    }

    $nameParts = [
        'last_name' => $last_name,
        'first_name' => $first_name,
        'middle_name' => $middle_name,
    ];

    if (!$errors) {
        $newImage = $user['image'];
        if (!empty($_FILES['image']['name'])) {
            $targetDir = dirname(__DIR__) . '/uploads/users/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($ext, $allowedTypes, true)) {
                $filename = uniqid('user_', true) . '.' . $ext;
                $targetFile = $targetDir . $filename;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                    $newImage = 'uploads/users/' . $filename;
                }
            }
        }

        if (!empty($_POST['password'])) {
            $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET full_name = ?, last_name = ?, first_name = ?, middle_name = ?,
                    email = ?, contact = ?, password_hash = ?, role_id = ?, status = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $last_name, $first_name, $middle_name !== '' ? $middle_name : null, $email, $contact, $password_hash, $role_id, $status, $newImage, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET full_name = ?, last_name = ?, first_name = ?, middle_name = ?,
                    email = ?, contact = ?, role_id = ?, status = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$full_name, $last_name, $first_name, $middle_name !== '' ? $middle_name : null, $email, $contact, $role_id, $status, $newImage, $id]);
        }

        if (isset($_SESSION['user']) && (int)$_SESSION['user']['id'] === (int)$id) {
            $_SESSION['user']['full_name'] = $full_name;
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['contact'] = $contact;
            $_SESSION['user']['role_id'] = $role_id;
            foreach ($roles as $availableRole) {
                if ((int) $availableRole['id'] === (int) $role_id) {
                    $_SESSION['user']['role'] = normalize_role_name((string) $availableRole['name']);
                    break;
                }
            }
            $_SESSION['user']['status'] = $status;
            $_SESSION['user']['image'] = $newImage;
        }

        $_SESSION['flash'] = 'User updated successfully.';
        header('Location: users.php');
        exit;
    }

    if ((string) ($_POST['return_to'] ?? '') === 'users.php') {
        $_SESSION['flash'] = implode(' ', $errors);
        $_SESSION['flash_type'] = 'error';
        $_SESSION['edit_user_form'] = [
            'id' => (int) $id,
            'last_name' => $last_name,
            'first_name' => $first_name,
            'middle_name' => $middle_name,
            'email' => $email,
            'contact' => $contact,
            'role_id' => $role_id,
            'status' => $status,
        ];

        header('Location: users.php?edit=' . (int) $id);
        exit;
    }
}

$image = !empty($user['image']) ? basename($user['image']) : '';
$previewSrc = '';
if ($image !== '') {
    $imgPath = dirname(__DIR__) . '/uploads/users/' . $image;
    if (is_file($imgPath)) {
        $previewSrc = 'uploads/users/' . rawurlencode($image);
    }
}
if ($previewSrc === '') {
    $previewSrc = 'img/CUP_BLACK.png';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit User - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-users-edit-user">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content user-form-page">
  <section class="user-page-heading" aria-labelledby="edit-user-title">
    <div>
      <p class="user-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> User Management</p>
      <h1 id="edit-user-title">Edit User</h1>
      <p class="user-page-copy">Update account details, access level, status, and profile image.</p>
    </div>
    <a href="users.php" class="user-secondary-action">
      <i class="fa fa-arrow-left"></i>
      Back to Users
    </a>
  </section>

  <?php if (!empty($errors)): ?>
    <div class="user-flash error">
      <?= htmlspecialchars(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="user-flash"><?= htmlspecialchars($_SESSION['flash']) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>

  <section class="user-form-panel">
    <div class="user-form-aside">
      <div class="user-preview">
        <i id="defaultIcon" class="fa fa-user-circle is-hidden"></i>
        <img id="preview" src="<?= htmlspecialchars($previewSrc) ?>" alt="Profile preview" class="is-visible">
      </div>
      <h2><?= htmlspecialchars(user_formatted_name_from_record($user, user_username_display($user['username'] ?? ''))) ?></h2>
      <p><?= htmlspecialchars($user['email']) ?></p>
    </div>

    <form method="post" enctype="multipart/form-data" class="user-form">
      <div class="form-group full">
        <label for="image"><i class="fa fa-image"></i> Profile Image</label>
        <input id="image" type="file" name="image" accept="image/*" onchange="previewImage(event)">
      </div>

      <div class="form-group">
        <label for="last_name">Last Name</label>
        <input id="last_name" type="text" name="last_name" value="<?= htmlspecialchars($nameParts['last_name']) ?>" required autocomplete="family-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="first_name">First Name</label>
        <input id="first_name" type="text" name="first_name" value="<?= htmlspecialchars($nameParts['first_name']) ?>" required autocomplete="given-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="middle_name">Middle Name <span class="optional-label">Optional</span></label>
        <input id="middle_name" type="text" name="middle_name" value="<?= htmlspecialchars($nameParts['middle_name']) ?>" autocomplete="additional-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
      </div>

      <div class="form-group">
        <label for="contact">Contact</label>
        <input id="contact" type="text" name="contact" value="<?= htmlspecialchars($user['contact']) ?>" placeholder="+639123456789">
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" placeholder="Leave blank to keep current">
      </div>

      <div class="form-group">
        <label for="role_id">Role</label>
        <select id="role_id" name="role_id">
          <?php foreach ($roles as $role): ?>
            <option value="<?= (int)$role['id'] ?>" <?= (int)$user['role_id'] === (int)$role['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(role_display_name($role['name'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" required>
          <option value="active" <?= $currentStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $currentStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>

      <div class="form-actions full">
        <button type="submit" class="user-submit-btn"><i class="fa fa-save"></i> Update User</button>
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
