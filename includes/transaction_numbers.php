<?php
/** Must run inside the same database transaction as the successful payment. */
function transaction_issue_invoice(PDO $pdo, int $orderId): ?string {
    if (!$pdo->inTransaction()) throw new LogicException('Invoice issuance requires a database transaction.');
    $stmt = $pdo->prepare('SELECT total, status, sales_invoice_no FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Order not found.');
    if ($order['sales_invoice_no']) return $order['sales_invoice_no'];
    if ($order['status'] !== 'pending' || (float) $order['total'] <= 0) return null;
    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE order_id = ? AND status = 'succeeded'");
    $paidStmt->execute([$orderId]);
    if ((int) round((float) $paidStmt->fetchColumn() * 100) < (int) round((float) $order['total'] * 100)) return null;
    // An ordinary transactional counter avoids AUTO_INCREMENT gaps after rollbacks.
    $pdo->exec("UPDATE document_sequences SET last_number = last_number + 1 WHERE sequence_key = 'sales_invoice'");
    $number = (int) $pdo->query("SELECT last_number FROM document_sequences WHERE sequence_key = 'sales_invoice'")->fetchColumn();
    if ($number <= 0) throw new RuntimeException('Invoice sequence is not configured.');
    $invoice = 'SI-' . str_pad((string) $number, 8, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE orders SET sales_invoice_no = ?, invoiced_at = NOW() WHERE id = ?')->execute([$invoice, $orderId]);
    return $invoice;
}

/** Record card confirmation idempotently, locking the parent order before payments. */
function transaction_confirm_card(PDO $pdo, string $sessionId, ?string $intent, ?string $receiptUrl, string $raw): ?int {
    $stmt = $pdo->prepare('SELECT order_id FROM payments WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    $orderId = (int) $stmt->fetchColumn();
    if (!$orderId) return null;
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM orders WHERE id = ? FOR UPDATE');
        $lock->execute([$orderId]);
        $pdo->prepare("UPDATE payments SET status = 'succeeded', provider_payment_id = ?, receipt_url = COALESCE(?, receipt_url), raw_response = ?, paid_at = COALESCE(paid_at, NOW()) WHERE session_id = ?")
            ->execute([$intent, $receiptUrl, $raw, $sessionId]);
        transaction_issue_invoice($pdo, $orderId);
        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
