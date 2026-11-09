<?php
// Centralized Order Code utilities
// Ensures a consistent daily-reset order code across the whole app.
function order_service_type_label(?string $type): string {
    return ['dine_in' => 'Dine-in', 'take_out' => 'Take-out'][$type ?? ''] ?? 'Not recorded';
}
function order_customer_display(?string $name, ?string $type): string {
    $name = trim((string) $name);
    $anonymous = in_array(strtolower($name), ['', 'walk-in', 'walk in', 'walkin', 'walk-in customer', 'guest', '--'], true);
    if (!$anonymous) return $name;
    return in_array($type, ['dine_in', 'take_out'], true) ? order_service_type_label($type) : 'Walk-in';
}
function order_preparation_label(?string $status): string {
    return ['queued' => 'Queued', 'in_progress' => 'Preparing', 'ready' => 'Ready', 'completed' => 'Completed', 'cancelled' => 'Cancelled'][$status ?? ''] ?? 'Queued';
}

require_once __DIR__ . '/db.php';

/**
 * Generate the order code for a specific order based on its creation date and id rank for that day.
 * Format: ddmmyy-NN (e.g., 031125-01)
 * - Resets daily because the prefix uses the date
 * - Sequence NN is computed by counting orders created the same day with id <= current id
 */
function generate_order_code_for_order(PDO $pdo, int $orderId): ?string {
    if ($orderId <= 0) return null;
    $stmt = $pdo->prepare("SELECT created_at FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $createdAt = $stmt->fetchColumn();
    if (!$createdAt) return null;

    $datePart = date('dmy', strtotime($createdAt));
    $rankStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = DATE(?) AND id <= ?");
    $rankStmt->execute([$createdAt, $orderId]);
    $count = (int)$rankStmt->fetchColumn();
    return $datePart . '-' . str_pad((string)$count, 2, '0', STR_PAD_LEFT);
}

/**
 * Ensure an order has an order_code set. If missing, compute and persist it.
 * Returns the order_code value (existing or newly computed).
 */
function ensure_order_code(PDO $pdo, int $orderId): ?string {
    if ($orderId <= 0) return null;
    $check = $pdo->prepare('SELECT order_code FROM orders WHERE id = ?');
    $check->execute([$orderId]);
    $existing = $check->fetchColumn();
    if ($existing && trim((string)$existing) !== '') return $existing;

    $code = generate_order_code_for_order($pdo, $orderId);
    if ($code) {
        $upd = $pdo->prepare('UPDATE orders SET order_code = ? WHERE id = ?');
        $upd->execute([$code, $orderId]);
    }
    return $code;
}

/**
 * Read the permanent transaction number stored with the order.
 */
function order_transaction_number(int $orderId, ?string $storedNumber = null): string {
    if ($orderId <= 0) {
        throw new InvalidArgumentException('A saved order is required for a transaction number.');
    }
    if ($storedNumber) return $storedNumber;
    $stmt = db()->prepare('SELECT transaction_no FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $number = $stmt->fetchColumn();
    if (!$number) throw new RuntimeException('Transaction number not found. Run the transaction-number migration.');
    return (string) $number;
}

/** Convenience: get the displayable label like "Order#<code>" for an order id. */
function get_order_label(PDO $pdo, int $orderId): string {
    $code = ensure_order_code($pdo, $orderId);
    return $code ? ('Order#' . $code) : ('Order#' . $orderId);
}

/**
 * Format the account shown on generated transactions.
 * Example: "renz" + "staff" becomes "Renz (Staff)".
 */
function order_generated_by_label(?string $username, ?string $role = null): string {
    $username = trim((string) $username);
    if ($username === '') {
        return 'Unassigned';
    }

    $displayUsername = preg_replace_callback(
        '/(^|[\s._-])([a-z])/i',
        static fn(array $match): string => $match[1] . strtoupper($match[2]),
        strtolower($username)
    );
    $displayUsername = $displayUsername !== null ? $displayUsername : ucfirst(strtolower($username));

    $role = trim((string) $role);
    $displayRole = $role !== '' ? ucwords(strtolower($role)) : 'Unknown';

    return $displayUsername . ' (' . $displayRole . ')';
}

?>
