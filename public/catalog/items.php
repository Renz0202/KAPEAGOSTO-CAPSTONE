<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/inventory_requirements.php';
require_once dirname(__DIR__, 2) . '/includes/management_lists.php';
require_capability('view_catalog');

$pdo = db();
$is_admin = is_admin();

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();

$stmt = $pdo->query("
    SELECT i.id, i.sku, i.name, i.category_id, i.price, i.cup_size, i.image, c.name AS category, rm.source AS recipe_source, rm.notes AS recipe_notes
    FROM items i
    JOIN categories c ON i.category_id = c.id
    LEFT JOIN item_recipe_metadata rm ON rm.item_id = i.id
    ORDER BY i.id DESC
");
$items = $stmt->fetchAll();

$totalItems = count($items);
$categoryCount = count($categories);
$flashMessage = trim((string)($_SESSION['flash'] ?? ''));
$flashType = (string)($_SESSION['flash_type'] ?? '');
if ($flashType === '') {
    $flashType = item_flash_is_error($flashMessage) ? 'error' : 'success';
}
$reopenCreateModal = $is_admin && (($_GET['create'] ?? '') === '1');
$reopenEditId = $is_admin ? max(0, (int)($_GET['edit'] ?? 0)) : 0;
$editFormData = is_array($_SESSION['item_edit_form'] ?? null) ? $_SESSION['item_edit_form'] : [];
unset($_SESSION['flash'], $_SESSION['flash_type'], $_SESSION['item_edit_form']);
$inventoryRecords = [];
$itemRequirements = [];
if ($is_admin) {
    $inventoryRecords = $pdo->query('SELECT id, sku, name, cup_size, unit, material_type FROM inventory ORDER BY material_type, name, cup_size')->fetchAll(PDO::FETCH_ASSOC);
    $requirementRows = $pdo->query('SELECT item_id, inventory_id, quantity_needed FROM item_requirements')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($requirementRows as $requirementRow) {
        $itemRequirements[(int)$requirementRow['item_id']][(int)$requirementRow['inventory_id']] = $requirementRow['quantity_needed'];
    }
}
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

$editModalItems = [];
foreach ($items as $item) {
    $itemId = (int)$item['id'];
    $editModalItems[$itemId] = [
        'id' => $itemId,
        'sku' => (string)$item['sku'],
        'name' => (string)$item['name'],
        'category_id' => (int)$item['category_id'],
        'price' => (string)$item['price'],
        'cup_size' => (string)$item['cup_size'],
        'image' => item_image_src($item['image'] ?? null),
        'requirements' => $itemRequirements[$itemId] ?? [],
        'recipe_source' => $item['recipe_source'] ?? 'custom',
        'recipe_notes' => $item['recipe_notes'] ?? '',
    ];
}
if ($reopenEditId > 0 && isset($editModalItems[$reopenEditId]) && (int)($editFormData['id'] ?? 0) === $reopenEditId) {
    foreach (['sku', 'name', 'category_id', 'price', 'cup_size', 'requirements'] as $field) {
        if (array_key_exists($field, $editFormData)) {
            $editModalItems[$reopenEditId][$field] = $editFormData[$field];
        }
    }
}
if (!isset($editModalItems[$reopenEditId])) {
    $reopenEditId = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Manage Item</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/management-workspace.css') . '?v=' . filemtime(dirname(__DIR__) . '/css/management-workspace.css')) ?>">
</head>
<body class="kape-catalog-items compact-management-page<?= is_cashier() ? ' cashier-pos-shell cashier-pos-menu' : '' ?>">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content item-page">
  <section class="item-page-heading" aria-labelledby="item-title">
    <div>
      <p class="item-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> <?= $is_admin ? 'Manage Item' : 'Item List' ?></p>
      <h1 id="item-title"><?= $is_admin ? 'Manage Item' : 'Item List' ?></h1>
      <p class="item-page-copy">Maintain sellable menu items, cup sizes, prices, and display images.</p>
    </div>
    <div class="item-heading-actions">
      <div class="item-summary-pills" aria-label="Item summary">
        <span><strong><?= number_format($totalItems) ?></strong> Items</span>
        <span><strong><?= number_format($categoryCount) ?></strong> Categories</span>
        <span><strong><?= number_format(count($sizes)) ?></strong> Sizes</span>
      </div>
    </div>
  </section>

  <?php if ($flashMessage !== '' && !$reopenCreateModal && $reopenEditId === 0): ?>
    <div class="item-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
      <?= htmlspecialchars($flashMessage) ?>
    </div>
  <?php endif; ?>

  <section class="item-table-panel management-list-panel" data-compact-list="items" data-page-param="item_page" data-list-noun="items" data-details-dialog="itemListDetails">
    <div class="item-panel-header">
      <div>
        <span class="panel-eyebrow">Menu Setup</span>
        <h2>Items List</h2>
      </div>
      <div class="item-panel-actions">
        <span class="panel-pill js-item-result-count" data-compact-count><?= number_format($totalItems) ?> record<?= $totalItems === 1 ? '' : 's' ?></span>
        <span class="panel-pill">Highest Price: PHP <?= number_format($highestPrice, 2) ?></span>
        <?php if ($is_admin): ?>
          <button type="button" class="item-primary-action js-open-create-item" aria-haspopup="dialog" aria-controls="createItemModal">
            <i class="fa fa-plus" aria-hidden="true"></i>
            Add New Item
          </button>
        <?php endif; ?>
      </div>
    </div>

    <form method="get" class="management-list-filters" data-compact-filters>
        <label class="management-search-field" for="itemListSearch">
          <span>Search items</span>
          <input id="itemListSearch" type="search" name="item_search" data-compact-search maxlength="160" placeholder="Search items..." aria-label="Search items" aria-controls="itemListTable" autocomplete="off">
        </label>
        <label class="management-filter-field" for="itemCategoryFilter">
          <span>Category</span>
          <select id="itemCategoryFilter" name="item_category" data-compact-filter="categoryId" class="catalog-list-filter" aria-label="Filter items by category" aria-controls="itemListTable">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="management-filter-field" for="itemSizeFilter">
          <span>Cup size</span>
          <select id="itemSizeFilter" name="item_size" data-compact-filter="cupSize" data-compact-dependent="categoryId" class="catalog-list-filter" aria-label="Filter items by cup size" aria-controls="itemListTable">
            <option value="">All sizes</option>
            <?php foreach (array_keys($sizes) as $size): ?>
              <option value="<?= htmlspecialchars($size, ENT_QUOTES) ?>"><?= htmlspecialchars($size) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <button type="button" class="management-reset-button" data-compact-reset>Reset</button>
    </form>
    <div class="item-table-wrap management-table-frame" role="region" aria-label="Menu items" tabindex="0">
      <table class="item-table management-compact-table" id="itemListTable">
        <thead>
          <tr>
            <th scope="col">Item</th><th scope="col">Category</th><th scope="col">Cup Size</th><th scope="col">Price</th><th scope="col">Details</th>
            <?php if ($is_admin): ?><th scope="col">Actions</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <?php
                $itemDetails = ['title'=>$item['name'], 'id'=>'#' . (int)$item['id'], 'sku'=>$item['sku'], 'category'=>$item['category'], 'size'=>$item['cup_size'] ?: 'Not set', 'price'=>'PHP ' . number_format((float)$item['price'], 2)];
                if ($is_admin) {
                    $itemDetails['recipe'] = management_item_recipe($item, $inventoryRecords, $itemRequirements[(int)$item['id']] ?? []);
                    $itemDetails['source'] = ($item['recipe_source'] ?? '') === 'generated' ? 'Generated starter recipe - review before use' : 'Custom recipe';
                    $itemDetails['notes'] = $item['recipe_notes'] ?: 'No recipe notes.';
                }
                $searchText = implode(' ', [$item['id'], $item['sku'], $item['name'], $item['category'], $item['cup_size']]);
              ?>
              <tr data-compact-row data-item-search-row data-row-id="<?= (int)$item['id'] ?>" data-search="<?= management_list_escape($searchText) ?>" data-category-id="<?= (int)$item['category_id'] ?>" data-cup-size="<?= htmlspecialchars($item['cup_size'], ENT_QUOTES) ?>">
                <td data-label="Item"><div class="management-identity-cell"><div class="item-image-preview"><img src="<?= htmlspecialchars(item_image_src($item['image'] ?? null)) ?>" alt="" loading="lazy"></div><div class="management-item-name"><strong><?= htmlspecialchars($item['name']) ?></strong><small><?= htmlspecialchars($item['sku']) ?></small></div></div></td>
                <td data-label="Category"><span class="item-category-chip"><?= htmlspecialchars($item['category']) ?></span></td>
                <td data-label="Cup Size"><?= htmlspecialchars($item['cup_size'] ?: '-') ?></td>
                <td data-label="Price" class="item-price">PHP <?= number_format((float)$item['price'], 2) ?></td>
                <td data-label="Details"><?= management_list_details($itemDetails, 'itemListDetails', $item['name']) ?></td>
                <?php if ($is_admin): ?>
                  <td data-label="Actions">
                    <div class="item-actions">
                      <a href="edit_item.php?id=<?= (int)$item['id'] ?>" class="item-action-btn edit js-open-edit-item" title="Edit item" aria-label="Edit <?= htmlspecialchars($item['name']) ?>" aria-haspopup="dialog" aria-controls="editItemModal" data-item-id="<?= (int)$item['id'] ?>">
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
          <?php endif; ?>
          <tr data-compact-empty <?= !empty($items) ? 'hidden' : '' ?>><td colspan="<?= $is_admin ? 6 : 5 ?>" class="item-empty">No items match these filters.</td></tr>
        </tbody>
      </table>
    </div>
    <?= management_list_pager('items') ?>
    <?php
      $detailFields = ['id'=>'Item ID','sku'=>'SKU','category'=>'Category','size'=>'Cup Size','price'=>'Price'];
      if ($is_admin) $detailFields += ['source'=>'Recipe Status','recipe'=>'Materials Per Serving','notes'=>'Recipe Notes'];
      echo management_list_dialog('itemListDetails', 'Item Details', $detailFields);
    ?>
  </section>
</main>

<?php if ($is_admin): ?>
<div class="item-create-modal" id="createItemModal" aria-hidden="true">
  <section class="item-create-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="create-item-modal-title">
    <header class="item-create-modal-header">
      <div>
        <span>Menu Setup</span>
        <h2 id="create-item-modal-title">Add New Item</h2>
      </div>
    </header>

    <?php if ($reopenCreateModal && $flashMessage !== ''): ?>
      <div class="item-create-modal-feedback <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
        <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage) ?></span>
      </div>
    <?php endif; ?>

    <form action="add_item.php" method="post" enctype="multipart/form-data" class="item-create-modal-form">
      <input type="hidden" name="inline_add" value="1">

      <div class="item-create-photo-field">
        <div class="item-create-preview" aria-hidden="true">
          <i class="fa fa-mug-saucer"></i>
          <img id="createItemPreview" alt="">
        </div>
        <div>
          <label for="create_item_image"><i class="fa fa-image"></i> Item Image</label>
          <input id="create_item_image" type="file" name="image" accept="image/*">
          <small>Optional. Upload JPG, PNG, GIF, or WEBP.</small>
        </div>
      </div>

      <div class="form-group">
        <label for="create_item_name"><i class="fa fa-mug-hot"></i> Item Name</label>
        <input id="create_item_name" type="text" name="name" placeholder="ITEM NAME" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required autocomplete="off">
      </div>

      <div class="form-group">
        <label for="create_item_sku"><i class="fa fa-barcode"></i> SKU</label>
        <input id="create_item_sku" type="text" name="sku" maxlength="64" placeholder="Auto-generated if blank" autocomplete="off">
      </div>

      <div class="form-group">
        <label for="create_item_category"><i class="fa fa-layer-group"></i> Category</label>
        <select id="create_item_category" name="category_id" required>
          <option value="">Select Category</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group item-variant-prices">
        <label><i class="fa fa-ruler"></i> Cup Sizes and Prices</label>
        <div class="item-variant-price-list" id="createItemVariants">
          <div class="item-variant-price-row">
            <select id="create_item_size" name="cup_sizes[]" aria-label="Cup size" required>
              <option value="12oz Hot">12oz Hot</option>
              <option value="16oz">16oz</option>
              <option value="22oz">22oz</option>
            </select>
            <input type="number" name="prices[]" min="0.01" step="0.01" placeholder="Price" aria-label="Price for cup size" required>
            <button type="button" class="item-variant-remove" aria-label="Remove cup size" hidden><i class="fa fa-xmark" aria-hidden="true"></i></button>
          </div>
        </div>
        <button type="button" class="item-variant-add" id="addCreateItemVariant"><i class="fa fa-plus"></i> Add Cup Size and Price</button>
      </div>

      <div class="item-create-modal-actions">
        <button type="button" class="item-modal-cancel js-close-create-item">Cancel</button>
        <button type="submit" class="item-modal-submit"><i class="fa fa-plus"></i> Add Item</button>
      </div>
    </form>
  </section>
</div>

<div class="item-create-modal item-edit-modal" id="editItemModal" aria-hidden="true">
  <section class="item-create-modal-dialog item-edit-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="edit-item-modal-title">
    <header class="item-create-modal-header">
      <div>
        <span>Menu Setup</span>
        <h2 id="edit-item-modal-title">Edit Item</h2>
      </div>
    </header>

    <?php if ($reopenEditId > 0 && $flashMessage !== ''): ?>
      <div class="item-create-modal-feedback <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
        <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage) ?></span>
      </div>
    <?php endif; ?>

    <form action="edit_item.php" method="post" enctype="multipart/form-data" class="item-create-modal-form item-edit-modal-form">
      <input type="hidden" name="inline_edit" value="1">
      <input type="hidden" name="id" id="edit_item_id" value="">

      <div class="item-create-photo-field">
        <div class="item-create-preview" aria-hidden="true">
          <i class="fa fa-mug-saucer"></i>
          <img id="editItemPreview" alt="">
        </div>
        <div>
          <label for="edit_item_image"><i class="fa fa-image"></i> Item Image</label>
          <input id="edit_item_image" type="file" name="image" accept="image/*">
          <small>Optional. Leave blank to keep the current image.</small>
        </div>
      </div>

      <div class="form-group">
        <label for="edit_item_name"><i class="fa fa-mug-hot"></i> Item Name</label>
        <input id="edit_item_name" type="text" name="name" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required autocomplete="off">
      </div>

      <div class="form-group">
        <label for="edit_item_sku"><i class="fa fa-barcode"></i> SKU</label>
        <input id="edit_item_sku" type="text" name="sku" maxlength="64" required autocomplete="off">
      </div>

      <div class="form-group">
        <label for="edit_item_category"><i class="fa fa-layer-group"></i> Category</label>
        <select id="edit_item_category" name="category_id" required>
          <option value="">Select Category</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int)$category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="edit_item_size"><i class="fa fa-ruler"></i> Cup Size and Temperature</label>
        <select id="edit_item_size" name="cup_size" required>
          <option value="12oz Hot">12oz Hot</option>
          <option value="16oz">16oz</option>
          <option value="22oz">22oz</option>
        </select>
      </div>

      <div class="form-group item-price-field">
        <label for="edit_item_price"><i class="fa fa-peso-sign"></i> Price</label>
        <input id="edit_item_price" type="number" step="0.01" name="price" min="0.01" required>
      </div>

      <div class="form-group item-edit-recipe-field" data-item-recipe>
        <label><i class="fa fa-boxes-stacked"></i> Recipe Materials <span>Quantity used per sale</span></label>
        <p data-recipe-scope aria-live="polite">Set quantities for one serving of the selected size.</p>
        <p>Enter the ingredient amounts needed for this size in g or ml. Blank packaging uses one matching cup, lid, and straw or stirrer. Materials are deducted when the order is marked Done.</p>
        <p id="edit_item_recipe_source" class="recipe-estimate-note" role="status" hidden></p>
        <div class="item-edit-recipe-list">
          <?php if (!$inventoryRecords): ?>
            <small class="item-edit-recipe-empty">Add inventory records before configuring a recipe.</small>
          <?php endif; ?>
          <?php foreach ($inventoryRecords as $inventoryRecord): ?>
            <label class="item-edit-recipe-row" data-recipe-row data-recipe-type="<?= htmlspecialchars($inventoryRecord['material_type']) ?>" data-recipe-size="<?= htmlspecialchars(item_variant_inventory_cup_size($inventoryRecord['cup_size']) ?? '') ?>" data-recipe-kind="<?= htmlspecialchars(inventory_service_packaging_kind($inventoryRecord) ?? '') ?>" data-recipe-unit="<?= htmlspecialchars($inventoryRecord['unit']) ?>" for="edit_requirement_<?= (int)$inventoryRecord['id'] ?>">
              <span>
                <strong><?= htmlspecialchars($inventoryRecord['sku']) ?> · <?= htmlspecialchars($inventoryRecord['unit']) ?></strong>
                <?= htmlspecialchars($inventoryRecord['name']) ?><?= $inventoryRecord['cup_size'] ? ' (' . htmlspecialchars($inventoryRecord['cup_size']) . ')' : '' ?>
              </span>
              <input id="edit_requirement_<?= (int)$inventoryRecord['id'] ?>" type="number" min="0" step="0.01" name="requirements[<?= (int)$inventoryRecord['id'] ?>]" data-requirement-id="<?= (int)$inventoryRecord['id'] ?>" placeholder="0 <?= htmlspecialchars($inventoryRecord['unit']) ?>">
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="item-create-modal-actions">
        <button type="button" class="item-modal-cancel js-close-edit-item">Cancel</button>
        <button type="submit" class="item-modal-submit"><i class="fa fa-floppy-disk"></i> Save Changes</button>
      </div>
    </form>
  </section>
</div>

<script type="application/json" id="itemEditData"><?= json_encode($editModalItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>

<?php if ($is_admin): ?>
<script>
(function () {
  const modal = document.getElementById('createItemModal');
  const openControl = document.querySelector('.js-open-create-item');
  const closeControls = modal?.querySelectorAll('.js-close-create-item') || [];
  const imageInput = document.getElementById('create_item_image');
  const preview = document.getElementById('createItemPreview');
  const variantContainer = document.getElementById('createItemVariants');
  const addVariantButton = document.getElementById('addCreateItemVariant');
  const cupSizes = ['12oz Hot', '16oz', '22oz'];
  let lastFocus = null;
  let previewUrl = '';

  if (!modal || !openControl) return;

  function refreshVariants() {
    if (!variantContainer || !addVariantButton) return;
    const rows = Array.from(variantContainer.querySelectorAll('.item-variant-price-row'));
    rows.forEach(function (row) {
      const select = row.querySelector('select[name="cup_sizes[]"]');
      row.querySelector('.item-variant-remove').hidden = rows.length === 1;
      Array.from(select.options).forEach(function (option) {
        option.disabled = option.value !== select.value && rows.some(function (otherRow) {
          return otherRow !== row && otherRow.querySelector('select[name="cup_sizes[]"]').value === option.value;
        });
      });
    });
    addVariantButton.disabled = rows.length >= cupSizes.length;
  }

  function addVariantRow() {
    if (!variantContainer) return;
    const used = new Set(Array.from(variantContainer.querySelectorAll('select[name="cup_sizes[]"]')).map(function (select) { return select.value; }));
    const nextSize = cupSizes.find(function (size) { return !used.has(size); });
    if (!nextSize) return;

    const row = document.createElement('div');
    row.className = 'item-variant-price-row';
    const select = document.createElement('select');
    select.name = 'cup_sizes[]';
    select.setAttribute('aria-label', 'Cup size');
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
    price.min = '0.01';
    price.step = '0.01';
    price.placeholder = 'Price';
    price.setAttribute('aria-label', 'Price for cup size');
    price.required = true;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'item-variant-remove';
    remove.setAttribute('aria-label', 'Remove cup size');
    remove.innerHTML = '<i class="fa fa-xmark" aria-hidden="true"></i>';
    row.append(select, price, remove);
    variantContainer.appendChild(row);
    refreshVariants();
  }

  addVariantButton?.addEventListener('click', addVariantRow);
  variantContainer?.addEventListener('change', function (event) {
    if (event.target.matches('select[name="cup_sizes[]"]')) refreshVariants();
  });
  variantContainer?.addEventListener('click', function (event) {
    const removeButton = event.target.closest('.item-variant-remove');
    if (!removeButton) return;
    removeButton.closest('.item-variant-price-row')?.remove();
    refreshVariants();
  });
  refreshVariants();

  function setModalOpen(open) {
    const shouldOpen = Boolean(open);
    modal.classList.toggle('is-open', shouldOpen);
    modal.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    openControl.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    document.documentElement.classList.toggle('item-modal-lock', shouldOpen);

    if (shouldOpen) {
      lastFocus = document.activeElement;
      window.setTimeout(function () {
        document.getElementById('create_item_name')?.focus();
      }, 0);
    } else if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus({ preventScroll: true });
    }
  }

  openControl.addEventListener('click', function () { setModalOpen(true); });
  closeControls.forEach(function (control) {
    control.addEventListener('click', function () { setModalOpen(false); });
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) setModalOpen(false);
  });

  modal.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      setModalOpen(false);
      return;
    }

    if (event.key !== 'Tab') return;
    const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled])'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  imageInput?.addEventListener('change', function () {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    const file = imageInput.files?.[0];
    if (!file) {
      preview.removeAttribute('src');
      preview.classList.remove('is-visible');
      previewUrl = '';
      return;
    }
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.classList.add('is-visible');
  });

  if (<?= $reopenCreateModal ? 'true' : 'false' ?>) {
    setModalOpen(true);
  }
})();
</script>
<script>
(function () {
  const modal = document.getElementById('editItemModal');
  const form = modal?.querySelector('form');
  const openControls = Array.from(document.querySelectorAll('.js-open-edit-item'));
  const closeControls = modal?.querySelectorAll('.js-close-edit-item') || [];
  const dataElement = document.getElementById('itemEditData');
  const imageInput = document.getElementById('edit_item_image');
  const preview = document.getElementById('editItemPreview');
  const title = document.getElementById('edit-item-modal-title');
  const fields = {
    id: document.getElementById('edit_item_id'),
    name: document.getElementById('edit_item_name'),
    sku: document.getElementById('edit_item_sku'),
    category_id: document.getElementById('edit_item_category'),
    cup_size: document.getElementById('edit_item_size'),
    price: document.getElementById('edit_item_price')
  };
  let itemData = {};
  let lastFocus = null;
  let previewUrl = '';
  let currentImage = '';

  if (!modal || !form || !dataElement || !openControls.length) return;

  try {
    itemData = JSON.parse(dataElement.textContent || '{}');
  } catch (error) {
    return;
  }

  function populate(itemId) {
    const item = itemData[String(itemId)];
    if (!item) return false;

    form.action = 'edit_item.php?id=' + encodeURIComponent(item.id);
    Object.keys(fields).forEach(function (key) {
      if (fields[key]) fields[key].value = item[key] ?? '';
    });
    if (fields.cup_size) {
      const legacyColdSize = { '16oz Cold': '16oz', '22oz Cold': '22oz' }[item.cup_size];
      const size = legacyColdSize || item.cup_size;
      fields.cup_size.value = ['12oz Hot', '16oz', '22oz'].includes(size) ? size : '12oz Hot';
    }
    title.textContent = 'Edit Item — ' + item.name;
    imageInput.value = '';
    currentImage = item.image || '';
    preview.src = currentImage;
    preview.classList.toggle('is-visible', currentImage !== '');

    const recipeSource = document.getElementById('edit_item_recipe_source');
    recipeSource.hidden = item.recipe_source !== 'generated';
    recipeSource.textContent = item.recipe_source === 'generated' ?
      (item.recipe_notes || 'Generated starter estimate. Review the amounts against your house recipe.') + ' Saving records your reviewed recipe.' : '';

    modal.querySelectorAll('[data-requirement-id]').forEach(function (input) {
      input.value = item.requirements?.[String(input.dataset.requirementId)] ?? '';
    });
    fields.cup_size?.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function setModalOpen(open, opener) {
    const shouldOpen = Boolean(open);
    modal.classList.toggle('is-open', shouldOpen);
    modal.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    document.documentElement.classList.toggle('item-modal-lock', shouldOpen);

    if (shouldOpen) {
      lastFocus = opener || document.activeElement;
      opener?.setAttribute('aria-expanded', 'true');
      window.setTimeout(function () { fields.name?.focus(); }, 0);
    } else {
      openControls.forEach(function (control) { control.setAttribute('aria-expanded', 'false'); });
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
        previewUrl = '';
      }
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus({ preventScroll: true });
      }
    }
  }

  openControls.forEach(function (control) {
    control.addEventListener('click', function (event) {
      if (!populate(control.dataset.itemId)) return;
      event.preventDefault();
      setModalOpen(true, control);
    });
  });

  closeControls.forEach(function (control) {
    control.addEventListener('click', function () { setModalOpen(false); });
  });

  modal.addEventListener('click', function (event) {
    if (event.target === modal) setModalOpen(false);
  });

  modal.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      setModalOpen(false);
      return;
    }

    if (event.key !== 'Tab') return;
    const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled])'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  imageInput?.addEventListener('change', function () {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    const file = imageInput.files?.[0];
    if (!file) {
      previewUrl = '';
      preview.src = currentImage;
      preview.classList.toggle('is-visible', currentImage !== '');
      return;
    }
    previewUrl = URL.createObjectURL(file);
    preview.src = previewUrl;
    preview.classList.add('is-visible');
  });

  const reopenItemId = <?= $reopenEditId ?>;
  if (reopenItemId > 0 && populate(reopenItemId)) {
    const opener = openControls.find(function (control) { return Number(control.dataset.itemId) === reopenItemId; });
    setModalOpen(true, opener || null);
  }
})();
</script>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/item-recipe.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/item-recipe.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(kape_asset_url('scripts/management-list.js') . '?v=' . filemtime(dirname(__DIR__) . '/scripts/management-list.js')) ?>" defer></script>
</body>
</html>
