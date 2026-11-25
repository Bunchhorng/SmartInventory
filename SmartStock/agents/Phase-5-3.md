You are a Senior PHP Backend Engineer and Financial Systems Architect working on SmartStock.

Read `AGENTS.md`, inspect the complete Phase 1–5.2 implementation, database migrations, purchasing services, immutable inventory/valuation ledgers, and integration tests.

## Task: Phase 5.3 — Supplier Invoice Matching & Accounts Payable Recognition

Focus exclusively on backend business logic, financial integrity, database transactions, security, and automated testing.

Do not redesign the UI.

### 1. Preflight Inspection

Inspect:

- Approved Purchase Orders and approval snapshots
- Confirmed purchase goods receipts
- PO fulfillment records
- Supplier models and tables
- Existing accounts payable schema
- Inventory and valuation posting
- Payment and receivable patterns from Phase 4.2

Reuse existing infrastructure and avoid duplicate accounting engines.

### 2. Supplier Invoice Management

Implement:

- Draft supplier invoices
- Supplier invoice references
- Supplier and purchase order relationships
- Invoice dates and due dates
- Invoice line items
- Quantity, purchase-unit, price, discount, currency, and exchange-rate snapshots
- Invoice attachment references where existing infrastructure supports them
- Submission, approval, posting, and cancellation under safe rules

Preserve original supplier invoice numbers and enforce appropriate duplicate detection.

### 3. Three-Way Matching

Match:

1. Approved Purchase Order
2. Confirmed Goods Receipt
3. Supplier Invoice

Rules:

- Do not recognize payable for goods that were not confirmed as received unless a separately approved prepayment or non-PO invoice policy exists.
- Never invoice more than the eligible unmatched received quantity.
- Support partial supplier invoices covering multiple goods receipts.
- Support multiple invoices for one purchase order.
- Prevent the same received quantity from being matched twice.
- Detect quantity, price, currency, and supplier mismatches.
- Require explicit Owner approval for permitted variances.
- Reject unresolved material discrepancies.

### 4. Accounts Payable Recognition

On approved invoice posting:

- Create an immutable payable record.
- Record supplier, invoice, due date, original currency, exchange-rate snapshot, and base-currency amount.
- Record the posted payable once.
- Keep a traceable linkage to eligible goods receipts and PO lines.
- Use idempotency keys and unique posting constraints.
- Do not create stock movements or update moving-average cost during ordinary invoice posting.
- Do not create supplier payments at this stage.

### 5. Financial Integrity

- Preserve the valuation posted at goods receipt.
- Define an explicit accounting policy for invoice-price differences.
- Do not silently rewrite inventory valuation or historical weighted-average cost.
- Record approved price variances separately where needed.
- Never double-count purchases, liabilities, or inventory value.
- Keep supplier payables separate from customer receivables.

### 6. Transaction Safety

Use:

- MySQL InnoDB transactions
- Deterministic row-lock ordering
- Fixed-point DECIMAL arithmetic
- Immutable financial postings
- Rollback on failure
- Concurrency-safe invoice-to-receipt matching

### 7. Security

- Owner-only invoice price access and payable approval.
- Staff may record authorized invoice metadata without sensitive prices.
- Enforce RBAC on all routes.
- CSRF protection for state-changing actions.
- Audit all approvals, postings, rejections, and reversals.
- Prevent confidential purchasing costs from leaking into Staff responses.

### 8. Reconciliation

Create read-only reconciliation for:

- Confirmed received quantities
- Quantities already invoiced
- Remaining uninvoiced quantities
- Approved invoice totals
- Posted payable amounts
- Invoice-level and supplier-level outstanding balances

Identify discrepancies without silently modifying historical records.

### 9. Automated Tests

Test:

- Full supplier invoice
- Partial supplier invoice
- Multiple receipts matched to one invoice
- Multiple invoices for one PO
- Duplicate invoice number
- Over-invoicing rejection
- Unreceived quantity rejection
- Price variance approval
- Wrong supplier rejection
- Currency mismatch
- Concurrent invoice posting
- Duplicate posting
- Rollback
- Staff cost privacy
- Owner authorization
- CSRF rejection
- Payable reconciliation
- Phase 1–5.2 regression suites

Use a disposable test database only.

### 10. Deliverables

Provide:

1. Files changed.
2. Database migrations.
3. Invoice and payable services.
4. Three-way matching rules.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended milestone.

Do not migrate the main `smartstock` database automatically.

Implement Phase 5.3 only. Do not implement supplier payments, landed costs, expenses, or fuel workflows yet.