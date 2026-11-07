-- ============================================================
-- MITCH COSMETICS SHOP — Database Schema + Seed Data
-- Compatible with MySQL 5.7+ / MariaDB 10.3+
-- Includes: inventory tracking (stock_movements table,
--           stock_deducted / stock_restored flags on orders,
--           low_stock_threshold on products)
--
-- HOW TO IMPORT ON INFINITYFREE:
--   1. Control Panel → MySQL Databases → create a new database
--   2. Click "phpMyAdmin" for that database
--   3. Click Import → choose this file → Go
--
--   DO NOT run this file against a live database that already
--   has data — the seed INSERT statements use INSERT IGNORE so
--   they skip duplicate emails safely.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120)    NOT NULL,
  `email`      VARCHAR(180)    NOT NULL,
  `phone`      VARCHAR(30)              DEFAULT NULL,
  `password`   VARCHAR(255)    NOT NULL,
  `role`       ENUM('admin','staff','customer') NOT NULL DEFAULT 'customer',
  `avatar`     VARCHAR(255)             DEFAULT NULL,
  `address`    TEXT                     DEFAULT NULL,
  `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. categories
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `description` TEXT                  DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. products
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `products` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `category_id`         INT UNSIGNED             DEFAULT NULL,
  `name`                VARCHAR(200)    NOT NULL,
  `sku`                 VARCHAR(50)     NOT NULL,
  `description`         TEXT                     DEFAULT NULL,
  `price`               DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `cost_price`          DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `quantity`            INT             NOT NULL DEFAULT 0,
  `low_stock_threshold` INT             NOT NULL DEFAULT 0
                        COMMENT '0 = use system default APP_LOW_STOCK_THRESHOLD',
  `image`               VARCHAR(255)             DEFAULT NULL,
  `video`               VARCHAR(255)             DEFAULT NULL,
  `status`              ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  KEY `fk_products_category` (`category_id`),
  KEY `idx_products_quantity` (`quantity`),
  CONSTRAINT `fk_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. orders
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_no`       VARCHAR(30)     NOT NULL,
  `customer_id`    INT UNSIGNED             DEFAULT NULL,
  `staff_id`       INT UNSIGNED             DEFAULT NULL,
  `subtotal`       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `discount`       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `tax`            DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `total_amount`   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `payment_method` VARCHAR(50)     NOT NULL DEFAULT 'cash',
  `status`         ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `stock_deducted` TINYINT(1)      NOT NULL DEFAULT 0
                   COMMENT '1 = inventory already reduced for this order',
  `stock_restored` TINYINT(1)      NOT NULL DEFAULT 0
                   COMMENT '1 = inventory already restored (cancelled/returned)',
  `notes`          TEXT                     DEFAULT NULL,
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_no` (`order_no`),
  KEY `fk_orders_customer` (`customer_id`),
  KEY `fk_orders_staff`    (`staff_id`),
  CONSTRAINT `fk_orders_customer`
    FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_staff`
    FOREIGN KEY (`staff_id`)    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. order_items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
  `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED    NOT NULL,
  `product_id` INT UNSIGNED    NOT NULL,
  `quantity`   INT             NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2)   NOT NULL,
  `discount`   DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `total`      DECIMAL(10,2)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_oi_order`   (`order_id`),
  KEY `fk_oi_product` (`product_id`),
  CONSTRAINT `fk_oi_order`
    FOREIGN KEY (`order_id`)   REFERENCES `orders`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oi_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. sales
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sales` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED             DEFAULT NULL,
  `receipt_no`      VARCHAR(30)     NOT NULL,
  `customer_name`   VARCHAR(120)             DEFAULT NULL,
  `staff_id`        INT UNSIGNED             DEFAULT NULL,
  `subtotal`        DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `discount`        DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `tax`             DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `total_amount`    DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `payment_method`  VARCHAR(50)     NOT NULL DEFAULT 'cash',
  `amount_tendered` DECIMAL(10,2)            DEFAULT NULL,
  `change_due`      DECIMAL(10,2)            DEFAULT NULL,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_receipt_no` (`receipt_no`),
  KEY `fk_sales_order` (`order_id`),
  KEY `fk_sales_staff` (`staff_id`),
  CONSTRAINT `fk_sales_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_staff`
    FOREIGN KEY (`staff_id`) REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. sale_items
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `sale_id`      INT UNSIGNED    NOT NULL,
  `product_id`   INT UNSIGNED    NOT NULL,
  `product_name` VARCHAR(200)    NOT NULL,
  `sku`          VARCHAR(50)     NOT NULL,
  `quantity`     INT             NOT NULL DEFAULT 1,
  `unit_price`   DECIMAL(10,2)   NOT NULL,
  `discount`     DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `total`        DECIMAL(10,2)   NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_si_sale`    (`sale_id`),
  KEY `fk_si_product` (`product_id`),
  CONSTRAINT `fk_si_sale`
    FOREIGN KEY (`sale_id`)    REFERENCES `sales`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_si_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. stock_movements — full inventory audit trail
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED             DEFAULT NULL
                   COMMENT 'NULL if product later deleted — history is preserved',
  `product_name`   VARCHAR(200)    NOT NULL COMMENT 'Snapshot at time of movement',
  `sku`            VARCHAR(50)     NOT NULL COMMENT 'Snapshot at time of movement',
  `qty_before`     INT             NOT NULL DEFAULT 0,
  `qty_change`     INT             NOT NULL DEFAULT 0
                   COMMENT 'Positive = added, Negative = removed',
  `qty_after`      INT             NOT NULL DEFAULT 0,
  `movement_type`  VARCHAR(50)     NOT NULL DEFAULT 'manual_adjustment'
                   COMMENT 'initial_stock|stock_added|sale|order_placed|return|cancelled_order|manual_adjustment|damaged|expired|lost|correction',
  `reference_id`   INT UNSIGNED             DEFAULT NULL
                   COMMENT 'order_id or sale_id that caused this movement',
  `reference_type` VARCHAR(20)              DEFAULT NULL
                   COMMENT 'order|sale',
  `notes`          TEXT                     DEFAULT NULL,
  `user_id`        INT UNSIGNED             DEFAULT NULL,
  `user_name`      VARCHAR(120)             DEFAULT NULL
                   COMMENT 'Name snapshot at time of movement',
  `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sm_product`   (`product_id`),
  KEY `idx_sm_type`      (`movement_type`),
  KEY `idx_sm_created`   (`created_at`),
  KEY `idx_sm_reference` (`reference_id`, `reference_type`),
  CONSTRAINT `fk_sm_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sm_user`
    FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. attendance
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `check_in`   DATETIME     NOT NULL,
  `check_out`  DATETIME              DEFAULT NULL,
  `date`       DATE         NOT NULL,
  `notes`      VARCHAR(255)          DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_user_date` (`user_id`, `date`),
  CONSTRAINT `fk_attendance_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- SEED DATA
-- Uses INSERT IGNORE so re-importing is safe — existing rows
-- are silently skipped, no duplicate-key errors.
-- ============================================================

-- Categories
INSERT IGNORE INTO `categories` (`name`, `description`) VALUES
  ('Skincare',   'Moisturizers, serums, sunscreens and cleansers'),
  ('Makeup',     'Foundation, lipstick, eyeshadow and more'),
  ('Hair Care',  'Shampoos, conditioners and hair treatments'),
  ('Fragrances', 'Perfumes, colognes and body mists'),
  ('Body Care',  'Body lotion, scrubs and bath products'),
  ('Nail Care',  'Nail polish, treatments and tools');

-- Demo Users
-- Passwords are bcrypt hashes of the literal string:  password
-- Generated with: password_hash('password', PASSWORD_BCRYPT, ['cost'=>12])
INSERT IGNORE INTO `users` (`name`, `email`, `phone`, `password`, `role`, `status`) VALUES
  ('Admin User',
   'admin@mitchcosmetics.com',
   '+231 770 000 001',
   '$2y$12$5zqH0D.kntf.YC4AriPgKuQkLWpZSJyaaZMyZwAm8Lt4hjtcROW.a',
   'admin',    'active'),

  ('Sarah Johnson',
   'staff@mitchcosmetics.com',
   '+231 770 000 002',
   '$2y$12$5zqH0D.kntf.YC4AriPgKuQkLWpZSJyaaZMyZwAm8Lt4hjtcROW.a',
   'staff',    'active'),

  ('Mary Brown',
   'mitchfredick@gmail.com',
   '+231 770 000 003',
   '$2y$12$5zqH0D.kntf.YC4AriPgKuQkLWpZSJyaaZMyZwAm8Lt4hjtcROW.a',
   'customer', 'active');

-- Sample Products (INSERT IGNORE skips if SKU already exists)
INSERT IGNORE INTO `products`
  (`category_id`, `name`, `sku`, `description`, `price`, `cost_price`, `quantity`, `image`)
SELECT c.id, p.name, p.sku, p.description, p.price, p.cost_price, p.quantity, p.image
FROM (
  SELECT 'Skincare'   AS cat, 'Glow Serum 30ml'        AS name, 'SKIN-A1B2C3' AS sku,
         'Vitamin C brightening serum for radiant skin' AS description,
         25.99 AS price, 12.00 AS cost_price, 50 AS quantity,
         'mcs_6ae3ab24a22152.48581835.jpg' AS image
  UNION ALL
  SELECT 'Skincare',  'Daily Moisturizer SPF30', 'SKIN-D4E5F6',
         'Lightweight daily moisturizer with SPF protection', 18.50, 8.00, 35,
         'mcs_6ae3ab45e53c49.79887735.jpg'
  UNION ALL
  SELECT 'Makeup',    'Matte Foundation',        'MAKE-G7H8I9',
         'Full-coverage matte foundation, 12 shades', 22.00, 10.00, 40,
         'mcs_6ae3ac5c99a1b5.64683297.jpg'
  UNION ALL
  SELECT 'Makeup',    'Red Velvet Lipstick',     'MAKE-J1K2L3',
         'Long-lasting velvet matte lipstick', 12.99, 5.00, 60,
         'mcs_6ae3ac45653298.44012229.jpg'
  UNION ALL
  SELECT 'Hair Care', 'Repair Shampoo 500ml',    'HAIR-M4N5O6',
         'Keratin repair shampoo for damaged hair', 15.00, 6.50, 28,
         'mcs_6ae3abc56f9e22.77223742.jpg'
  UNION ALL
  SELECT 'Fragrances','Rose Bloom Perfume',      'FRAG-P7Q8R9',
         'Floral rose scent, 50ml EDP', 35.00, 18.00, 20,
         'mcs_6ae3981f9e0e01.11177032.jpg'
  UNION ALL
  SELECT 'Body Care', 'Shea Body Lotion 400ml',  'BODY-S1T2U3',
         'Nourishing shea butter body lotion', 14.50, 6.00, 45,
         'mcs_6ae3ac91335a47.67687184.jpg'
  UNION ALL
  SELECT 'Nail Care', 'Nail Polish Set (6pcs)',  'NAIL-V4W5X6',
         'Classic colors nail polish set', 19.99, 9.00, 8,
         'mcs_6ae3acc322e7f1.81013029.jpg'
) AS p
JOIN categories c ON c.name = p.cat;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Staff Payments (Payroll) Table
-- Safe to re-run: uses CREATE TABLE IF NOT EXISTS.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `staff_payments` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `staff_id`      INT UNSIGNED    NOT NULL,
  `pay_period`    VARCHAR(20)     NOT NULL COMMENT 'e.g. 2024-07',
  `basic_salary`  DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `allowances`    DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `deductions`    DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `net_pay`       DECIMAL(10,2)   NOT NULL DEFAULT '0.00',
  `payment_method` VARCHAR(50)    NOT NULL DEFAULT 'cash',
  `status`        ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `notes`         TEXT                     DEFAULT NULL,
  `paid_at`       DATETIME                 DEFAULT NULL,
  `created_by`    INT UNSIGNED             DEFAULT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sp_staff`   (`staff_id`),
  KEY `fk_sp_creator` (`created_by`),
  CONSTRAINT `fk_sp_staff`
    FOREIGN KEY (`staff_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sp_creator`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Attendance Settings Table
-- (merged from patch_attendance_settings.sql)
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + INSERT IGNORE
-- ============================================================

CREATE TABLE IF NOT EXISTS `attendance_settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `day_of_week`   TINYINT      NOT NULL COMMENT '0=Sunday 1=Monday … 6=Saturday',
  `is_enabled`    TINYINT(1)   NOT NULL DEFAULT 1  COMMENT '1=open 0=closed',
  `checkin_start` TIME         NOT NULL DEFAULT '08:00:00' COMMENT 'Earliest allowed check-in',
  `checkin_end`   TIME         NOT NULL DEFAULT '10:00:00' COMMENT 'Latest allowed check-in',
  `late_after`    TIME                  DEFAULT NULL COMMENT 'Marked late if check-in after this time',
  `checkout_time` TIME                  DEFAULT NULL COMMENT 'Earliest allowed check-out (optional)',
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_day` (`day_of_week`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed one row per day (INSERT IGNORE = safe on re-import)
-- Defaults: Mon–Fri 08:00–10:00, late after 08:30, checkout from 17:00
--           Sat half-day 08:00–09:00, checkout from 13:00
--           Sun closed
INSERT IGNORE INTO `attendance_settings`
  (`day_of_week`,`is_enabled`,`checkin_start`,`checkin_end`,`late_after`,`checkout_time`)
VALUES
  (0, 0, '08:00:00', '10:00:00', NULL,        NULL),        -- Sunday   (closed)
  (1, 1, '08:00:00', '10:00:00', '08:30:00',  '17:00:00'),  -- Monday
  (2, 1, '08:00:00', '10:00:00', '08:30:00',  '17:00:00'),  -- Tuesday
  (3, 1, '08:00:00', '10:00:00', '08:30:00',  '17:00:00'),  -- Wednesday
  (4, 1, '08:00:00', '10:00:00', '08:30:00',  '17:00:00'),  -- Thursday
  (5, 1, '08:00:00', '10:00:00', '08:30:00',  '17:00:00'),  -- Friday
  (6, 1, '08:00:00', '09:00:00', NULL,         '13:00:00'); -- Saturday (half-day)

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Site Settings Table (key-value store)
-- Used for hero video, poster image, and other admin settings.
-- ============================================================
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`   VARCHAR(100) NOT NULL,
  `setting_value` TEXT         DEFAULT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- social_accounts — Google (and future) OAuth identities
-- One user can have multiple social logins (Google, Facebook…)
-- One social provider+ID can only ever map to ONE user.
-- Compatible with MySQL 5.7+ / MariaDB 10.3+ (InfinityFree)
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `social_accounts` (
  `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`          INT UNSIGNED    NOT NULL,
  `provider`         VARCHAR(32)     NOT NULL  COMMENT 'e.g. google',
  `provider_user_id` VARCHAR(255)    NOT NULL  COMMENT 'sub / uid from the provider',
  `email`            VARCHAR(180)             DEFAULT NULL
                     COMMENT 'Email as returned by the provider at link time',
  `name`             VARCHAR(120)             DEFAULT NULL
                     COMMENT 'Display name from the provider at link time',
  `avatar`           VARCHAR(500)             DEFAULT NULL
                     COMMENT 'Profile picture URL from provider',
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                              ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- One provider account can only ever belong to ONE website user
  UNIQUE KEY `uq_social_provider_id` (`provider`, `provider_user_id`),
  KEY `fk_social_user` (`user_id`),
  CONSTRAINT `fk_social_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
