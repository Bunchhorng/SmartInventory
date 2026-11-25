-- Forward-only, one-time migration after Phase 5.5. No historical backfill.
USE smartstock;

CREATE TABLE purchase_returns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 return_no VARCHAR(50) NOT NULL UNIQUE,
 document_key VARCHAR(80) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL, purchase_id BIGINT UNSIGNED NOT NULL,
 goods_receipt_id BIGINT UNSIGNED NOT NULL, location_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 status ENUM('draft','submitted','approved','confirmed','rejected','cancelled') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
 dispatched_by BIGINT UNSIGNED NULL, dispatched_at DATETIME NULL, dispatch_reference VARCHAR(120) NULL,
 confirmed_by BIGINT UNSIGNED NULL, confirmed_at DATETIME NULL,
 rejected_by BIGINT UNSIGNED NULL, rejected_at DATETIME NULL,
 cancelled_by BIGINT UNSIGNED NULL, cancelled_at DATETIME NULL, termination_reason VARCHAR(500) NULL,
 FOREIGN KEY (supplier_id) REFERENCES suppliers(id), FOREIGN KEY (purchase_id) REFERENCES purchases(id),
 FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipts(id), FOREIGN KEY (location_id) REFERENCES stock_locations(id),
 FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
 FOREIGN KEY (approved_by) REFERENCES users(id), FOREIGN KEY (dispatched_by) REFERENCES users(id),
 FOREIGN KEY (confirmed_by) REFERENCES users(id), FOREIGN KEY (rejected_by) REFERENCES users(id), FOREIGN KEY (cancelled_by) REFERENCES users(id),
 KEY idx_purchase_return_po (purchase_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, return_id BIGINT UNSIGNED NOT NULL,
 goods_receipt_item_id BIGINT UNSIGNED NOT NULL, purchase_item_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL, product_unit_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 conversion_snapshot DECIMAL(18,6) NOT NULL, unit_precision_snapshot TINYINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 FOREIGN KEY (return_id) REFERENCES purchase_returns(id), FOREIGN KEY (goods_receipt_item_id) REFERENCES goods_receipt_items(id),
 FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id), FOREIGN KEY (product_id) REFERENCES products(id),
 FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
 UNIQUE KEY uq_purchase_return_receipt_line (return_id,goods_receipt_item_id),
 CHECK (quantity>0 AND qty_base>0 AND conversion_snapshot>0 AND unit_precision_snapshot<=3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_approvals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, return_id BIGINT UNSIGNED NOT NULL UNIQUE,
 content_hash CHAR(64) NOT NULL, approved_by BIGINT UNSIGNED NOT NULL,
 approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (return_id) REFERENCES purchase_returns(id), FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_postings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, return_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
 stock_movement_id BIGINT UNSIGNED NOT NULL UNIQUE, posting_key VARCHAR(150) NOT NULL UNIQUE,
 quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 unit_cost_khr DECIMAL(18,6) NOT NULL, carrying_value_khr DECIMAL(18,4) NOT NULL,
 receipt_value_khr DECIMAL(18,4) NOT NULL, carrying_difference_khr DECIMAL(18,4) NOT NULL,
 posted_by BIGINT UNSIGNED NOT NULL, posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (return_item_id) REFERENCES purchase_return_items(id), FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id),
 FOREIGN KEY (posted_by) REFERENCES users(id),
 CHECK (quantity>0 AND qty_base>0 AND unit_cost_khr>=0 AND carrying_value_khr>=0 AND receipt_value_khr>=0),
 CHECK (carrying_value_khr-receipt_value_khr=carrying_difference_khr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attribution only: linking never posts another credit, payable allocation or cash flow.
CREATE TABLE purchase_return_credit_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, link_key VARCHAR(80) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 return_item_id BIGINT UNSIGNED NOT NULL, credit_id BIGINT UNSIGNED NOT NULL,
 invoice_match_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 amount_original DECIMAL(18,4) NOT NULL, credit_value_khr DECIMAL(18,4) NOT NULL,
 carrying_value_khr DECIMAL(18,4) NOT NULL, difference_khr DECIMAL(18,4) NOT NULL,
 reason VARCHAR(500) NOT NULL, linked_by BIGINT UNSIGNED NOT NULL, linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (return_item_id) REFERENCES purchase_return_items(id), FOREIGN KEY (credit_id) REFERENCES supplier_credit_notes(id),
 FOREIGN KEY (invoice_match_id) REFERENCES supplier_invoice_matches(id), FOREIGN KEY (linked_by) REFERENCES users(id),
 KEY idx_return_credit_credit (credit_id,id), KEY idx_return_credit_match (invoice_match_id,id),
 CHECK (quantity>0 AND qty_base>0 AND amount_original>0 AND credit_value_khr>=0 AND carrying_value_khr>=0),
 CHECK (credit_value_khr-carrying_value_khr=difference_khr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code,description) VALUES
 ('purchase_returns.view','View quantity-only purchase returns'),
 ('purchase_returns.request','Create and submit purchase returns'),
 ('purchase_returns.approve','Approve/reject/cancel purchase returns'),
 ('purchase_returns.confirm','Confirm physical supplier dispatch'),
 ('purchase_returns.link_credit','Attribute posted supplier credits to physical returns'),
 ('purchase_returns.reconcile','Reconcile physical purchase returns and credit attribution');
INSERT INTO role_permissions (role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE p.code LIKE 'purchase_returns.%'
 AND (r.code='owner' OR (r.code='staff' AND p.code IN ('purchase_returns.view','purchase_returns.request')));
