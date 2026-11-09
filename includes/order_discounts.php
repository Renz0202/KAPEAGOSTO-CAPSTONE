<?php
/** Server-side checkout discount rules. Menu prices are VAT-inclusive when the business is VAT-registered. */
function order_discount_calculate(float $subtotal, float $promoPercent, string $beneficiaryType, string $taxType): array
{
    $grossCents = max(0, (int) round($subtotal * 100));
    $promoPercent = max(0, min(100, $promoPercent));
    $promoDiscountCents = (int) round($grossCents * $promoPercent / 100);
    $promoDueCents = max(0, $grossCents - $promoDiscountCents);
    $eligible = in_array($beneficiaryType, ['senior', 'pwd'], true) ? $beneficiaryType : '';
    $statutoryNetCents = $taxType === 'vat' ? (int) round($grossCents / 1.12) : $grossCents;
    $vatExemptCents = $taxType === 'vat' ? max(0, $grossCents - $statutoryNetCents) : 0;
    $statutoryDiscountCents = (int) round($statutoryNetCents * 0.20);
    $statutoryDueCents = max(0, $statutoryNetCents - $statutoryDiscountCents);

    $appliedType = 'none';
    $rate = 0.0;
    $discountCents = 0;
    $vatExemptApplied = 0;
    $totalCents = $grossCents;
    if ($eligible !== '') {
        if ($promoPercent > 0 && $promoDueCents < $statutoryDueCents) {
            $appliedType = 'promo';
            $rate = $promoPercent;
            $discountCents = $promoDiscountCents;
            $totalCents = $promoDueCents;
        } else {
            $appliedType = $eligible;
            $rate = 20.0;
            $discountCents = $statutoryDiscountCents;
            $vatExemptApplied = $vatExemptCents;
            $totalCents = $statutoryDueCents;
        }
    } elseif ($promoPercent > 0) {
        $appliedType = 'promo';
        $rate = $promoPercent;
        $discountCents = $promoDiscountCents;
        $totalCents = $promoDueCents;
    }

    return [
        'subtotal' => $grossCents / 100,
        'discount_type' => $appliedType,
        'discount_beneficiary_type' => $eligible !== '' ? $eligible : null,
        'discount_rate' => $rate,
        'discount_amount' => $discountCents / 100,
        'vat_exempt_amount' => $vatExemptApplied / 100,
        'total' => $totalCents / 100,
        'promo_percent' => $promoPercent,
        'used_promo' => $appliedType === 'promo',
        'statutory_discount_amount' => $statutoryDiscountCents / 100,
        'statutory_vat_exempt_amount' => $vatExemptCents / 100,
        'statutory_total' => $statutoryDueCents / 100,
        'promo_total' => $promoDueCents / 100,
    ];
}

function order_discount_from_record(array $order): array
{
    return [
        'discount_type' => (string) ($order['discount_type'] ?? 'none'),
        'discount_beneficiary_type' => $order['discount_beneficiary_type'] ?? null,
        'discount_rate' => (float) ($order['discount_rate'] ?? 0),
        'discount_amount' => (float) ($order['discount_amount'] ?? 0),
        'vat_exempt_amount' => (float) ($order['vat_exempt_amount'] ?? 0),
        'total' => (float) ($order['total'] ?? 0),
    ];
}

function order_discount_recalculate_pending(PDO $pdo, int $orderId, float $subtotal, string $taxType): array
{
    $stmt = $pdo->prepare('SELECT promo_percent, discount_beneficiary_type FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Order not found.');
    $discount = order_discount_calculate($subtotal, (float) $order['promo_percent'], (string) ($order['discount_beneficiary_type'] ?? ''), $taxType);
    $update = $pdo->prepare('UPDATE orders SET subtotal = ?, total = ?, discount_type = ?, discount_rate = ?, discount_amount = ?, vat_exempt_amount = ? WHERE id = ?');
    $update->execute([$discount['subtotal'], $discount['total'], $discount['discount_type'], $discount['discount_rate'], $discount['discount_amount'], $discount['vat_exempt_amount'], $orderId]);
    return $discount;
}

function order_discount_report_totals(PDO $pdo, string $start, string $end, string $taxType): array
{
    $stmt = $pdo->prepare("SELECT total, discount_type FROM orders WHERE status = 'completed' AND created_at >= ? AND created_at < ?");
    $stmt->execute([$start, $end]);
    $salesCents = 0;
    $taxCents = 0;
    $orders = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grossCents = max(0, (int) round((float) $row['total'] * 100));
        $salesCents += $grossCents;
        $orders++;
        if ($taxType === 'vat' && !in_array((string) ($row['discount_type'] ?? ''), ['senior', 'pwd'], true)) {
            $taxCents += max(0, $grossCents - (int) round($grossCents / 1.12));
        }
    }
    return ['total_sales' => $salesCents / 100, 'total_orders' => $orders, 'sales_tax' => $taxCents / 100];
}
