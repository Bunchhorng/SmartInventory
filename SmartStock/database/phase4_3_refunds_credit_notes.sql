-- SmartStock Phase 4.3: immutable financial credit notes and customer refunds.
-- Import once after phase4_2_payments_credit.sql. This migration is forward-only.
USE smartstock;

ALTER TABLE payments
    MODIFY COLUMN payment_purpose ENUM('sale_settlement','customer_repayment','sale_void_refund','customer_credit_refund')
        NOT NULL DEFAULT 'sale_settlement';

CREATE TABLE credit_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    credit_note_no VARCHAR(50) NOT NULL UNIQUE,
    credit_note_key VARCHAR(80) NOT NULL UNIQUE,
    sale_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    status ENUM('draft','approved','posted','reversed') NOT NULL DEFAULT 'draft',
    amount_khr DECIMAL(18,4) NOT NULL,
    receivable_reduction_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
    excess_credit_khr DECIMAL(18,4) NOT NULL DEFAULT 0,
    reason VARCHAR(500) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    CHECK (amount_khr > 0),
    CHECK (receivable_reduction_khr >= 0),
    CHECK (excess_credit_khr >= 0),
    CHECK (receivable_reduction_khr + excess_credit_khr <= amount_khr),
    KEY idx_credit_notes_sale_status (sale_id, status),
    KEY idx_credit_notes_customer_status (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_note_receivable_applications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    credit_note_id BIGINT UNSIGNED NOT NULL UNIQUE,
    receivable_id BIGINT UNSIGNED NOT NULL,
    amount_khr DECIMAL(18,4) NOT NULL,
    applied_by BIGINT UNSIGNED NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id),
    FOREIGN KEY (receivable_id) REFERENCES receivables(id),
    FOREIGN KEY (applied_by) REFERENCES users(id),
    CHECK (amount_khr > 0),
    KEY idx_credit_note_receivable_applications_receivable (receivable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE credit_note_reversals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    credit_note_id BIGINT UNSIGNED NOT NULL UNIQUE,
    amount_khr DECIMAL(18,4) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    reversed_by BIGINT UNSIGNED NOT NULL,
    reversed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id),
    FOREIGN KEY (reversed_by) REFERENCES users(id),
    CHECK (amount_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    refund_no VARCHAR(50) NOT NULL UNIQUE,
    refund_key VARCHAR(80) NOT NULL UNIQUE,
    credit_note_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    payment_method_id BIGINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    amount_original DECIMAL(18,4) NOT NULL,
    exchange_rate_to_khr DECIMAL(18,6) NOT NULL,
    amount_khr DECIMAL(18,4) NOT NULL,
    reference_no VARCHAR(120) NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('draft','approved','posted','rejected','reversed') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    payment_id BIGINT UNSIGNED NULL UNIQUE,
    rejection_reason VARCHAR(500) NULL,
    reversed_by BIGINT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(500) NULL,
    FOREIGN KEY (credit_note_id) REFERENCES credit_notes(id),
    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    FOREIGN KEY (payment_id) REFERENCES payments(id),
    FOREIGN KEY (reversed_by) REFERENCES users(id),
    CHECK (amount_original > 0),
    CHECK (exchange_rate_to_khr > 0),
    CHECK (amount_khr > 0),
    KEY idx_refunds_credit_note_status (credit_note_id, status),
    KEY idx_refunds_customer_status (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
