<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_capability('view_catalog');

$pdo = db();
$is_admin = is_admin();

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

$stmt = $pdo->query("
    SELECT i.id, i.sku, i.name, i.price, i.cup_size, i.image, c.name AS category
    FROM items i
    JOIN categories c ON i.category_id = c.id
    ORDER BY i.id DESC
");
$items = $stmt->fetchAll();

$totalItems = count($items);
$categoryCount = count($categories);
$sizes = [];
$highestPrice = 0;
foreach ($items as $item) {
    if (!empty($item['cup_size'])) {
        $sizes[$item['cup_size']] = true;
    }
    $highestPrice = max($highestPrice, (float)$item['price']);
}

function item_image_src(?string $image): string {
    if (!empty($image)) {
        $fileName = basename($image);
        $absolutePath = dirname(__DIR__) . '/uploads/' . $fileName;
        if (is_file($absolutePath)) {
            return 'uploads/' . rawurlencode($fileName);
        }
    }

    return 'img/CUP_BLACK.png';
}

function item_flash_is_error(string $message): bool {
    $message = strtolower($message);
    return strpos($message, 'failed') !== false
        || strpos($message, 'invalid') !== false
        || strpos($message, 'required') !== false
        || strpos($message, 'not found') !== false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Manage Item</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
</head>
<body class="kape-catalog-items<?= is_cashier() ? ' cashier-pos-shell cashier-pos-menu' : '' ?>">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content item-page">
  <section class="item-page-heading" aria-labelledby="item-title">
    <div>
      <p class="item-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> <?= $is_admin ? 'Manage Item' : 'Item List' ?></p>
      <h1 id="item-title"><?= $is_admin ? 'Manage Item' : 'Item List' ?></h1>
      <p class="item-page-copy">Maintain sellable menu items, cup sizes, prices, and display images.</p>
    </div>
    <div class="item-summary-pills" aria-label="Item summary">
      <span><strong><?= number_format($totalItems) ?></strong> Items</span>
      <span><strong><?= number_format($categoryCount) ?></strong> Categories</span>
      <span><strong><?= number_format(count($sizes)) ?></strong> Sizes</span>
    </div>
  </section>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="item-flash <?= item_flash_is_error((string)$_SESSION['flash']) ? 'error' : 'success' ?>">
      <?= htmlspecialchars($_SESSION['flash']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>

  <?php if ($is_admin): ?>
    <section class="item-create-panel" aria-labelledby="add-item-title">
      <div class="item-create-intro">
        <span class="panel-eyebrow">Add New Item</span>
        <h2 id="add-item-title">Add New Item</h2>
      </div>

      <form action="add_item.php" method="post" enctype="multipart/form-data" class="item-form">
        <input type="hidden" name="inline_add" value="1">

        <div class="form-group">
          <label for="name">Item Name</label>
          <input id="name" type="text" name="name" placeholder="Item Name" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required>
        </div>

        <div class="form-group">
          <label for="sku">SKU</label>
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

        <div class="form-group">
          <label for="cup_size">Cup Size</label>
          <select id="cup_size" name="cup_sizes[]" required>
            <option value="">Select Size</option>
            <option value="12oz Hot">12oz Hot</option>
            <option value="16oz">16oz</option>
            <option value="22oz">22oz</option>
          </select>
        </div>

        <div class="form-group">
          <label for="price">Price</label>
          <input id="price" type="number" step="0.01" name="prices[]" min="0" required>
        </div>

        <div class="form-group">
          <label for="image">Image Upload</label>
          <input id="image" type="file" name="image" accept="image/*">
        </div>

        <button type="submit" class="item-submit-btn">
          <i class="fa fa-plus"></i>
          Add Item
        </button>
      </form>
    </section>
  <?php endif; ?>

  <section class="item-table-panel">
    <div class="item-panel-header">
      <div>
        <span class="panel-eyebrow">Menu Setup</span>
        <h2>Items List</h2>
      </div>
      <span class="panel-pill">Highest Price: PHP <?= number_format($highestPrice, 2) ?></span>
    </div>

    <div class="item-table-wrap">
      <table class="item-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>SKU</th>
            <th>Name</th>
            <th>Category</th>
            <th>Cup Size</th>
            <th>Price</th>
            <th>Image</th>
            <?php if ($is_admin): ?><th>Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td class="item-id">#<?= (int)$item['id'] ?></td>
                <td><strong><?= htmlspecialchars($item['sku']) ?></strong></td>
                <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                <td><span class="item-category-chip"><?= htmlspecialchars($item['category']) ?></span></td>
                <td><?= htmlspecialchars($item['cup_size']) ?></td>
                <td class="item-price">PHP <?= number_format((float)$item['price'], 2) ?></td>
                <td>
                  <div class="item-image-preview">
                    <img src="<?= htmlspecialchars(item_image_src($item['image'] ?? null)) ?>" alt="<?= htmlspecialchars($item['name']) ?> item image">
                  </div>
                </td>
                <?php if ($is_admin): ?>
                  <td>
                    <div class="item-actions">
                      <a href="edit_item.php?id=<?= (int)$item['id'] ?>" class="item-action-btn edit" title="Edit item" aria-label="Edit <?= htmlspecialchars($item['name']) ?>">
                        <i class="fa fa-pen-to-square"></i>
                      </a>
                      <a href="delete_item.php?id=<?= (int)$item['id'] ?>" class="item-action-btn delete" title="Delete item" aria-label="Delete <?= htmlspecialchars($item['name']) ?>" data-confirm="Delete item '<?= htmlspecialchars($item['name'], ENT_QUOTES) ?>'?" data-confirm-title="Delete Item" data-confirm-type="danger">
                        <i class="fa fa-trash"></i>
                      </a>
                    </div>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="<?= $is_admin ? 8 : 7 ?>" class="item-empty">No items found.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body>
</html>
