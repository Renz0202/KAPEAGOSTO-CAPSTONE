<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/user_utils.php';

require_admin();

$createUserErrorRedirect = (($_POST['return_to'] ?? '') === 'users.php')
    ? 'users.php?create=1'
    : 'create_user.php';

function create_user_flash(string $message, string $type = 'error', ?string $redirect = null): void {
    global $createUserErrorRedirect;
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: ' . ($redirect ?? $createUserErrorRedirect));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create_user.php');
    exit;
}

$lastName = user_title_case($_POST['last_name'] ?? '');
$firstName = user_title_case($_POST['first_name'] ?? '');
$middleName = user_title_case($_POST['middle_name'] ?? '');
$fullName = user_build_full_name($lastName, $firstName, $middleName);
$username = trim((string) ($_POST['username'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$contact = trim((string) ($_POST['contact'] ?? ''));
$plainPassword = (string) ($_POST['password'] ?? '');
$roleId = (int) ($_POST['role_id'] ?? 0);
$status = strtolower((string) ($_POST['status'] ?? 'active'));

if ($lastName === '' || $firstName === '' || $username === '' || $email === '' || $plainPassword === '' || $roleId <= 0) {
    create_user_flash('Please complete all required account details.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    create_user_flash('Please enter a valid email address.');
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

$imagePath = null;
if (!empty($_FILES['user_image']['name'])) {
    $uploadDir = dirname(__DIR__) . '/uploads/users/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $ext = strtolower(pathinfo((string) $_FILES['user_image']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowed, true)) {
        create_user_flash('Invalid profile image. Please upload JPG, PNG, GIF, or WEBP only.');
    }

    if (($_FILES['user_image']['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        create_user_flash('Profile image could not be uploaded. Please try again.');
    }

    $fileName = uniqid('user_', true) . '.' . $ext;
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES['user_image']['tmp_name'], $targetPath)) {
        create_user_flash('Profile image could not be saved. Please try again.');
    }

    $imagePath = 'uploads/users/' . $fileName;
}

try {
    $pdo = db();

    $roleStmt = $pdo->prepare('SELECT LOWER(name) FROM roles WHERE id = ? LIMIT 1');
    $roleStmt->execute([$roleId]);
    $selectedRole = normalize_role_name((string) $roleStmt->fetchColumn());
    if (!in_array($selectedRole, ['admin', 'manager', 'cashier'], true)) {
        create_user_flash('Please select a valid Admin, Manager, or Cashier role.');
    }

    $check = $pdo->prepare('
        SELECT username, email
        FROM users
        WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)
        LIMIT 1
    ');
    $check->execute([$username, $email]);
    $existingUser = $check->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        if (strcasecmp((string) $existingUser['username'], $username) === 0) {
            create_user_flash('Username already exists. Please choose a different username.');
        }

        if (strcasecmp((string) $existingUser['email'], $email) === 0) {
            create_user_flash('Email address already exists. Please use a different email address.');
        }

        create_user_flash('An account with the same username or email already exists.');
    }

    $stmt = $pdo->prepare('
        INSERT INTO users (full_name, last_name, first_name, middle_name, username, email, contact, password_hash, role_id, status, image)
        VALUES (:full_name, :last_name, :first_name, :middle_name, :username, :email, :contact, :password_hash, :role_id, :status, :image)
    ');
    $stmt->execute([
        ':full_name' => $fullName,
        ':last_name' => $lastName,
        ':first_name' => $firstName,
        ':middle_name' => $middleName !== '' ? $middleName : null,
        ':username' => $username,
        ':email' => $email,
        ':contact' => $contact,
        ':password_hash' => password_hash($plainPassword, PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':status' => $status,
        ':image' => $imagePath,
    ]);

    create_user_flash('User account created successfully.', 'success', 'users.php');
} catch (PDOException $e) {
    error_log('Create user failed: ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        create_user_flash('Unable to create account because the username or email is already in use.');
    }

    create_user_flash('Unable to create account at this time. Please review the details and try again.');
} catch (Throwable $e) {
    error_log('Create user failed: ' . $e->getMessage());
    create_user_flash('Unable to create account at this time. Please try again.');
}
?>
