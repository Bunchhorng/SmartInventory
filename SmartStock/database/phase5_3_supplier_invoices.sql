-- Phase 5.3: supplier invoice matching and AP recognition. Import ONCE after
-- Phase 5.2. No business database migration is run by the application.
USE smartstock;

CREATE TABLE supplier_invoices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_key VARCHAR(80) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    supplier_invoice_no VARCHAR(120) NOT NULL,
    normalized_invoice_no VARCHAR(120) NOT NULL,
    supplier_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    notes VARCHAR(1000) NULL,
    currency CHAR(3) NOT NULL,
    exchange_rate_to_khr DECIMAL(18,6) NOT NULL,
    total_original DECIMAL(18,4) NULL,
    total_khr DECIMAL(18,4) NULL,
    status ENUM('draft','submitted','approved','posted','rejected','cancelled') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    submitted_by BIGINT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    rejected_by BIGINT UNSIGNED NULL,
    rejected_at DATETIME NULL,
    rejection_reason VARCHAR(500) NULL,
    cancelled_by BIGINT UNSIGNED NULL,
    cancelled_at DATETIME NULL,
    cancellation_reason VARCHAR(500) NULL,
    UNIQUE KEY uq_supplier_invoice_number (supplier_id, normalized_invoice_no),
    KEY idx_supplier_invoice_purchase_status (purchase_id, status),
    KEY idx_supplier_invoice_supplier_due (supplier_id, due_date),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    FOREIGN KEY (rejected_by) REFERENCES users(id),
    FOREIGN KEY (cancelled_by) REFERENCES users(id),
    CHECK (due_date >= invoice_date),
    CHECK (exchange_rate_to_khr > 0),
    CHECK (total_original IS NULL OR total_original > 0),
    CHECK (total_khr IS NULL OR total_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_invoice_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    goods_receipt_item_id BIGINT UNSIGNED NOT NULL,
    purchase_item_id BIGINT UNSIGNED NOT NULL,
    product_unit_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    qty_base DECIMAL(18,3) NOT NULL,
    unit_code_snapshot VARCHAR(50) NOT NULL,
    conversion_snapshot DECIMAL(18,6) NOT NULL,
    unit_purchase_price DECIMAL(18,4) NOT NULL,
    discount_percent DECIMAL(7,4) NOT NULL,
    po_unit_price_snapshot DECIMAL(18,4) NOT NULL,
    po_discount_snapshot DECIMAL(7,4) NOT NULL,
    receipt_cost_snapshot_khr DECIMAL(18,6) NOT NULL,
    line_subtotal DECIMAL(18,4) NOT NULL,
    line_discount DECIMAL(18,4) NOT NULL,
    line_total DECIMAL(18,4) NOT NULL,
    line_total_khr DECIMAL(18,4) NOT NULL,
    UNIQUE KEY uq_supplier_invoice_receipt_line (invoice_id, goods_receipt_item_id),
    FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id),
    FOREIGN KEY (goods_receipt_item_id) REFERENCES goods_receipt_items(id),
    FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id),
    FOREIGN KEY (product_unit_id) REFERENCES product_units(id),
    CHECK (quantity > 0 AND qty_base > 0 AND conversion_snapshot > 0),
    CHECK (unit_purchase_price > 0 AND receipt_cost_snapshot_khr > 0),
    CHECK (discount_percent >= 0 AND discount_percent < 100),
    CHECK (line_subtotal > 0 AND line_discount >= 0 AND line_total > 0 AND line_total_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_invoice_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL UNIQUE,
    content_hash CHAR(64) NOT NULL,
    total_original DECIMAL(18,4) NOT NULL,
    total_khr DECIMAL(18,4) NOT NULL,
    variance_required BOOLEAN NOT NULL,
    variance_reason VARCHAR(500) NULL,
    approved_by BIGINT UNSIGNED NOT NULL,
    approved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    CHECK (total_original > 0 AND total_khr > 0),
    CHECK (variance_required = 0 OR variance_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keep the existing payable ledger. Replace the old one-PO uniqueness with
-- one posted payable per invoice, retaining uniqueness for legacy PO payables.
ALTER TABLE payables
    ADD COLUMN supplier_invoice_id BIGINT UNSIGNED NULL,
    ADD COLUMN posting_key VARCHAR(80) NULL,
    ADD COLUMN currency CHAR(3) NULL,
    ADD COLUMN amount_original DECIMAL(18,4) NULL,
    ADD COLUMN exchange_rate_to_khr DECIMAL(18,6) NULL,
    ADD COLUMN posted_by BIGINT UNSIGNED NULL,
    ADD COLUMN posted_at DATETIME NULL,
    ADD KEY idx_payables_purchase (purchase_id),
    ADD UNIQUE KEY uq_payables_supplier_invoice (supplier_invoice_id),
    ADD UNIQUE KEY uq_payables_posting_key (posting_key),
    ADD COLUMN legacy_purchase_id BIGINT UNSIGNED GENERATED ALWAYS AS
        (CASE WHEN supplier_invoice_id IS NULL THEN purchase_id ELSE NULL END) STORED,
    ADD UNIQUE KEY uq_payables_legacy_purchase (legacy_purchase_id),
    ADD CONSTRAINT fk_payables_supplier_invoice FOREIGN KEY (supplier_invoice_id) REFERENCES supplier_invoices(id),
    ADD CONSTRAINT fk_payables_posted_by FOREIGN KEY (posted_by) REFERENCES users(id),
    ADD CONSTRAINT chk_payables_positive CHECK (original_khr > 0),
    ADD CONSTRAINT chk_payables_invoice_fields CHECK (supplier_invoice_id IS NULL OR
        (posting_key IS NOT NULL AND currency IS NOT NULL AND amount_original IS NOT NULL AND amount_original > 0
         AND exchange_rate_to_khr IS NOT NULL AND exchange_rate_to_khr > 0 AND posted_by IS NOT NULL AND posted_at IS NOT NULL));
ALTER TABLE payables DROP INDEX purchase_id;

-- Immutable matched quantities and amounts, written only when the payable is
-- posted. Approval/drafts do not reserve receipt quantities.
CREATE TABLE supplier_invoice_matches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invoice_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
    invoice_id BIGINT UNSIGNED NOT NULL,
    payable_id BIGINT UNSIGNED NOT NULL,
    goods_receipt_item_id BIGINT UNSIGNED NOT NULL,
    purchase_item_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,3) NOT NULL,
    qty_base DECIMAL(18,3) NOT NULL,
    receipt_value_khr DECIMAL(18,4) NOT NULL,
    invoice_value_khr DECIMAL(18,4) NOT NULL,
    posted_by BIGINT UNSIGNED NOT NULL,
    posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_supplier_match_receipt (goods_receipt_item_id),
    FOREIGN KEY (invoice_item_id) REFERENCES supplier_invoice_items(id),
    FOREIGN KEY (invoice_id) REFERENCES supplier_invoices(id),
    FOREIGN KEY (payable_id) REFERENCES payables(id),
    FOREIGN KEY (goods_receipt_item_id) REFERENCES goods_receipt_items(id),
    FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id),
    FOREIGN KEY (posted_by) REFERENCES users(id),
    CHECK (quantity > 0 AND qty_base > 0 AND receipt_value_khr >= 0 AND invoice_value_khr > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Financial price/rounding differences are not inventory revaluations or
-- operating-expense postings. Signed values remain a separate AP variance log.
CREATE TABLE supplier_invoice_price_variances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    match_id BIGINT UNSIGNED NOT NULL UNIQUE,
    amount_khr DECIMAL(18,4) NOT NULL,
    variance_type ENUM('price','rounding') NOT NULL,
    approval_id BIGINT UNSIGNED NOT NULL,
    FOREIGN KEY (match_id) REFERENCES supplier_invoice_matches(id),
    FOREIGN KEY (approval_id) REFERENCES supplier_invoice_approvals(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description) VALUES
    ('supplier_invoices.view', 'View permitted supplier invoice metadata'),
    ('supplier_invoices.record', 'Record supplier invoice metadata'),
    ('supplier_invoices.manage', 'Manage priced supplier invoices'),
    ('supplier_invoices.approve', 'Approve matched supplier invoices'),
    ('supplier_invoices.post', 'Recognize supplier accounts payable'),
    ('supplier_invoices.reconcile', 'Reconcile supplier invoices and payables');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'owner' AND p.code LIKE 'supplier_invoices.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'staff' AND p.code IN ('supplier_invoices.view','supplier_invoices.record');
