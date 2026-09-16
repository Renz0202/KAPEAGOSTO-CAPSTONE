<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_admin();

$pdo = db();

function category_add_redirect(string $message, string $type = 'error', bool $reopenModal = true): void {
    $_SESSION['flash'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: categories.php' . ($reopenModal ? '?create=1' : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: categories.php');
    exit;
}

$csrfToken = $_SESSION['csrf_category'] ?? '';
if ($csrfToken === '' || !hash_equals($csrfToken, $_POST['csrf'] ?? '')) {
    category_add_redirect('Invalid form token. Please try again.');
}

$name = normalize_uppercase_name((string)($_POST['name'] ?? ''));
$imageName = null;

if ($name === '') {
    category_add_redirect('Category name is required.');
}

if (!empty($_FILES['image']['name'])) {
    $uploadDir = dirname(__DIR__) . '/uploads/categories/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        category_add_redirect('Failed to upload image.');
    }

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowedTypes, true)) {
        category_add_redirect('Invalid image format. Only JPG, PNG, GIF, and WEBP are allowed.');
    }

    $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($_FILES['image']['name'], PATHINFO_FILENAME));
    $imageName = uniqid('category_', true) . '_' . $baseName . '.' . $ext;
    $target = $uploadDir . $imageName;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
        category_add_redirect('Failed to upload image.');
    }
}

try {
    $stmt = $pdo->prepare('INSERT INTO categories (name, image) VALUES (?, ?)');
    $stmt->execute([$name, $imageName]);
    $resultMessage = 'Category added successfully.';
    $resultType = 'success';
} catch (PDOException $e) {
    if (strpos(strtolower($e->getMessage()), 'duplicate') !== false || ($e->errorInfo[1] ?? null) == 1062) {
        $resultMessage = 'Category name already exists.';
    } else {
        $resultMessage = 'Failed to add category.';
    }
    $resultType = 'error';

    if ($imageName && file_exists(dirname(__DIR__) . '/uploads/categories/' . $imageName)) {
        @unlink(dirname(__DIR__) . '/uploads/categories/' . $imageName);
    }
}

category_add_redirect($resultMessage, $resultType, $resultType === 'error');
