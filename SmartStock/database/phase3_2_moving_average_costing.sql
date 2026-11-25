-- SmartStock Phase 3.2 moving weighted-average costing migration.
-- Import once AFTER the base schema, reference seed, Phase 1/2 migrations,
-- and database/phase3_inventory_ledger.sql. This migration is forward-only.
USE smartstock;

-- Reuse the existing valuation ledger. Legacy/manual rows, if any, remain
-- readable with a NULL stock_movement_id; all Phase 3.2 postings populate
-- every new attribution column through MovingAverageCostService.
ALTER TABLE inventory_cost_entries
    ADD COLUMN stock_movement_id BIGINT UNSIGNED NULL AFTER product_id,
    ADD COLUMN avg_unit_cost_before_khr DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER unit_cost_snapshot_khr,
    ADD COLUMN avg_unit_cost_after_khr DECIMAL(18,6) NOT NULL DEFAULT 0 AFTER avg_unit_cost_before_khr,
    ADD COLUMN costing_type VARCHAR(50) NOT NULL DEFAULT 'legacy' AFTER avg_unit_cost_after_khr,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER created_at,
    ADD UNIQUE KEY uq_inventory_cost_entry_movement (stock_movement_id),
    ADD KEY idx_inventory_cost_product_type_time (product_id, costing_type, created_at),
    ADD CONSTRAINT fk_inventory_cost_entry_movement
        FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id),
    ADD CONSTRAINT fk_inventory_cost_entry_created_by
        FOREIGN KEY (created_by) REFERENCES users(id);

-- Product costs are the locked, materialized company-wide average state. The
-- values are maintained only by the Phase 3.2 costing service; immutable
-- inventory_cost_entries provide reconciliation and correction history.
ALTER TABLE product_costs
    ADD CONSTRAINT chk_product_costs_qty_nonnegative CHECK (qty_valued >= 0),
    ADD CONSTRAINT chk_product_costs_value_nonnegative CHECK (inventory_value_khr >= 0),
    ADD CONSTRAINT chk_product_costs_average_nonnegative CHECK (avg_unit_cost_khr >= 0);
