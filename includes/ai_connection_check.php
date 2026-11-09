<?php
require_once __DIR__ . '/analytics.php';

// Included only after require_admin(), before either settings page saves a form.
if (empty($_SESSION['ai_connection_token'])) {
    $_SESSION['ai_connection_token'] = bin2hex(random_bytes(32));
}
if (isset($_GET['check_ai_connection'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['ai_connection_token'], (string) ($_POST['token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['state' => 'unknown', 'message' => 'Refresh the page and try again.']);
        exit;
    }
    if (time() - (int) ($_SESSION['ai_connection_checked_at'] ?? 0) < 30) {
        http_response_code(429);
        echo json_encode(['state' => 'unknown', 'message' => 'Please wait 30 seconds before checking again.']);
        exit;
    }
    try {
        $config = settings_all($pdo);
        $key = trim($config['ai_api_key'] ?? '');
        $model = ai_model($config);
        $result = ['state' => 'unknown', 'message' => 'No saved API key. Save a key before checking.'];
        if ($key !== '' && !in_array(strtolower($config['ai_provider'] ?? ''), ['openai', 'gemini'], true)) {
            $result['message'] = 'Select OpenAI or Gemini and save settings before checking.';
        } elseif ($key !== '' && !function_exists('curl_init')) {
            $result['message'] = 'Cannot check: PHP cURL is unavailable on the server.';
        } elseif ($key !== '') {
            $_SESSION['ai_connection_checked_at'] = time();
            @set_time_limit(75);
            $request = ai_request_config($config, 'Reply OK.', 16);
            $curl = curl_init($request['url']);
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $request['headers'], CURLOPT_POSTFIELDS => json_encode($request['payload'])]);
            $raw = curl_exec($curl);
            $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $errno = curl_errno($curl);
            curl_close($curl);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $code = ai_error_code($data ?? []);
            if ($http >= 200 && $http < 300 && is_array($data) && !isset($data['error']) && ($data['status'] ?? '') !== 'failed') {
                $result = ['state' => 'active', 'message' => 'Active: the saved API key and model accepted a live request. No replacement is needed now.'];
            } else {
                $message = analytics_ai_failure_message($http, $errno, $code, $data ?? []);
                if (in_array($code, ['invalid_api_key', 'invalid_api_key_error'], true)) {
                    $result = ['state' => 'invalid', 'message' => 'API key rejected: it may be invalid, expired, or revoked. Check the key in your provider account and save a replacement if needed.'];
                } elseif ($code === 'ip_not_authorized') {
                    $result['message'] = 'The server IP is not allowed by the OpenAI account. Check the IP allowlist; replacing the key may not help.';
                } else {
                    $result['message'] = $message . ' This does not confirm that a replacement key is needed.';
                }
            }
        }
        $result['checkedAt'] = date('M d, Y h:i:s A T');
        $result['fingerprint'] = hash('sha256', $key . '|' . $model . '|' . ($config['ai_provider'] ?? ''));
        $_SESSION['ai_connection_result'] = $result;
        unset($result['fingerprint']);
        echo json_encode($result);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('AI connection check failed.');
        echo json_encode(['state' => 'unknown', 'message' => 'Unable to check the API connection. Please try again.']);
    }
    exit;
}
