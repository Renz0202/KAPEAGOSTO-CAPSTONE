<?php
require_once dirname(__DIR__) . '/includes/receipt_tax.php';
$tax = receipt_tax_breakdown(115, 'vat');
if ($tax !== ['VATable Sales' => 102.68, 'VAT Amount (12%)' => 12.32, 'VAT-Exempt Sales' => 0.0, 'Zero-Rated Sales' => 0.0]) throw new RuntimeException('Incorrect VAT-inclusive breakdown');
foreach ([0, 0.01, 99.99, 112, 115, 300.25, 9999.99] as $total) {
    $rows = receipt_tax_breakdown((float) $total, 'vat');
    if ((int) round(array_sum($rows) * 100) !== (int) round($total * 100)) throw new RuntimeException('Tax rows do not reconcile');
}
foreach (['', 'non_vat', 'invalid'] as $type) {
    if (receipt_tax_breakdown(115, $type) !== []) throw new RuntimeException('Unconfigured and non-VAT sales must not calculate VAT');
}
echo "PASS: VAT-inclusive breakdown, cent rounding, and non-VAT/unconfigured behavior.\n";
