<?php
// Exercise the installed database triggers; all test rows and sequences roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/transaction_numbers.php';
$pdo = db();
$checks = 0;
function verify_number($condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: $label\n";
}
try {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO orders (customer_name, total, status) VALUES ('NUMBER TEST - ROLLBACK', 100, 'pending')");
    $id = (int) $pdo->lastInsertId();
    $read = $pdo->prepare('SELECT transaction_no, sales_invoice_no FROM orders WHERE id = ?');
    $read->execute([$id]);
    $order = $read->fetch();
    verify_number((bool) preg_match('/^TRX-\d{8}-\d{4,}$/', $order['transaction_no']) && $order['sales_invoice_no'] === null, 'Saved pending orders have a stored transaction number and no invoice');
    verify_number(transaction_issue_invoice($pdo, $id) === null, 'Unpaid orders do not consume an invoice number');
    $pay = $pdo->prepare("INSERT INTO payments (order_id, provider, amount, currency, status) VALUES (?, 'manual', ?, 'php', 'succeeded')");
    $pay->execute([$id, 40]);
    verify_number(transaction_issue_invoice($pdo, $id) === null, 'Partial payments do not issue an invoice');
    $pdo->exec('SAVEPOINT before_final_payment');
    $pay->execute([$id, 60]);
    $first = transaction_issue_invoice($pdo, $id);
    verify_number((bool) preg_match('/^SI-\d{8,}$/', $first), 'Full payment issues a separate sales invoice');
    verify_number(transaction_issue_invoice($pdo, $id) === $first, 'Duplicate confirmation reuses the invoice');
    $other = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET innodb_lock_wait_timeout = 1');
    $other->beginTransaction();
    $serialized = false;
    try {
        $other->exec("UPDATE document_sequences SET last_number = last_number + 1 WHERE sequence_key = 'sales_invoice'");
    } catch (PDOException $e) {
        $serialized = (int) ($e->errorInfo[1] ?? 0) === 1205;
    } finally {
        if ($other->inTransaction()) $other->rollBack();
    }
    verify_number($serialized, 'A simultaneous connection cannot allocate from the locked invoice counter');
    $pdo->exec('ROLLBACK TO SAVEPOINT before_final_payment');
    $pay->execute([$id, 60]);
    verify_number(transaction_issue_invoice($pdo, $id) === $first, 'Rolled-back issuance does not leave an invoice sequence gap');
    $pdo->prepare("UPDATE orders SET status = 'void', voided_at = NOW() WHERE id = ?")->execute([$id]);
    $read->execute([$id]);
    $voided = $read->fetch();
    verify_number($voided['transaction_no'] === $order['transaction_no'] && $voided['sales_invoice_no'] === $first, 'Voids retain both permanent numbers');
    $blocked = false;
    try { $pdo->prepare('UPDATE orders SET sales_invoice_no = NULL WHERE id = ?')->execute([$id]); } catch (PDOException $e) { $blocked = true; }
    verify_number($blocked, 'Database prevents erasing an issued invoice');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM transaction_events WHERE order_id = ?');
    $stmt->execute([$id]);
    verify_number((int) $stmt->fetchColumn() >= 5, 'Order creation, payments, invoice issuance and void are audited');
    $pdo->exec("INSERT INTO orders (customer_name,total,status) VALUES ('CANCEL TEST - ROLLBACK', 50, 'pending')");
    $cancelId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")->execute([$cancelId]);
    verify_number(transaction_issue_invoice($pdo, $cancelId) === null, 'Cancelled unpaid orders never receive invoices');
    $pdo->rollBack();
    echo "All $checks numbering checks passed. Test records and sequence changes rolled back.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
