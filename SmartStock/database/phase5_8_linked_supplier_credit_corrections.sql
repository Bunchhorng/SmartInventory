-- Phase 5.8: import ONCE after Phase 5.7, only with explicit main DB approval.
-- Forward-only. Posted supplier credits, purchase returns, stock and valuation history remain immutable.
USE smartstock;

CREATE TABLE supplier_credit_return_corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_no VARCHAR(60) NOT NULL UNIQUE,
 document_key VARCHAR(80) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL, credit_id BIGINT UNSIGNED NOT NULL,
 purchase_return_id BIGINT UNSIGNED NOT NULL, purchase_return_credit_link_id BIGINT UNSIGNED NOT NULL,
 invoice_id BIGINT UNSIGNED NOT NULL, currency CHAR(3) NOT NULL, exchange_rate_to_khr DECIMAL(18,6) NOT NULL,
 amount_original DECIMAL(18,4) NOT NULL, quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 reason VARCHAR(500) NOT NULL, evidence_reference VARCHAR(500) NOT NULL,
 status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
 posted_by BIGINT UNSIGNED NULL, posted_at DATETIME NULL,
 terminated_by BIGINT UNSIGNED NULL, terminated_at DATETIME NULL, termination_reason VARCHAR(500) NULL,
 FOREIGN KEY (supplier_id) REFERENCES suppliers(id), FOREIGN KEY (credit_id) REFERENCES supplier_credit_notes(id),
 FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id), FOREIGN KEY (purchase_return_credit_link_id) REFERENCES purchase_return_credit_links(id),
 FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id),
 FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
 FOREIGN KEY (approved_by) REFERENCES users(id), FOREIGN KEY (posted_by) REFERENCES users(id), FOREIGN KEY (terminated_by) REFERENCES users(id),
 KEY idx_supplier_credit_return_correction_supplier (supplier_id,id),
 KEY idx_supplier_credit_return_correction_link (purchase_return_credit_link_id,id),
 KEY idx_supplier_credit_return_correction_credit (credit_id,id),
 CHECK (amount_original>0 AND quantity>0 AND qty_base>0 AND exchange_rate_to_khr>0),
 CHECK (currency IN ('KHR','USD')), CHECK (currency<>'KHR' OR exchange_rate_to_khr=1),
 CHECK (CHAR_LENGTH(TRIM(reason))>0 AND CHAR_LENGTH(TRIM(evidence_reference))>0),
 CHECK (status<>'posted' OR (posted_by IS NOT NULL AND posted_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_credit_return_correction_approvals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, correction_id BIGINT UNSIGNED NOT NULL UNIQUE,
 content_hash CHAR(64) NOT NULL, approved_by BIGINT UNSIGNED NOT NULL, approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (correction_id) REFERENCES supplier_credit_return_corrections(id), FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- This is a compensating financial posting, not another supplier credit or cash record.
CREATE TABLE supplier_credit_return_correction_postings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, correction_id BIGINT UNSIGNED NOT NULL UNIQUE,
 credit_id BIGINT UNSIGNED NOT NULL, credit_allocation_id BIGINT UNSIGNED NOT NULL,
 purchase_return_credit_link_id BIGINT UNSIGNED NOT NULL, payable_id BIGINT UNSIGNED NOT NULL,
 posting_key VARCHAR(180) NOT NULL UNIQUE,
 amount_original DECIMAL(18,4) NOT NULL, amount_khr DECIMAL(18,4) NOT NULL,
 restored_ap_original DECIMAL(18,4) NOT NULL, restored_ap_khr DECIMAL(18,4) NOT NULL,
 reduced_recoverable_original DECIMAL(18,4) NOT NULL, reduced_recoverable_khr DECIMAL(18,4) NOT NULL,
 rounding_difference_khr DECIMAL(18,4) NOT NULL,
 quantity DECIMAL(18,3) NOT NULL, qty_base DECIMAL(18,3) NOT NULL,
 posted_by BIGINT UNSIGNED NOT NULL, posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (correction_id) REFERENCES supplier_credit_return_corrections(id), FOREIGN KEY (credit_id) REFERENCES supplier_credit_notes(id),
 FOREIGN KEY (credit_allocation_id) REFERENCES supplier_credit_allocations(id),
 FOREIGN KEY (purchase_return_credit_link_id) REFERENCES purchase_return_credit_links(id),
 FOREIGN KEY (payable_id) REFERENCES payables(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 KEY idx_supplier_credit_return_posting_credit (credit_id,id),
 KEY idx_supplier_credit_return_posting_link (purchase_return_credit_link_id,id),
 KEY idx_supplier_credit_return_posting_payable (payable_id,id),
 CHECK (amount_original>0 AND amount_khr>=0 AND quantity>0 AND qty_base>0),
 CHECK (restored_ap_original>=0 AND restored_ap_khr>=0 AND reduced_recoverable_original>=0 AND reduced_recoverable_khr>=0),
 CHECK (restored_ap_original+reduced_recoverable_original=amount_original),
 CHECK (restored_ap_khr+reduced_recoverable_khr+rounding_difference_khr=amount_khr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend the existing ordered AP provenance chain. The named Phase 5.5 check is
-- deterministic on MySQL 8 for the preceding migration and is replaced atomically.
ALTER TABLE supplier_payable_activities
 DROP CHECK supplier_payable_activities_chk_1,
 ADD COLUMN credit_return_correction_posting_id BIGINT UNSIGNED NULL UNIQUE,
 ADD CONSTRAINT fk_supplier_payable_activity_credit_return_correction
   FOREIGN KEY (credit_return_correction_posting_id) REFERENCES supplier_credit_return_correction_postings(id),
 ADD CONSTRAINT chk_supplier_payable_activity_exactly_one
   CHECK ((payment_allocation_id IS NOT NULL)+(credit_allocation_id IS NOT NULL)+(correction_allocation_id IS NOT NULL)
       +(reapplication_id IS NOT NULL)+(credit_return_correction_posting_id IS NOT NULL)=1);

INSERT INTO permissions (code,description) VALUES
 ('supplier_credit_return_corrections.view','View linked supplier-credit correction evidence'),
 ('supplier_credit_return_corrections.record','Create and submit linked supplier-credit corrections'),
 ('supplier_credit_return_corrections.approve','Approve/reject linked supplier-credit corrections'),
 ('supplier_credit_return_corrections.post','Post linked supplier-credit corrections'),
 ('supplier_credit_return_corrections.reconcile','Reconcile linked supplier-credit corrections');
INSERT INTO role_permissions (role_id,permission_id)
 SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
 WHERE r.code='owner' AND p.code LIKE 'supplier_credit_return_corrections.%';
