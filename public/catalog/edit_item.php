<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_admin();

if (!is_admin()) {
    header('Location: items.php');
    exit;
}

$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: items.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
$stmt->execute([$id]);
$item = $stmt->fetch();

if (!$item) {
    $_SESSION['flash'] = 'Item not found.';
    header('Location: items.php');
    exit;
}

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$inventoryRecords = $pdo->query('SELECT id, sku, name, cup_size, unit, material_type FROM inventory ORDER BY material_type, name, cup_size')->fetchAll(PDO::FETCH_ASSOC);
$requirementStmt = $pdo->prepare('SELECT inventory_id, quantity_needed FROM item_requirements WHERE item_id = ?');
$requirementStmt->execute([$id]);
$requirementMap = $requirementStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$recipeMetadataStmt = $pdo->prepare('SELECT source, notes FROM item_recipe_metadata WHERE item_id = ?');
$recipeMetadataStmt->execute([$id]);
$recipeMetadata = $recipeMetadataStmt->fetch(PDO::FETCH_ASSOC);
$errors = [];

function edit_item_image_src(?string $image): string {
    if (!empty($image)) {
        $fileName = basename($image);
        $absolutePath = dirname(__DIR__) . '/uploads/' . $fileName;
        if (is_file($absolutePath)) {
            return 'uploads/' . rawurlencode($fileName);
        }
    }

    return 'img/CUP_BLACK.png';
}

function replace_item_image(?string $currentImage): ?string {
    if (empty($_FILES['image']['name'])) {
        return $currentImage;
    }

    if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }

    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowedTypes, true)) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WEBP images are allowed.');
    }

    $targetDir = dirname(__DIR__) . '/uploads/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $baseName = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($_FILES['image']['name'], PATHINFO_FILENAME));
    $filename = uniqid('item_', true) . '_' . $baseName . '.' . $ext;
    $targetFile = $targetDir . $filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
        throw new RuntimeException('Image could not be saved.');
    }

    $oldName = !empty($currentImage) ? basename($currentImage) : '';
    $oldPath = dirname(__DIR__) . '/uploads/' . $oldName;
    if ($oldName !== '' && is_file($oldPath)) {
        @unlink($oldPath);
    }

    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sku = strtoupper(trim($_POST['sku'] ?? ''));
    $name = normalize_uppercase_name((string)($_POST['name'] ?? ''));
    $category_id = (int)($_POST['category_id'] ?? 0);
    $price = is_numeric($_POST['price'] ?? null) ? (float)$_POST['price'] : 0;
    $cup_size = trim($_POST['cup_size'] ?? '');
    $image = $item['image'];
    $postedRequirements = is_array($_POST['requirements'] ?? null) ? $_POST['requirements'] : [];

    if ($name === '') {
        $errors[] = 'Item name is required.';
    }
    if ($sku === '' || !preg_match('/^[A-Z0-9-]{2,64}$/', $sku)) {
        $errors[] = 'SKU must contain 2-64 uppercase letters, numbers, or hyphens.';
    }
    if ($category_id <= 0) {
        $errors[] = 'Category is required.';
    }
    if ($cup_size === '') {
        $errors[] = 'Cup size is required.';
    } elseif (!in_array($cup_size, ['12oz Hot', '16oz', '22oz'], true)) {
        $errors[] = 'Choose 12oz Hot, 16oz, or 22oz.';
    }
    if ($price <= 0) {
        $errors[] = 'Price must be greater than zero.';
    }

    try {
        $recipe = inventory_service_validate_recipe($pdo, $postedRequirements, $cup_size, $name);
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
    if (!$errors) {
        try {
            $image = replace_item_image($image);
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE items SET sku = ?, name = ?, category_id = ?, price = ?, cup_size = ?, image = ? WHERE id = ?');
            $stmt->execute([$sku, $name, $category_id, $price, $cup_size, $image, $id]);
            inventory_service_save_recipe($pdo, $id, $recipe);
            $pdo->commit();
            $_SESSION['flash'] = 'Item and raw material recipe updated successfully.';
            $_SESSION['flash_type'] = 'success';
            header('Location: items.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Item recipe update failed: ' . $e->getMessage());
            $errors[] = 'The item and recipe could not be saved. Check for a duplicate SKU and try again.';
        }
    }
    $item['name'] = $name;
    $item['sku'] = $sku;
    $item['category_id'] = $category_id;
    $item['price'] = $price;
    $item['cup_size'] = $cup_size;
    $item['image'] = $image;
    $requirementMap = $postedRequirements;

    if (!empty($_POST['inline_edit'])) {
        $_SESSION['flash'] = implode(' ', $errors);
        $_SESSION['flash_type'] = 'error';
        $_SESSION['item_edit_form'] = [
            'id' => $id,
            'sku' => $sku,
            'name' => $name,
            'category_id' => $category_id,
            'price' => $price,
            'cup_size' => $cup_size,
            'requirements' => $postedRequirements,
        ];
        header('Location: items.php?edit=' . $id);
        exit;
    }
}

$previewSrc = edit_item_image_src($item['image'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Item - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-catalog-edit-item">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content item-form-page">
  <section class="item-page-heading" aria-labelledby="edit-item-title">
    <div>
      <p class="item-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Item <i class="fa fa-chevron-right"></i> Edit</p>
      <h1 id="edit-item-title">Edit Item</h1>
      <p class="item-page-copy">Update the menu item name, category, cup size, price, and image.</p>
    </div>
    <a href="items.php" class="item-secondary-action"><i class="fa fa-arrow-left"></i> Back to Items</a>
  </section>

  <?php if (!empty($errors)): ?>
    <div class="item-flash error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <section class="item-edit-panel">
    <aside class="item-edit-aside">
      <div class="item-edit-preview">
        <img id="preview" src="<?= htmlspecialchars($previewSrc) ?>" alt="<?= htmlspecialchars($item['name']) ?> preview">
      </div>
      <h2><?= htmlspecialchars($item['name']) ?></h2>
      <p>Choose a clear product photo so staff can identify menu items quickly.</p>
    </aside>

    <form method="post" enctype="multipart/form-data" class="item-edit-form">
      <div class="form-group">
        <label for="name">Name</label>
        <input id="name" type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required>
      </div>

      <div class="form-group">
        <label for="sku">SKU</label>
        <input id="sku" type="text" name="sku" maxlength="64" value="<?= htmlspecialchars($item['sku']) ?>" required>
      </div>

      <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int)$category['id'] ?>" <?= (int)$category['id'] === (int)$item['category_id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($category['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="cup_size">Cup Size and Temperature</label>
        <select id="cup_size" name="cup_size" required>
          <option value="12oz Hot">12oz Hot</option>
          <option value="16oz" <?= in_array($item['cup_size'], ['16oz Cold', '16oz'], true) ? 'selected' : '' ?>>16oz</option>
          <option value="22oz" <?= in_array($item['cup_size'], ['22oz Cold', '22oz'], true) ? 'selected' : '' ?>>22oz</option>
        </select>
      </div>

      <div class="form-group">
        <label for="price">Price</label>
        <input id="price" type="number" step="0.01" name="price" value="<?= htmlspecialchars((string)$item['price']) ?>" required>
      </div>

      <div class="form-group full">
        <label for="image">Image</label>
        <input id="image" type="file" name="image" accept="image/*" onchange="previewItemImage(event)">
      </div>

      <div class="form-group full" data-item-recipe>
        <label>Recipe Materials (quantity used per sold item)</label>
        <p data-recipe-scope aria-live="polite">Set quantities for one serving of the selected size.</p>
        <p>Enter the ingredient amounts needed for this size in g or ml. Blank packaging uses one matching cup, lid, and straw or stirrer. Materials are deducted when the order is marked Done.</p>
        <?php if (($recipeMetadata['source'] ?? '') === 'generated'): ?>
          <p class="recipe-estimate-note" role="status"><?= htmlspecialchars($recipeMetadata['notes'] ?? 'Generated starter estimate.') ?> Saving records your reviewed recipe.</p>
        <?php endif; ?>
        <div class="cup-size-container">
          <?php if (!$inventoryRecords): ?><small>Add inventory records before configuring a recipe. Packaging fallback will be used meanwhile.</small><?php endif; ?>
          <?php foreach ($inventoryRecords as $inventoryRecord): ?>
            <div class="cup-size-row" data-recipe-row data-recipe-type="<?= htmlspecialchars($inventoryRecord['material_type']) ?>" data-recipe-size="<?= htmlspecialchars(item_variant_inventory_cup_size($inventoryRecord['cup_size']) ?? '') ?>" data-recipe-kind="<?= htmlspecialchars(inventory_service_packaging_kind($inventoryRecord) ?? '') ?>" data-recipe-unit="<?= htmlspecialchars($inventoryRecord['unit']) ?>">
              <span><strong><?= htmlspecialchars($inventoryRecord['sku']) ?> · <?= htmlspecialchars($inventoryRecord['unit']) ?></strong> — <?= htmlspecialchars($inventoryRecord['name']) ?> <?= $inventoryRecord['cup_size'] ? '(' . htmlspecialchars($inventoryRecord['cup_size']) . ')' : '' ?></span>
              <input type="number" min="0" step="0.01" name="requirements[<?= (int) $inventoryRecord['id'] ?>]" value="<?= htmlspecialchars((string) ($requirementMap[$inventoryRecord['id']] ?? '')) ?>" placeholder="0 <?= htmlspecialchars($inventoryRecord['unit']) ?>">
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-actions full">
        <button type="submit" class="item-submit-btn"><i class="fa fa-save"></i> Update Item</button>
        <a href="items.php" class="item-cancel-btn"><i class="fa fa-xmark"></i> Cancel</a>
      </div>
    </form>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
function previewItemImage(event) {
  const file = event.target.files[0];
  const preview = document.getElementById('preview');
  if (file && preview) {
    preview.src = URL.createObjectURL(file);
  }
}
</script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/item-recipe.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/item-recipe.js')) ?>" defer></script>
</body>
</html>
