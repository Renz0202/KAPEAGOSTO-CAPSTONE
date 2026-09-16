<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT image FROM categories WHERE id = ?');
$stmt->execute([$id]);
$category = $stmt->fetch();

if ($category) {
    $imageName = !empty($category['image']) ? basename($category['image']) : '';
    $imageCandidates = [
        dirname(__DIR__) . '/uploads/categories/' . $imageName,
        dirname(__DIR__) . '/uploads/' . $imageName,
    ];

    foreach ($imageCandidates as $imagePath) {
        if ($imageName !== '' && is_file($imagePath)) {
            @unlink($imagePath);
        }
    }

    $stmt = $pdo->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $_SESSION['flash'] = 'Category deleted successfully.';
}

header('Location: categories.php');
exit;
