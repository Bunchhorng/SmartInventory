-- Phase 5.4: import ONCE after Phase 5.3. Forward-only; no historical backfill.
-- Main smartstock migration requires explicit business approval.
USE smartstock;

ALTER TABLE payments
    MODIFY payment_purpose ENUM('sale_settlement','customer_repayment','sale_void_refund','customer_credit_refund','supplier_payment') NOT NULL DEFAULT 'sale_settlement',
    ADD supplier_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_payments_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    ADD CONSTRAINT chk_payments_supplier_scope CHECK (
        (payment_purpose = 'supplier_payment' AND supplier_id IS NOT NULL AND customer_id IS NULL AND direction = 'out')
        OR (payment_purpose <> 'supplier_payment' AND supplier_id IS NULL));

CREATE TABLE supplier_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_key VARCHAR(80) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
    payment_method_id BIGINT UNSIGNED NULL,
    currency CHAR(3) NULL,
    amount_original DECIMAL(18,4) NULL,
    exchange_rate_to_khr DECIMAL(18,6) NULL,
    amount_khr DECIMAL(18,4) NULL,
    settlement_usd_rate_to_khr DECIMAL(18,6) NULL,
    reference_no VARCHAR(120) NULL,
    normalized_reference VARCHAR(120) NULL,
    paid_at DATETIME NULL,
    notes VARCHAR(1000) NULL,
    payment_id BIGINT UNSIGNED NULL UNIQUE,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_by BIGINT UNSIGNED NULL, submitted_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL, approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL, posted_at DATETIME NULL,
    rejected_by BIGINT UNSIGNED NULL, rejected_at DATETIME NULL, rejection_reason VARCHAR(500) NULL,
    cancelled_by BIGINT UNSIGNED NULL, cancelled_at DATETIME NULL, cancellation_reason VARCHAR(500) NULL,
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    FOREIGN KEY (rejected_by) REFERENCES users(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id),
    UNIQUE KEY uq_supplier_payment_reference (supplier_id, payment_method_id, normalized_reference),
    KEY idx_supplier_payment_status (supplier_id, status, id),
    CHECK (currency IS NULL OR currency IN ('KHR','USD')),
    CHECK (amount_original IS NULL OR amount_original > 0),
    CHECK (amount_khr IS NULL OR amount_khr > 0),
    CHECK (exchange_rate_to_khr IS NULL OR exchange_rate_to_khr > 0),
    CHECK (settlement_usd_rate_to_khr IS NULL OR settlement_usd_rate_to_khr > 0),
    CHECK (currency IS NULL OR currency <> 'KHR' OR exchange_rate_to_khr = 1),
    CHECK (status NOT IN ('submitted','approved','posted') OR
        (payment_method_id IS NOT NULL AND currency IS NOT NULL AND amount_original IS NOT NULL
         AND amount_khr IS NOT NULL AND exchange_rate_to_khr IS NOT NULL AND settlement_usd_rate_to_khr IS NOT NULL AND paid_at IS NOT NULL)),
    CHECK ((status = 'posted' AND payment_id IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL)
        OR (status <> 'posted' AND payment_id IS NULL AND posted_at IS NULL AND posted_by IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mutable plans only while the parent is draft. Approval reserves original-currency
-- debt; only immutable payable_allocations on final posting settle that debt.
CREATE TABLE supplier_payment_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_payment_id BIGINT UNSIGNED NOT NULL,
    invoice_id BIGINT UNSIGNED NOT NULL,
    invoice_amount_original DECIMAL(18,4) NOT NULL,
    payment_amount_original DECIMAL(18,4) NOT NULL,
    FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id),
    FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id),
    UNIQUE KEY uq_supplier_payment_invoice (supplier_payment_id, invoice_id),
    KEY idx_supplier_payment_items_invoice (invoice_id),
    CHECK (invoice_amount_original > 0 AND payment_amount_original > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payment_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    content_hash CHAR(64) NOT NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_payment_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    content_hash CHAR(64) NOT NULL,
    evidence VARCHAR(1000) NOT NULL,
    verified_by BIGINT UNSIGNED NOT NULL,
    verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id),
    FOREIGN KEY (verified_by) REFERENCES users(id),
    CHECK (CHAR_LENGTH(TRIM(evidence)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payable_allocations
    ADD supplier_payment_id BIGINT UNSIGNED NULL,
    ADD invoice_amount_original DECIMAL(18,4) NULL,
    ADD payment_amount_original DECIMAL(18,4) NULL,
    ADD cash_amount_khr DECIMAL(18,4) NULL,
    ADD allocated_by BIGINT UNSIGNED NULL,
    ADD allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD CONSTRAINT fk_payable_allocations_document FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id),
    ADD CONSTRAINT fk_payable_allocations_actor FOREIGN KEY (allocated_by) REFERENCES users(id),
    ADD UNIQUE KEY uq_payable_document (payable_id, supplier_payment_id),
    ADD CONSTRAINT chk_payable_allocations_snapshots CHECK (supplier_payment_id IS NULL OR
        (invoice_amount_original IS NOT NULL AND invoice_amount_original > 0
         AND payment_amount_original IS NOT NULL AND payment_amount_original > 0
         AND cash_amount_khr IS NOT NULL AND cash_amount_khr > 0 AND allocated_by IS NOT NULL));

CREATE TABLE supplier_payment_differences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allocation_id BIGINT UNSIGNED NOT NULL UNIQUE,
    fx_difference_khr DECIMAL(18,4) NOT NULL,
    rounding_difference_khr DECIMAL(18,4) NOT NULL,
    FOREIGN KEY (allocation_id) REFERENCES payable_allocations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description) VALUES
 ('supplier_payments.view','View permitted supplier payment requests'),
 ('supplier_payments.request','Request supplier payments'),
 ('supplier_payments.manage','Manage supplier payment pricing and cancellation'),
 ('supplier_payments.approve','Approve or reject supplier payments'),
 ('supplier_payments.verify','Manually verify supplier bank/KHQR payments'),
 ('supplier_payments.post','Post supplier payments'),
 ('supplier_payments.reconcile','Reconcile supplier payments');
INSERT INTO role_permissions (role_id, permission_id)
 SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code LIKE 'supplier_payments.%'
 WHERE r.code = 'owner' OR (r.code = 'staff' AND p.code IN ('supplier_payments.view','supplier_payments.request'));
