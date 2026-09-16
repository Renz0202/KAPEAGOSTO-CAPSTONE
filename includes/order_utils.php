<?php
// Centralized Order Code utilities
// Ensures a consistent daily-reset order code across the whole app.

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
 * Convenience: get the displayable label like "Order#<code>" for an order id.
 */
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
