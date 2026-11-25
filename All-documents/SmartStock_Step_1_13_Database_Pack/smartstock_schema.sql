-- SmartStock Step 1.13: draft MySQL 8 schema
-- ONE owner / ONE business / multiple internal stock locations.
-- IMPORTANT: schema only; NOT validated against a running MySQL server.
-- Import into an EMPTY database. No DROP statements are included.
-- Business logic and service-layer transactions are required for ledger consistency.
CREATE DATABASE IF NOT EXISTS smartstock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE smartstock;
SET NAMES utf8mb4;

CREATE TABLE businesses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(200) NOT NULL,
  phone VARCHAR(40) NULL,
  address TEXT NULL,
  base_currency CHAR(3) NOT NULL DEFAULT 'KHR',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Phnom_Penh',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
username VARCHAR(100) NOT NULL UNIQUE,
  full_name VARCHAR(180) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  phone VARCHAR(40) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
code VARCHAR(120) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_user_role (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_role_permission (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  before_json JSON NULL,
  after_json JSON NULL,
  reason TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_id) REFERENCES users(id),
KEY idx_audit_entity (entity_type,entity_id), KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_locations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  type ENUM('retail','storage','agriculture','damage','other') NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(140) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (parent_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE brands (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  precision_digits TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
sku VARCHAR(80) NOT NULL UNIQUE,
  name VARCHAR(200) NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  brand_id BIGINT UNSIGNED NULL,
  base_unit_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('physical','fuel') NOT NULL DEFAULT 'physical',
  track_batches BOOLEAN NOT NULL DEFAULT FALSE,
  track_expiry BOOLEAN NOT NULL DEFAULT FALSE,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  FOREIGN KEY (brand_id) REFERENCES brands(id),
  FOREIGN KEY (base_unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  to_base_factor DECIMAL(18,6) NOT NULL,
  UNIQUE KEY uq_product_unit (product_id, unit_id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (unit_id) REFERENCES units(id),
  CHECK (to_base_factor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_unit_id BIGINT UNSIGNED NOT NULL,
  price_type ENUM('retail','wholesale') NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'KHR',
  amount DECIMAL(18,4) NOT NULL,
  effective_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_current BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
  CHECK (amount >= 0),
KEY idx_prices_current (product_unit_id,price_type,is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_barcodes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_unit_id BIGINT UNSIGNED NOT NULL,
  barcode VARCHAR(100) NOT NULL UNIQUE,
  FOREIGN KEY (product_unit_id) REFERENCES product_units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(180) NOT NULL,
  phone VARCHAR(50) NULL,
  address TEXT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(180) NOT NULL,
  phone VARCHAR(50) NULL,
  credit_limit DECIMAL(18,4) NOT NULL DEFAULT 0,
  status ENUM('active','archived') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
purchase_no VARCHAR(50) NOT NULL UNIQUE,
  supplier_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','ordered','part_received','received','cancelled') NOT NULL DEFAULT 'draft',
  currency CHAR(3) NOT NULL DEFAULT 'KHR',
  exchange_rate_to_khr DECIMAL(18,6) NOT NULL DEFAULT 1,
  ordered_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
purchase_id BIGINT UNSIGNED NOT NULL,
  product_unit_id BIGINT UNSIGNED NOT NULL,
  ordered_qty DECIMAL(18,3) NOT NULL,
  unit_purchase_price DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
  CHECK (ordered_qty > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE goods_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
receipt_no VARCHAR(50) NOT NULL UNIQUE,
  purchase_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  status ENUM('draft','confirmed','reversed') NOT NULL DEFAULT 'draft',
  confirmed_at DATETIME NULL,
  confirmed_by BIGINT UNSIGNED NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  FOREIGN KEY (location_id) REFERENCES stock_locations(id),
  FOREIGN KEY (confirmed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE goods_receipt_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
receipt_id BIGINT UNSIGNED NOT NULL,
  purchase_item_id BIGINT UNSIGNED NOT NULL,
  qty_base DECIMAL(18,3) NOT NULL,
  landed_unit_cost_khr DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (receipt_id) REFERENCES goods_receipts(id),
  FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id),
  CHECK (qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL,
  batch_code VARCHAR(100) NOT NULL,
  expiry_date DATE NULL,
  UNIQUE KEY uq_batch (product_id,batch_code),
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_balances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  batch_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(batch_id,0)) STORED,
  qty_base DECIMAL(18,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_stock (product_id,location_id,batch_key),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (location_id) REFERENCES stock_locations(id),
  FOREIGN KEY (batch_id) REFERENCES stock_batches(id),
  CHECK (qty_base >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_costs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL UNIQUE,
  qty_valued DECIMAL(18,3) NOT NULL DEFAULT 0,
  inventory_value_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
  avg_unit_cost_khr DECIMAL(18,6) NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  batch_id BIGINT UNSIGNED NULL,
  qty_delta DECIMAL(18,3) NOT NULL,
  source_type VARCHAR(50) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  movement_key VARCHAR(150) NOT NULL UNIQUE,
  unit_cost_khr DECIMAL(18,6) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by BIGINT UNSIGNED NULL,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (location_id) REFERENCES stock_locations(id),
  FOREIGN KEY (batch_id) REFERENCES stock_batches(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
KEY idx_movement_product_time (product_id,created_at), KEY idx_movement_source (source_type,source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_cost_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL,
  source_type VARCHAR(50) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  source_line_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  entry_key VARCHAR(150) NOT NULL UNIQUE,
  qty_delta DECIMAL(18,3) NOT NULL,
  value_delta_khr DECIMAL(18,4) NOT NULL,
  unit_cost_snapshot_khr DECIMAL(18,6) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
KEY idx_cost_product (product_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
transfer_no VARCHAR(50) NOT NULL UNIQUE,
  from_location_id BIGINT UNSIGNED NOT NULL,
  to_location_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','confirmed','reversed') NOT NULL DEFAULT 'draft',
  confirmed_by BIGINT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  FOREIGN KEY (from_location_id) REFERENCES stock_locations(id),
  FOREIGN KEY (to_location_id) REFERENCES stock_locations(id),
  FOREIGN KEY (confirmed_by) REFERENCES users(id),
  CHECK (from_location_id <> to_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfer_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
transfer_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  qty_base DECIMAL(18,3) NOT NULL,
  FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (batch_id) REFERENCES stock_batches(id),
  CHECK (qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_adjustments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
adjustment_no VARCHAR(50) NOT NULL UNIQUE,
  reason TEXT NOT NULL,
  status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft',
  requested_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_adjustment_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
adjustment_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  qty_delta DECIMAL(18,3) NOT NULL,
  FOREIGN KEY (adjustment_id) REFERENCES stock_adjustments(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (location_id) REFERENCES stock_locations(id),
  FOREIGN KEY (batch_id) REFERENCES stock_batches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
invoice_no VARCHAR(50) NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NULL,
  sale_channel ENUM('retail','wholesale','fuel_shift') NOT NULL DEFAULT 'retail',
  status ENUM('draft','confirmed','voided') NOT NULL DEFAULT 'draft',
  currency CHAR(3) NOT NULL DEFAULT 'KHR',
  exchange_rate_to_khr DECIMAL(18,6) NOT NULL DEFAULT 1,
  subtotal_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
  discount_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
  total_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
KEY idx_sales_status_date (status,confirmed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
sale_id BIGINT UNSIGNED NOT NULL,
  product_unit_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  qty_sold DECIMAL(18,3) NOT NULL,
  qty_base DECIMAL(18,3) NOT NULL,
  unit_price_khr DECIMAL(18,4) NOT NULL,
  discount_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
  line_revenue_khr DECIMAL(18,4) NOT NULL,
  cogs_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
  FOREIGN KEY (location_id) REFERENCES stock_locations(id),
  CHECK (qty_sold > 0),
  CHECK (qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
return_no VARCHAR(50) NOT NULL UNIQUE,
  sale_id BIGINT UNSIGNED NOT NULL,
  status ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
  reason TEXT NOT NULL,
  confirmed_at DATETIME NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_return_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
sale_return_id BIGINT UNSIGNED NOT NULL,
  sale_item_id BIGINT UNSIGNED NOT NULL,
  qty_base DECIMAL(18,3) NOT NULL,
  restock BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (sale_return_id) REFERENCES sale_returns(id),
  FOREIGN KEY (sale_item_id) REFERENCES sale_items(id),
  CHECK (qty_base > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_methods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
direction ENUM('in','out') NOT NULL,
  payment_method_id BIGINT UNSIGNED NOT NULL,
  amount_original DECIMAL(18,4) NOT NULL,
  currency CHAR(3) NOT NULL,
  exchange_rate_to_khr DECIMAL(18,6) NOT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  reference_no VARCHAR(120) NULL,
  verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
  CHECK (amount_original > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receivables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
sale_id BIGINT UNSIGNED NOT NULL UNIQUE,
  customer_id BIGINT UNSIGNED NOT NULL,
  due_date DATE NULL,
  original_khr DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receivable_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
receivable_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  UNIQUE KEY uq_recv_allocation (receivable_id,payment_id),
  FOREIGN KEY (receivable_id) REFERENCES receivables(id),
  FOREIGN KEY (payment_id) REFERENCES payments(id),
  CHECK (amount_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payables (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
purchase_id BIGINT UNSIGNED NOT NULL UNIQUE,
  supplier_id BIGINT UNSIGNED NOT NULL,
  due_date DATE NULL,
  original_khr DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payable_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
payable_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  UNIQUE KEY uq_payable_allocation (payable_id,payment_id),
  FOREIGN KEY (payable_id) REFERENCES payables(id),
  FOREIGN KEY (payment_id) REFERENCES payments(id),
  CHECK (amount_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
name VARCHAR(120) NOT NULL UNIQUE,
  classification ENUM('operating','asset','other') NOT NULL DEFAULT 'operating'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
expense_no VARCHAR(50) NOT NULL UNIQUE,
  category_id BIGINT UNSIGNED NOT NULL,
  expense_date DATE NOT NULL,
  description TEXT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  status ENUM('draft','confirmed','voided') NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  CHECK (amount_khr >= 0),
KEY idx_expense_date (expense_date,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
expense_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  UNIQUE KEY uq_expense_payment (expense_id,payment_id),
  FOREIGN KEY (expense_id) REFERENCES expenses(id),
  FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_budgets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
category_id BIGINT UNSIGNED NOT NULL,
  period_month DATE NOT NULL,
  limit_khr DECIMAL(18,4) NOT NULL,
  UNIQUE KEY uq_budget_month (category_id,period_month),
  FOREIGN KEY (category_id) REFERENCES expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE recurring_expense_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
category_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  frequency ENUM('monthly','weekly') NOT NULL,
  next_due_date DATE NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  FOREIGN KEY (category_id) REFERENCES expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_types (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL UNIQUE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_tanks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
tank_code VARCHAR(40) NOT NULL UNIQUE,
  fuel_type_id BIGINT UNSIGNED NOT NULL,
  capacity_liters DECIMAL(18,3) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  FOREIGN KEY (fuel_type_id) REFERENCES fuel_types(id),
  CHECK (capacity_liters > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_pumps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
pump_code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_nozzles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
pump_id BIGINT UNSIGNED NOT NULL,
  tank_id BIGINT UNSIGNED NULL,
  nozzle_no INT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_pump_nozzle (pump_id,nozzle_no),
  FOREIGN KEY (pump_id) REFERENCES fuel_pumps(id),
  FOREIGN KEY (tank_id) REFERENCES fuel_tanks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_receipts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
fuel_tank_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  qty_liters DECIMAL(18,3) NOT NULL,
  landed_cost_khr DECIMAL(18,4) NOT NULL,
  received_at DATETIME NOT NULL,
  confirmed_by BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (fuel_tank_id) REFERENCES fuel_tanks(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (confirmed_by) REFERENCES users(id),
  CHECK (qty_liters > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_shifts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
shift_no VARCHAR(60) NOT NULL UNIQUE,
  status ENUM('open','pending_review','returned','confirmed','voided') NOT NULL DEFAULT 'open',
  opened_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  opened_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  approved_at DATETIME NULL,
  approval_reason TEXT NULL,
  FOREIGN KEY (opened_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_shift_nozzles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
shift_id BIGINT UNSIGNED NOT NULL,
  nozzle_id BIGINT UNSIGNED NOT NULL,
  opening_meter DECIMAL(18,3) NOT NULL,
  closing_meter DECIMAL(18,3) NULL,
  UNIQUE KEY uq_shift_nozzle (shift_id,nozzle_id),
  FOREIGN KEY (shift_id) REFERENCES fuel_shifts(id),
  FOREIGN KEY (nozzle_id) REFERENCES fuel_nozzles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_price_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
fuel_type_id BIGINT UNSIGNED NOT NULL,
  price_khr_per_liter DECIMAL(18,4) NOT NULL,
  effective_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (fuel_type_id) REFERENCES fuel_types(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id),
KEY idx_fuel_price_type_time (fuel_type_id,effective_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_sale_segments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
shift_nozzle_id BIGINT UNSIGNED NOT NULL,
  start_meter DECIMAL(18,3) NOT NULL,
  end_meter DECIMAL(18,3) NOT NULL,
  unit_price_khr DECIMAL(18,4) NOT NULL,
  segment_revenue_khr DECIMAL(18,4) NOT NULL,
  FOREIGN KEY (shift_nozzle_id) REFERENCES fuel_shift_nozzles(id),
  CHECK (end_meter >= start_meter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_shift_settlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
shift_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  settlement_type ENUM('cash','bank','credit') NOT NULL,
  amount_khr DECIMAL(18,4) NOT NULL,
  reference_no VARCHAR(120) NULL,
  FOREIGN KEY (shift_id) REFERENCES fuel_shifts(id),
  FOREIGN KEY (payment_id) REFERENCES payments(id),
  FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_reconciliations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
shift_id BIGINT UNSIGNED NOT NULL UNIQUE,
  expected_revenue_khr DECIMAL(18,4) NOT NULL,
  accounted_amount_khr DECIMAL(18,4) NOT NULL,
  variance_khr DECIMAL(18,4) NOT NULL,
  explanation TEXT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  FOREIGN KEY (shift_id) REFERENCES fuel_shifts(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_tank_measurements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
tank_id BIGINT UNSIGNED NOT NULL,
  measured_liters DECIMAL(18,3) NOT NULL,
  measured_at DATETIME NOT NULL,
  recorded_by BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (tank_id) REFERENCES fuel_tanks(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fuel_adjustments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
tank_id BIGINT UNSIGNED NOT NULL,
  qty_delta DECIMAL(18,3) NOT NULL,
  reason TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  requested_by BIGINT UNSIGNED NOT NULL,
  approved_by BIGINT UNSIGNED NULL,
  FOREIGN KEY (tank_id) REFERENCES fuel_tanks(id),
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
entity_type VARCHAR(60) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  reviewed_by BIGINT UNSIGNED NULL,
  status ENUM('pending','approved','returned','rejected') NOT NULL DEFAULT 'pending',
  reason TEXT NULL,
  review_note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  FOREIGN KEY (requested_by) REFERENCES users(id),
  FOREIGN KEY (reviewed_by) REFERENCES users(id),
KEY idx_approval_entity (entity_type,entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(60) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reorder_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
product_id BIGINT UNSIGNED NOT NULL,
  location_id BIGINT UNSIGNED NULL,
  minimum_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  safety_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  supplier_lead_days INT UNSIGNED NOT NULL DEFAULT 7,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (location_id) REFERENCES stock_locations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_settings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
setting_key VARCHAR(120) NOT NULL UNIQUE,
  setting_value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
