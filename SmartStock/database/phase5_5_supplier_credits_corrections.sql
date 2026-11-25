-- Phase 5.5: import ONCE after Phase 5.4, only with explicit main DB approval.
-- Forward-only. No historical amounts, verifications or cash returns are backfilled.
USE smartstock;

CREATE TABLE supplier_credit_notes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_key VARCHAR(80) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL,
 credit_no VARCHAR(120) NOT NULL, normalized_credit_no VARCHAR(120) NOT NULL,
 currency CHAR(3) NOT NULL, exchange_rate_to_khr DECIMAL(18,6) NOT NULL,
 amount_original DECIMAL(18,4) NOT NULL, reason VARCHAR(500) NOT NULL,
 status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
 posted_by BIGINT UNSIGNED NULL, posted_at DATETIME NULL,
 rejected_by BIGINT UNSIGNED NULL, rejected_at DATETIME NULL,
 cancelled_by BIGINT UNSIGNED NULL, cancelled_at DATETIME NULL, termination_reason VARCHAR(500) NULL,
 FOREIGN KEY (supplier_id) REFERENCES suppliers(id), FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id),
 FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
 FOREIGN KEY (approved_by) REFERENCES users(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 FOREIGN KEY (rejected_by) REFERENCES users(id), FOREIGN KEY (cancelled_by) REFERENCES users(id),
 UNIQUE KEY uq_supplier_credit_number (supplier_id, normalized_credit_no),
 KEY idx_supplier_credit_invoice (invoice_id, status), KEY idx_supplier_credit_supplier (supplier_id, id),
 CHECK (amount_original > 0 AND exchange_rate_to_khr > 0), CHECK (currency IN ('KHR','USD')),
 CHECK (currency <> 'KHR' OR exchange_rate_to_khr = 1), CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
 CHECK (status <> 'posted' OR (posted_by IS NOT NULL AND posted_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payment_corrections (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_key VARCHAR(80) NOT NULL UNIQUE, request_hash CHAR(64) NOT NULL,
 supplier_id BIGINT UNSIGNED NOT NULL, supplier_payment_id BIGINT UNSIGNED NOT NULL,
 source_hash CHAR(64) NOT NULL, reason VARCHAR(500) NOT NULL,
 resolution ENUM('funds_returned','proven_not_settled') NOT NULL,
 evidence_reference VARCHAR(120) NOT NULL, evidence VARCHAR(1000) NOT NULL,
 status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
 active_payment_id BIGINT UNSIGNED GENERATED ALWAYS AS
   (CASE WHEN status IN ('draft','submitted','approved','posted') THEN supplier_payment_id ELSE NULL END) STORED,
 created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
 approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
 posted_by BIGINT UNSIGNED NULL, posted_at DATETIME NULL,
 rejected_by BIGINT UNSIGNED NULL, rejected_at DATETIME NULL,
 cancelled_by BIGINT UNSIGNED NULL, cancelled_at DATETIME NULL, termination_reason VARCHAR(500) NULL,
 FOREIGN KEY (supplier_id) REFERENCES suppliers(id), FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id),
 FOREIGN KEY (created_by) REFERENCES users(id), FOREIGN KEY (submitted_by) REFERENCES users(id),
 FOREIGN KEY (approved_by) REFERENCES users(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 FOREIGN KEY (rejected_by) REFERENCES users(id), FOREIGN KEY (cancelled_by) REFERENCES users(id),
 UNIQUE KEY uq_supplier_correction_active (active_payment_id), KEY idx_supplier_correction_supplier (supplier_id, id),
 CHECK (CHAR_LENGTH(TRIM(reason)) > 0 AND CHAR_LENGTH(TRIM(evidence_reference)) > 0 AND CHAR_LENGTH(TRIM(evidence)) > 0),
 CHECK (status <> 'posted' OR (posted_by IS NOT NULL AND posted_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_financial_approvals (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 credit_id BIGINT UNSIGNED NULL UNIQUE, correction_id BIGINT UNSIGNED NULL UNIQUE,
 content_hash CHAR(64) NOT NULL, approved_by BIGINT UNSIGNED NOT NULL,
 approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (credit_id) REFERENCES supplier_credit_notes(id),
 FOREIGN KEY (correction_id) REFERENCES supplier_payment_corrections(id), FOREIGN KEY (approved_by) REFERENCES users(id),
 CHECK ((credit_id IS NOT NULL) + (correction_id IS NOT NULL) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_credit_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 credit_id BIGINT UNSIGNED NOT NULL UNIQUE, payable_id BIGINT UNSIGNED NOT NULL,
 posting_key VARCHAR(80) NOT NULL UNIQUE,
 amount_original DECIMAL(18,4) NOT NULL, amount_khr DECIMAL(18,4) NOT NULL,
 applied_original DECIMAL(18,4) NOT NULL, applied_khr DECIMAL(18,4) NOT NULL,
 recoverable_original DECIMAL(18,4) NOT NULL, recoverable_khr DECIMAL(18,4) NOT NULL,
 rounding_difference_khr DECIMAL(18,4) NOT NULL,
 posted_by BIGINT UNSIGNED NOT NULL, posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (credit_id) REFERENCES supplier_credit_notes(id), FOREIGN KEY (payable_id) REFERENCES payables(id),
 FOREIGN KEY (posted_by) REFERENCES users(id), KEY idx_supplier_credit_allocation_payable (payable_id, id),
 CHECK (amount_original > 0 AND amount_khr >= 0 AND applied_original >= 0 AND applied_khr >= 0
    AND recoverable_original >= 0 AND recoverable_khr >= 0),
 CHECK (applied_original + recoverable_original = amount_original),
 CHECK (applied_khr + recoverable_khr + rounding_difference_khr = amount_khr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payment_correction_allocations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 correction_id BIGINT UNSIGNED NOT NULL, original_allocation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 payable_id BIGINT UNSIGNED NOT NULL, posting_key VARCHAR(80) NOT NULL UNIQUE,
 reversed_original DECIMAL(18,4) NOT NULL, reversed_carrying_khr DECIMAL(18,4) NOT NULL,
 cash_original DECIMAL(18,4) NOT NULL, cash_khr DECIMAL(18,4) NOT NULL,
 fx_reversal_khr DECIMAL(18,4) NOT NULL, rounding_reversal_khr DECIMAL(18,4) NOT NULL,
 ap_rounding_adjustment_khr DECIMAL(18,4) NOT NULL,
 posted_by BIGINT UNSIGNED NOT NULL, posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (correction_id) REFERENCES supplier_payment_corrections(id),
 FOREIGN KEY (original_allocation_id) REFERENCES payable_allocations(id),
 FOREIGN KEY (payable_id) REFERENCES payables(id), FOREIGN KEY (posted_by) REFERENCES users(id),
 KEY idx_supplier_correction_payable (payable_id, id),
 CHECK (reversed_original > 0 AND reversed_carrying_khr > 0 AND cash_original > 0 AND cash_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Converts only existing recoverable credit on the SAME invoice into AP reduction
-- when a proven payment correction reopens that invoice. Never a cash refund.
CREATE TABLE supplier_credit_reapplications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 credit_allocation_id BIGINT UNSIGNED NOT NULL, correction_allocation_id BIGINT UNSIGNED NOT NULL,
 payable_id BIGINT UNSIGNED NOT NULL,
 applied_original DECIMAL(18,4) NOT NULL, applied_khr DECIMAL(18,4) NOT NULL,
 consumed_recoverable_khr DECIMAL(18,4) NOT NULL, rounding_difference_khr DECIMAL(18,4) NOT NULL,
 FOREIGN KEY (credit_allocation_id) REFERENCES supplier_credit_allocations(id),
 FOREIGN KEY (correction_allocation_id) REFERENCES supplier_payment_correction_allocations(id),
 FOREIGN KEY (payable_id) REFERENCES payables(id),
 UNIQUE KEY uq_credit_correction_reapplication (credit_allocation_id, correction_allocation_id),
 CHECK (applied_original > 0 AND applied_khr >= 0 AND consumed_recoverable_khr >= 0),
 CHECK (applied_khr + rounding_difference_khr = consumed_recoverable_khr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payable_allocations ADD posting_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
 ADD CONSTRAINT chk_payable_allocation_version CHECK (posting_version IN (1,2));

-- Ordered provenance index, NOT another money ledger. Amounts live only in the
-- referenced immutable allocation records. Pre-5.5 payments retain version 1.
CREATE TABLE supplier_payable_activities (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, payable_id BIGINT UNSIGNED NOT NULL,
 payment_allocation_id BIGINT UNSIGNED NULL UNIQUE, credit_allocation_id BIGINT UNSIGNED NULL UNIQUE,
 correction_allocation_id BIGINT UNSIGNED NULL UNIQUE, reapplication_id BIGINT UNSIGNED NULL UNIQUE,
 FOREIGN KEY (payable_id) REFERENCES payables(id),
 FOREIGN KEY (payment_allocation_id) REFERENCES payable_allocations(id),
 FOREIGN KEY (credit_allocation_id) REFERENCES supplier_credit_allocations(id),
 FOREIGN KEY (correction_allocation_id) REFERENCES supplier_payment_correction_allocations(id),
 FOREIGN KEY (reapplication_id) REFERENCES supplier_credit_reapplications(id),
 KEY idx_supplier_payable_activity_order (payable_id, id),
 CHECK ((payment_allocation_id IS NOT NULL) + (credit_allocation_id IS NOT NULL)
    + (correction_allocation_id IS NOT NULL) + (reapplication_id IS NOT NULL) = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description) VALUES
 ('supplier_credits.view','View supplier credits and recoverables'),
 ('supplier_credits.record','Create and submit supplier credits'),
 ('supplier_credits.approve','Approve/reject supplier credits'),
 ('supplier_credits.post','Post supplier credits'),
 ('supplier_credits.reconcile','Reconcile supplier financial corrections'),
 ('supplier_payment_corrections.view','View supplier payment corrections'),
 ('supplier_payment_corrections.record','Request supplier payment corrections'),
 ('supplier_payment_corrections.approve','Approve/reject supplier payment corrections'),
 ('supplier_payment_corrections.post','Post evidenced supplier payment corrections');
INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'owner'
 AND (p.code LIKE 'supplier_credits.%' OR p.code LIKE 'supplier_payment_corrections.%');
