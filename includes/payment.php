<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/payment_cash.php';
require_once __DIR__ . '/transaction_numbers.php';

/**
 * Ensure the payments table exists; create it if missing (idempotent).
 */
function payment_ensure_schema(PDO $pdo): void {
    // Create a minimal table compatible with most MySQL versions; raw_response as LONGTEXT for compatibility.
    $sql = "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        provider VARCHAR(50) NOT NULL DEFAULT 'stripe',
        session_id VARCHAR(255) DEFAULT NULL,
        provider_payment_id VARCHAR(255) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        currency VARCHAR(10) NOT NULL DEFAULT 'php',
        status ENUM('created','pending','succeeded','failed','cancelled','expired') NOT NULL DEFAULT 'created',
        receipt_url VARCHAR(255) DEFAULT NULL,
        raw_response LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        paid_at DATETIME NULL DEFAULT NULL,
        UNIQUE KEY uniq_session_id (session_id),
        KEY idx_payments_order (order_id),
        CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // Silently ignore here; subsequent queries may still provide clearer error messages
    }

    $creditSql = "CREATE TABLE IF NOT EXISTS payment_change_credits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_payment_id INT NOT NULL,
        destination_payment_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_destination_payment (destination_payment_id),
        KEY idx_change_credit_source (source_payment_id),
        CONSTRAINT fk_change_credit_source FOREIGN KEY (source_payment_id) REFERENCES payments(id) ON DELETE CASCADE,
        CONSTRAINT fk_change_credit_destination FOREIGN KEY (destination_payment_id) REFERENCES payments(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    try {
        $pdo->exec($creditSql);
    } catch (Throwable $e) {
        // Subsequent change-credit queries will provide the actionable error.
    }
}

function payment_total_paid(PDO $pdo, int $orderId): float {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status = 'succeeded'");
        $stmt->execute([$orderId]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        payment_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE order_id = ? AND status = 'succeeded'");
        $stmt->execute([$orderId]);
        return (float)$stmt->fetchColumn();
    }
}

function payment_is_order_paid(PDO $pdo, int $orderId): bool {
    // Order considered paid when total successful payments >= order total
    $totalPaid = payment_total_paid($pdo, $orderId);
    $total = 0.0;
    try {
        $s = $pdo->prepare('SELECT total FROM orders WHERE id = ?');
        $s->execute([$orderId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $total = (float)$row['total'];
    } catch (Throwable $e) {}
    return $totalPaid >= $total && $total > 0;
}

function payment_latest_for_order(PDO $pdo, int $orderId) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        payment_ensure_schema($pdo);
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$orderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

/**
 * Return the unspent cash change from a completed source order, grouped by payment.
 * Change credits attached to voided or cancelled destination orders are released.
 */
function payment_change_credit_sources(PDO $pdo, int $sourceOrderId, bool $lock = false, string $requiredStatus = 'completed'): array {
    if ($sourceOrderId <= 0) return [];

    $orderSql = 'SELECT status FROM orders WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : '');
    $orderStmt = $pdo->prepare($orderSql);
    $orderStmt->execute([$sourceOrderId]);
    $sourceStatus = strtolower(trim((string) $orderStmt->fetchColumn()));
    if ($sourceStatus !== strtolower(trim($requiredStatus))) return [];

    $paymentSql = "SELECT id, raw_response FROM payments WHERE order_id = ? AND provider = 'manual' AND status = 'succeeded' ORDER BY id ASC" . ($lock ? ' FOR UPDATE' : '');
    $paymentStmt = $pdo->prepare($paymentSql);
    $paymentStmt->execute([$sourceOrderId]);
    $sources = [];

    $usedSql = "
        SELECT cc.amount, credited_payment.status AS payment_status, destination_order.status AS order_status
        FROM payment_change_credits cc
        JOIN payments credited_payment ON credited_payment.id = cc.destination_payment_id
        JOIN orders destination_order ON destination_order.id = credited_payment.order_id
        WHERE cc.source_payment_id = ?
    ";
    if ($lock) $usedSql .= ' FOR UPDATE';
    $usedStmt = $pdo->prepare($usedSql);

    foreach ($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $details = json_decode((string) ($payment['raw_response'] ?? ''), true);
        $change = is_array($details) ? max(0, (float) ($details['change'] ?? 0)) : 0.0;
        if ($change <= 0) continue;

        $usedStmt->execute([(int) $payment['id']]);
        $used = 0.0;
        foreach ($usedStmt->fetchAll(PDO::FETCH_ASSOC) as $credit) {
            $creditOrderStatus = strtolower(trim((string) ($credit['order_status'] ?? '')));
            if (($credit['payment_status'] ?? '') === 'succeeded' && !in_array($creditOrderStatus, ['void', 'voided', 'cancelled'], true)) {
                $used += (float) $credit['amount'];
            }
        }
        $available = round(max(0, $change - $used), 2);
        if ($available > 0) {
            $sources[] = ['payment_id' => (int) $payment['id'], 'amount' => $available];
        }
    }

    return $sources;
}

function payment_change_credit_available(PDO $pdo, int $sourceOrderId): float {
    $total = 0.0;
    foreach (payment_change_credit_sources($pdo, $sourceOrderId) as $source) {
        $total += (float) $source['amount'];
    }
    return round($total, 2);
}

/** Apply change due from earlier cash tenders to newly added items on the same pending order. */
function payment_apply_pending_order_change_credit(PDO $pdo, int $orderId): float {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Change credit must be applied inside an order transaction.');
    }
    if ($orderId <= 0) return 0.0;

    $orderStmt = $pdo->prepare('SELECT status, total FROM orders WHERE id = ? LIMIT 1 FOR UPDATE');
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || strtolower(trim((string) $order['status'])) !== 'pending') return 0.0;

    $remainingTotal = round(max(0, (float) $order['total'] - payment_total_paid($pdo, $orderId)), 2);
    if ($remainingTotal <= 0) return 0.0;

    $sourceOrderIds = payment_change_credit_source_orders_for_order($pdo, $orderId);
    $sourceOrderIds[] = $orderId;
    $sourceOrderIds = array_values(array_unique(array_map('intval', $sourceOrderIds)));
    sort($sourceOrderIds, SORT_NUMERIC);

    $sources = [];
    foreach ($sourceOrderIds as $sourceOrderId) {
        $requiredStatus = $sourceOrderId === $orderId ? 'pending' : 'completed';
        foreach (payment_change_credit_sources($pdo, $sourceOrderId, true, $requiredStatus) as $source) {
            $source['source_order_id'] = $sourceOrderId;
            $sources[] = $source;
        }
    }
    if (empty($sources)) return 0.0;

    $appliedTotal = 0.0;
    $currency = defined('PAYMENT_CURRENCY') ? PAYMENT_CURRENCY : 'php';
    foreach ($sources as $source) {
        if ($remainingTotal <= 0) break;
        $amount = round(min((float) $source['amount'], $remainingTotal), 2);
        if ($amount <= 0) continue;

        $raw = json_encode([
            'method' => 'change_credit',
            'source_order_id' => (int) ($source['source_order_id'] ?? $orderId),
            'source_payment_id' => (int) $source['payment_id'],
            'tendered' => 0,
            'change' => 0,
        ]);
        $insertPayment = $pdo->prepare("INSERT INTO payments (order_id, provider, amount, currency, status, raw_response, paid_at) VALUES (?, 'change_credit', ?, ?, 'succeeded', ?, NOW())");
        $insertPayment->execute([$orderId, $amount, $currency, $raw]);
        $destinationPaymentId = (int) $pdo->lastInsertId();

        $insertCredit = $pdo->prepare('INSERT INTO payment_change_credits (source_payment_id, destination_payment_id, amount) VALUES (?, ?, ?)');
        $insertCredit->execute([(int) $source['payment_id'], $destinationPaymentId, $amount]);

        $appliedTotal += $amount;
        $remainingTotal = round(max(0, $remainingTotal - $amount), 2);
    }

    transaction_issue_invoice($pdo, $orderId);
    return round($appliedTotal, 2);
}

/** Return this order and its completed source orders connected by applied change credit. */
function payment_related_order_ids(PDO $pdo, int $orderId): array {
    if ($orderId <= 0) return [];

    $related = [$orderId => true];
    $frontier = [$orderId];
    $stmt = $pdo->prepare("
        SELECT DISTINCT source_order.id
        FROM payment_change_credits cc
        JOIN payments source_payment ON source_payment.id = cc.source_payment_id
        JOIN orders source_order ON source_order.id = source_payment.order_id
        JOIN payments credited_payment ON credited_payment.id = cc.destination_payment_id
        JOIN orders destination_order ON destination_order.id = credited_payment.order_id
        WHERE destination_order.id = ?
          AND source_payment.status = 'succeeded'
          AND credited_payment.status = 'succeeded'
          AND LOWER(TRIM(source_order.status)) = 'completed'
          AND LOWER(TRIM(destination_order.status)) NOT IN ('void', 'voided', 'cancelled')
    " );

    while (!empty($frontier)) {
        $currentId = array_shift($frontier);
        $stmt->execute([(int) $currentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sourceOrderId) {
            $sourceOrderId = (int) $sourceOrderId;
            if ($sourceOrderId > 0 && !isset($related[$sourceOrderId])) {
                $related[$sourceOrderId] = true;
                $frontier[] = $sourceOrderId;
            }
        }
    }

    $orderIds = array_map('intval', array_keys($related));
    sort($orderIds, SORT_NUMERIC);
    return $orderIds;
}

/** Find unused change linked to this completed order or change previously carried into it. */
function payment_change_credit_source_orders_for_order(PDO $pdo, int $orderId): array {
    if ($orderId <= 0) return [];
    $orderIds = [$orderId];
    $stmt = $pdo->prepare("
        SELECT DISTINCT source_payment.order_id
        FROM payment_change_credits cc
        JOIN payments source_payment ON source_payment.id = cc.source_payment_id
        JOIN payments credited_payment ON credited_payment.id = cc.destination_payment_id
        WHERE credited_payment.order_id = ?
          AND credited_payment.status = 'succeeded'
    " );
    $stmt->execute([$orderId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sourceOrderId) {
        $orderIds[] = (int) $sourceOrderId;
    }

    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn(int $id): bool => $id > 0)));
    $availableOrderIds = [];
    foreach ($orderIds as $sourceOrderId) {
        if (payment_change_credit_available($pdo, $sourceOrderId) > 0) {
            $availableOrderIds[] = $sourceOrderId;
        }
    }
    sort($availableOrderIds, SORT_NUMERIC);
    return $availableOrderIds;
}

/** Apply available change from completed orders to a new order. Call inside a transaction. */
function payment_apply_change_credit(PDO $pdo, $sourceOrderIds, int $destinationOrderId, float $destinationTotal): float {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Change credit must be applied inside an order transaction.');
    }
    if (!is_array($sourceOrderIds)) $sourceOrderIds = [$sourceOrderIds];
    $sourceOrderIds = array_values(array_unique(array_filter(array_map('intval', $sourceOrderIds), static fn(int $id): bool => $id > 0)));
    sort($sourceOrderIds, SORT_NUMERIC);
    if (empty($sourceOrderIds) || $destinationOrderId <= 0 || in_array($destinationOrderId, $sourceOrderIds, true)) {
        throw new RuntimeException('Invalid change credit source.');
    }

    $sources = [];
    foreach ($sourceOrderIds as $sourceOrderId) {
        foreach (payment_change_credit_sources($pdo, $sourceOrderId, true) as $source) {
            $source['source_order_id'] = $sourceOrderId;
            $sources[] = $source;
        }
    }

    $remainingTotal = max(0, round($destinationTotal, 2));
    $appliedTotal = 0.0;
    $currency = defined('PAYMENT_CURRENCY') ? PAYMENT_CURRENCY : 'php';

    foreach ($sources as $source) {
        if ($remainingTotal <= 0) break;
        $amount = round(min((float) $source['amount'], $remainingTotal), 2);
        if ($amount <= 0) continue;

        $raw = json_encode([
            'method' => 'change_credit',
            'source_order_id' => (int) $source['source_order_id'],
            'source_payment_id' => (int) $source['payment_id'],
            'tendered' => 0,
            'change' => 0,
        ]);
        $insertPayment = $pdo->prepare("INSERT INTO payments (order_id, provider, amount, currency, status, raw_response, paid_at) VALUES (?, 'change_credit', ?, ?, 'succeeded', ?, NOW())");
        $insertPayment->execute([$destinationOrderId, $amount, $currency, $raw]);
        $destinationPaymentId = (int) $pdo->lastInsertId();

        $insertCredit = $pdo->prepare('INSERT INTO payment_change_credits (source_payment_id, destination_payment_id, amount) VALUES (?, ?, ?)');
        $insertCredit->execute([(int) $source['payment_id'], $destinationPaymentId, $amount]);

        $appliedTotal += $amount;
        $remainingTotal = round(max(0, $remainingTotal - $amount), 2);
    }

    if ($appliedTotal <= 0) {
        throw new RuntimeException('No unused change is available from the original order.');
    }

    transaction_issue_invoice($pdo, $destinationOrderId);
    return round($appliedTotal, 2);
}

function payment_require_stripe(): void {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) {
        http_response_code(500);
        die('Stripe SDK not installed. Please run: composer install');
    }
    require_once $autoload;
    if (!class_exists('Stripe\\StripeClient')) {
        http_response_code(500);
        die('Stripe SDK not available. Ensure composer installed stripe/stripe-php.');
    }
}

function payment_list_for_order(PDO $pdo, int $orderId): array {
    try {
        $stmt = $pdo->prepare('SELECT id, provider, amount, currency, status, receipt_url, raw_response, created_at FROM payments WHERE order_id = ? ORDER BY id ASC');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        payment_ensure_schema($pdo);
        $stmt = $pdo->prepare('SELECT id, provider, amount, currency, status, receipt_url, raw_response, created_at FROM payments WHERE order_id = ? ORDER BY id ASC');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

/**
 * Lightweight readiness check for UI (do not die here).
 */
function payment_gateway_ready(): bool {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    // try require_once safely
    try {
        require_once $autoload;
    } catch (Throwable $e) {
        return false;
    }
    return class_exists('Stripe\\StripeClient');
}
