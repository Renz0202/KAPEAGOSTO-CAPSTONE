<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

if (!is_admin()) {
    header('Location: items.php');
    exit;
}

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['flash'] = 'Invalid item ID.';
    header('Location: items.php');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT image FROM items WHERE id = ?');
    $stmt->execute([$id]);
    $item = $stmt->fetch();

    if (!$item) {
        $_SESSION['flash'] = 'Item not found.';
        header('Location: items.php');
        exit;
    }

    $stmt = $pdo->prepare('DELETE FROM items WHERE id = ?');
    $stmt->execute([$id]);

    $imageName = !empty($item['image']) ? basename($item['image']) : '';
    $imagePath = dirname(__DIR__) . '/uploads/' . $imageName;
    if ($imageName !== '' && is_file($imagePath)) {
        @unlink($imagePath);
    }

    $_SESSION['flash'] = 'Item deleted successfully.';
} catch (PDOException $e) {
    $_SESSION['flash'] = 'Failed to delete item. Try again later.';
}

header('Location: items.php');
exit;
