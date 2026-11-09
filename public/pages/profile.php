<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/user_utils.php';
require_login();

$pdo = db();
$sessionUser = $_SESSION['user'];
$userId = (int)($sessionUser['id'] ?? 0);
$errors = [];
$message = '';
$messageType = 'success';

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.last_name, u.first_name, u.middle_name,
           u.email, u.contact, u.image,
           u.status, u.registered_at, r.name AS role_name
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    logout_user();
    header('Location: login.php');
    exit;
}

$username = $currentUser['username'] ?? '';
$fullName = $currentUser['full_name'] ?? '';
$email = $currentUser['email'] ?? '';
$contact = $currentUser['contact'] ?? '';
$image = $currentUser['image'] ?? '';
$roleName = $currentUser['role_name'] ?? ($sessionUser['role'] ?? 'User');
$status = $currentUser['status'] ?? 'active';
$registeredAt = $currentUser['registered_at'] ?? null;
$nameParts = user_name_parts_from_record($currentUser);

$isDefaultAdmin = (strcasecmp($username, (defined('DEFAULT_ADMIN_USERNAME') ? DEFAULT_ADMIN_USERNAME : 'Admin')) === 0)
    || (strcasecmp($email, (defined('DEFAULT_ADMIN_EMAIL') ? DEFAULT_ADMIN_EMAIL : 'admin@kapeagosto.local')) === 0);

function profile_avatar_src(?string $image): string {
    if (!empty($image)) {
        $fileName = basename($image);
        $absolutePath = dirname(__DIR__) . '/uploads/users/' . $fileName;
        if (is_file($absolutePath)) {
            return 'uploads/users/' . rawurlencode($fileName);
        }
    }

    return 'img/CUP_BLACK.png';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['update_profile'])) {
    $newUsername = trim($_POST['username'] ?? '');
    $newLastName = user_title_case($_POST['last_name'] ?? '');
    $newFirstName = user_title_case($_POST['first_name'] ?? '');
    $newMiddleName = user_title_case($_POST['middle_name'] ?? '');
    $newFullName = user_build_full_name($newLastName, $newFirstName, $newMiddleName);
    $newEmail = strtolower(trim($_POST['email'] ?? ''));
    $newContact = trim($_POST['contact'] ?? '');
    $newPassword = trim($_POST['password'] ?? '');
    $newImage = $image;

    if ($newUsername === '') {
        $errors[] = 'Username is required.';
    }

    if ($newLastName === '') {
        $errors[] = 'Last name is required.';
    }

    if ($newFirstName === '') {
        $errors[] = 'First name is required.';
    }

    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }

    if ($newUsername !== '') {
        $dupStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$newUsername, $userId]);
        if ($dupStmt->fetch()) {
            $errors[] = 'Username is already used by another account.';
        }
    }

    if ($newEmail !== '') {
        $dupStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$newEmail, $userId]);
        if ($dupStmt->fetch()) {
            $errors[] = 'Email is already used by another account.';
        }
    }

    $nameParts = [
        'last_name' => $newLastName,
        'first_name' => $newFirstName,
        'middle_name' => $newMiddleName,
    ];

    if (!empty($_FILES['image']['name'])) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Profile image upload failed. Please try another file.';
        } else {
            $uploadDir = dirname(__DIR__) . '/uploads/users/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $allowedTypes, true)) {
                $errors[] = 'Only JPG, PNG, GIF, and WEBP profile images are allowed.';
            } else {
                $fileName = uniqid('user_', true) . '.' . $ext;
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $newImage = 'uploads/users/' . $fileName;
                } else {
                    $errors[] = 'Profile image could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        if ($newPassword !== '' && !$isDefaultAdmin) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                UPDATE users
                SET username = ?, full_name = ?, last_name = ?, first_name = ?, middle_name = ?,
                    email = ?, contact = ?, password_hash = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$newUsername, $newFullName, $newLastName, $newFirstName, $newMiddleName !== '' ? $newMiddleName : null, $newEmail, $newContact, $hashedPassword, $newImage, $userId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users
                SET username = ?, full_name = ?, last_name = ?, first_name = ?, middle_name = ?,
                    email = ?, contact = ?, image = ?
                WHERE id = ?
            ");
            $stmt->execute([$newUsername, $newFullName, $newLastName, $newFirstName, $newMiddleName !== '' ? $newMiddleName : null, $newEmail, $newContact, $newImage, $userId]);
        }

        $_SESSION['user']['username'] = $newUsername;
        $_SESSION['user']['full_name'] = $newFullName;
        $_SESSION['user']['email'] = $newEmail;
        $_SESSION['user']['contact'] = $newContact;
        $_SESSION['user']['image'] = $newImage;
        $_SESSION['user']['role'] = $roleName;

        $username = $newUsername;
        $fullName = $newFullName;
        $email = $newEmail;
        $contact = $newContact;
        $image = $newImage;

        if ($isDefaultAdmin && $newPassword !== '') {
            $message = 'Profile updated, but the default Admin password was kept protected.';
        } else {
            $message = 'Profile updated successfully.';
        }
    } else {
        $messageType = 'error';
        $message = implode(' ', $errors);
    }
}

$previewSrc = profile_avatar_src($image);
$displayName = user_formatted_full_name($fullName, user_username_display($username));
$roleLabel = role_display_name($roleName);
$statusLabel = user_title_case($status, 'Active');
$registeredLabel = $registeredAt ? date('M d, Y', strtotime($registeredAt)) : 'Recently added';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Profile - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-pages-profile">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content profile-page">
  <section class="profile-page-heading" aria-labelledby="profile-title">
    <div>
      <p class="profile-breadcrumb">
        <?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> My Account <i class="fa fa-chevron-right"></i> User Profile
      </p>
      <h1 id="profile-title">User Profile</h1>
      <p class="profile-page-copy">Keep your account details current for daily operations and team visibility.</p>
    </div>
    <a href="dashboard.php" class="profile-secondary-action">
      <i class="fa fa-arrow-left"></i>
      Back to Dashboard
    </a>
  </section>

  <?php if ($message !== ''): ?>
    <div class="profile-flash <?= $messageType === 'error' ? 'error' : 'success' ?>">
      <?= htmlspecialchars($message) ?>
    </div>
  <?php endif; ?>

  <section class="profile-panel">
    <aside class="profile-aside">
      <button type="button" class="profile-avatar-picker" id="profileAvatarPicker" aria-label="Choose a new profile photo" title="Change profile photo">
        <span class="profile-preview">
          <i id="defaultIcon" class="fa fa-user-circle is-hidden"></i>
          <img id="preview" src="<?= htmlspecialchars($previewSrc) ?>" alt="<?= htmlspecialchars($displayName) ?> profile photo" class="is-visible">
        </span>
        <span class="profile-avatar-badge" aria-hidden="true"><i class="fa fa-camera"></i></span>
      </button>
      <small class="profile-avatar-hint">Click the photo to replace it</small>
      <h2><?= htmlspecialchars($displayName) ?></h2>
      <p><?= htmlspecialchars($email ?: 'No email set') ?></p>
      <div class="profile-chip-row">
        <span class="profile-role-chip"><?= htmlspecialchars($roleLabel) ?></span>
        <span class="profile-status-chip <?= htmlspecialchars(strtolower($statusLabel)) ?>"><?= htmlspecialchars($statusLabel) ?></span>
      </div>
      <dl class="profile-meta-list">
        <div>
          <dt>Contact</dt>
          <dd><?= htmlspecialchars($contact ?: 'Not set') ?></dd>
        </div>
        <div>
          <dt>Member Since</dt>
          <dd><?= htmlspecialchars($registeredLabel) ?></dd>
        </div>
      </dl>
    </aside>

    <form method="post" enctype="multipart/form-data" class="profile-form-modern">
      <input id="image" class="profile-avatar-input" type="file" name="image" accept="image/*" tabindex="-1" aria-hidden="true">

      <div class="form-group">
        <label for="username"><i class="fa fa-user"></i> Username</label>
        <input id="username" type="text" name="username" value="<?= htmlspecialchars($username) ?>" required>
      </div>

      <div class="form-group">
        <label for="last_name"><i class="fa fa-id-card"></i> Last Name</label>
        <input id="last_name" type="text" name="last_name" value="<?= htmlspecialchars($nameParts['last_name']) ?>" required autocomplete="family-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="first_name"><i class="fa fa-id-card"></i> First Name</label>
        <input id="first_name" type="text" name="first_name" value="<?= htmlspecialchars($nameParts['first_name']) ?>" required autocomplete="given-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="middle_name"><i class="fa fa-id-card"></i> Middle Name <span class="optional-label">Optional</span></label>
        <input id="middle_name" type="text" name="middle_name" value="<?= htmlspecialchars($nameParts['middle_name']) ?>" autocomplete="additional-name" autocapitalize="words" data-auto-capitalize-name>
      </div>

      <div class="form-group">
        <label for="email"><i class="fa fa-envelope"></i> Email</label>
        <input id="email" type="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
      </div>

      <div class="form-group">
        <label for="contact"><i class="fa fa-phone"></i> Contact Number</label>
        <input id="contact" type="text" name="contact" value="<?= htmlspecialchars($contact) ?>" placeholder="+639123456789">
      </div>

      <div class="form-group full">
        <label for="password"><i class="fa fa-lock"></i> New Password</label>
        <input id="password" type="password" name="password" placeholder="<?= $isDefaultAdmin ? 'Disabled for default Admin' : 'Leave blank to keep current password' ?>" <?= $isDefaultAdmin ? 'disabled' : '' ?>>
        <?php if ($isDefaultAdmin): ?>
          <small class="profile-field-note">The default administrator password is protected from this screen.</small>
        <?php else: ?>
          <small class="profile-field-note">Leave this blank if you do not want to change your password.</small>
        <?php endif; ?>
      </div>

      <div class="form-actions full">
        <button type="submit" name="update_profile" class="profile-submit-btn">
          <i class="fa fa-save"></i>
          Update Profile
        </button>
        <a href="dashboard.php" class="profile-cancel-btn">
          <i class="fa fa-xmark"></i>
          Cancel
        </a>
      </div>
    </form>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
(function () {
  const picker = document.getElementById('profileAvatarPicker');
  const imageInput = document.getElementById('image');
  const preview = document.getElementById('preview');
  const defaultIcon = document.getElementById('defaultIcon');
  const currentAvatar = preview?.getAttribute('src') || 'img/CUP_BLACK.png';
  let previewUrl = '';

  picker?.addEventListener('click', function () {
    imageInput?.click();
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
      defaultIcon?.classList.add('is-hidden');
      return;
    }

    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.classList.add('is-visible');
    defaultIcon?.classList.add('is-hidden');
  });
})();

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
