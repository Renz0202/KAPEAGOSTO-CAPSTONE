<?php
/** Return an inclusive start and exclusive end for a validated calendar period. */
function order_date_filter(string $period, string $value): ?array {
    $formats = ['day' => 'Y-m-d', 'month' => 'Y-m', 'year' => 'Y'];
    if (!isset($formats[$period])) return null;
    $start = DateTimeImmutable::createFromFormat('!' . $formats[$period], $value);
    if (!$start || $start->format($formats[$period]) !== $value) return null;
    $end = $start->modify(['day' => '+1 day', 'month' => '+1 month', 'year' => '+1 year'][$period]);
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}
