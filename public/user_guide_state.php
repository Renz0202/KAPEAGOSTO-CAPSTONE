<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/user_guide.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!current_user()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Please sign in.']);
    exit;
}
require_login();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    $userId = (int) current_user()['id'];
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'progress' => user_guide_progress(db(), $userId)], JSON_THROW_ON_ERROR);
    } elseif ($method === 'POST') {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) throw new InvalidArgumentException('Request is too large.');
        $raw = file_get_contents('php://input', false, null, 0, 4097);
        if (strlen($raw) > 4096) throw new InvalidArgumentException('Request is too large.');
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) throw new InvalidArgumentException('Invalid request.');
        $status = user_guide_validate_update($body, $_SESSION['user_guide_csrf'] ?? '');
        echo json_encode(['success' => true, 'progress' => user_guide_save_progress(db(), $userId, $status)], JSON_THROW_ON_ERROR);
    } else {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    }
} catch (DomainException $error) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
} catch (InvalidArgumentException | JsonException $error) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid guide preference.']);
} catch (Throwable $error) {
    error_log('Guide preference: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Your preference could not be saved. Please try again.']);
}
