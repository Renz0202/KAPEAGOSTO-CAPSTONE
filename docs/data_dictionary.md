# KAPE AGOSTO Data Dictionary

This data dictionary describes the schema in `db.sql` and the columns added by the current migrations.

## Key Legend

| Code | Meaning |
| --- | --- |
| PK | Primary key |
| FK | Foreign key |
| UQ | Unique key |
| IDX | Non-unique index |

## Roles

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| roles | id | INT | 11 | PK |
| roles | name | VARCHAR | 50 | UQ |

## Users

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| users | id | INT | 11 | PK |
| users | username | VARCHAR | 50 | UQ |
| users | full_name | VARCHAR | 100 |  |
| users | last_name | VARCHAR | 100 |  |
| users | first_name | VARCHAR | 100 |  |
| users | middle_name | VARCHAR | 100 |  |
| users | email | VARCHAR | 100 | UQ |
| users | contact | VARCHAR | 20 |  |
| users | password_hash | VARCHAR | 255 |  |
| users | status | ENUM | active, inactive, disabled |  |
| users | role_id | INT | 11 | FK -> roles.id |
| users | image | VARCHAR | 255 |  |
| users | registered_at | TIMESTAMP |  |  |
| users | reset_token | VARCHAR | 64 | IDX |
| users | reset_expires | DATETIME |  |  |
| users | reset_sent_at | DATETIME |  |  |
| users | reset_used | TINYINT | 1 |  |

## Categories

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| categories | id | INT | 11 | PK |
| categories | name | VARCHAR | 100 | UQ |
| categories | image | VARCHAR | 255 |  |

## Items

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| items | id | INT | 11 | PK |
| items | sku | VARCHAR | 64 | UQ |
| items | category_id | INT | 11 | FK -> categories.id, IDX |
| items | name | VARCHAR | 100 |  |
| items | cup_size | ENUM | 12oz Hot, 16oz, 22oz |  |
| items | price | DECIMAL | 10,2 |  |
| items | image | VARCHAR | 255 |  |
| items | created_at | TIMESTAMP |  |  |

## Customers

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| customers | id | INT | 11 | PK |
| customers | customer_code | VARCHAR | 32 | UQ |
| customers | name | VARCHAR | 100 | IDX |
| customers | contact | VARCHAR | 30 | IDX |
| customers | email | VARCHAR | 150 | IDX |
| customers | created_at | TIMESTAMP |  |  |
| customers | updated_at | TIMESTAMP |  |  |

## Orders

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| orders | id | INT | 11 | PK |
| orders | order_code | VARCHAR | 50 | UQ |
| orders | user_id | INT | 11 | FK -> users.id, IDX |
| orders | customer_id | INT | 11 | FK -> customers.id, IDX |
| orders | customer_name | VARCHAR | 100 |  |
| orders | service_details | TEXT |  |  |
| orders | job_status | ENUM | queued, in_progress, ready, completed, cancelled | IDX |
| orders | total | DECIMAL | 10,2 |  |
| orders | subtotal | DECIMAL | 10,2 |  |
| orders | discount_type | ENUM | none, promo, senior, pwd |  |
| orders | discount_rate | DECIMAL | 5,2 |  |
| orders | promo_percent | DECIMAL | 5,2 |  |
| orders | discount_amount | DECIMAL | 10,2 |  |
| orders | vat_exempt_amount | DECIMAL | 10,2 |  |
| orders | discount_beneficiary_type | ENUM | senior, pwd |  |
| orders | discount_customer_name | VARCHAR | 100 |  |
| orders | discount_customer_tin | VARCHAR | 32 |  |
| orders | discount_id_number | VARCHAR | 64 |  |
| orders | discount_applied_by | INT | 11 |  |
| orders | exclusive_use_confirmed | TINYINT | 1 |  |
| orders | status | ENUM | pending, completed, cancelled, void, voided | IDX |
| orders | created_at | TIMESTAMP |  |  |
| orders | updated_at | TIMESTAMP |  |  |
| orders | voided_at | DATETIME |  |  |
| orders | cancelled_at | DATETIME |  |  |
| orders | inventory_deducted_at | DATETIME |  |  |

## Order Items

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| order_items | id | INT | 11 | PK |
| order_items | order_id | INT | 11 | FK -> orders.id, IDX |
| order_items | item_id | INT | 11 | FK -> items.id, IDX |
| order_items | qty | INT | 11 |  |
| order_items | price | DECIMAL | 10,2 |  |
| order_items | subtotal | DECIMAL | 10,2 | Generated |

## Inventory

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| inventory | id | INT | 11 | PK |
| inventory | sku | VARCHAR | 64 | UQ |
| inventory | name | VARCHAR | 100 | IDX |
| inventory | material_type | ENUM | raw_material, packaging, other |  |
| inventory | cup_size | VARCHAR | 50 | IDX |
| inventory | unit | VARCHAR | 50 |  |
| inventory | qty | DECIMAL | 10,2 |  |
| inventory | unit_cost | DECIMAL | 10,2 |  |
| inventory | unit_cost_source | ENUM | estimate, verified |  |
| inventory | critical_level | DECIMAL | 10,2 |  |
| inventory | safety_stock | DECIMAL | 10,2 |  |
| inventory | lead_time_days | INT | 11 |  |
| inventory | target_stock | DECIMAL | 10,2 |  |
| inventory | date_added | TIMESTAMP |  |  |
| inventory | updated_at | TIMESTAMP |  |  |

## Item Requirements

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| item_requirements | id | INT | 11 | PK |
| item_requirements | item_id | INT | 11 | FK -> items.id, UQ/IDX |
| item_requirements | inventory_id | INT | 11 | FK -> inventory.id, UQ/IDX |
| item_requirements | quantity_needed | DECIMAL | 10,2 |  |

## Inventory Logs

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| inventory_logs | id | INT | 11 | PK |
| inventory_logs | inventory_id | INT | 11 | FK -> inventory.id, IDX |
| inventory_logs | order_id | INT | 11 | FK -> orders.id, IDX |
| inventory_logs | user_id | INT | 11 | FK -> users.id |
| inventory_logs | change_type | ENUM | added, restock, used, deduct, auto-deduct, damage, spoilage, adjustment |  |
| inventory_logs | quantity | DECIMAL | 10,2 |  |
| inventory_logs | remaining | DECIMAL | 10,2 |  |
| inventory_logs | loss_unit_cost | DECIMAL | 10,2 |  |
| inventory_logs | loss_value | DECIMAL | 18,2 |  |
| inventory_logs | loss_cost_source | ENUM | estimate, verified |  |
| inventory_logs | remarks | VARCHAR | 255 |  |
| inventory_logs | created_at | TIMESTAMP |  |  |

## Daily Reports

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| daily_reports | id | INT | 11 | PK |
| daily_reports | report_date | DATE |  | UQ |
| daily_reports | total_sales | DECIMAL | 10,2 |  |
| daily_reports | total_orders | INT | 11 |  |
| daily_reports | created_at | TIMESTAMP |  |  |
| daily_reports | updated_at | TIMESTAMP |  |  |

## Daily Sales Reports

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| daily_sales_reports | id | INT | 11 | PK |
| daily_sales_reports | report_date | DATE |  | UQ |
| daily_sales_reports | generated_by | INT | 11 | FK -> users.id, IDX |
| daily_sales_reports | total_sales | DECIMAL | 10,2 |  |
| daily_sales_reports | total_orders | INT | 11 |  |
| daily_sales_reports | sales_tax | DECIMAL | 10,2 |  |
| daily_sales_reports | notes | TEXT |  |  |
| daily_sales_reports | created_at | TIMESTAMP |  |  |
| daily_sales_reports | updated_at | TIMESTAMP |  |  |

## Daily Sales Items

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| daily_sales_items | id | INT | 11 | PK |
| daily_sales_items | report_id | INT | 11 | FK -> daily_sales_reports.id, IDX |
| daily_sales_items | item_id | INT | 11 | IDX |
| daily_sales_items | item_name | VARCHAR | 255 |  |
| daily_sales_items | price | DECIMAL | 10,2 |  |
| daily_sales_items | qty | INT | 11 |  |
| daily_sales_items | subtotal | DECIMAL | 10,2 |  |

## Payments

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| payments | id | INT | 11 | PK |
| payments | order_id | INT | 11 | FK -> orders.id, IDX |
| payments | provider | VARCHAR | 50 |  |
| payments | session_id | VARCHAR | 255 | UQ |
| payments | provider_payment_id | VARCHAR | 255 |  |
| payments | amount | DECIMAL | 10,2 |  |
| payments | currency | VARCHAR | 10 |  |
| payments | status | ENUM | created, pending, succeeded, failed, cancelled, expired |  |
| payments | receipt_url | VARCHAR | 255 |  |
| payments | raw_response | JSON |  |  |
| payments | created_at | TIMESTAMP |  |  |
| payments | updated_at | TIMESTAMP |  |  |
| payments | paid_at | DATETIME |  |  |

## Password Resets

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| password_resets | id | INT | 11 | PK |
| password_resets | user_id | INT | 11 | FK -> users.id, IDX |
| password_resets | token | VARCHAR | 64 | UQ |
| password_resets | selector | VARCHAR | 32 |  |
| password_resets | verifier_hash | VARCHAR | 255 |  |
| password_resets | requested_at | DATETIME |  |  |
| password_resets | expires_at | DATETIME | IDX |
| password_resets | used_at | DATETIME |  |
| password_resets | request_ip | VARCHAR | 45 |  |
| password_resets | user_agent | VARCHAR | 255 |  |
| password_resets | method | VARCHAR | 30 |  |

## Password Reset Events

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| password_reset_events | id | INT | 11 | PK |
| password_reset_events | event_type | VARCHAR | 40 | IDX |
| password_reset_events | user_id | INT | 11 | FK -> users.id, IDX |
| password_reset_events | email | VARCHAR | 150 |  |
| password_reset_events | token | VARCHAR | 64 | IDX |
| password_reset_events | message | VARCHAR | 255 |  |
| password_reset_events | ip | VARCHAR | 45 |  |
| password_reset_events | user_agent | VARCHAR | 255 |  |
| password_reset_events | created_at | DATETIME |  |  |

## Notifications

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| notifications | id | INT | 11 | PK |
| notifications | user_id | INT | 11 | FK -> users.id, IDX |
| notifications | notification_type | VARCHAR | 50 |  |
| notifications | entity_type | VARCHAR | 50 |  |
| notifications | entity_id | INT | 11 |  |
| notifications | dedupe_key | VARCHAR | 150 | UQ |
| notifications | message | VARCHAR | 255 |  |
| notifications | is_read | TINYINT | 1 | IDX |
| notifications | read_at | DATETIME |  |
| notifications | created_at | TIMESTAMP |  | IDX |

## Forecast Snapshots

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| forecast_snapshots | id | INT | 11 | PK |
| forecast_snapshots | forecast_date | DATE |  | UQ |
| forecast_snapshots | metric_type | ENUM | orders, revenue, item_demand | UQ |
| forecast_snapshots | subject_key | VARCHAR | 80 | UQ |
| forecast_snapshots | item_id | INT | 11 | FK, IDX |
| forecast_snapshots | predicted_value | DECIMAL | 14,4 |  |
| forecast_snapshots | actual_value | DECIMAL | 14,4 |  |
| forecast_snapshots | accuracy_percent | DECIMAL | 6,2 |  |
| forecast_snapshots | method | VARCHAR | 80 |  |
| forecast_snapshots | generated_at | TIMESTAMP |  |  |
| forecast_snapshots | evaluated_at | DATETIME |  |  |

## System Settings

| Table Name | Field Name | Data Type | Length | Key |
| --- | --- | --- | --- | --- |
| system_settings | setting_key | VARCHAR | 100 | PK |
| system_settings | setting_value | TEXT |  |  |
| system_settings | updated_at | TIMESTAMP |  |  |
