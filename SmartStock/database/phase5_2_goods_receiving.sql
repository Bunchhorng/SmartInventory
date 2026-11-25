-- Phase 5.2. Import ONCE after Phase 5.1, following backup/review.
-- No stock, valuation, receiving history, or financial posting is backfilled.
USE smartstock;

ALTER TABLE purchases
    ADD COLUMN closed_by BIGINT UNSIGNED NULL,
    ADD COLUMN closed_at DATETIME NULL,
    ADD CONSTRAINT fk_purchases_closed_by FOREIGN KEY (closed_by) REFERENCES users(id);

ALTER TABLE purchase_items
    ADD COLUMN received_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
    ADD COLUMN unit_precision_snapshot TINYINT UNSIGNED NULL,
    ADD CONSTRAINT chk_purchase_received_range CHECK (received_qty >= 0 AND received_qty <= ordered_qty);

-- Phase 5.1 did not capture precision or supplier identity in the header
-- snapshot. Capture the existing catalog precision for upgrade compatibility;
-- review historical POs if catalog precision was changed after their approval.
UPDATE purchase_items pi JOIN product_units pu ON pu.id = pi.product_unit_id
JOIN units u ON u.id = pu.unit_id SET pi.unit_precision_snapshot = u.precision_digits;
ALTER TABLE purchase_order_approval_snapshots
    ADD COLUMN supplier_id_snapshot BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_po_snapshot_supplier FOREIGN KEY (supplier_id_snapshot) REFERENCES suppliers(id);
UPDATE purchase_order_approval_snapshots a JOIN purchases p ON p.id = a.purchase_id
SET a.supplier_id_snapshot = p.supplier_id;

ALTER TABLE inventory_receipts
    MODIFY COLUMN status ENUM('draft','confirmed','reversed','cancelled') NOT NULL DEFAULT 'draft';

-- Reuse the base purchase-bound receiving documents and link each to the
-- existing Phase 3 inventory document. Only InventoryService posts its ledger.
ALTER TABLE goods_receipts
    MODIFY COLUMN status ENUM('draft','confirmed','reversed','cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN receipt_key VARCHAR(80) NULL,
    ADD COLUMN request_hash CHAR(64) NULL,
    ADD COLUMN supplier_id BIGINT UNSIGNED NULL,
    ADD COLUMN reference_no VARCHAR(80) NULL,
    ADD COLUMN delivery_notes VARCHAR(1000) NULL,
    ADD COLUMN received_by BIGINT UNSIGNED NULL,
    ADD COLUMN received_at DATETIME NULL,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN cancelled_by BIGINT UNSIGNED NULL,
    ADD COLUMN cancelled_at DATETIME NULL,
    ADD COLUMN cancellation_reason VARCHAR(500) NULL,
    ADD COLUMN inventory_receipt_id BIGINT UNSIGNED NULL,
    ADD COLUMN currency_snapshot CHAR(3) NULL,
    ADD COLUMN exchange_rate_snapshot DECIMAL(18,6) NULL,
    ADD UNIQUE KEY uq_goods_receipt_key (receipt_key),
    ADD UNIQUE KEY uq_goods_supplier_delivery (supplier_id, reference_no),
    ADD UNIQUE KEY uq_goods_inventory_receipt (inventory_receipt_id),
    ADD KEY idx_goods_purchase_status (purchase_id, status),
    ADD CONSTRAINT fk_goods_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    ADD CONSTRAINT fk_goods_received_by FOREIGN KEY (received_by) REFERENCES users(id),
    ADD CONSTRAINT fk_goods_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id),
    ADD CONSTRAINT fk_goods_inventory FOREIGN KEY (inventory_receipt_id) REFERENCES inventory_receipts(id),
    ADD CONSTRAINT chk_goods_rate CHECK (exchange_rate_snapshot IS NULL OR exchange_rate_snapshot > 0);

ALTER TABLE goods_receipt_items
    MODIFY COLUMN landed_unit_cost_khr DECIMAL(18,6) NOT NULL,
    ADD COLUMN inventory_receipt_item_id BIGINT UNSIGNED NULL,
    ADD COLUMN received_qty DECIMAL(18,3) NULL,
    ADD COLUMN conversion_snapshot DECIMAL(18,6) NULL,
    ADD COLUMN approved_cost_per_base_khr DECIMAL(18,6) NULL,
    ADD UNIQUE KEY uq_goods_receipt_po_line (receipt_id, purchase_item_id),
    ADD UNIQUE KEY uq_goods_inventory_item (inventory_receipt_item_id),
    ADD CONSTRAINT fk_goods_inventory_item FOREIGN KEY (inventory_receipt_item_id) REFERENCES inventory_receipt_items(id),
    ADD CONSTRAINT chk_goods_received_positive CHECK (received_qty IS NULL OR received_qty > 0),
    ADD CONSTRAINT chk_goods_cost_positive CHECK (landed_unit_cost_khr > 0);

-- Append-only explicit Owner variance approvals; no approved PO is repriced.
CREATE TABLE goods_receipt_cost_variances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    goods_receipt_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
    approved_cost_per_base_khr DECIMAL(18,6) NOT NULL,
    actual_cost_per_base_khr DECIMAL(18,6) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (goods_receipt_item_id) REFERENCES goods_receipt_items(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    CHECK (approved_cost_per_base_khr > 0 AND actual_cost_per_base_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description) VALUES
    ('purchases.receive', 'Create quantity-only goods receipt drafts'),
    ('purchases.receive.confirm', 'Confirm valued purchase receipts'),
    ('purchases.cost_variance.approve', 'Approve a receipt cost variance'),
    ('purchases.close', 'Close fully received purchase orders');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'owner' AND p.code IN ('purchases.receive','purchases.receive.confirm','purchases.cost_variance.approve','purchases.close');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'purchases.receive' WHERE r.code = 'staff';
