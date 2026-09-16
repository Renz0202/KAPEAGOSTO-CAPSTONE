<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../config/payment.php';

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
