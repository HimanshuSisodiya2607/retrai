-- =============================================================================
-- Dinetous — Multi-tenant restaurant database
-- =============================================================================
--
-- WORKFLOW (maps to the current website)
-- --------------------------------------
-- 1. Landing (index.html) → Sign in (Restro/sign-in.html) or Register (onboarding.html)
-- 2. Registration creates one row in `restaurants` with a unique `restro_key`
--    and stores email + password in plain text (per project requirement).
-- 3. Owner dashboard (Restro/overview.html, orders, tables, menu, categories…)
--    loads all data filtered by `restro_key` after login.
-- 4. Tables page generates QR codes → customer-menu.html?table=<id>&name=…
-- 5. Customer places order → row in `orders` + lines in `order_items`, both
--    tagged with `restro_key` so data never mixes between restaurants.
-- 6. Live Orders / Bill print read active orders for a table under that restro.
--
-- TENANT IDENTIFICATION
-- ---------------------
-- Every restaurant gets:  restro_key  (e.g. rst_a1b2c3d4e5f6)
-- Every category gets:    category_key + restro_key
-- Every menu item gets:   item_key + category_key + restro_key
-- Every floor table gets: table_key + restro_key
-- Every order gets:       order_key + restro_key + table_key
--
-- Inventory is NOT included (removed from the product).
--
-- Engine: MySQL 8+ / MariaDB 10.5+
-- =============================================================================

CREATE DATABASE IF NOT EXISTS restroai
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE restroai;

-- -----------------------------------------------------------------------------
-- RESTAURANTS — one row per registered restaurant (tenant root)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS restaurants (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restro_key      VARCHAR(32)  NOT NULL COMMENT 'Public unique tenant id, e.g. rst_a1b2c3d4e5f6',
  restaurant_name VARCHAR(150) NOT NULL,
  owner_name      VARCHAR(120) NOT NULL,
  email           VARCHAR(180) NOT NULL,
  password        VARCHAR(255) NOT NULL COMMENT 'Stored in plain text per project requirement — hash in production',
  phone           VARCHAR(30)  NULL,
  cuisine         VARCHAR(80)  NULL,
  city            VARCHAR(100) NULL,
  address         VARCHAR(255) NULL,
  opening_time    VARCHAR(20)  NULL,
  closing_time    VARCHAR(20)  NULL,
  notify_new_orders    TINYINT(1) NOT NULL DEFAULT 1,
  notify_weekly_digest TINYINT(1) NOT NULL DEFAULT 0,
  plan_name       VARCHAR(50)  NULL DEFAULT 'Growth',
  billing_cycle   VARCHAR(20)  NULL DEFAULT 'monthly',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_restaurants_restro_key (restro_key),
  UNIQUE KEY uq_restaurants_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- CATEGORIES — menu sections per restaurant
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_key VARCHAR(32)  NOT NULL COMMENT 'Unique id for this category, e.g. cat_x1y2z3',
  restro_key   VARCHAR(32)  NOT NULL COMMENT 'Which restaurant owns this category',
  name         VARCHAR(100) NOT NULL,
  emoji        VARCHAR(8)   NULL,
  sort_order   INT          NOT NULL DEFAULT 0,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_categories_category_key (category_key),
  UNIQUE KEY uq_categories_restro_name (restro_key, name),
  KEY idx_categories_restro_key (restro_key),

  CONSTRAINT fk_categories_restro
    FOREIGN KEY (restro_key) REFERENCES restaurants (restro_key)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ITEMS — dishes / drinks on the menu
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_key     VARCHAR(32)   NOT NULL COMMENT 'Unique id for this dish, e.g. itm_p4q5r6',
  restro_key   VARCHAR(32)   NOT NULL COMMENT 'Which restaurant owns this item',
  category_key VARCHAR(32)   NOT NULL COMMENT 'Which category this item belongs to',
  name         VARCHAR(150)  NOT NULL,
  emoji        VARCHAR(8)    NULL,
  description  TEXT          NULL,
  price        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  glb_url      VARCHAR(500)  NULL COMMENT 'Optional 3D AR model URL',
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order   INT           NOT NULL DEFAULT 0,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_items_item_key (item_key),
  KEY idx_items_restro_key (restro_key),
  KEY idx_items_category_key (category_key),
  KEY idx_items_restro_active (restro_key, is_active),

  CONSTRAINT fk_items_restro
    FOREIGN KEY (restro_key) REFERENCES restaurants (restro_key)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_items_category
    FOREIGN KEY (category_key) REFERENCES categories (category_key)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- TABLES — dine-in tables / QR targets per restaurant
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS restaurant_tables (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  table_key  VARCHAR(32)  NOT NULL COMMENT 'Unique id for this table, e.g. tbl_m7n8o9',
  restro_key VARCHAR(32)  NOT NULL COMMENT 'Which restaurant owns this table',
  table_name VARCHAR(50)  NOT NULL COMMENT 'Display name, e.g. T-01',
  seats      INT UNSIGNED NOT NULL DEFAULT 2,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_tables_table_key (table_key),
  UNIQUE KEY uq_tables_restro_name (restro_key, table_name),
  KEY idx_tables_restro_key (restro_key),

  CONSTRAINT fk_tables_restro
    FOREIGN KEY (restro_key) REFERENCES restaurants (restro_key)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- STAFF — team members per restaurant (used by staff.php)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_key   VARCHAR(32)  NOT NULL COMMENT 'Unique id, e.g. stf_a1b2c3',
  restro_key  VARCHAR(32)  NOT NULL COMMENT 'Which restaurant owns this staff member',
  name        VARCHAR(120) NOT NULL,
  role_title  VARCHAR(80)  NOT NULL COMMENT 'Job title, e.g. Head Chef',
  department  VARCHAR(80)  NOT NULL COMMENT 'Kitchen, Floor, Front desk, etc.',
  status      ENUM('active', 'off') NOT NULL DEFAULT 'active',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_staff_staff_key (staff_key),
  KEY idx_staff_restro_key (restro_key),

  CONSTRAINT fk_staff_restro
    FOREIGN KEY (restro_key) REFERENCES restaurants (restro_key)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ORDERS — one checkout / ticket per table visit (or staff-created order)
-- Status flow matches the dashboard: new → kitchen → ready → served → completed
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_key    VARCHAR(32)   NOT NULL COMMENT 'Unique id for this order, e.g. ord_k1l2m3',
  restro_key   VARCHAR(32)   NOT NULL COMMENT 'Which restaurant this order belongs to',
  table_key    VARCHAR(32)   NOT NULL COMMENT 'Which table placed the order',
  status       ENUM('new','kitchen','ready','served','completed') NOT NULL DEFAULT 'new',
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  items_summary TEXT         NULL COMMENT 'Optional comma-separated snapshot for quick display',
  ordered_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_orders_order_key (order_key),
  KEY idx_orders_restro_key (restro_key),
  KEY idx_orders_table_key (table_key),
  KEY idx_orders_restro_status (restro_key, status),
  KEY idx_orders_ordered_at (ordered_at),

  CONSTRAINT fk_orders_restro
    FOREIGN KEY (restro_key) REFERENCES restaurants (restro_key)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_orders_table
    FOREIGN KEY (table_key) REFERENCES restaurant_tables (table_key)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ORDER_ITEMS — line items inside an order (for bills & analytics)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_key   VARCHAR(32)   NOT NULL,
  restro_key  VARCHAR(32)   NOT NULL COMMENT 'Denormalized for fast tenant-scoped queries',
  item_key    VARCHAR(32)   NULL COMMENT 'Links to items.item_key when still on menu',
  item_name   VARCHAR(150)  NOT NULL COMMENT 'Snapshot at time of order',
  quantity    INT UNSIGNED  NOT NULL DEFAULT 1,
  unit_price  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  line_total  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_order_items_order_key (order_key),
  KEY idx_order_items_restro_key (restro_key),
  KEY idx_order_items_item_key (item_key),

  CONSTRAINT fk_order_items_order
    FOREIGN KEY (order_key) REFERENCES orders (order_key)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_order_items_restro
    FOREIGN KEY (restro_key) REFERENCES restaurants (restro_key)
    ON UPDATE CASCADE ON DELETE CASCADE,

  CONSTRAINT fk_order_items_item
    FOREIGN KEY (item_key) REFERENCES items (item_key)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA — demo restaurant "Spice Bazaar" (matches the current UI demo)
-- =============================================================================

INSERT INTO restaurants (
  restro_key, restaurant_name, owner_name, email, password,
  phone, cuisine, city, plan_name, billing_cycle
) VALUES (
  'rst_spicebazaar01',
  'Spice Bazaar',
  'Spice Bazaar Owner',
  'owner@spicebazaar.com',
  'demo1234',
  '+91 9876543210',
  'North Indian',
  'Jodhpur',
  'Growth',
  'monthly'
);

INSERT INTO categories (category_key, restro_key, name, emoji, sort_order) VALUES
  ('cat_sb_starters',  'rst_spicebazaar01', 'Starters',  '🥗', 1),
  ('cat_sb_mains',     'rst_spicebazaar01', 'Mains',     '🍛', 2),
  ('cat_sb_desserts',  'rst_spicebazaar01', 'Desserts',  '🍰', 3),
  ('cat_sb_beverages', 'rst_spicebazaar01', 'Beverages', '🍹', 4);

INSERT INTO items (
  item_key, restro_key, category_key, name, emoji, description, price, glb_url, is_active, sort_order
) VALUES
  ('itm_sb_pasta',   'rst_spicebazaar01', 'cat_sb_mains',     'Truffle Pasta',        '🍝', 'Hand-rolled pasta, black truffle cream.',              440.00, 'https://modelviewer.dev/shared-assets/models/Astronaut.glb', 1, 1),
  ('itm_sb_burger',  'rst_spicebazaar01', 'cat_sb_mains',     'Signature Burger',     '🍔', 'Smash patty, aged cheddar, house sauce.',              320.00, NULL, 1, 2),
  ('itm_sb_butter',  'rst_spicebazaar01', 'cat_sb_mains',     'Butter Chicken Bowl',  '🍛', 'Slow-simmered tomato butter gravy.',                   360.00, NULL, 1, 3),
  ('itm_sb_salad',   'rst_spicebazaar01', 'cat_sb_starters',  'Quinoa Salad',         '🥗', 'Quinoa, citrus, roasted vegetables.',                  260.00, NULL, 0, 4),
  ('itm_sb_brownie', 'rst_spicebazaar01', 'cat_sb_desserts',  'Molten Brownie',       '🍰', 'Warm brownie, vanilla bean ice cream.',                210.00, NULL, 1, 5),
  ('itm_sb_coffee',  'rst_spicebazaar01', 'cat_sb_beverages', 'Cold Coffee',          '🍹', 'Espresso, cold milk, whipped cream.',                  150.00, NULL, 1, 6),
  ('itm_sb_pizza',   'rst_spicebazaar01', 'cat_sb_mains',     'Margherita Pizza',     '🍕', 'San Marzano tomato, fresh basil.',                     340.00, 'https://modelviewer.dev/shared-assets/models/Astronaut.glb', 1, 7),
  ('itm_sb_biryani', 'rst_spicebazaar01', 'cat_sb_starters',  'Veg Biryani',          '🍜', 'Fragrant basmati, saffron, vegetables.',               280.00, NULL, 0, 8);

INSERT INTO restaurant_tables (table_key, restro_key, table_name, seats) VALUES
  ('tbl_sb_01', 'rst_spicebazaar01', 'T-01', 4),
  ('tbl_sb_02', 'rst_spicebazaar01', 'T-02', 4),
  ('tbl_sb_03', 'rst_spicebazaar01', 'T-03', 2),
  ('tbl_sb_04', 'rst_spicebazaar01', 'T-04', 6),
  ('tbl_sb_05', 'rst_spicebazaar01', 'T-05', 4),
  ('tbl_sb_06', 'rst_spicebazaar01', 'T-06', 2),
  ('tbl_sb_07', 'rst_spicebazaar01', 'T-07', 4),
  ('tbl_sb_08', 'rst_spicebazaar01', 'T-08', 4),
  ('tbl_sb_09', 'rst_spicebazaar01', 'T-09', 6),
  ('tbl_sb_10', 'rst_spicebazaar01', 'T-10', 2),
  ('tbl_sb_11', 'rst_spicebazaar01', 'T-11', 4),
  ('tbl_sb_12', 'rst_spicebazaar01', 'T-12', 4),
  ('tbl_sb_13', 'rst_spicebazaar01', 'T-13', 2),
  ('tbl_sb_14', 'rst_spicebazaar01', 'T-14', 4),
  ('tbl_sb_15', 'rst_spicebazaar01', 'T-15', 4);

INSERT INTO staff (staff_key, restro_key, name, role_title, department, status, created_at) VALUES
  ('stf_sb_01', 'rst_spicebazaar01', 'Ravi Kumar',   'Head Chef', 'Kitchen',     'active', '2023-06-12 00:00:00'),
  ('stf_sb_02', 'rst_spicebazaar01', 'Priya Mehta',  'Manager',   'Floor',       'active', '2023-08-01 00:00:00'),
  ('stf_sb_03', 'rst_spicebazaar01', 'Arjun Singh',  'Waiter',    'Floor',       'active', '2024-01-15 00:00:00'),
  ('stf_sb_04', 'rst_spicebazaar01', 'Neha Jain',    'Waiter',    'Floor',       'off',    '2024-03-20 00:00:00'),
  ('stf_sb_05', 'rst_spicebazaar01', 'Vikram Shah',  'Line Cook', 'Kitchen',     'active', '2024-05-08 00:00:00'),
  ('stf_sb_06', 'rst_spicebazaar01', 'Divya Kapoor', 'Cashier',   'Front desk',  'off',    '2024-09-01 00:00:00');

INSERT INTO orders (order_key, restro_key, table_key, status, total_amount, items_summary, ordered_at) VALUES
  ('ord_sb_0001', 'rst_spicebazaar01', 'tbl_sb_04',  'new',     460.00, 'Margherita Pizza, Cold Coffee',       DATE_SUB(NOW(), INTERVAL 2 MINUTE)),
  ('ord_sb_0002', 'rst_spicebazaar01', 'tbl_sb_11',  'kitchen', 320.00, 'Butter Chicken Bowl',                 DATE_SUB(NOW(), INTERVAL 6 MINUTE)),
  ('ord_sb_0003', 'rst_spicebazaar01', 'tbl_sb_07',  'ready',   880.00, 'Truffle Pasta ×2',                    DATE_SUB(NOW(), INTERVAL 9 MINUTE)),
  ('ord_sb_0004', 'rst_spicebazaar01', 'tbl_sb_02',  'served',  540.00, 'Paneer Tikka, Naan ×3',               DATE_SUB(NOW(), INTERVAL 14 MINUTE)),
  ('ord_sb_0005', 'rst_spicebazaar01', 'tbl_sb_14',  'new',     250.00, 'Cold Coffee, Molten Brownie',         DATE_SUB(NOW(), INTERVAL 1 MINUTE)),
  ('ord_sb_0006', 'rst_spicebazaar01', 'tbl_sb_09',  'kitchen', 390.00, 'Veg Biryani, Raita',                  DATE_SUB(NOW(), INTERVAL 4 MINUTE));

INSERT INTO order_items (order_key, restro_key, item_key, item_name, quantity, unit_price, line_total) VALUES
  ('ord_sb_0001', 'rst_spicebazaar01', 'itm_sb_pizza',  'Margherita Pizza', 1, 340.00, 340.00),
  ('ord_sb_0001', 'rst_spicebazaar01', 'itm_sb_coffee', 'Cold Coffee',      1, 150.00, 150.00),
  ('ord_sb_0002', 'rst_spicebazaar01', 'itm_sb_butter', 'Butter Chicken Bowl', 1, 360.00, 360.00),
  ('ord_sb_0003', 'rst_spicebazaar01', 'itm_sb_pasta',  'Truffle Pasta',    2, 440.00, 880.00),
  ('ord_sb_0005', 'rst_spicebazaar01', 'itm_sb_coffee', 'Cold Coffee',      1, 150.00, 150.00),
  ('ord_sb_0005', 'rst_spicebazaar01', 'itm_sb_brownie','Molten Brownie',   1, 210.00, 210.00);

-- =============================================================================
-- HELPER QUERIES (examples for backend integration)
-- =============================================================================

-- Sign in: verify email + plain password, return restro_key
-- SELECT restro_key, restaurant_name, owner_name
-- FROM restaurants
-- WHERE email = 'owner@spicebazaar.com' AND password = 'demo1234' AND is_active = 1;

-- Fetch all menu data for one restaurant (customer QR menu)
-- SELECT c.category_key, c.name AS category_name, c.emoji,
--        i.item_key, i.name, i.emoji, i.description, i.price, i.glb_url
-- FROM categories c
-- JOIN items i ON i.category_key = c.category_key AND i.restro_key = c.restro_key
-- WHERE c.restro_key = 'rst_spicebazaar01' AND i.is_active = 1
-- ORDER BY c.sort_order, i.sort_order;

-- Live orders for dashboard
-- SELECT o.order_key, t.table_name, o.status, o.total_amount, o.items_summary, o.ordered_at
-- FROM orders o
-- JOIN restaurant_tables t ON t.table_key = o.table_key
-- WHERE o.restro_key = 'rst_spicebazaar01' AND o.status != 'completed'
-- ORDER BY o.ordered_at DESC;

-- Bill for a table (active orders + line items)
-- SELECT o.order_key, oi.item_name, oi.quantity, oi.unit_price, oi.line_total
-- FROM orders o
-- JOIN order_items oi ON oi.order_key = o.order_key
-- WHERE o.restro_key = 'rst_spicebazaar01'
--   AND o.table_key = 'tbl_sb_07'
--   AND o.status != 'completed';

-- -----------------------------------------------------------------------------
-- MIGRATION — run once if your database was created before settings columns
-- -----------------------------------------------------------------------------
-- ALTER TABLE restaurants
--   ADD COLUMN address VARCHAR(255) NULL AFTER city,
--   ADD COLUMN opening_time VARCHAR(20) NULL AFTER address,
--   ADD COLUMN closing_time VARCHAR(20) NULL AFTER opening_time,
--   ADD COLUMN notify_new_orders TINYINT(1) NOT NULL DEFAULT 1 AFTER closing_time,
--   ADD COLUMN notify_weekly_digest TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_new_orders;

-- ============================================================
-- bills — one row per settled table bill.
-- A bill spans every open order on a table, so discounts live
-- here rather than on individual orders. Line items and the
-- table name are snapshotted so a bill can still be reprinted
-- after its orders are completed (or its table deleted).
-- ============================================================
CREATE TABLE IF NOT EXISTS bills (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bill_key        VARCHAR(32)   NOT NULL,
  restro_key      VARCHAR(32)   NOT NULL,
  table_key       VARCHAR(32)   NOT NULL,
  table_name      VARCHAR(50)   NOT NULL,
  order_count     INT UNSIGNED  NOT NULL DEFAULT 0,
  subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_type   ENUM('none','percent','flat') NOT NULL DEFAULT 'none',
  discount_value  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  items_snapshot  TEXT          NULL,
  settled_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_bill_key (bill_key),
  KEY idx_restro_settled (restro_key, settled_at),
  KEY idx_bill_table (table_key),
  CONSTRAINT fk_bills_restro FOREIGN KEY (restro_key)
    REFERENCES restaurants(restro_key) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- super_admins — platform operators, not tenants.
-- Separate from `restaurants` so platform access can never be
-- reached through a restaurant login. Passwords are stored as
-- plaintext, matching the tenant logins.
-- ============================================================
CREATE TABLE IF NOT EXISTS super_admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_key     VARCHAR(32)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(180) NOT NULL,
  password      VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_admin_key (admin_key),
  UNIQUE KEY uniq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Dish photos, uploaded by the restaurant on the menu page.
-- ============================================================
ALTER TABLE items ADD COLUMN photo_url VARCHAR(500) NULL AFTER description;

-- ============================================================
-- ar_requests — a restaurant asks for a 3D model of one dish.
-- Raised from the AR Menu Studio, fulfilled by a super admin
-- uploading the .glb, which then lands on items.glb_url.
-- ============================================================
CREATE TABLE IF NOT EXISTS ar_requests (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_key  VARCHAR(32) NOT NULL,
  restro_key   VARCHAR(32) NOT NULL,
  item_key     VARCHAR(32) NOT NULL,
  status       ENUM('requested','in_progress','delivered','rejected') NOT NULL DEFAULT 'requested',
  admin_note   VARCHAR(500) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  delivered_at DATETIME NULL,
  UNIQUE KEY uniq_ar_request (request_key),
  UNIQUE KEY uniq_ar_item (item_key),
  KEY idx_ar_status (status),
  KEY idx_ar_restro (restro_key),
  CONSTRAINT fk_ar_item FOREIGN KEY (item_key)
    REFERENCES items(item_key) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Public directory fields.
-- `slug` gives each restaurant a stable, SEO-friendly URL
-- (/r/spice-bazaar). `city_slug` is the normalized form of the
-- free-text city so /restaurants/bikaner groups reliably.
-- `is_listed` lets a restaurant opt out of the public directory.
-- ============================================================
ALTER TABLE restaurants
  ADD COLUMN slug        VARCHAR(160) NULL AFTER restaurant_name,
  ADD COLUMN city_slug   VARCHAR(100) NULL AFTER city,
  ADD COLUMN tagline     VARCHAR(200) NULL AFTER cuisine,
  ADD COLUMN is_listed   TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
  ADD UNIQUE KEY uq_restaurants_slug (slug),
  ADD KEY idx_restaurants_city_slug (city_slug, is_listed);

-- ============================================================
-- payments — signup fees collected through Razorpay.
-- A row is created before checkout opens and only marked 'paid'
-- after the signature verifies, so this doubles as the audit
-- trail for what was actually charged.
-- ============================================================
CREATE TABLE IF NOT EXISTS payments (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_key         VARCHAR(32)  NOT NULL,
  restro_key          VARCHAR(32)  NULL COMMENT 'Set once the account is created',
  email               VARCHAR(180) NOT NULL,
  razorpay_order_id   VARCHAR(64)  NOT NULL,
  razorpay_payment_id VARCHAR(64)  NULL,
  amount              INT UNSIGNED NOT NULL COMMENT 'In paise',
  currency            VARCHAR(8)   NOT NULL DEFAULT 'INR',
  purpose             VARCHAR(40)  NOT NULL DEFAULT 'signup',
  status              ENUM('created','paid','failed') NOT NULL DEFAULT 'created',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at             DATETIME     NULL,
  UNIQUE KEY uq_payment_key (payment_key),
  UNIQUE KEY uq_rzp_order (razorpay_order_id),
  KEY idx_payments_email (email),
  KEY idx_payments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
