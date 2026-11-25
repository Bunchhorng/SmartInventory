-- SmartStock Phase 6.1: controlled internal transfers. Import once after Phase 5.8.
-- This forward-only migration upgrades the unused base-schema transfer placeholders
-- into approval-controlled, immutable transfer documents. Do not run it on the
-- business database without an approved migration window.
USE smartstock;

ALTER TABLE stock_transfers
    MODIFY COLUMN status ENUM('draft','submitted','approved','confirmed','rejected','cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN document_key VARCHAR(80) NOT NULL UNIQUE AFTER transfer_no,
    ADD COLUMN request_hash CHAR(64) NOT NULL AFTER document_key,
    ADD COLUMN created_by BIGINT UNSIGNED NOT NULL AFTER status,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by,
    ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER created_at,
    ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER submitted_at,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN terminated_by BIGINT UNSIGNED NULL AFTER confirmed_at,
    ADD COLUMN terminated_at DATETIME NULL AFTER terminated_by,
    ADD COLUMN termination_reason VARCHAR(500) NULL AFTER terminated_at,
    ADD CONSTRAINT fk_stock_transfer_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    ADD CONSTRAINT fk_stock_transfer_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id),
    ADD CONSTRAINT fk_stock_transfer_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    ADD CONSTRAINT fk_stock_transfer_terminated_by FOREIGN KEY (terminated_by) REFERENCES users(id),
    ADD KEY idx_stock_transfer_status_created (status, created_at),
    ADD KEY idx_stock_transfer_source_destination (from_location_id, to_location_id, id),
    ADD CONSTRAINT chk_stock_transfer_confirmation
        CHECK (status <> 'confirmed' OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL));

ALTER TABLE stock_transfer_items
    ADD COLUMN product_unit_id BIGINT UNSIGNED NOT NULL AFTER transfer_id,
    ADD COLUMN quantity DECIMAL(18,3) NOT NULL AFTER product_unit_id,
    ADD COLUMN conversion_snapshot DECIMAL(18,6) NOT NULL AFTER qty_base,
    ADD COLUMN unit_precision_snapshot TINYINT UNSIGNED NOT NULL AFTER conversion_snapshot,
    ADD CONSTRAINT fk_stock_transfer_item_product_unit FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
    ADD UNIQUE KEY uq_stock_transfer_item_product (transfer_id, product_id),
    ADD KEY idx_stock_transfer_item_product (product_id, id),
    ADD CONSTRAINT chk_stock_transfer_item_snapshot
        CHECK (quantity > 0 AND qty_base > 0 AND conversion_snapshot > 0 AND unit_precision_snapshot <= 3);

CREATE TABLE stock_transfer_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transfer_id BIGINT UNSIGNED NOT NULL UNIQUE,
    content_hash CHAR(64) NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transfer_postings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transfer_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
    source_stock_movement_id BIGINT UNSIGNED NOT NULL UNIQUE,
    destination_stock_movement_id BIGINT UNSIGNED NOT NULL UNIQUE,
    source_cost_entry_id BIGINT UNSIGNED NOT NULL UNIQUE,
    destination_cost_entry_id BIGINT UNSIGNED NOT NULL UNIQUE,
    posting_key VARCHAR(180) NOT NULL UNIQUE,
    qty_base DECIMAL(18,3) NOT NULL,
    unit_cost_khr DECIMAL(18,6) NOT NULL,
    carrying_value_khr DECIMAL(18,4) NOT NULL,
    posted_by BIGINT UNSIGNED NOT NULL,
    posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (transfer_item_id) REFERENCES stock_transfer_items(id),
    FOREIGN KEY (source_stock_movement_id) REFERENCES stock_movements(id),
    FOREIGN KEY (destination_stock_movement_id) REFERENCES stock_movements(id),
    FOREIGN KEY (source_cost_entry_id) REFERENCES inventory_cost_entries(id),
    FOREIGN KEY (destination_cost_entry_id) REFERENCES inventory_cost_entries(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    CHECK (qty_base > 0 AND unit_cost_khr >= 0 AND carrying_value_khr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description) VALUES
    ('stock_transfers.view', 'View internal stock transfer quantities'),
    ('stock_transfers.request', 'Create and submit quantity-only internal stock transfers'),
    ('stock_transfers.approve', 'Approve, reject or cancel internal stock transfers'),
    ('stock_transfers.confirm', 'Confirm internal stock transfers'),
    ('stock_transfers.reconcile', 'View internal stock-transfer valuation reconciliation');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE (r.code = 'owner' AND p.code LIKE 'stock_transfers.%')
   OR (r.code = 'staff' AND p.code IN ('stock_transfers.view', 'stock_transfers.request'));
