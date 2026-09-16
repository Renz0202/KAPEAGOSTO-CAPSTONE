<?php

function analytics_daily_sales(PDO $pdo, int $days = 28): array
{
    $days = max(7, min(365, $days));
    $stmt = $pdo->prepare("
        SELECT DATE(created_at) AS sales_date, COUNT(*) AS orders_count, COALESCE(SUM(total), 0) AS revenue
        FROM orders
        WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(created_at)
    ");
    $stmt->execute([$days - 1]);
    $rows = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $series = [];
    for ($offset = $days - 1; $offset >= 0; $offset--) {
        $date = date('Y-m-d', strtotime('-' . $offset . ' days'));
        $series[] = [
            'date' => $date,
            'orders' => (float) ($rows[$date]['orders_count'] ?? 0),
            'revenue' => (float) ($rows[$date]['revenue'] ?? 0),
        ];
    }
    return $series;
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

function analytics_store_forecasts(PDO $pdo): array
{
    $series = analytics_daily_sales($pdo, 28);
    $orders = analytics_weighted_forecast(array_column($series, 'orders'));
    $revenue = analytics_weighted_forecast(array_column($series, 'revenue'));
    $forecastDate = date('Y-m-d', strtotime('+1 day'));
    $insert = $pdo->prepare("
        INSERT IGNORE INTO forecast_snapshots (forecast_date, metric_type, subject_key, item_id, predicted_value, method)
        VALUES (?, ?, ?, ?, ?, 'weighted_28_day_trend')
    ");
    $insert->execute([$forecastDate, 'orders', 'overall', null, $orders]);
    $insert->execute([$forecastDate, 'revenue', 'overall', null, $revenue]);

    $itemStmt = $pdo->query("
        SELECT i.id, i.sku, COALESCE(SUM(CASE WHEN o.status = 'completed' AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 27 DAY) THEN oi.qty ELSE 0 END), 0) AS qty_28,
               COALESCE(SUM(CASE WHEN o.status = 'completed' AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN oi.qty ELSE 0 END), 0) AS qty_7
        FROM items i
        LEFT JOIN order_items oi ON oi.item_id = i.id
        LEFT JOIN orders o ON o.id = oi.order_id
        GROUP BY i.id, i.sku
    ");
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $longDaily = (float) $item['qty_28'] / 28;
        $recentDaily = (float) $item['qty_7'] / 7;
        $predicted = max(0, ($recentDaily * 0.7) + ($longDaily * 0.3));
        $insert->execute([$forecastDate, 'item_demand', (string) $item['sku'], (int) $item['id'], $predicted]);
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

function analytics_ai_recommendation(PDO $pdo, array $settings, array $context): ?string
{
    if (($settings['ai_enabled'] ?? '0') !== '1' || strtolower($settings['ai_provider'] ?? '') !== 'openai' || trim($settings['ai_api_key'] ?? '') === '' || !function_exists('curl_init')) {
        return null;
    }
    $cached = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ai_last_recommendation','ai_last_generated_at')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($cached['ai_last_recommendation']) && !empty($cached['ai_last_generated_at']) && strtotime($cached['ai_last_generated_at']) >= time() - 21600) {
        return (string) $cached['ai_last_recommendation'];
    }

    $payload = [
        'model' => trim((string) ($settings['ai_model'] ?? 'gpt-4o-mini')) ?: 'gpt-4o-mini',
        'input' => "You are a concise cafe operations analyst. Based only on this aggregate JSON, provide one actionable recommendation in at most 45 words. Do not invent facts. Data: " . json_encode($context, JSON_UNESCAPED_SLASHES),
        'max_output_tokens' => 120,
    ];
    $curl = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $settings['ai_api_key'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($payload)]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($raw) || $status < 200 || $status >= 300) return null;
    $data = json_decode($raw, true);
    $text = trim((string) ($data['output_text'] ?? ''));
    if ($text === '') {
        foreach (($data['output'] ?? []) as $output) foreach (($output['content'] ?? []) as $content) if (($content['type'] ?? '') === 'output_text') $text .= (string) ($content['text'] ?? '');
        $text = trim($text);
    }
    if ($text === '') return null;
    $save = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    $save->execute(['ai_last_recommendation', $text]);
    $save->execute(['ai_last_generated_at', date('Y-m-d H:i:s')]);
    return $text;
}

