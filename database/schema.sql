-- Kape Agosto schema-only export from the supplied database update.
-- Contains table definitions, constraints, and triggers; excludes all records and credentials.
CREATE DATABASE IF NOT EXISTS `kape_agosto` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kape_agosto`;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `customer_code` varchar(32) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daily_reports` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `total_sales` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_orders` int(11) NOT NULL DEFAULT 0,
  `total_sold` int(11) DEFAULT 0,
  `total_damaged` int(11) DEFAULT 0,
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daily_sales_items` (
  `id` int(11) NOT NULL,
  `report_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `qty` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daily_sales_reports` (
  `id` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `total_sales` decimal(10,2) DEFAULT 0.00,
  `total_orders` int(11) DEFAULT 0,
  `sales_tax` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_sequences` (
  `sequence_key` varchar(50) NOT NULL,
  `last_number` bigint(20) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `forecast_snapshots` (
  `id` int(11) NOT NULL,
  `forecast_date` date NOT NULL,
  `metric_type` enum('orders','revenue','item_demand') NOT NULL,
  `subject_key` varchar(80) NOT NULL DEFAULT 'overall',
  `item_id` int(11) DEFAULT NULL,
  `predicted_value` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `actual_value` decimal(14,4) DEFAULT NULL,
  `accuracy_percent` decimal(6,2) DEFAULT NULL,
  `method` varchar(80) NOT NULL DEFAULT 'weighted_moving_average',
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `evaluated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `sku` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `material_type` enum('raw_material','packaging','other') NOT NULL DEFAULT 'raw_material',
  `material_group` varchar(40) DEFAULT NULL,
  `cup_size` varchar(50) DEFAULT NULL,
  `unit` varchar(50) DEFAULT 'pcs',
  `qty` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit_cost` decimal(10,2) DEFAULT NULL,
  `unit_cost_source` enum('estimate','verified') DEFAULT NULL,
  `critical_level` decimal(10,2) DEFAULT NULL,
  `safety_stock` decimal(10,2) NOT NULL DEFAULT 0.00,
  `lead_time_days` int(11) NOT NULL DEFAULT 1,
  `target_stock` decimal(10,2) DEFAULT NULL,
  `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_logs` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `change_type` enum('added','restock','used','deduct','auto-deduct','damage','spoilage','adjustment') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `remaining` decimal(10,2) NOT NULL DEFAULT 0.00,
  `loss_unit_cost` decimal(10,2) DEFAULT NULL,
  `loss_value` decimal(18,2) DEFAULT NULL,
  `loss_cost_source` enum('estimate','verified') DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `sku` varchar(64) NOT NULL,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `cup_size` enum('12oz Hot','16oz','22oz') DEFAULT '16oz',
  `price` decimal(10,2) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `item_recipe_metadata` (
  `item_id` int(11) NOT NULL,
  `source` enum('generated','custom') NOT NULL DEFAULT 'custom',
  `notes` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `item_requirements` (
  `id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `quantity_needed` decimal(10,2) NOT NULL DEFAULT 1.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notification_type` varchar(50) NOT NULL DEFAULT 'general',
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `dedupe_key` varchar(150) DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_code` varchar(50) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `service_details` text DEFAULT NULL,
  `job_status` enum('queued','in_progress','ready','completed','cancelled') NOT NULL DEFAULT 'queued',
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_type` enum('none','promo','senior','pwd') NOT NULL DEFAULT 'none',
  `discount_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_exempt_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_beneficiary_type` enum('senior','pwd') DEFAULT NULL,
  `discount_customer_name` varchar(100) DEFAULT NULL,
  `discount_customer_tin` varchar(32) DEFAULT NULL,
  `discount_id_number` varchar(64) DEFAULT NULL,
  `discount_applied_by` int(11) DEFAULT NULL,
  `exclusive_use_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `promo_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','completed','cancelled','void','voided') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `voided_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `inventory_deducted_at` datetime DEFAULT NULL,
  `transaction_no` varchar(40) DEFAULT NULL,
  `sales_invoice_no` varchar(40) DEFAULT NULL,
  `invoiced_at` datetime DEFAULT NULL,
  `service_type` enum('dine_in','take_out') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) GENERATED ALWAYS AS (`qty` * `price`) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `selector` varchar(32) DEFAULT NULL,
  `verifier_hash` varchar(255) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `request_ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `method` varchar(30) NOT NULL DEFAULT 'link'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_events` (
  `id` int(11) NOT NULL,
  `event_type` varchar(40) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `token` varchar(64) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `provider` varchar(50) NOT NULL DEFAULT 'stripe',
  `session_id` varchar(255) DEFAULT NULL,
  `provider_payment_id` varchar(255) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'php',
  `status` enum('created','pending','succeeded','failed','cancelled','expired') NOT NULL DEFAULT 'created',
  `receipt_url` varchar(255) DEFAULT NULL,
  `raw_response` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_response`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_change_credits` (
  `id` int(11) NOT NULL,
  `source_payment_id` int(11) NOT NULL,
  `destination_payment_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transaction_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `order_id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `details_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','inactive','disabled') NOT NULL DEFAULT 'active',
  `role_id` int(11) NOT NULL DEFAULT 2,
  `image` varchar(255) DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `reset_sent_at` datetime DEFAULT NULL,
  `reset_used` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_guide_progress` (
  `user_id` int(11) NOT NULL,
  `welcome_status` enum('completed','skipped','existing') NOT NULL,
  `guide_version` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_customers_code` (`customer_code`),
  ADD KEY `idx_customers_name` (`name`),
  ADD KEY `idx_customers_contact` (`contact`),
  ADD KEY `idx_customers_email` (`email`);

ALTER TABLE `daily_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_daily_reports_date` (`report_date`);

ALTER TABLE `daily_sales_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `report_id` (`report_id`);

ALTER TABLE `daily_sales_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `report_date` (`report_date`),
  ADD KEY `generated_by` (`generated_by`);

ALTER TABLE `document_sequences`
  ADD PRIMARY KEY (`sequence_key`);

ALTER TABLE `forecast_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_forecast_metric` (`forecast_date`,`metric_type`,`subject_key`),
  ADD KEY `idx_forecast_item` (`item_id`);

ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_inventory_sku` (`sku`),
  ADD KEY `idx_inventory_name_size` (`name`,`cup_size`),
  ADD KEY `idx_inventory_material_group` (`material_group`);

ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_logs_inv_date` (`inventory_id`,`created_at`),
  ADD KEY `idx_inventory_logs_order` (`order_id`),
  ADD KEY `fk_inventory_logs_user` (`user_id`);

ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_items_sku` (`sku`),
  ADD KEY `category_id` (`category_id`);

ALTER TABLE `item_recipe_metadata`
  ADD PRIMARY KEY (`item_id`);

ALTER TABLE `item_requirements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_item_inventory_requirement` (`item_id`,`inventory_id`),
  ADD KEY `idx_requirements_inventory` (`inventory_id`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_notifications_dedupe` (`dedupe_key`),
  ADD KEY `idx_notifications_user_read` (`user_id`,`is_read`,`created_at`);

ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_orders_order_code` (`order_code`),
  ADD UNIQUE KEY `uniq_transaction_no` (`transaction_no`),
  ADD UNIQUE KEY `uniq_sales_invoice_no` (`sales_invoice_no`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_orders_status_date` (`status`,`created_at`),
  ADD KEY `idx_orders_customer` (`customer_id`),
  ADD KEY `idx_orders_job_status` (`job_status`,`created_at`);

ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `item_id` (`item_id`);

ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_password_resets_token` (`token`),
  ADD KEY `idx_password_resets_user` (`user_id`),
  ADD KEY `idx_password_resets_expires` (`expires_at`);

ALTER TABLE `password_reset_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pre_events_type` (`event_type`),
  ADD KEY `idx_pre_events_user` (`user_id`),
  ADD KEY `idx_pre_events_token` (`token`);

ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_session_id` (`session_id`),
  ADD KEY `idx_payments_order` (`order_id`);

ALTER TABLE `payment_change_credits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_destination_payment` (`destination_payment_id`),
  ADD KEY `idx_change_credit_source` (`source_payment_id`);

ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `transaction_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transaction_event_order` (`order_id`,`id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_reset_token` (`reset_token`),
  ADD KEY `role_id` (`role_id`);

ALTER TABLE `user_guide_progress`
  ADD PRIMARY KEY (`user_id`);

ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `daily_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `daily_sales_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `daily_sales_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `forecast_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `item_requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `password_reset_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `payment_change_credits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `transaction_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `daily_sales_items`
  ADD CONSTRAINT `daily_sales_items_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `daily_sales_reports` (`id`) ON DELETE CASCADE;

ALTER TABLE `daily_sales_reports`
  ADD CONSTRAINT `daily_sales_reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `forecast_snapshots`
  ADD CONSTRAINT `fk_forecast_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `inventory_logs`
  ADD CONSTRAINT `fk_inventory_logs_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `inventory_logs_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;

ALTER TABLE `items`
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

ALTER TABLE `item_recipe_metadata`
  ADD CONSTRAINT `fk_recipe_metadata_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `item_requirements`
  ADD CONSTRAINT `fk_requirements_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_requirements_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE;

ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `password_reset_events`
  ADD CONSTRAINT `fk_pre_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

ALTER TABLE `payment_change_credits`
  ADD CONSTRAINT `fk_change_credit_destination` FOREIGN KEY (`destination_payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_change_credit_source` FOREIGN KEY (`source_payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

ALTER TABLE `user_guide_progress`
  ADD CONSTRAINT `fk_user_guide_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

DELIMITER $$
CREATE TRIGGER `kape_service_type_event` AFTER UPDATE ON `orders` FOR EACH ROW BEGIN
        IF NOT (OLD.service_type <=> NEW.service_type) THEN
            INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json)
            VALUES (NEW.id, @kape_actor_id, 'service_type_changed', JSON_OBJECT('old_type', OLD.service_type, 'service_type', NEW.service_type));
        END IF;
    END
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_transaction_create` BEFORE INSERT ON `orders` FOR EACH ROW BEGIN
        DECLARE seq BIGINT;
        DECLARE sequence_name VARCHAR(50);
        SET sequence_name = CONCAT('transaction:', DATE_FORMAT(COALESCE(NEW.created_at, NOW()), '%Y%m%d'));
        INSERT INTO document_sequences VALUES (sequence_name, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1;
        SELECT last_number INTO seq FROM document_sequences WHERE sequence_key = sequence_name;
        SET NEW.transaction_no = CONCAT('TRX-', DATE_FORMAT(COALESCE(NEW.created_at, NOW()), '%Y%m%d'), '-', LPAD(seq, GREATEST(4, CHAR_LENGTH(seq)), '0'));
        SET NEW.sales_invoice_no = NULL;
        SET NEW.invoiced_at = NULL;
    END
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_transaction_delete_protect` BEFORE DELETE ON `orders` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain transactions for audit. Cancel or void the order instead.'
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_transaction_insert_event` AFTER INSERT ON `orders` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.id, @kape_actor_id, 'order_created', JSON_OBJECT('transaction_no', NEW.transaction_no, 'total', NEW.total, 'status', NEW.status))
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_transaction_protect` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN
        IF NOT (OLD.transaction_no <=> NEW.transaction_no) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Transaction number is permanent.'; END IF;
        IF OLD.sales_invoice_no IS NOT NULL AND (NOT (OLD.sales_invoice_no <=> NEW.sales_invoice_no) OR NOT (OLD.invoiced_at <=> NEW.invoiced_at) OR OLD.total <> NEW.total) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An invoiced sale cannot be renumbered or have its total changed. Create an additional order.';
        END IF;
    END
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_transaction_update_event` AFTER UPDATE ON `orders` FOR EACH ROW BEGIN
        IF NOT (OLD.status <=> NEW.status) OR OLD.total <> NEW.total OR NOT (OLD.sales_invoice_no <=> NEW.sales_invoice_no) OR NOT (OLD.customer_name <=> NEW.customer_name) OR NOT (OLD.job_status <=> NEW.job_status) THEN
            INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.id, @kape_actor_id, 'order_updated', JSON_OBJECT('old_status', OLD.status, 'status', NEW.status, 'old_total', OLD.total, 'total', NEW.total, 'sales_invoice_no', NEW.sales_invoice_no, 'job_status', NEW.job_status));
        END IF;
    END
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_event_item_delete` AFTER DELETE ON `order_items` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (OLD.order_id, @kape_actor_id, 'item_delete', JSON_OBJECT('item_id', OLD.item_id, 'qty', OLD.qty, 'price', OLD.price))
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_event_item_insert` AFTER INSERT ON `order_items` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.order_id, @kape_actor_id, 'item_insert', JSON_OBJECT('item_id', NEW.item_id, 'qty', NEW.qty, 'price', NEW.price))
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_event_item_update` AFTER UPDATE ON `order_items` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.order_id, @kape_actor_id, 'item_update', JSON_OBJECT('item_id', NEW.item_id, 'qty', NEW.qty, 'price', NEW.price))
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_event_payment_delete` AFTER DELETE ON `payments` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (OLD.order_id, @kape_actor_id, 'payment_delete', JSON_OBJECT('payment_id', OLD.id, 'provider', OLD.provider, 'amount', OLD.amount, 'status', OLD.status))
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_event_payment_insert` AFTER INSERT ON `payments` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.order_id, @kape_actor_id, 'payment_insert', JSON_OBJECT('payment_id', NEW.id, 'provider', NEW.provider, 'amount', NEW.amount, 'status', NEW.status))
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `kape_event_payment_update` AFTER UPDATE ON `payments` FOR EACH ROW INSERT INTO transaction_events (order_id, actor_user_id, event_type, details_json) VALUES (NEW.order_id, @kape_actor_id, 'payment_update', JSON_OBJECT('payment_id', NEW.id, 'provider', NEW.provider, 'amount', NEW.amount, 'status', NEW.status))
$$
DELIMITER ;

SET FOREIGN_KEY_CHECKS=1;
