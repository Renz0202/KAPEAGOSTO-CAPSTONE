<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_admin();

$id = (int)($_GET['id'] ?? 0);
$pdo = db();

$stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
$stmt->execute([$id]);
$category = $stmt->fetch();

if (!$category) {
    header('Location: categories.php');
    exit;
}

if (empty($_SESSION['csrf_category_edit'])) {
    $_SESSION['csrf_category_edit'] = bin2hex(random_bytes(32));
}

$errors = [];

function category_edit_image_src(?string $image): string {
    if (!empty($image)) {
        $fileName = basename($image);
        $candidates = [
            dirname(__DIR__) . '/uploads/categories/' . $fileName => 'uploads/categories/' . rawurlencode($fileName),
            dirname(__DIR__) . '/uploads/' . $fileName => 'uploads/' . rawurlencode($fileName),
        ];

        foreach ($candidates as $absolutePath => $webPath) {
            if (is_file($absolutePath)) {
                return $webPath;
            }
        }
    }

    return 'img/CUP_BLACK.png';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = normalize_uppercase_name((string)($_POST['name'] ?? ''));
    $imageName = $category['image'];

    $csrfToken = $_SESSION['csrf_category_edit'] ?? '';
    if ($csrfToken === '' || !hash_equals($csrfToken, $_POST['csrf'] ?? '')) {
        $errors[] = 'Invalid form token. Please try again.';
    }

    if ($name === '') {
        $errors[] = 'Category name is required.';
    }

    if (!$errors && !empty($_FILES['image']['name'])) {
        if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors[] = 'Failed to upload image.';
        } else {
            $uploadDir = dirname(__DIR__) . '/uploads/categories/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $allowedTypes, true)) {
                $errors[] = 'Invalid image format. Only JPG, PNG, GIF, and WEBP are allowed.';
            } else {
                $oldFileName = !empty($imageName) ? basename($imageName) : '';
                $oldCandidates = [
                    dirname(__DIR__) . '/uploads/categories/' . $oldFileName,
                    dirname(__DIR__) . '/uploads/' . $oldFileName,
                ];

                $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($_FILES['image']['name'], PATHINFO_FILENAME));
                $imageName = uniqid('category_', true) . '_' . $baseName . '.' . $ext;
                $target = $uploadDir . $imageName;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $target)) {
                    foreach ($oldCandidates as $oldPath) {
                        if ($oldFileName !== '' && is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                } else {
                    $errors[] = 'Failed to save image.';
                }
            }
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, image = ? WHERE id = ?');
            $stmt->execute([$name, $imageName, $id]);
            $_SESSION['flash'] = 'Category updated successfully.';
            header('Location: categories.php');
            exit;
        } catch (PDOException $e) {
            if (strpos(strtolower($e->getMessage()), 'duplicate') !== false || ($e->errorInfo[1] ?? null) == 1062) {
                $errors[] = 'Category name already exists.';
            } else {
                $errors[] = 'Failed to update category.';
            }
        }
    }

    $category['name'] = $name;
    $category['image'] = $imageName;
}

$previewSrc = category_edit_image_src($category['image'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Category - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-catalog-edit-category">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content category-edit-page">
  <section class="category-edit-heading category-page-heading" aria-labelledby="edit-category-title">
    <div>
      <p class="category-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Category <i class="fa fa-chevron-right"></i> Edit</p>
      <h1 id="edit-category-title">Edit Category</h1>
      <p class="category-page-copy">Update the category name and display image used across the menu setup.</p>
    </div>
    <a href="categories.php" class="category-secondary-action">
      <i class="fa fa-arrow-left"></i>
      Back to Categories
    </a>
  </section>

  <?php if (!empty($errors)): ?>
    <div class="category-flash error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <section class="category-edit-panel">
    <aside class="category-edit-aside">
      <div class="category-edit-preview">
        <img id="preview" src="<?= htmlspecialchars($previewSrc) ?>" alt="<?= htmlspecialchars($category['name'] ?? 'Category') ?> preview">
      </div>
      <h2><?= htmlspecialchars($category['name'] ?? 'Category') ?></h2>
      <p>Use a clear image that represents this menu group.</p>
    </aside>

    <form method="post" enctype="multipart/form-data" class="category-edit-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_category_edit']) ?>">

      <div class="form-group">
        <label for="name">Category Name</label>
        <input id="name" type="text" name="name" value="<?= htmlspecialchars($category['name'] ?? '') ?>" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required>
      </div>

      <div class="form-group">
        <label for="image">Category Image</label>
        <input id="image" type="file" name="image" accept="image/*" onchange="previewCategoryImage(event)">
      </div>

      <div class="form-actions">
        <button type="submit" class="category-submit-btn">
          <i class="fa fa-save"></i>
          Update Category
        </button>
        <a href="categories.php" class="category-cancel-btn">
          <i class="fa fa-xmark"></i>
          Cancel
        </a>
      </div>
    </form>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
function previewCategoryImage(event) {
  const file = event.target.files[0];
  const preview = document.getElementById('preview');

  if (file) {
    preview.src = URL.createObjectURL(file);
  }
}
</script>
</body>
</html>
