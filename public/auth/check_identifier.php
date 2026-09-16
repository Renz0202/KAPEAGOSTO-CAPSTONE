<?php
// Robust identifier check endpoint with silent error handling
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', 0);
require_once dirname(__DIR__, 2) . '/includes/db.php';

$response = [
    'exists' => false,
    'active' => false,
    'error'  => null,
];

try {
    $pdo = db();
    // CSRF validation if token present in session
    $csrfClient = $_POST['csrf_token'] ?? '';
    if (isset($_SESSION['csrf_token']) && $_SESSION['csrf_token'] !== '' && !hash_equals($_SESSION['csrf_token'], (string)$csrfClient)) {
        $response['error'] = 'csrf';
        echo json_encode($response);
        exit;
    }

    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') {
        $response['error'] = 'empty';
        echo json_encode($response);
        exit;
    }

    $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
    $sql = $isEmail
        ? 'SELECT id, status FROM users WHERE email = ? LIMIT 1'
        : 'SELECT id, status FROM users WHERE username = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identifier]);
    if ($row = $stmt->fetch()) {
        $response['exists'] = true;
        $status = strtolower(trim((string)$row['status']));
        $response['active'] = ($status === 'active');
    }
} catch (Throwable $e) {
    // Generic error without leaking details
    $response['error'] = 'server';
}

echo json_encode($response);
