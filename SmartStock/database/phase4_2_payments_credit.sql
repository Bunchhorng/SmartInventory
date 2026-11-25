-- SmartStock Phase 4.2: payments and customer credit.
-- Import once after phase4_1_sales_posting.sql. This migration is forward-only.
-- It extends the draft-schema financial tables; it does not backfill or alter
-- historical business transactions in the main smartstock database.
USE smartstock;

ALTER TABLE customers
    ADD COLUMN credit_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER credit_limit,
    ADD COLUMN payment_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER credit_enabled,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD CONSTRAINT chk_customers_credit_limit_nonnegative CHECK (credit_limit >= 0),
    ADD CONSTRAINT chk_customers_terms_reasonable CHECK (payment_terms_days <= 3650);

ALTER TABLE sales
    ADD COLUMN settlement_type ENUM('cash','credit') NOT NULL DEFAULT 'cash' AFTER sale_channel,
    ADD COLUMN credit_limit_override TINYINT(1) NOT NULL DEFAULT 0 AFTER settlement_type,
    ADD COLUMN credit_override_reason VARCHAR(500) NULL AFTER credit_limit_override,
    ADD COLUMN credit_override_approved_by BIGINT UNSIGNED NULL AFTER credit_override_reason,
    ADD COLUMN credit_override_approved_at DATETIME NULL AFTER credit_override_approved_by,
    ADD INDEX idx_sales_customer_settlement (customer_id, settlement_type, status),
    ADD CONSTRAINT fk_sales_credit_override_approved_by
        FOREIGN KEY (credit_override_approved_by) REFERENCES users(id),
    ADD CONSTRAINT chk_sales_credit_override_reason
        CHECK ((credit_limit_override = 0 AND credit_override_reason IS NULL AND credit_override_approved_by IS NULL)
            OR (credit_limit_override = 1 AND credit_override_reason IS NOT NULL AND credit_override_approved_by IS NOT NULL));

ALTER TABLE payments
    ADD COLUMN payment_key VARCHAR(80) NULL AFTER id,
    ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER payment_method_id,
    ADD COLUMN payment_purpose ENUM('sale_settlement','customer_repayment','sale_void_refund')
        NOT NULL DEFAULT 'sale_settlement' AFTER customer_id,
    ADD COLUMN verified_at DATETIME NULL AFTER verification_status,
    ADD COLUMN verified_by BIGINT UNSIGNED NULL AFTER verified_at,
    ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER recorded_by,
    ADD UNIQUE KEY uq_payments_payment_key (payment_key),
    ADD INDEX idx_payments_customer_status (customer_id, verification_status, paid_at),
    ADD INDEX idx_payments_recorded_by (recorded_by, paid_at),
    ADD CONSTRAINT fk_payments_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    ADD CONSTRAINT fk_payments_verified_by FOREIGN KEY (verified_by) REFERENCES users(id),
    ADD CONSTRAINT chk_payments_exchange_rate_positive CHECK (exchange_rate_to_khr > 0),
    ADD CONSTRAINT chk_payments_amount_khr_positive CHECK (amount_khr > 0);

ALTER TABLE receivables
    MODIFY COLUMN customer_id BIGINT UNSIGNED NULL,
    ADD COLUMN credit_terms_days SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER due_date,
    ADD COLUMN credit_limit_khr DECIMAL(18,4) NOT NULL DEFAULT 0 AFTER credit_terms_days,
    ADD COLUMN voided_at DATETIME NULL AFTER original_khr,
    ADD COLUMN voided_by BIGINT UNSIGNED NULL AFTER voided_at,
    ADD COLUMN void_reason VARCHAR(500) NULL AFTER voided_by,
    ADD INDEX idx_receivables_customer_due (customer_id, due_date),
    ADD INDEX idx_receivables_voided (voided_at),
    ADD CONSTRAINT fk_receivables_voided_by FOREIGN KEY (voided_by) REFERENCES users(id),
    ADD CONSTRAINT chk_receivables_original_positive CHECK (original_khr > 0),
    ADD CONSTRAINT chk_receivables_credit_limit_nonnegative CHECK (credit_limit_khr >= 0);

ALTER TABLE receivable_allocations
    ADD COLUMN allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER amount_khr,
    ADD COLUMN allocated_by BIGINT UNSIGNED NULL AFTER allocated_at,
    ADD COLUMN reversed_at DATETIME NULL AFTER allocated_by,
    ADD COLUMN reversed_by BIGINT UNSIGNED NULL AFTER reversed_at,
    ADD COLUMN reversal_reason VARCHAR(500) NULL AFTER reversed_by,
    ADD INDEX idx_receivable_allocations_payment (payment_id),
    ADD CONSTRAINT fk_receivable_allocations_allocated_by FOREIGN KEY (allocated_by) REFERENCES users(id),
    ADD CONSTRAINT fk_receivable_allocations_reversed_by FOREIGN KEY (reversed_by) REFERENCES users(id),
    ADD CONSTRAINT chk_receivable_allocations_reversal_reason
        CHECK ((reversed_at IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
            OR (reversed_at IS NOT NULL AND reversed_by IS NOT NULL AND reversal_reason IS NOT NULL));

-- The base seed has a bank method that covers bank transfer. KHQR is stored as
-- a distinct method but remains manually verified; no provider is implied.
INSERT IGNORE INTO payment_methods (code, name, is_active)
VALUES ('khqr', 'KHQR (manual verification)', 1);
