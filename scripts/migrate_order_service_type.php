<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/includes/db.php';
$pdo = db();
if (!$pdo->query("SHOW COLUMNS FROM orders LIKE 'service_type'")->fetch()) {
    $pdo->exec("ALTER TABLE orders ADD COLUMN service_type ENUM('dine_in','take_out') NULL DEFAULT NULL");
}
if (!$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'kape_service_type_event'")->fetchColumn()) {
    $pdo->exec("CREATE TRIGGER kape_service_type_event AFTER UPDATE ON orders FOR EACH ROW BEGIN
        IF NOT (OLD.service_type <=> NEW.service_type) THEN
            INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json)
            VALUES (NEW.id, @kape_actor_id, 'service_type_changed', JSON_OBJECT('old_type', OLD.service_type, 'service_type', NEW.service_type));
        END IF;
    END");
}
echo "Order service type installed. Historical orders remain unrecorded.\n";
