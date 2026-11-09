<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_login();

$pdo = db();
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$notificationVisibility = 'user_id IS NULL OR user_id = ?';
if (is_manager()) {
    $notificationVisibility .= " OR (notification_type = 'general' AND entity_type IS NULL AND message LIKE 'Order #% has been voided by admin %')";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE (' . $notificationVisibility . ')');
    $stmt->execute([$userId]);

    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        exit;
    }

    header('Location: notifications.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE (' . $notificationVisibility . ') ORDER BY is_read ASC, created_at DESC LIMIT 100');
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Notifications - Kape Agosto</title><?php include dirname(__DIR__) . '/partials/stylesheets.php'; ?></head>
<body class="kape-reports-module-reports"><?php include dirname(__DIR__, 2) . '/includes/sidebar.php'; ?><?php include dirname(__DIR__, 2) . '/includes/topbar.php'; ?>
<main class="content reports-page"><section class="reports-hero"><div><p class="reports-breadcrumb"><?= htmlspecialchars(role_display_name(current_user_role())) ?> <i class="fa fa-chevron-right"></i> Notifications</p><h1>Notification Center</h1><p>Low-stock alerts and important transaction events.</p></div><form method="post"><button class="reports-action secondary" type="submit"><i class="fa fa-check-double"></i> Mark all read</button></form></section>
<section class="reports-table-panel"><div class="reports-table-wrap"><table class="reports-table"><thead><tr><th>Status</th><th>Type</th><th>Message</th><th>Date</th><th>Action</th></tr></thead><tbody>
<?php if (!$notifications): ?><tr><td colspan="5">No notifications.</td></tr><?php endif; ?>
<?php foreach ($notifications as $notification): ?><tr><td><?= $notification['is_read'] ? 'Read' : 'Unread' ?></td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $notification['notification_type']))) ?></td><td><?= htmlspecialchars($notification['message']) ?></td><td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($notification['created_at']))) ?></td><td><?php if ($notification['entity_type'] === 'inventory'): ?><a href="<?= is_admin() ? 'inventory.php' : (is_manager() ? 'manager_settings.php' : 'items.php') ?>">View stock</a><?php elseif ($notification['entity_type'] === 'order'): ?><a href="order_list.php?scope=all&amp;search=<?= (int) $notification['entity_id'] ?>">View order</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section></main><?php include dirname(__DIR__) . '/partials/admin_footer.php'; ?></body></html>
