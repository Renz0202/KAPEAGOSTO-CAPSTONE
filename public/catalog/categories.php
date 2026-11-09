<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/management_lists.php';
require_admin();

$pdo = db();

if (empty($_SESSION['csrf_category'])) {
    $_SESSION['csrf_category'] = bin2hex(random_bytes(32));
}

$stmt = $pdo->query("
    SELECT c.id, c.name, c.image, COUNT(i.id) AS item_count
    FROM categories c
    LEFT JOIN items i ON i.category_id = c.id
    GROUP BY c.id, c.name, c.image
    ORDER BY c.id DESC
");
$categories = $stmt->fetchAll();

$totalCategories = count($categories);
$totalLinkedItems = array_sum(array_map(static fn($category) => (int)$category['item_count'], $categories));
$flashMessage = trim((string)($_SESSION['flash'] ?? ''));
$flashType = (string)($_SESSION['flash_type'] ?? '');
if ($flashType === '') {
    $flashType = preg_match('/failed|required|exists|invalid|could not/i', $flashMessage) ? 'error' : 'success';
}
$reopenCreateModal = ($_GET['create'] ?? '') === '1';
unset($_SESSION['flash'], $_SESSION['flash_type']);

function category_image_src(?string $image): string {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KAPE AGOSTO - Manage Category</title>
  <?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(kape_asset_url('css/management-workspace.css')) ?>?v=<?= filemtime(dirname(__DIR__) . '/css/management-workspace.css') ?>">
</head>
<body class="kape-catalog-categories compact-management-page">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>

<main class="content category-page">
  <section class="category-page-heading" aria-labelledby="category-title">
    <div>
      <p class="category-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Manage Category</p>
      <h1 id="category-title">Manage Category</h1>
      <p class="category-page-copy">Organize menu groups and keep category images ready for item setup.</p>
    </div>
    <div class="category-heading-actions">
      <div class="category-summary-pills" aria-label="Category summary">
        <span><strong><?= number_format($totalCategories) ?></strong> Categories</span>
        <span><strong><?= number_format($totalLinkedItems) ?></strong> Linked Items</span>
      </div>
    </div>
  </section>

  <?php if ($flashMessage !== '' && !$reopenCreateModal): ?>
    <div class="category-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
      <?= htmlspecialchars($flashMessage) ?>
    </div>
  <?php endif; ?>

  <section class="category-table-panel management-list-panel" data-compact-list="categories" data-page-param="category_page" data-list-noun="categories">
    <div class="category-panel-header">
      <div><span class="panel-eyebrow">Menu Setup</span><h2>Category List</h2></div>
      <div class="category-panel-actions">
        <span class="panel-pill" data-compact-count><?= number_format($totalCategories) ?> records</span>
        <button type="button" class="category-primary-action js-open-create-category" aria-haspopup="dialog" aria-controls="createCategoryModal"><i class="fa fa-plus" aria-hidden="true"></i> Add New Category</button>
      </div>
    </div>
    <form method="get" class="management-list-filters category-list-filters" data-compact-filters>
      <label class="management-search-field" for="categoryListSearch"><span>Search categories</span><input id="categoryListSearch" type="search" name="category_search" placeholder="Category name or ID..." aria-controls="categoryListTable" autocomplete="off" data-compact-search></label>
      <label for="categoryUsageFilter"><span>Linked items</span><select id="categoryUsageFilter" name="category_usage" data-compact-filter="usage"><option value="">All categories</option><option value="linked">With linked items</option><option value="empty">Without linked items</option></select></label>
      <button type="button" class="management-reset-button" data-compact-reset>Reset</button>
    </form>
    <div class="category-table-wrap management-table-frame" tabindex="0" role="region" aria-label="Category list">
      <table class="category-table management-compact-table" id="categoryListTable">
        <thead><tr><th scope="col">Category</th><th scope="col">Linked Items</th><th scope="col">Actions</th></tr></thead>
        <tbody>
          <?php foreach ($categories as $category):
            $categoryId = (int)$category['id'];
            $categoryName = trim((string)$category['name']);
            $imageSrc = category_image_src($category['image'] ?? null);
            $itemCount = (int)$category['item_count'];
            $confirmDelete = "Delete category '" . $categoryName . "'?";
          ?>
          <tr data-compact-row data-row-id="<?= $categoryId ?>" data-usage="<?= $itemCount ? 'linked' : 'empty' ?>" data-search="<?= management_list_escape($categoryName . ' ID ' . $categoryId) ?>">
            <td data-label="Category"><div class="management-identity-cell"><div class="category-image-preview"><img src="<?= management_list_escape($imageSrc) ?>" alt="" loading="lazy"></div><div class="category-name-cell"><strong><?= management_list_escape($categoryName) ?></strong><span>ID <?= $categoryId ?></span></div></div></td>
            <td data-label="Linked items"><a class="category-count-chip" href="items.php?item_category=<?= $categoryId ?>" aria-label="View <?= $itemCount ?> linked items in <?= management_list_escape($categoryName) ?>"><?= number_format($itemCount) ?> item<?= $itemCount === 1 ? '' : 's' ?></a></td>
            <td data-label="Actions"><div class="category-actions">
              <a href="edit_category.php?id=<?= $categoryId ?>" class="category-action-btn edit" title="Edit category" aria-label="Edit <?= management_list_escape($categoryName) ?>"><i class="fa fa-pen-to-square" aria-hidden="true"></i></a>
              <a href="delete_category.php?id=<?= $categoryId ?>" class="category-action-btn delete" title="Delete category" aria-label="Delete <?= management_list_escape($categoryName) ?>" data-confirm="<?= management_list_escape($confirmDelete) ?>" data-confirm-title="Delete Category" data-confirm-type="danger"><i class="fa fa-trash" aria-hidden="true"></i></a>
            </div></td>
          </tr>
          <?php endforeach; ?>
          <tr data-compact-empty <?= $categories ? 'hidden' : '' ?>><td colspan="3" class="category-empty"><?= $categories ? 'No categories match your filters.' : 'No categories found. Create your first category.' ?></td></tr>
        </tbody>
      </table>
    </div>
    <?= management_list_pager('Categories') ?>
  </section>
</main>

<div class="category-create-modal" id="createCategoryModal" aria-hidden="true">
  <section class="category-create-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="create-category-modal-title">
    <header class="category-create-modal-header">
      <div>
        <span>Category Setup</span>
        <h2 id="create-category-modal-title">Create New Category</h2>
      </div>
    </header>

    <?php if ($reopenCreateModal && $flashMessage !== ''): ?>
      <div class="category-create-modal-feedback <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
        <i class="fa <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($flashMessage) ?></span>
      </div>
    <?php endif; ?>

    <form action="add_category.php" method="post" enctype="multipart/form-data" class="category-create-modal-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_category']) ?>">

      <div class="form-group">
        <label for="category_name"><i class="fa fa-tag" aria-hidden="true"></i> Category Name</label>
        <input id="category_name" type="text" name="name" data-uppercase autocapitalize="characters" oninput="this.value = this.value.toLocaleUpperCase()" required autocomplete="off" placeholder="COFFEE">
      </div>

      <div class="category-create-photo-field">
        <div class="category-create-preview" aria-hidden="true">
          <i class="fa fa-image"></i>
          <img id="createCategoryPreview" alt="">
        </div>
        <div>
          <label for="category_image"><i class="fa fa-image" aria-hidden="true"></i> Category Image</label>
          <input id="category_image" type="file" name="image" accept="image/*">
          <small>Optional. Upload JPG, PNG, GIF, or WEBP.</small>
        </div>
      </div>

      <div class="category-create-modal-actions">
        <button type="button" class="category-modal-cancel js-close-create-category">Cancel</button>
        <button type="submit" class="category-modal-submit"><i class="fa fa-plus" aria-hidden="true"></i> Create Category</button>
      </div>
    </form>
  </section>
</div>

<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
<script>
(function () {
  const modal = document.getElementById('createCategoryModal');
  const openControl = document.querySelector('.js-open-create-category');
  const closeControls = modal?.querySelectorAll('.js-close-create-category') || [];
  const form = modal?.querySelector('form');
  const imageInput = document.getElementById('category_image');
  const preview = document.getElementById('createCategoryPreview');
  let lastFocus = null;
  let previewUrl = '';

  if (!modal || !openControl || !form) return;

  function setModalOpen(open) {
    const shouldOpen = Boolean(open);
    modal.classList.toggle('is-open', shouldOpen);
    modal.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    openControl.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
    document.documentElement.classList.toggle('category-modal-lock', shouldOpen);

    if (shouldOpen) {
      lastFocus = document.activeElement;
      window.setTimeout(function () {
        document.getElementById('category_name')?.focus();
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
    const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), input:not([disabled])'));
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
<script src="<?= htmlspecialchars(kape_asset_url('scripts/management-list.js')) ?>?v=<?= filemtime(dirname(__DIR__) . '/scripts/management-list.js') ?>" defer></script>
</body>
</html>
