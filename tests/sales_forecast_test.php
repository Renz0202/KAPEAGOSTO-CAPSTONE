<?php
require_once __DIR__ . '/../includes/analytics.php';
date_default_timezone_set('Asia/Manila');
function verify_sales($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$target = date('Y-m-d', strtotime('+1 day'));
$series = [];
for ($offset = 28; $offset >= 1; $offset--) {
    $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $sameDay = date('N', strtotime($day)) === date('N', strtotime($target));
    $series[] = ['date' => $day, 'orders' => $sameDay ? 100 : 10, 'revenue' => $sameDay ? 10000 : 1000];
}
$baseline = analytics_weighted_forecast(array_column($series, 'orders'));
$forecast = analytics_weekday_forecast($series, 'orders', $target);
verify_sales(abs($forecast - (.6 * 100 + .4 * $baseline)) < .00001, 'Forecast must recognize matching weekdays.');
$withPartial = array_merge($series, [['date' => date('Y-m-d'), 'orders' => 999999, 'revenue' => 9999999]]);
verify_sales(analytics_weekday_forecast($withPartial, 'orders', $target) === $forecast, 'Partial today must not alter tomorrow forecast.');
$short = array_slice($series, -7);
verify_sales(analytics_weekday_forecast($short, 'orders', $target) === analytics_weighted_forecast(array_column($short, 'orders')), 'Sparse weekday history must use trend fallback.');
verify_sales(analytics_weekday_forecast([], 'orders') === 0.0, 'Empty history must be safe.');
$comparison = analytics_sales_comparison($series);
verify_sales($comparison['recentStart'] === $series[21]['date'] && $comparison['recentEnd'] === $series[27]['date'], 'Recent window dates incorrect.');
verify_sales($comparison['previousStart'] === $series[14]['date'] && $comparison['previousEnd'] === $series[20]['date'], 'Prior window dates incorrect.');
verify_sales(abs($comparison['changePercent']) < .00001, 'Equal full weeks must show no change.');
verify_sales(analytics_sales_comparison([])['changePercent'] === null, 'Missing baseline must not invent a percentage.');
echo "8 sales forecast checks passed.\n";
