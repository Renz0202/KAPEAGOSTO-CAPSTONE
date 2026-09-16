<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_capability('view_order_history');
if (!is_admin() && !is_manager()) deny_access();

$pdo = db();
$search = trim($_GET['search'] ?? '');
$sql = "
    SELECT c.id, c.customer_code, c.name, c.contact, c.email, c.created_at,
           COUNT(o.id) AS order_count,
           COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total ELSE 0 END), 0) AS lifetime_sales,
           MAX(o.created_at) AS last_order_at
    FROM customers c
    LEFT JOIN orders o ON o.customer_id = c.id
";
$params = [];
if ($search !== '') {
    $sql .= ' WHERE c.name LIKE ? OR c.customer_code LIKE ? OR c.contact LIKE ? OR c.email LIKE ?';
    $params = array_fill(0, 4, '%' . $search . '%');
}
$sql .= ' GROUP BY c.id, c.customer_code, c.name, c.contact, c.email, c.created_at ORDER BY last_order_at DESC, c.name';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Customer History - Kape Agosto</title><?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?></head>
<body class="kape-reports-module-reports">
<?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>
<main class="content reports-page">
  <section class="reports-hero"><div><p class="reports-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Customer History</p><h1>Customers & Job Orders</h1><p>Review durable customer transaction and service history.</p></div><a class="reports-action muted" href="order_list.php?scope=all"><i class="fa fa-arrow-left"></i> Full Order History</a></section>
  <section class="reports-toolbar"><form method="get" class="reports-date-form"><label for="customer-search">Search Customer</label><div class="reports-date-row"><input id="customer-search" type="search" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name, code, phone, or email"><button class="reports-action primary" type="submit"><i class="fa fa-search"></i> Search</button></div></form></section>
  <section class="reports-table-panel"><div class="reports-panel-heading"><div><span class="reports-eyebrow">History</span><h2>Customer Records</h2></div><span class="reports-count-pill"><?= number_format(count($customers)) ?> customers</span></div><div class="reports-table-wrap"><table class="reports-table"><thead><tr><th>Code</th><th>Customer</th><th>Contact</th><th>Orders</th><th>Completed Sales</th><th>Last Order</th><th>History</th></tr></thead><tbody>
  <?php if (!$customers): ?><tr><td colspan="7">No customer records found.</td></tr><?php endif; ?>
  <?php foreach ($customers as $customer): ?><tr><td><strong><?= htmlspecialchars($customer['customer_code']) ?></strong></td><td><?= htmlspecialchars($customer['name']) ?></td><td><?= htmlspecialchars(trim(($customer['contact'] ?: '') . ' ' . ($customer['email'] ?: ''))) ?></td><td><?= number_format((int) $customer['order_count']) ?></td><td>PHP <?= number_format((float) $customer['lifetime_sales'], 2) ?></td><td><?= $customer['last_order_at'] ? htmlspecialchars(date('M d, Y g:i A', strtotime($customer['last_order_at']))) : '-' ?></td><td><a href="order_list.php?scope=all&amp;search=<?= urlencode($customer['customer_code']) ?>">View</a></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
</main>
<?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?>
</body></html>
