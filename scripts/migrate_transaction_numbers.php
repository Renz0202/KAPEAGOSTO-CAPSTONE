<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
$pdo = db();
// Additive migration: historical invoice numbers are intentionally not invented.
foreach (['transaction_no' => 'VARCHAR(40) NULL', 'sales_invoice_no' => 'VARCHAR(40) NULL', 'invoiced_at' => 'DATETIME NULL'] as $column => $definition) {
    if (!$pdo->query("SHOW COLUMNS FROM orders LIKE '$column'")->fetch()) $pdo->exec("ALTER TABLE orders ADD COLUMN $column $definition");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS document_sequences (sequence_key VARCHAR(50) PRIMARY KEY, last_number BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB");
$pdo->exec("INSERT IGNORE INTO document_sequences VALUES ('sales_invoice', 0)");
$pdo->exec("UPDATE orders SET transaction_no = CONCAT('TRX-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, GREATEST(4, CHAR_LENGTH(id)), '0')) WHERE transaction_no IS NULL");
$pdo->exec("INSERT INTO document_sequences (sequence_key, last_number) SELECT CONCAT('transaction:', DATE_FORMAT(created_at, '%Y%m%d')), MAX(id) FROM orders GROUP BY DATE_FORMAT(created_at, '%Y%m%d') ON DUPLICATE KEY UPDATE last_number = GREATEST(last_number, VALUES(last_number))");
foreach (['uniq_transaction_no' => 'transaction_no', 'uniq_sales_invoice_no' => 'sales_invoice_no'] as $key => $column) {
    if (!$pdo->query("SHOW INDEX FROM orders WHERE Key_name = '$key'")->fetch()) $pdo->exec("ALTER TABLE orders ADD UNIQUE KEY $key ($column)");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS transaction_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL,
    actor_user_id INT NULL, event_type VARCHAR(50) NOT NULL, details_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY idx_transaction_event_order (order_id, id)
) ENGINE=InnoDB");
$triggers = [
    'kape_transaction_create' => "BEFORE INSERT ON orders FOR EACH ROW BEGIN
        DECLARE seq BIGINT;
        DECLARE sequence_name VARCHAR(50);
        SET sequence_name = CONCAT('transaction:', DATE_FORMAT(COALESCE(NEW.created_at, NOW()), '%Y%m%d'));
        INSERT INTO document_sequences VALUES (sequence_name, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1;
        SELECT last_number INTO seq FROM document_sequences WHERE sequence_key = sequence_name;
        SET NEW.transaction_no = CONCAT('TRX-', DATE_FORMAT(COALESCE(NEW.created_at, NOW()), '%Y%m%d'), '-', LPAD(seq, GREATEST(4, CHAR_LENGTH(seq)), '0'));
        SET NEW.sales_invoice_no = NULL;
        SET NEW.invoiced_at = NULL;
    END",
    'kape_transaction_protect' => "BEFORE UPDATE ON orders FOR EACH ROW BEGIN
        IF NOT (OLD.transaction_no <=> NEW.transaction_no) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction number is permanent.'; END IF;
        IF OLD.sales_invoice_no IS NOT NULL AND (NOT (OLD.sales_invoice_no <=> NEW.sales_invoice_no) OR NOT (OLD.invoiced_at <=> NEW.invoiced_at) OR OLD.total <> NEW.total) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An invoiced sale cannot be renumbered or have its total changed. Create an additional order.';
        END IF;
    END",
    'kape_transaction_insert_event' => "AFTER INSERT ON orders FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.id, @kape_actor_id, 'order_created', JSON_OBJECT('transaction_no', NEW.transaction_no, 'total', NEW.total, 'status', NEW.status))",
    'kape_transaction_update_event' => "AFTER UPDATE ON orders FOR EACH ROW BEGIN
        IF NOT (OLD.status <=> NEW.status) OR OLD.total <> NEW.total OR NOT (OLD.sales_invoice_no <=> NEW.sales_invoice_no) OR NOT (OLD.customer_name <=> NEW.customer_name) OR NOT (OLD.job_status <=> NEW.job_status) THEN
            INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.id, @kape_actor_id, 'order_updated', JSON_OBJECT('old_status', OLD.status, 'status', NEW.status, 'old_total', OLD.total, 'total', NEW.total, 'sales_invoice_no', NEW.sales_invoice_no, 'job_status', NEW.job_status));
        END IF;
    END",
    'kape_transaction_delete_protect' => "BEFORE DELETE ON orders FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain transactions for audit. Cancel or void the order instead.'",
];
foreach (['INSERT' => 'NEW', 'UPDATE' => 'NEW', 'DELETE' => 'OLD'] as $operation => $row) {
    foreach (['order_items' => 'item', 'payments' => 'payment'] as $table => $kind) {
        $details = $kind === 'item' ? "JSON_OBJECT('item_id', $row.item_id, 'qty', $row.qty, 'price', $row.price)" : "JSON_OBJECT('payment_id', $row.id, 'provider', $row.provider, 'amount', $row.amount, 'status', $row.status)";
        $name = 'kape_event_' . $kind . '_' . strtolower($operation);
        $triggers[$name] = "AFTER $operation ON $table FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES ($row.order_id, @kape_actor_id, '" . $kind . '_' . strtolower($operation) . "', $details)";
    }
}
foreach ($triggers as $name => $body) {
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
    $exists->execute([$name]);
    if (!$exists->fetchColumn()) $pdo->exec("CREATE TRIGGER $name $body");
}
echo "Transaction numbers, invoice sequence, and saved-order audit triggers installed. Historical invoices were not generated.\n";
