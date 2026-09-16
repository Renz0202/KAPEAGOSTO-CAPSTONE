<?php
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
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
</head>
<body class="kape-catalog-categories">
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
      <button type="button" class="category-primary-action js-open-create-category" aria-haspopup="dialog" aria-controls="createCategoryModal">
        <i class="fa fa-plus" aria-hidden="true"></i>
        Add New Category
      </button>
    </div>
  </section>

  <?php if ($flashMessage !== '' && !$reopenCreateModal): ?>
    <div class="category-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" role="alert">
      <?= htmlspecialchars($flashMessage) ?>
    </div>
  <?php endif; ?>

  <section class="category-table-panel">
    <div class="category-panel-header">
      <div>
        <span class="panel-eyebrow">Inventory Setup</span>
        <h2>Category List</h2>
      </div>
      <span class="panel-pill"><?= number_format($totalCategories) ?> record<?= $totalCategories === 1 ? '' : 's' ?></span>
    </div>

    <div class="category-table-wrap">
      <table class="category-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Category Name</th>
            <th>Category Image</th>
            <th>Linked Items</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($categories)): ?>
            <?php foreach ($categories as $index => $category): ?>
              <?php
                $categoryId = (int)$category['id'];
                $categoryName = trim((string)$category['name']);
                $imageSrc = category_image_src($category['image'] ?? null);
                $confirmDelete = "Delete category '" . $categoryName . "'?";
              ?>
              <tr>
                <td class="category-id">#<?= $index + 1 ?></td>
                <td>
                  <div class="category-name-cell">
                    <strong><?= htmlspecialchars($categoryName) ?></strong>
                    <span>ID <?= $categoryId ?></span>
                  </div>
                </td>
                <td>
                  <div class="category-image-preview">
                    <img src="<?= htmlspecialchars($imageSrc) ?>" alt="<?= htmlspecialchars($categoryName) ?> category image">
                  </div>
                </td>
                <td>
                  <span class="category-count-chip"><?= number_format((int)$category['item_count']) ?> item<?= (int)$category['item_count'] === 1 ? '' : 's' ?></span>
                </td>
                <td>
                  <div class="category-actions">
                    <a href="edit_category.php?id=<?= $categoryId ?>" class="category-action-btn edit" title="Edit category" aria-label="Edit <?= htmlspecialchars($categoryName) ?>">
                      <i class="fa fa-pen-to-square"></i>
                    </a>
                    <a href="delete_category.php?id=<?= $categoryId ?>" class="category-action-btn delete" title="Delete category" aria-label="Delete <?= htmlspecialchars($categoryName) ?>" data-confirm="<?= htmlspecialchars($confirmDelete, ENT_QUOTES) ?>" data-confirm-title="Delete Category" data-confirm-type="danger">
                      <i class="fa fa-trash"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="category-empty">No categories found. Create your first category.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
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
</body>
</html>
