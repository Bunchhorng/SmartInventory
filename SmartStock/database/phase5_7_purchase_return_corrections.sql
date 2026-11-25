-- Forward-only, one-time migration after Phase 5.6. No historical backfill.
-- Import manually only after reviewing the documented preflight; the application never runs migrations.
USE smartstock;

CREATE TABLE purchase_return_corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_no VARCHAR(50) NOT NULL UNIQUE,
 document_key VARCHAR(80) NOT NULL UNIQUE,
 request_hash CHAR(64) NOT NULL,
 purchase_return_id BIGINT UNSIGNED NOT NULL,
 reason VARCHAR(500) NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL,
 status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
 posted_by BIGINT UNSIGNED NULL, posted_at DATETIME NULL,
 terminated_by BIGINT UNSIGNED NULL, terminated_at DATETIME NULL, termination_reason VARCHAR(500) NULL,
 FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id),
 FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
 FOREIGN KEY (approved_by) REFERENCES users(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 FOREIGN KEY (terminated_by) REFERENCES users(id),
 KEY idx_purchase_return_correction_return (purchase_return_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_correction_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_id BIGINT UNSIGNED NOT NULL,
 purchase_return_posting_id BIGINT UNSIGNED NOT NULL,
 return_item_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL, location_id BIGINT UNSIGNED NOT NULL,
 quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 conversion_snapshot DECIMAL(18,6) NOT NULL, unit_precision_snapshot TINYINT UNSIGNED NOT NULL,
 original_unit_cost_khr DECIMAL(18,6) NOT NULL, original_carrying_value_khr DECIMAL(18,4) NOT NULL,
 evidence_reference VARCHAR(500) NOT NULL,
 FOREIGN KEY (correction_id) REFERENCES purchase_return_corrections(id),
 FOREIGN KEY (purchase_return_posting_id) REFERENCES purchase_return_postings(id),
 FOREIGN KEY (return_item_id) REFERENCES purchase_return_items(id),
 FOREIGN KEY (product_id) REFERENCES products(id), FOREIGN KEY (location_id) REFERENCES stock_locations(id),
 UNIQUE KEY uq_purchase_return_correction_source (correction_id,purchase_return_posting_id),
 CHECK (quantity>0 AND qty_base>0 AND conversion_snapshot>0 AND unit_precision_snapshot<=3),
 CHECK (original_unit_cost_khr>=0 AND original_carrying_value_khr>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_correction_approvals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_id BIGINT UNSIGNED NOT NULL UNIQUE, content_hash CHAR(64) NOT NULL,
 approved_by BIGINT UNSIGNED NOT NULL, approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (correction_id) REFERENCES purchase_return_corrections(id), FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_return_correction_postings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_item_id BIGINT UNSIGNED NOT NULL UNIQUE, stock_movement_id BIGINT UNSIGNED NOT NULL UNIQUE,
 posting_key VARCHAR(160) NOT NULL UNIQUE, quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 unit_cost_khr DECIMAL(18,6) NOT NULL, carrying_value_khr DECIMAL(18,4) NOT NULL,
 posted_by BIGINT UNSIGNED NOT NULL, posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (correction_item_id) REFERENCES purchase_return_correction_items(id),
 FOREIGN KEY (stock_movement_id) REFERENCES stock_movements(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 CHECK (quantity>0 AND qty_base>0 AND unit_cost_khr>=0 AND carrying_value_khr>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code,description) VALUES
 ('purchase_return_corrections.view','View quantity-only purchase return corrections'),
 ('purchase_return_corrections.request','Create and submit purchase return correction requests'),
 ('purchase_return_corrections.approve','Approve/reject/cancel purchase return corrections'),
 ('purchase_return_corrections.post','Post verified purchase return restoration corrections'),
 ('purchase_return_corrections.reconcile','Reconcile purchase return correction postings');
INSERT INTO role_permissions (role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE p.code LIKE 'purchase_return_corrections.%'
 AND (r.code='owner' OR (r.code='staff' AND p.code IN ('purchase_return_corrections.view','purchase_return_corrections.request')));
