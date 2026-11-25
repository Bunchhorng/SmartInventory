-- SmartStock Phase 4.4: physical sales returns, restocking, and COGS reversal.
-- Import once after phase4_3_refunds_credit_notes.sql. This migration is forward-only.
USE smartstock;

-- Reuse the draft base-schema return documents. Existing historical rows, if
-- any, remain readable; all Phase 4.4 workflows populate the new attribution
-- and approval fields.
ALTER TABLE sale_returns
    MODIFY COLUMN status ENUM('draft','approved','confirmed','cancelled') NOT NULL DEFAULT 'draft',
    ADD COLUMN return_key VARCHAR(80) NULL AFTER return_no,
    ADD COLUMN credit_note_id BIGINT UNSIGNED NULL AFTER sale_id,
    ADD COLUMN requested_by BIGINT UNSIGNED NULL AFTER reason,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER requested_by,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER created_at,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN confirmed_by BIGINT UNSIGNED NULL AFTER confirmed_at,
    ADD COLUMN financial_amount_khr DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER confirmed_by,
    ADD UNIQUE KEY uq_sale_returns_return_key (return_key),
    ADD KEY idx_sale_returns_sale_status (sale_id, status),
    ADD KEY idx_sale_returns_credit_note_status (credit_note_id, status),
    ADD CONSTRAINT fk_sale_returns_credit_note FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id),
    ADD CONSTRAINT fk_sale_returns_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    ADD CONSTRAINT fk_sale_returns_approved_by FOREIGN KEY (approved_by) REFERENCES users(id),
    ADD CONSTRAINT fk_sale_returns_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id),
    ADD CONSTRAINT chk_sale_returns_financial_nonnegative CHECK (financial_amount_khr >= 0);

ALTER TABLE sale_return_items
    ADD COLUMN approved_qty_base DECIMAL(18,3) NOT NULL DEFAULT 0 AFTER qty_base,
    ADD COLUMN condition_code ENUM('resellable','damaged','expired','quarantine') NOT NULL DEFAULT 'resellable' AFTER approved_qty_base,
    ADD COLUMN financial_amount_khr DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER condition_code,
    ADD COLUMN cost_basis_khr DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER financial_amount_khr,
    ADD UNIQUE KEY uq_sale_return_item_sale_line (sale_return_id, sale_item_id),
    ADD KEY idx_sale_return_items_sale_item (sale_item_id),
    ADD CONSTRAINT chk_sale_return_items_approved_range CHECK (approved_qty_base >= 0 AND approved_qty_base <= qty_base),
    ADD CONSTRAINT chk_sale_return_items_financial_nonnegative CHECK (financial_amount_khr >= 0),
    ADD CONSTRAINT chk_sale_return_items_cost_nonnegative CHECK (cost_basis_khr >= 0);

-- Damaged/expired goods are never restored to sellable stock. Their original
-- sale-line cost is retained as a separately traceable loss record.
CREATE TABLE sale_return_writeoffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_return_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
    condition_code ENUM('damaged','expired') NOT NULL,
    amount_khr DECIMAL(18,4) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_return_item_id) REFERENCES sale_return_items(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    CHECK (amount_khr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quarantined goods are physically traceable but intentionally excluded from
-- available stock and product_costs until a later authorized disposition flow.
CREATE TABLE sale_return_quarantine_holds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_return_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
    product_id BIGINT UNSIGNED NOT NULL,
    origin_location_id BIGINT UNSIGNED NOT NULL,
    qty_base DECIMAL(18,3) NOT NULL,
    cost_basis_khr DECIMAL(18,4) NOT NULL,
    status ENUM('held') NOT NULL DEFAULT 'held',
    held_by BIGINT UNSIGNED NOT NULL,
    held_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_return_item_id) REFERENCES sale_return_items(id),
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (origin_location_id) REFERENCES stock_locations(id),
    FOREIGN KEY (held_by) REFERENCES users(id),
    CHECK (qty_base > 0),
    CHECK (cost_basis_khr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
