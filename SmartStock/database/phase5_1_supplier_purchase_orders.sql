-- SmartStock Phase 5.1: supplier management and non-posting purchase orders.
-- Import once after phase4_4_sales_returns.sql. This migration is forward-only.
-- It intentionally does not create goods receipts, stock movements, payables,
-- supplier invoices, or supplier-payment postings.
USE smartstock;

-- The draft base schema already owns suppliers and purchases. Extend those
-- structures rather than creating a second supplier or purchase-order domain.
ALTER TABLE suppliers
    MODIFY COLUMN status ENUM('active','inactive','archived') NOT NULL DEFAULT 'active',
    ADD COLUMN supplier_code VARCHAR(50) NULL AFTER id,
    ADD COLUMN contact_person VARCHAR(180) NULL AFTER name,
    ADD COLUMN email VARCHAR(190) NULL AFTER phone,
    ADD COLUMN payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER address,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER payment_terms_days,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD UNIQUE KEY uq_suppliers_supplier_code (supplier_code),
    ADD KEY idx_suppliers_status_name (status, name),
    ADD CONSTRAINT chk_suppliers_payment_terms_nonnegative CHECK (payment_terms_days >= 0);

-- Preserve any historical "ordered" base-schema rows before replacing the
-- original narrow status set with the Phase 5.1 purchase-order lifecycle.
UPDATE purchases SET status = 'submitted' WHERE status = 'ordered';

ALTER TABLE purchases
    MODIFY COLUMN status ENUM('draft','submitted','approved','partially_received','received','closed','rejected','cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN purchase_key VARCHAR(80) NULL AFTER purchase_no,
    ADD COLUMN expected_delivery_date DATE NULL AFTER ordered_at,
    ADD COLUMN notes VARCHAR(1000) NULL AFTER expected_delivery_date,
    ADD COLUMN estimated_total DECIMAL(18,4) NULL AFTER exchange_rate_to_khr,
    ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER created_by,
    ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN rejected_by BIGINT UNSIGNED NULL AFTER approved_at,
    ADD COLUMN rejected_at DATETIME NULL AFTER rejected_by,
    ADD COLUMN rejection_reason VARCHAR(500) NULL AFTER rejected_at,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL AFTER rejection_reason,
    ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
    ADD COLUMN cancellation_reason VARCHAR(500) NULL AFTER cancelled_at,
    ADD UNIQUE KEY uq_purchases_purchase_key (purchase_key),
    ADD KEY idx_purchases_supplier_status (supplier_id, status),
    ADD KEY idx_purchases_status_created (status, created_at),
    ADD CONSTRAINT fk_purchases_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id),
    ADD CONSTRAINT fk_purchases_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    ADD CONSTRAINT fk_purchases_rejected_by FOREIGN KEY (rejected_by) REFERENCES users(id),
    ADD CONSTRAINT fk_purchases_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id),
    ADD CONSTRAINT chk_purchases_exchange_rate_positive CHECK (exchange_rate_to_khr > 0),
    ADD CONSTRAINT chk_purchases_estimated_total_nonnegative CHECK (estimated_total IS NULL OR estimated_total >= 0);

ALTER TABLE purchase_items
    MODIFY COLUMN unit_purchase_price DECIMAL(18,4) NULL,
    ADD COLUMN discount_percent DECIMAL(7,4) NOT NULL DEFAULT 0 AFTER unit_purchase_price,
    ADD COLUMN product_sku_snapshot VARCHAR(100) NULL AFTER product_unit_id,
    ADD COLUMN product_name_snapshot VARCHAR(180) NULL AFTER product_sku_snapshot,
    ADD COLUMN unit_code_snapshot VARCHAR(50) NULL AFTER product_name_snapshot,
    ADD COLUMN unit_name_snapshot VARCHAR(120) NULL AFTER unit_code_snapshot,
    ADD COLUMN unit_to_base_factor_snapshot DECIMAL(18,6) NULL AFTER unit_name_snapshot,
    ADD COLUMN qty_base_snapshot DECIMAL(18,6) NULL AFTER unit_to_base_factor_snapshot,
    ADD COLUMN line_subtotal DECIMAL(18,4) NULL AFTER qty_base_snapshot,
    ADD COLUMN line_discount_amount DECIMAL(18,4) NULL AFTER line_subtotal,
    ADD COLUMN line_total DECIMAL(18,4) NULL AFTER line_discount_amount,
    ADD KEY idx_purchase_items_purchase (purchase_id),
    ADD CONSTRAINT chk_purchase_items_discount_range CHECK (discount_percent >= 0 AND discount_percent <= 100),
    ADD CONSTRAINT chk_purchase_items_price_positive CHECK (unit_purchase_price IS NULL OR unit_purchase_price > 0),
    ADD CONSTRAINT chk_purchase_items_qty_base_positive CHECK (qty_base_snapshot IS NULL OR qty_base_snapshot > 0),
    ADD CONSTRAINT chk_purchase_items_line_values_nonnegative CHECK (
        (line_subtotal IS NULL OR line_subtotal >= 0)
        AND (line_discount_amount IS NULL OR line_discount_amount >= 0)
        AND (line_total IS NULL OR line_total >= 0)
    );

-- This is an immutable header snapshot created exactly once when an Owner
-- approves a submitted order. Line-level product, conversion, price, and
-- discount snapshots live on purchase_items and become immutable at approval.
CREATE TABLE purchase_order_approval_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    purchase_id BIGINT UNSIGNED NOT NULL UNIQUE,
    purchase_no_snapshot VARCHAR(50) NOT NULL,
    supplier_code_snapshot VARCHAR(50) NOT NULL,
    supplier_name_snapshot VARCHAR(180) NOT NULL,
    currency_snapshot CHAR(3) NOT NULL,
    exchange_rate_to_khr_snapshot DECIMAL(18,6) NOT NULL,
    estimated_total_snapshot DECIMAL(18,4) NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    CHECK (exchange_rate_to_khr_snapshot > 0),
    CHECK (estimated_total_snapshot >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchasing permissions are server-side checks. Staff receive only supplier
-- visibility, PO visibility, and draft-request capability; all cost, approval,
-- rejection, and cancellation authority remains Owner-only in service code.
INSERT INTO permissions (code, description) VALUES
    ('suppliers.view', 'View permitted supplier information'),
    ('suppliers.manage', 'Manage supplier records'),
    ('purchases.view', 'View permitted purchase orders'),
    ('purchases.request', 'Create and submit purchase requests'),
    ('purchases.approve', 'Approve or reject purchase orders'),
    ('purchases.cancel', 'Cancel purchase orders');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN (
    'suppliers.view','suppliers.manage','purchases.view',
    'purchases.request','purchases.approve','purchases.cancel'
)
WHERE r.code = 'owner';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code IN ('suppliers.view','purchases.view','purchases.request')
WHERE r.code = 'staff';
