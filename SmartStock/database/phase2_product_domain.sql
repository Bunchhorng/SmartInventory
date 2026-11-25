-- SmartStock Phase 2 product-domain migration.
-- Import once AFTER the base schema, reference seed, and both Phase 1 migrations.
-- This migration is forward-only and assumes no duplicate category siblings,
-- unit names, or current product-unit/type/currency prices already exist.
-- Resolve any such data before importing it.
USE smartstock;

-- MySQL UNIQUE indexes treat NULL values as distinct. A generated root key makes
-- duplicate root categories as well as duplicate child categories impossible.
ALTER TABLE categories
    ADD COLUMN parent_key BIGINT UNSIGNED
        GENERATED ALWAYS AS (COALESCE(parent_id, 0)) STORED,
    ADD UNIQUE KEY uq_category_sibling (parent_key, name),
    ADD KEY idx_categories_parent_active_name (parent_id, is_active, name);

-- Units are master data. Keep historical references intact by archiving rather
-- than deleting a unit. Product and stock quantities currently use DECIMAL(18,3),
-- so precision is validated in PHP to a maximum of three fractional digits.
ALTER TABLE units
    ADD COLUMN status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    ADD UNIQUE KEY uq_units_name (name),
    ADD KEY idx_units_status_name (status, name);

-- A price history may contain many non-current rows, while at most one current
-- price may exist for each product-unit/type/currency combination.
ALTER TABLE product_prices
    ADD COLUMN current_price_type VARCHAR(12)
        GENERATED ALWAYS AS (CASE WHEN is_current THEN price_type ELSE NULL END) STORED,
    ADD COLUMN current_currency CHAR(3)
        GENERATED ALWAYS AS (CASE WHEN is_current THEN currency ELSE NULL END) STORED,
    ADD UNIQUE KEY uq_product_price_current (product_unit_id, current_price_type, current_currency);

ALTER TABLE products
    ADD KEY idx_products_status_name (status, name),
    ADD KEY idx_products_category_status (category_id, status);
