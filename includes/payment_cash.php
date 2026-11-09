<?php
/** Cash is stored separately from the amount applied to the order. */
function payment_cash_cents($value): int {
    if (!is_scalar($value) || is_bool($value)) {
        throw new InvalidArgumentException('Enter a cash amount with at most two decimal places.', 400);
    }
    $value = trim((string) $value);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('Enter a cash amount with at most two decimal places.', 400);
    }
    $parts = explode('.', $value, 2);
    return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
}

/** An empty result means the historical tender was not recorded. */
function payment_cash_details(array $payment): array {
    if (($payment['provider'] ?? '') !== 'manual') return [];
    $raw = json_decode((string) ($payment['raw_response'] ?? ''), true);
    if (!is_array($raw) || !isset($raw['tendered'])) return [];
    try {
        $tendered = payment_cash_cents($raw['tendered']);
        $applied = payment_cash_cents($payment['amount'] ?? 0);
        $change = isset($raw['change']) ? payment_cash_cents($raw['change']) : max(0, $tendered - $applied);
        if ($tendered < $applied || $change !== $tendered - $applied) return [];
        return ['tendered' => $tendered / 100, 'change' => $change / 100];
    } catch (InvalidArgumentException $e) {
        return [];
    }
}

/** Lock the order so simultaneous payment submissions cannot overpay it. */
function payment_record_cash(PDO $pdo, int $orderId, $cashReceived): array {
    $tendered = payment_cash_cents($cashReceived);
    if ($orderId <= 0 || $tendered <= 0) {
        throw new InvalidArgumentException('Enter a valid order and cash amount greater than zero.', 400);
    }
    if ($pdo->inTransaction()) throw new LogicException('Cash payment requires its own transaction.');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT total, status FROM orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new RuntimeException('Order not found.', 404);
        if (($order['status'] ?? '') !== 'pending') {
            throw new RuntimeException('Payments can only be added to pending orders.', 409);
        }
        $total = (int) round((float) $order['total'] * 100);
        $paid = (int) round(payment_total_paid($pdo, $orderId) * 100);
        $remaining = max(0, $total - $paid);
        if ($remaining <= 0) throw new RuntimeException('This order is already fully paid.', 409);
        $applied = min($tendered, $remaining);
        $change = $tendered - $applied;
        $details = json_encode(['method' => 'cash', 'tendered' => $tendered / 100, 'change' => $change / 100], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare("INSERT INTO payments (order_id, provider, amount, currency, status, raw_response, paid_at) VALUES (?, 'manual', ?, 'php', 'succeeded', ?, NOW())");
        $insert->execute([$orderId, number_format($applied / 100, 2, '.', ''), $details]);
        $paymentId = (int) $pdo->lastInsertId();
        transaction_issue_invoice($pdo, $orderId);
        $pdo->commit();
        return ['ok' => true, 'payment_id' => $paymentId, 'tendered' => $tendered / 100, 'applied' => $applied / 100,
            'paid' => ($paid + $applied) / 100, 'remaining' => ($remaining - $applied) / 100, 'change' => $change / 100];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Summarize actual cash across all orders displayed on a receipt, including earlier tenders. */
function payment_receipt_summary(PDO $pdo, array $orderIds): array {
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($id) => $id > 0)));
    $summary = ['paid' => 0.0, 'cash_received' => 0.0, 'change' => 0.0, 'change_applied' => 0.0,
        'has_cash' => false, 'cash_complete' => true, 'change_applied_elsewhere' => 0.0];
    if (!$orderIds) return $summary;
    $marks = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("SELECT id, order_id, provider, amount, raw_response FROM payments WHERE order_id IN ($marks) AND status = 'succeeded' ORDER BY id");
    $stmt->execute($orderIds);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $used = [];
    $usedElsewhere = [];
    $credits = $pdo->prepare("SELECT cc.source_payment_id, cc.destination_payment_id, cc.amount, destination_payment.order_id AS destination_order_id
        FROM payment_change_credits cc
        JOIN payments source_payment ON source_payment.id = cc.source_payment_id
        JOIN payments destination_payment ON destination_payment.id = cc.destination_payment_id
        JOIN orders destination_order ON destination_order.id = destination_payment.order_id
        WHERE source_payment.order_id IN ($marks) AND source_payment.status = 'succeeded'
          AND destination_payment.status = 'succeeded' AND LOWER(TRIM(destination_order.status)) NOT IN ('void','voided','cancelled')");
    $credits->execute($orderIds);
    foreach ($credits->fetchAll(PDO::FETCH_ASSOC) as $credit) {
        $id = (int) $credit['source_payment_id'];
        $used[$id] = ($used[$id] ?? 0) + (int) round((float) $credit['amount'] * 100);
        if (!in_array((int) $credit['destination_order_id'], $orderIds, true)) {
            $usedElsewhere[$id] = ($usedElsewhere[$id] ?? 0) + (int) round((float) $credit['amount'] * 100);
        }
    }
    $paid = $cash = $change = $changeApplied = $changeElsewhere = 0;
    foreach ($payments as $payment) {
        $paid += (int) round((float) $payment['amount'] * 100);
        if ($payment['provider'] !== 'manual') continue;
        $summary['has_cash'] = true;
        $details = payment_cash_details($payment);
        if (!$details) {
            $summary['cash_complete'] = false;
            continue;
        }
        $cash += (int) round($details['tendered'] * 100);
        $originalChange = (int) round($details['change'] * 100);
        $appliedChange = min($originalChange, $used[(int) $payment['id']] ?? 0);
        $elsewhere = min($appliedChange, $usedElsewhere[(int) $payment['id']] ?? 0);
        $changeElsewhere += $elsewhere;
        $changeApplied += $appliedChange - $elsewhere;
        $change += $originalChange - $appliedChange;
    }
    $summary['paid'] = $paid / 100;
    $summary['cash_received'] = $cash / 100;
    $summary['change'] = $change / 100;
    $summary['change_applied'] = $changeApplied / 100;
    $summary['change_applied_elsewhere'] = $changeElsewhere / 100;
    return $summary;
}
