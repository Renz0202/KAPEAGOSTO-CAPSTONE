<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/name_utils.php';
require_admin();

if (!is_admin()) {
    header('Location: items.php');
    exit;
}

$pdo = db();
$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$errors = [];

function item_sku(string $posted, string $name, string $cupSize, bool $appendSize): string {
    $base = strtoupper(trim($posted));
    if ($base === '') {
        $base = 'ITEM-' . substr(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($name)), 0, 34);
    }
    $base = trim(preg_replace('/[^A-Z0-9-]+/', '-', $base), '-');
    if ($appendSize) {
        $base .= '-' . preg_replace('/[^A-Z0-9]+/', '', strtoupper($cupSize));
    }
    return substr($base, 0, 64);
}

function upload_item_image(): ?string {
    if (empty($_FILES['image']['name'])) {
        return null;
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

    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = normalize_uppercase_name((string)($_POST['name'] ?? ''));
    $skuPosted = trim($_POST['sku'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $cup_sizes = $_POST['cup_sizes'] ?? [];
    $prices = $_POST['prices'] ?? [];

    if ($name === '') {
        $errors[] = 'Item name is required.';
    }
    if ($category_id <= 0) {
        $errors[] = 'Category is required.';
    }
    if (!is_array($cup_sizes) || !is_array($prices) || count($cup_sizes) < 1 || count($cup_sizes) > 3 || count($prices) !== count($cup_sizes)) {
        $errors[] = 'Choose one or more cup sizes and enter a price for each.';
    }

    $rows = [];
    $seenCupSizes = [];
    foreach ($cup_sizes as $index => $cup_size) {
        $cup_size = trim((string)$cup_size);
        $price = $prices[$index] ?? null;
        $allowedCupSizes = ['12oz Hot', '16oz', '22oz'];
        if (!in_array($cup_size, $allowedCupSizes, true) || !is_numeric($price) || (float)$price <= 0) {
            $errors[] = 'Each supported cup size needs a valid price greater than zero.';
            break;
        }
        if (isset($seenCupSizes[$cup_size])) {
            $errors[] = 'Choose each cup size only once.';
            break;
        }
        $seenCupSizes[$cup_size] = true;
        $rows[] = [$cup_size, (float)$price];
    }

    if (!$errors) {
        $existingVariant = $pdo->prepare('SELECT COUNT(*) FROM items WHERE name = ? AND category_id = ? AND cup_size IN (?, ?)');
        foreach ($rows as [$cup_size]) {
            $legacySize = ['16oz' => '16oz Cold', '22oz' => '22oz Cold'][$cup_size] ?? $cup_size;
            $existingVariant->execute([$name, $category_id, $cup_size, $legacySize]);
            if ((int)$existingVariant->fetchColumn() > 0) {
                $errors[] = 'This item already has a price for ' . $cup_size . '.';
                break;
            }
        }
    }

    $image = null;
    if (!$errors) {
        try {
            $image = upload_item_image();
        } catch (RuntimeException $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO items (sku, name, category_id, price, cup_size, image) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($rows as [$cup_size, $price]) {
            $stmt->execute([item_sku($skuPosted, $name, $cup_size, true), $name, $category_id, $price, $cup_size, $image]);
        }
        $_SESSION['flash'] = 'Item added successfully.';
        $_SESSION['flash_type'] = 'success';
        header('Location: items.php');
        exit;
    }

    if (!empty($_POST['inline_add'])) {
        $_SESSION['flash'] = implode(' ', $errors);
        $_SESSION['flash_type'] = 'error';
        header('Location: items.php?create=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Item - Kape Agosto</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-catalog-add-item">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content item-form-page">
  <section class="item-page-heading" aria-labelledby="add-item-title">
    <div>
      <p class="item-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Item</p>
      <h1 id="add-item-title">Add Item</h1>
      <p class="item-page-copy">Add one or more cup sizes, with a separate price for each.</p>
    </div>
    <a href="items.php" class="item-secondary-action"><i class="fa fa-arrow-left"></i> Back to Items</a>
  </section>

  <?php if (!empty($errors)): ?>
    <div class="item-flash error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <section class="item-edit-panel">
    <aside class="item-edit-aside">
      <div class="item-edit-preview"><i class="fa fa-mug-saucer"></i></div>
      <h2>Menu Item</h2>
      <p>Hot drinks are 12oz. Cold drinks are 16oz or 22oz.</p>
    </aside>

    <form method="post" enctype="multipart/form-data" class="item-edit-form">
      <div class="form-group">
        <label for="name">Item Name</label>
        <input id="name" type="text" name="name" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required>
      </div>
      <div class="form-group">
        <label for="sku">Base SKU</label>
        <input id="sku" type="text" name="sku" maxlength="64" placeholder="Auto-generated if blank">
      </div>
      <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required>
          <option value="">Select Category</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group full">
        <label for="add_item_size">Cup Size</label>
        <div id="cup-size-container" class="cup-size-container">
          <div class="cup-size-row">
            <select id="add_item_size" name="cup_sizes[]" required>
              <option value="12oz Hot">12oz Hot</option>
              <option value="16oz">16oz</option>
              <option value="22oz">22oz</option>
            </select>
            <input type="number" step="0.01" name="prices[]" placeholder="Price" required>
            <button type="button" class="item-row-remove" aria-label="Remove cup size" hidden><i class="fa fa-xmark"></i></button>
          </div>
        </div>
        <button type="button" class="item-add-row" id="addCupSizeRow"><i class="fa fa-plus"></i> Add Another Cup Size</button>
      </div>
      <div class="form-group full">
        <label for="image">Image</label>
        <input id="image" type="file" name="image" accept="image/*">
      </div>
      <div class="form-actions full">
        <button type="submit" class="item-submit-btn"><i class="fa fa-save"></i> Save Item</button>
        <a href="items.php" class="item-cancel-btn"><i class="fa fa-xmark"></i> Cancel</a>
      </div>
    </form>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<script>
(function () {
  const container = document.getElementById('cup-size-container');
  const addButton = document.getElementById('addCupSizeRow');
  const cupSizes = ['12oz Hot', '16oz', '22oz'];

  if (!container || !addButton) return;

  function refreshRows() {
    const rows = Array.from(container.querySelectorAll('.cup-size-row'));
    rows.forEach(function (row) {
      const select = row.querySelector('select[name="cup_sizes[]"]');
      const remove = row.querySelector('.item-row-remove');
      Array.from(select.options).forEach(function (option) {
        option.disabled = option.value !== select.value && rows.some(function (otherRow) {
          return otherRow !== row && otherRow.querySelector('select[name="cup_sizes[]"]').value === option.value;
        });
      });
      remove.hidden = rows.length === 1;
    });
    addButton.disabled = rows.length >= cupSizes.length;
  }

  function createRow() {
    const row = document.createElement('div');
    row.className = 'cup-size-row';
    const usedSizes = new Set(Array.from(container.querySelectorAll('select[name="cup_sizes[]"]')).map(function (select) { return select.value; }));
    const nextSize = cupSizes.find(function (size) { return !usedSizes.has(size); });
    if (!nextSize) return;
    const select = document.createElement('select');
    select.name = 'cup_sizes[]';
    select.required = true;
    cupSizes.forEach(function (size) {
      const option = document.createElement('option');
      option.value = size;
      option.textContent = size;
      select.appendChild(option);
    });
    select.value = nextSize;
    const price = document.createElement('input');
    price.type = 'number';
    price.name = 'prices[]';
    price.step = '0.01';
    price.min = '0.01';
    price.placeholder = 'Price';
    price.required = true;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'item-row-remove';
    remove.setAttribute('aria-label', 'Remove cup size');
    remove.innerHTML = '<i class="fa fa-xmark" aria-hidden="true"></i>';
    remove.addEventListener('click', function () {
      row.remove();
      refreshRows();
    });
    select.addEventListener('change', refreshRows);
    row.append(select, price, remove);
    container.appendChild(row);
    refreshRows();
  }

  container.querySelector('select[name="cup_sizes[]"]')?.addEventListener('change', refreshRows);
  container.querySelector('.item-row-remove')?.addEventListener('click', function (event) {
    event.currentTarget.closest('.cup-size-row')?.remove();
    refreshRows();
  });
  addButton.addEventListener('click', createRow);
  refreshRows();
})();
</script>

</body>
</html>
