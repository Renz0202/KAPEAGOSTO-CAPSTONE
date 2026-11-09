<?php
require_once __DIR__ . '/ai_provider.php';

function analytics_daily_sales(PDO $pdo, int $days = 28, bool $completedDays = false): array
{
    $days = max(7, min(365, $days));
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) AS sales_date, COUNT(*) AS orders_count, COALESCE(SUM(total), 0) AS revenue
        FROM orders
        WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
          AND created_at < DATE_ADD(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->execute([$completedDays ? $days : $days - 1, $completedDays ? 0 : 1]);
    $rows = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $series = [];
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = date('Y-m-d', strtotime('-' . ($offset + ($completedDays ? 1 : 0)) . ' days'));
        $series[] = [
            'date' => $date,
            'orders' => (float) ($rows[$date]['orders_count'] ?? 0),
            'revenue' => (float) ($rows[$date]['revenue'] ?? 0),
        ];
    }
    return $series;
}

function analytics_sales_chart(PDO $pdo): array
{
    $series = analytics_daily_sales($pdo, 28);
    $completed = analytics_daily_sales($pdo, 28, true);
    $snapshots = $pdo->query("SELECT forecast_date, metric_type, predicted_value FROM forecast_snapshots
        WHERE subject_key = 'overall' AND metric_type IN ('orders', 'revenue')
        AND forecast_date >= DATE_SUB(CURDATE(), INTERVAL 27 DAY)
        AND forecast_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchAll(PDO::FETCH_ASSOC);
    $saved = [];
    foreach ($snapshots as $row) $saved[$row['metric_type']][$row['forecast_date']] = (float) $row['predicted_value'];
    $result = ['dates' => array_column($series, 'date'), 'metrics' => [], 'hasHistory' => array_sum(array_column($series, 'orders')) > 0];
    $result['comparison'] = analytics_sales_comparison($completed);
    $result['dates'][] = date('Y-m-d', strtotime('+1 day'));
    foreach (['revenue', 'orders'] as $metric) {
        $actual = array_map('floatval', array_column($series, $metric));
        $average = [];
        foreach ($actual as $index => $value) {
            $average[] = $index < 6 ? null : round(array_sum(array_slice($actual, $index - 6, 7)) / 7, 2);
        }
        $predictions = [];
        foreach ($series as $day) $predictions[] = $saved[$metric][$day['date']] ?? null;
        $tomorrow = array_sum(array_column($completed, 'orders')) > 0 ? analytics_weekday_forecast($completed, $metric) : null;
        $result['metrics'][$metric] = [
            'actual' => array_merge($actual, [null]),
            'average' => array_merge($average, [null]),
            'saved' => array_merge($predictions, [null]),
            'projection' => array_merge(array_fill(0, count($actual) - 1, null), [$result['hasHistory'] ? end($actual) : null, $tomorrow]),
        ];
    }
    return $result;
}

function analytics_weighted_forecast(array $values): float
{
    $values = array_values(array_map('floatval', $values));
    if (!$values) return 0.0;
    $recent = array_slice($values, -7);
    $previous = array_slice($values, -14, 7);
    $recentAverage = array_sum($recent) / max(1, count($recent));
    $previousAverage = $previous ? array_sum($previous) / count($previous) : $recentAverage;
    $trend = max(-0.35, min(0.35, ($recentAverage - $previousAverage) / max(1.0, $previousAverage)));
    return max(0.0, (($recentAverage * 0.7) + ($previousAverage * 0.3)) * (1 + ($trend * 0.5)));
}

function analytics_sales_comparison(array $series): array
{
    $recent = array_slice($series, -7);
    $previous = array_slice($series, -14, 7);
    $current = array_sum(array_column($recent, 'revenue'));
    $prior = array_sum(array_column($previous, 'revenue'));
    return ['recentRevenue' => $current, 'previousRevenue' => $prior,
        'recentStart' => $recent[0]['date'] ?? '', 'recentEnd' => $recent[count($recent) - 1]['date'] ?? '',
        'previousStart' => $previous[0]['date'] ?? '', 'previousEnd' => $previous[count($previous) - 1]['date'] ?? '',
        'changePercent' => $prior > 0 ? ($current - $prior) / $prior * 100 : null];
}

function analytics_weekday_forecast(array $series, string $metric, ?string $targetDate = null): float
{
    $targetDate = $targetDate ?? date('Y-m-d', strtotime('+1 day'));
    // Filter out partial/current days and future records before estimating tomorrow.
    $cutoff = min(date('Y-m-d'), $targetDate);
    $series = array_values(array_filter($series, fn($row) => $row['date'] < $cutoff));
    $trend = analytics_weighted_forecast(array_column($series, $metric));
    $weekday = date('N', strtotime($targetDate));
    $matching = array_values(array_filter($series, fn($row) => date('N', strtotime($row['date'])) === $weekday));
    if (count($matching) < 3) return $trend;
    $matching = array_slice($matching, -4);
    $total = 0.0;
    $weights = 0;
    foreach ($matching as $index => $row) {
        $weight = $index + 1;
        $total += (float) $row[$metric] * $weight;
        $weights += $weight;
    }
    return max(0.0, .6 * ($total / $weights) + .4 * $trend);
}

function analytics_store_forecasts(PDO $pdo): array
{
    $series = analytics_daily_sales($pdo, 28, true);
    $orders = analytics_weekday_forecast($series, 'orders');
    $revenue = analytics_weekday_forecast($series, 'revenue');
    $forecastDate = date('Y-m-d', strtotime('+1 day'));
    $insert = $pdo->prepare("
        INSERT IGNORE INTO forecast_snapshots (forecast_date, metric_type, subject_key, item_id, predicted_value, method)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $insert->execute([$forecastDate, 'orders', 'overall', null, $orders, 'completed_day_weekday_blend']);
    $insert->execute([$forecastDate, 'revenue', 'overall', null, $revenue, 'completed_day_weekday_blend']);

    $itemStmt = $pdo->query("
        SELECT i.id, i.sku, COALESCE(SUM(CASE WHEN o.status = 'completed' AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 28 DAY) AND o.created_at < CURDATE() THEN oi.qty ELSE 0 END), 0) AS qty_28,
               COALESCE(SUM(CASE WHEN o.status = 'completed' AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND o.created_at < CURDATE() THEN oi.qty ELSE 0 END), 0) AS qty_7
        FROM items i
        LEFT JOIN order_items oi ON oi.item_id = i.id
        LEFT JOIN orders o ON o.id = oi.order_id
        GROUP BY i.id, i.sku
    ");
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $longDaily = (float) $item['qty_28'] / 28;
        $recentDaily = (float) $item['qty_7'] / 7;
        $predicted = max(0, ($recentDaily * 0.7) + ($longDaily * 0.3));
        $insert->execute([$forecastDate, 'item_demand', (string) $item['sku'], (int) $item['id'], $predicted, 'completed_day_item_average']);
    }
    return ['orders' => $orders, 'revenue' => $revenue, 'date' => $forecastDate];
}

function analytics_evaluate_forecasts(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT id, forecast_date, metric_type, item_id, predicted_value FROM forecast_snapshots WHERE forecast_date < CURDATE() AND actual_value IS NULL ORDER BY forecast_date LIMIT 500");
    $update = $pdo->prepare('UPDATE forecast_snapshots SET actual_value = ?, accuracy_percent = ?, evaluated_at = NOW() WHERE id = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $forecast) {
        if ($forecast['metric_type'] === 'orders') {
            $actualStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status = 'completed' AND DATE(created_at) = ?");
            $actualStmt->execute([$forecast['forecast_date']]);
        } elseif ($forecast['metric_type'] === 'revenue') {
            $actualStmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE status = 'completed' AND DATE(created_at) = ?");
            $actualStmt->execute([$forecast['forecast_date']]);
        } else {
            $actualStmt = $pdo->prepare("SELECT COALESCE(SUM(oi.qty), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status = 'completed' AND DATE(o.created_at) = ? AND oi.item_id = ?");
            $actualStmt->execute([$forecast['forecast_date'], (int) $forecast['item_id']]);
        }
        $actual = (float) $actualStmt->fetchColumn();
        $predicted = (float) $forecast['predicted_value'];
        $accuracy = $actual == 0.0 ? ($predicted == 0.0 ? 100.0 : 0.0) : max(0.0, 100.0 - (abs($predicted - $actual) / $actual * 100.0));
        $update->execute([$actual, round($accuracy, 2), (int) $forecast['id']]);
    }
}

function analytics_accuracy(PDO $pdo): ?float
{
    $value = $pdo->query("SELECT AVG(accuracy_percent) FROM forecast_snapshots WHERE accuracy_percent IS NOT NULL AND forecast_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
    return $value === null || $value === false ? null : (float) $value;
}

function analytics_product_movement(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT i.id, i.sku, i.name, i.cup_size,
               COALESCE(SUM(CASE WHEN o.status = 'completed' AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN oi.qty ELSE 0 END), 0) AS qty_30,
               COALESCE(SUM(CASE WHEN o.status = 'completed' AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN oi.qty ELSE 0 END), 0) AS qty_7
        FROM items i
        LEFT JOIN order_items oi ON oi.item_id = i.id
        LEFT JOIN orders o ON o.id = oi.order_id
        GROUP BY i.id, i.sku, i.name, i.cup_size
        ORDER BY qty_30 DESC, i.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    $positive = array_values(array_filter(array_map(fn($row) => (float) $row['qty_30'], $rows), fn($value) => $value > 0));
    sort($positive);
    $fastThreshold = $positive ? $positive[(int) floor((count($positive) - 1) * 0.7)] : 0;
    foreach ($rows as &$row) {
        $qty30 = (float) $row['qty_30'];
        $recentRate = (float) $row['qty_7'] / 7;
        $longRate = $qty30 / 30;
        $row['trend_percent'] = $longRate > 0 ? (($recentRate - $longRate) / $longRate) * 100 : 0;
        $row['movement'] = $qty30 <= 0 ? 'No movement' : ($qty30 >= $fastThreshold && $fastThreshold > 0 ? 'Fast-moving' : 'Slow-moving');
    }
    unset($row);
    return $rows;
}

function analytics_inventory_turnover(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT i.id, i.sku, i.name, i.cup_size, i.qty,
               COALESCE(SUM(CASE WHEN l.change_type IN ('used','deduct','auto-deduct') AND l.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN l.quantity ELSE 0 END), 0) AS used_30
        FROM inventory i
        LEFT JOIN inventory_logs l ON l.inventory_id = i.id
        GROUP BY i.id, i.sku, i.name, i.cup_size, i.qty
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $used = (float) $row['used_30'];
        $estimatedAverageStock = max(0.01, (float) $row['qty'] + ($used / 2));
        $row['turnover_rate'] = $used / $estimatedAverageStock;
    }
    unset($row);
    usort($rows, fn($a, $b) => $b['turnover_rate'] <=> $a['turnover_rate']);
    return $rows;
}

function analytics_ai_failure_message(int $status, int $curlError, string $code, array $errorData = []): string
{
    if ($curlError === 28) return 'The AI connection timed out. Please try again.';
    if ($curlError !== 0 || $status === 0) return 'Unable to connect to the AI service. Please check the server connection and try again.';
    if ($code === 'invalid_api_key' || $status === 401) return 'The API key is invalid or expired. Update it in AI settings.';
    if (in_array($code, ['insufficient_quota', 'billing_hard_limit_reached', 'credit_balance_exhausted'], true) || ($errorData['error']['type'] ?? '') === 'insufficient_quota') return 'No API credits available. Add credits in your provider billing settings, then try again. Replacing the API key will not add credits.';
    if ($status === 429) {
        $details = json_encode($errorData);
        $message = strtolower((string) ($errorData['error']['message'] ?? ''));
        if (strpos($message, 'per day') !== false || stripos($details, 'PerDay') !== false || stripos($details, 'daily') !== false) {
            $retry = '';
            foreach (($errorData['error']['details'] ?? []) as $detail) {
                if (isset($detail['retryDelay'])) {
                    $seconds = (int) ceil((float) $detail['retryDelay']);
                    if ($seconds > 0) $retry = ' Retry after approximately ' . (int) ceil($seconds / 60) . ' minutes.';
                }
            }
            return 'Daily AI quota exhausted for this project/model.' . $retry . ' Wait for the quota reset or review provider billing. Replacing a key in the same project will not reset its quota.';
        }
        return 'The AI service request limit was reached. Please wait and try again.';
    }
    if ($status === 403 || $status === 404) return 'The configured AI model is unavailable or this API account does not have access. Check AI settings.';
    if ($status >= 500) return 'The AI service is temporarily unavailable. Please try again later.';
    return 'The AI service could not complete this request. Check the configured model or try again.';
}

function analytics_ai_recommendation(PDO $pdo, array $settings, array $context, bool $force = false, ?string &$failure = null): ?string
{
    $failure = null;
    if (($settings['ai_enabled'] ?? '0') !== '1' || !in_array(strtolower($settings['ai_provider'] ?? ''), ['openai', 'gemini'], true) || trim($settings['ai_api_key'] ?? '') === '' || !function_exists('curl_init')) {
        return null;
    }
    $cached = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ai_sales_last_recommendation','ai_sales_last_generated_at','ai_sales_last_source')")->fetchAll(PDO::FETCH_KEY_PAIR);
    $source = hash('sha256', 'completed-day-v2|' . ($settings['ai_provider'] ?? 'openai') . '|' . ai_model($settings) . '|' . ($settings['ai_api_key'] ?? ''));
    if (!$force && ($cached['ai_sales_last_source'] ?? '') === $source && !empty($cached['ai_sales_last_recommendation']) && !empty($cached['ai_sales_last_generated_at']) && strtotime($cached['ai_sales_last_generated_at']) >= time() - 21600) {
        return (string) $cached['ai_sales_last_recommendation'];
    }
    // Dashboard loads and 15-second polling must never spend provider quota.
    // Only the explicit Generate action sends a new request.
    if (!$force) return null;

    @set_time_limit(75);
    $request = ai_request_config($settings, "You are a concise cafe operations analyst. Based only on this aggregate JSON, provide one actionable recommendation in English in at most 45 words. All revenue values are Philippine pesos (PHP), never US dollars. Forecasts are estimates, not guarantees. Do not infer catering, bulk orders, staffing requirements, or labor costs from order averages alone. Recommend only actions supported by the supplied data. Do not invent facts. Data: " . json_encode($context, JSON_UNESCAPED_SLASHES), 120);
    $curl = curl_init($request['url']);
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $request['headers'], CURLOPT_POSTFIELDS => json_encode($request['payload'])]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);
    if (!is_string($raw) || $status < 200 || $status >= 300) {
        $errorData = is_string($raw) ? json_decode($raw, true) : [];
        $failure = analytics_ai_failure_message($status, $curlError, ai_error_code($errorData ?? []), $errorData ?? []);
        return null;
    }
    $data = json_decode($raw, true);
    $text = ai_response_text($data ?? [], $settings['ai_provider'] ?? 'openai');
    if ($text === '') {
        $failure = 'The AI service returned no recommendation. Please try generating again.';
        return null;
    }
    $save = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $save->execute(['ai_sales_last_source', $source]);
    $save->execute(['ai_sales_last_recommendation', $text]);
    $save->execute(['ai_sales_last_generated_at', date('Y-m-d H:i:s')]);
    return $text;
}

