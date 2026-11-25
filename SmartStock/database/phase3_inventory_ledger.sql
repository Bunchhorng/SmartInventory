-- SmartStock Phase 3.1 inventory ledger and valued stock-receipt migration.
-- Import once AFTER the base schema, reference seed, and Phase 1/Phase 2 migrations.
-- This migration is forward-only and does not alter posted stock movements.
USE smartstock;

-- The base goods_receipts table is deliberately purchase-bound. These receipt
-- documents cover explicitly valued opening stock and externally referenced
-- purchases while the purchase workflow and moving-average costing remain
-- separate milestones.
CREATE TABLE inventory_receipts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(50) NOT NULL UNIQUE,
    receipt_type ENUM('opening','purchase') NOT NULL,
    reference_no VARCHAR(80) NOT NULL UNIQUE,
    location_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft','confirmed','reversed') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    reversed_by BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (location_id) REFERENCES stock_locations(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (confirmed_by) REFERENCES users(id),
    FOREIGN KEY (reversed_by) REFERENCES users(id),
    KEY idx_inventory_receipts_status_location (status, location_id),
    KEY idx_inventory_receipts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_receipt_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receipt_id BIGINT UNSIGNED NOT NULL,
    product_unit_id BIGINT UNSIGNED NOT NULL,
    qty_received DECIMAL(18,3) NOT NULL,
    qty_base DECIMAL(18,3) NOT NULL,
    -- Cost is per product base unit. It may be omitted in a Staff-created
    -- draft, but confirmation rejects every receipt with a NULL cost.
    cost_per_base_khr DECIMAL(18,6) NULL,
    FOREIGN KEY (receipt_id) REFERENCES inventory_receipts(id),
    FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
    UNIQUE KEY uq_inventory_receipt_product_unit (receipt_id, product_unit_id),
    CHECK (qty_received > 0),
    CHECK (qty_base > 0),
    CHECK (cost_per_base_khr IS NULL OR cost_per_base_khr >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- stock_movements already has source_type/source_id/source_line_id and a
-- globally unique movement_key. Phase 3 uses those as the immutable movement
-- type, document reference, line reference, and posting identifier.
ALTER TABLE stock_movements
    ADD KEY idx_movement_product_location_time (product_id, location_id, created_at);
