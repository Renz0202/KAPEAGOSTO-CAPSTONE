-- Built-in application roles and a local demo administrator.
USE `kape_agosto`;

INSERT IGNORE INTO `roles` (`id`, `name`) VALUES
  (1, 'admin'),
  (2, 'cashier'),
  (3, 'manager');

INSERT IGNORE INTO `users`
  (`username`, `full_name`, `first_name`, `last_name`, `email`, `password_hash`, `role_id`, `status`)
VALUES
  ('admin', 'Kape Agosto Admin', 'Admin', 'User', 'admin', '$2y$10$VR0TRBa01RP6Gq95urHS4upQiUQsbUHuySLuO/wZ403dTFDbONKzO', 1, 'active');
