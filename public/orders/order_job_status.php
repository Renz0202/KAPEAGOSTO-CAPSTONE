<?php

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_capability('process_transactions');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$jobStatus = (string) ($_POST['job_status'] ?? '');
$allowed = ['queued', 'in_progress', 'ready'];
if ($orderId <= 0 || !in_array($jobStatus, $allowed, true)) {
    header('Location: order_list.php?error=invalid_request');
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("UPDATE orders SET job_status = ? WHERE id = ? AND status = 'pending'");
$stmt->execute([$jobStatus, $orderId]);
header('Location: order_list.php?job_updated=1');
exit;

