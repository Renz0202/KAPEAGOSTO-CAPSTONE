<?php
/** Regular menu sales with VAT-inclusive prices; no special exemptions inferred. */
function receipt_tax_breakdown(float $total, string $taxType): array {
    if ($taxType !== 'vat') return [];
    $grossCents = (int) round($total * 100);
    $netCents = (int) round($grossCents * 100 / 112);
    return [
        'VATable Sales' => $netCents / 100,
        'VAT Amount (12%)' => ($grossCents - $netCents) / 100,
        'VAT-Exempt Sales' => 0.0,
        'Zero-Rated Sales' => 0.0,
    ];
}
