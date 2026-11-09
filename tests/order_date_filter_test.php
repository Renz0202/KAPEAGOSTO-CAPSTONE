<?php
require_once dirname(__DIR__) . '/includes/order_date_filter.php';
$cases = [
    ['day', '2024-02-29', ['2024-02-29 00:00:00', '2024-03-01 00:00:00']],
    ['month', '2026-12', ['2026-12-01 00:00:00', '2027-01-01 00:00:00']],
    ['year', '2024', ['2024-01-01 00:00:00', '2025-01-01 00:00:00']],
    ['day', '2026-02-29', null],
    ['month', '2026-13', null],
    ['year', 'invalid', null],
    ['', '', null],
];
foreach ($cases as [$period, $value, $expected]) {
    if (order_date_filter($period, $value) !== $expected) throw new RuntimeException("Failed: $period $value");
}
echo "PASS: All 7 date filter boundary and validation checks.\n";
