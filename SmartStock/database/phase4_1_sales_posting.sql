-- SmartStock Phase 4.1 confirmed sales posting migration.
-- Import once AFTER the base schema, reference seed, Phases 1/2/3.1/3.2.
-- This is forward-only. It does not post or backfill historical sales.
USE smartstock;

ALTER TABLE sales
    ADD COLUMN confirmed_by BIGINT UNSIGNED NULL AFTER confirmed_at,
    ADD COLUMN voided_at DATETIME NULL AFTER confirmed_by,
    ADD COLUMN voided_by BIGINT UNSIGNED NULL AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(500) NULL AFTER voided_by,
    ADD COLUMN void_restock_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER void_reason,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER created_by,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD CONSTRAINT fk_sales_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id),
    ADD CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users(id),
    ADD KEY idx_sales_created_by_status (created_by, status, id);

ALTER TABLE sale_items
    ADD COLUMN price_type VARCHAR(12) NOT NULL DEFAULT 'retail' AFTER product_unit_id,
    ADD COLUMN unit_to_base_factor DECIMAL(18,6) NOT NULL DEFAULT 1 AFTER location_id,
    ADD COLUMN is_price_override TINYINT(1) NOT NULL DEFAULT 0 AFTER unit_price_khr,
    ADD COLUMN cogs_unit_cost_khr DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER cogs_khr,
    ADD UNIQUE KEY uq_sale_item_product_unit (sale_id, product_unit_id),
    ADD KEY idx_sale_items_location_product (location_id, product_unit_id),
    ADD CONSTRAINT chk_sale_items_price_type CHECK (price_type IN ('retail', 'wholesale')),
    ADD CONSTRAINT chk_sale_items_discount_nonnegative CHECK (discount_khr >= 0),
    ADD CONSTRAINT chk_sale_items_revenue_nonnegative CHECK (line_revenue_khr >= 0),
    ADD CONSTRAINT chk_sale_items_cogs_nonnegative CHECK (cogs_khr >= 0),
    ADD CONSTRAINT chk_sale_items_cogs_unit_nonnegative CHECK (cogs_unit_cost_khr >= 0);

ALTER TABLE sales
    ADD CONSTRAINT chk_sales_subtotal_nonnegative CHECK (subtotal_khr >= 0),
    ADD CONSTRAINT chk_sales_discount_nonnegative CHECK (discount_khr >= 0),
    ADD CONSTRAINT chk_sales_total_nonnegative CHECK (total_khr >= 0);
