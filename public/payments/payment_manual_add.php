<?php
session_start();
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/payment.php';
require_capability('process_transactions');
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Use POST to record cash.']);
    exit;
}
$method = is_string($_POST['method'] ?? null) ? trim($_POST['method']) : 'cash';
if (!in_array($method, ALLOWED_MANUAL_METHODS, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Payment method not allowed.']);
    exit;
}
$pdo = db();
payment_ensure_schema($pdo);
try {
    echo json_encode(payment_record_cash($pdo, (int) ($_POST['order_id'] ?? 0), $_POST['amount'] ?? ''), JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    $status = in_array($e->getCode(), [404, 409], true) ? $e->getCode() : 500;
    http_response_code($status);
    if ($status === 500) error_log('Cash payment failed: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => $status === 500 ? 'Could not record cash. Please try again.' : $e->getMessage()]);
}
