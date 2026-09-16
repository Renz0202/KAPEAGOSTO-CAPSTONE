-- =========================
-- CREATE DATABASE
-- =========================
CREATE DATABASE IF NOT EXISTS kape_agosto CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kape_agosto;

-- =========================
-- ROLES
-- =========================
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO roles (name) VALUES ('admin'), ('cashier'), ('manager');

-- =========================
-- USERS
-- =========================
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) DEFAULT NULL,
  first_name VARCHAR(100) DEFAULT NULL,
  middle_name VARCHAR(100) DEFAULT NULL,
  email VARCHAR(100) UNIQUE NOT NULL,
  contact VARCHAR(20),
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive','disabled') NOT NULL DEFAULT 'active',
  role_id INT NOT NULL DEFAULT 2,
  image VARCHAR(255) DEFAULT NULL,
  registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reset_token VARCHAR(64) DEFAULT NULL,
  reset_expires DATETIME DEFAULT NULL,
  reset_sent_at DATETIME DEFAULT NULL,
  reset_used TINYINT(1) DEFAULT 0,
  INDEX idx_reset_token (reset_token),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- MENU CATEGORIES
-- =========================
CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) UNIQUE NOT NULL,
  image VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- FINISHED PRODUCTS (menu items)
-- =========================
CREATE TABLE IF NOT EXISTS items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(64) NOT NULL,
  category_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  cup_size ENUM('12oz Hot','16oz','22oz') DEFAULT '16oz',
  price DECIMAL(10,2) NOT NULL,
  image VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_items_sku (sku),
  INDEX idx_items_category (category_id),
  CONSTRAINT fk_items_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- CUSTOMERS
-- =========================
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_code VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(30) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_customers_code (customer_code),
  INDEX idx_customers_name (name),
  INDEX idx_customers_contact (contact),
  INDEX idx_customers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- ORDERS / JOB ORDERS
-- =========================
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_code VARCHAR(50) DEFAULT NULL,
  user_id INT NULL,
  customer_id INT NULL,
  customer_name VARCHAR(100),
  service_details TEXT DEFAULT NULL,
  job_status ENUM('queued','in_progress','ready','completed','cancelled') NOT NULL DEFAULT 'queued',
  total DECIMAL(10,2) NOT NULL DEFAULT 0,
  -- include 'voided' to match application queries; keep 'void' for compatibility
  status ENUM('pending','completed','cancelled','void','voided') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  voided_at DATETIME DEFAULT NULL,
  cancelled_at DATETIME DEFAULT NULL,
  inventory_deducted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_orders_order_code (order_code),
  INDEX idx_orders_user (user_id),
  INDEX idx_orders_customer (customer_id),
  INDEX idx_orders_job_status (job_status, created_at),
  INDEX idx_orders_status_date (status, created_at),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- ORDER ITEMS
-- =========================
CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  item_id INT NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL,
  subtotal DECIMAL(10,2) GENERATED ALWAYS AS (qty * price) STORED,
  INDEX idx_order_items_order (order_id),
  INDEX idx_order_items_item (item_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_order_items_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- INVENTORY (simplified)
-- =========================
CREATE TABLE IF NOT EXISTS inventory (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(64) NOT NULL,
  name VARCHAR(100) NOT NULL,
  cup_size VARCHAR(50) DEFAULT NULL,
  unit VARCHAR(50) DEFAULT 'pcs',
  qty DECIMAL(10,2) NOT NULL DEFAULT 0,
  critical_level DECIMAL(10,2) DEFAULT NULL,
  safety_stock DECIMAL(10,2) NOT NULL DEFAULT 0,
  lead_time_days INT NOT NULL DEFAULT 1,
  target_stock DECIMAL(10,2) DEFAULT NULL,
  date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_inventory_sku (sku),
  INDEX idx_inventory_name_size (name, cup_size)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- ITEM INVENTORY REQUIREMENTS
-- Defines the raw materials consumed by one menu item.
-- =========================
CREATE TABLE IF NOT EXISTS item_requirements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  inventory_id INT NOT NULL,
  quantity_needed DECIMAL(10,2) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_item_inventory_requirement (item_id, inventory_id),
  INDEX idx_requirements_inventory (inventory_id),
  CONSTRAINT fk_requirements_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_requirements_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- INVENTORY LOGS (record usage, restock, damage)
-- =========================
CREATE TABLE IF NOT EXISTS inventory_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  inventory_id INT NOT NULL,
  order_id INT DEFAULT NULL,
  user_id INT DEFAULT NULL,
  -- accept both new ('added','used') and legacy ('restock','deduct') values
  change_type ENUM('added','restock','used','deduct','auto-deduct','damage','adjustment') NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  remaining DECIMAL(10,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_inventory_logs_inv_date (inventory_id, created_at),
  INDEX idx_inventory_logs_order (order_id),
  CONSTRAINT fk_inventory_logs_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_inventory_logs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_inventory_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- DAILY REPORTS (simple summary)
-- =========================
CREATE TABLE IF NOT EXISTS daily_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_date DATE NOT NULL UNIQUE,
  total_sales DECIMAL(10,2) NOT NULL DEFAULT 0,
  total_orders INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- DETAILED DAILY SALES REPORTS
-- =========================
CREATE TABLE IF NOT EXISTS daily_sales_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_date DATE NOT NULL UNIQUE,
  generated_by INT NULL,
  total_sales DECIMAL(10,2) DEFAULT 0,
  total_orders INT DEFAULT 0,
  sales_tax DECIMAL(10,2) DEFAULT 0,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_daily_sales_generated_by (generated_by),
  CONSTRAINT fk_daily_sales_generated_by FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_sales_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  item_id INT NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  qty INT NOT NULL,
  subtotal DECIMAL(10,2) NOT NULL,
  INDEX idx_daily_sales_items_report (report_id),
  INDEX idx_daily_sales_items_item (item_id),
  CONSTRAINT fk_daily_sales_items_report FOREIGN KEY (report_id) REFERENCES daily_sales_reports(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- ADMIN ACCOUNT
-- =========================
INSERT IGNORE INTO users (username, full_name, last_name, first_name, middle_name, email, password_hash, status, role_id)
VALUES (
  'Admin',
  'Administrator, System',
  'Administrator',
  'System',
  NULL,
  'admin@kapeagosto.local',
  '$2y$10$OvnH7ixAsAzxS46FlUsVGO4B03H3fGCOZOKnZJTe8VTKPbUMnBOAO',
  -- Admin@123
  'active',
  1
);

-- =========================
-- PAYMENTS (for online payments)
-- =========================
CREATE TABLE IF NOT EXISTS payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  provider VARCHAR(50) NOT NULL DEFAULT 'stripe',
  session_id VARCHAR(255) DEFAULT NULL,
  provider_payment_id VARCHAR(255) DEFAULT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency VARCHAR(10) NOT NULL DEFAULT 'php',
  status ENUM('created','pending','succeeded','failed','cancelled','expired') NOT NULL DEFAULT 'created',
  receipt_url VARCHAR(255) DEFAULT NULL,
  raw_response JSON DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  paid_at DATETIME NULL DEFAULT NULL,
  UNIQUE KEY uniq_session_id (session_id),
  KEY idx_payments_order (order_id),
  CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- PASSWORD RESETS (forgot password flow)
-- =========================
-- While the app writes the token/expiry to the users table for lookup,
-- this table keeps an audit trail and supports multi-token flows if needed.
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token VARCHAR(64) NOT NULL,
  selector VARCHAR(32) DEFAULT NULL,
  verifier_hash VARCHAR(255) DEFAULT NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  request_ip VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  method VARCHAR(30) NOT NULL DEFAULT 'link',
  UNIQUE KEY uniq_password_resets_token (token),
  KEY idx_password_resets_user (user_id),
  KEY idx_password_resets_expires (expires_at),
  CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- PASSWORD RESET EVENT LOG
-- Auditable events (request, send failure, usage, invalid attempts, rate limiting)
-- =========================
CREATE TABLE IF NOT EXISTS password_reset_events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_type VARCHAR(40) NOT NULL,
  user_id INT NULL,
  email VARCHAR(150) NULL,
  token VARCHAR(64) NULL,
  message VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pre_events_type (event_type),
  KEY idx_pre_events_user (user_id),
  KEY idx_pre_events_token (token),
  CONSTRAINT fk_pre_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- IN-APP NOTIFICATIONS
-- =========================
CREATE TABLE IF NOT EXISTS notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  notification_type VARCHAR(50) NOT NULL DEFAULT 'general',
  entity_type VARCHAR(50) DEFAULT NULL,
  entity_id INT DEFAULT NULL,
  dedupe_key VARCHAR(150) DEFAULT NULL,
  message VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_notifications_dedupe (dedupe_key),
  INDEX idx_notifications_user_read (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- FORECAST SNAPSHOTS
-- Stores predictions before the forecast date so accuracy can be measured later.
-- =========================
CREATE TABLE IF NOT EXISTS forecast_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  forecast_date DATE NOT NULL,
  metric_type ENUM('orders','revenue','item_demand') NOT NULL,
  subject_key VARCHAR(80) NOT NULL DEFAULT 'overall',
  item_id INT DEFAULT NULL,
  predicted_value DECIMAL(14,4) NOT NULL DEFAULT 0,
  actual_value DECIMAL(14,4) DEFAULT NULL,
  accuracy_percent DECIMAL(6,2) DEFAULT NULL,
  method VARCHAR(80) NOT NULL DEFAULT 'weighted_moving_average',
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  evaluated_at DATETIME DEFAULT NULL,
  UNIQUE KEY uniq_forecast_metric (forecast_date, metric_type, subject_key),
  INDEX idx_forecast_item (item_id),
  CONSTRAINT fk_forecast_item FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- SYSTEM SETTINGS
-- =========================
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
  setting_value TEXT DEFAULT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('sales_target_daily', '0'),
  ('sales_target_weekly', '0'),
  ('sales_target_monthly', '0'),
  ('performance_period_days', '30');
