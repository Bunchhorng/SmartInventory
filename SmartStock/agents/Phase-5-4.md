You are a Senior PHP Backend Engineer and Financial Systems Architect working on the existing SmartStock project.

Read `AGENTS.md` and inspect the complete Phase 1–5.3 implementation, migrations, services, audit logs, payment allocation contracts, and integration tests.

## Task: Phase 5.4 — Supplier Payments & Accounts Payable Allocation

Focus exclusively on backend logic, financial integrity, database transactions, security, and automated testing.

Do not redesign UI or introduce unrelated modules.

### 1. Preflight Inspection

Inspect:

- Supplier invoices and immutable payable records
- Existing supplier and payable tables
- Customer payment patterns from Phase 4.2
- Existing currency and exchange-rate handling
- Existing RBAC and audit services
- Supplier invoice reconciliation

Reuse existing infrastructure where appropriate.

Do not combine customer receivables and supplier payables.

### 2. Supplier Payment Management

Implement:

- Draft supplier payments
- Cash payments in KHR and USD
- Manual bank transfer payments
- Manual KHQR payments where applicable
- Payment submission and Owner approval
- Payment posting
- Payment rejection and cancellation under safe rules
- Payment reference numbers
- Idempotency keys and posting timestamps

Only successfully posted/verified payments may reduce supplier payables.

### 3. Payment Allocation

Support:

- One payment allocated to one supplier invoice
- One payment allocated across multiple invoices from the same supplier
- Partial invoice settlement
- Multiple payments settling one invoice
- Invoice-level outstanding balances

Rules:

- Prevent cross-supplier allocations.
- Prevent overpayment and over-allocation.
- Reject allocations against draft, rejected, cancelled, or unposted invoices.
- Protect against concurrent duplicate settlements.
- Lock affected invoices and payable balances in deterministic order.
- Preserve immutable allocation history.

### 4. Multi-Currency Accounting

Support KHR and USD.

- Store the original payment currency.
- Store exchange-rate snapshots.
- Use fixed-point arithmetic.
- Define a consistent base currency.
- Preserve invoice and payment conversion details.
- Detect currency and rounding differences.
- Record foreign-exchange differences separately when necessary.
- Never silently modify historical invoice exchange rates.

Do not use PHP floating-point arithmetic for financial calculations.

### 5. Financial Rules

- Posted supplier invoices establish Accounts Payable.
- Supplier payments settle Accounts Payable.
- Supplier payments must not create additional purchase expenses.
- Supplier payments must not change inventory quantity.
- Supplier payments must not recalculate moving-average cost.
- Customer payments and supplier payments remain independent.
- Protect immutable posted payment records.

### 6. Supplier Payment Lifecycle

Suggested states:

DRAFT → SUBMITTED → APPROVED → POSTED

Additional states:

REJECTED, CANCELLED

Define valid state transitions and prevent editing posted records.

For bank/KHQR payments, require a documented verification step before final payable settlement.

### 7. Authorization

- Staff may create authorized payment requests without seeing restricted purchase costs.
- Owner controls payment approvals and posting.
- Enforce backend RBAC.
- Enforce CSRF on all state-changing routes.
- Never expose cost, profit, or sensitive payable totals to unauthorized Staff.
- Record all approvals, postings, and rejections in audit logs.

### 8. Reconciliation

Create read-only reconciliation for:

- Posted supplier invoices
- Posted payment allocations
- Remaining invoice balances
- Supplier-level outstanding payables
- Payment status and verification state
- Duplicate references
- Rounding and foreign-exchange differences

Never silently repair discrepancies.

### 9. Integration Tests

Create disposable-database tests for:

- Full supplier payment
- Partial supplier payment
- Multiple payments per invoice
- One payment across several invoices
- Cross-supplier rejection
- Overpayment rejection
- Draft invoice allocation rejection
- Duplicate posting
- Concurrent settlement
- Cash KHR and USD
- Manual bank/KHQR verification
- Unauthorized approval
- Invalid status transitions
- Transaction rollback
- Staff confidentiality
- CSRF rejection
- Payable reconciliation
- Phase 1–5.3 regression tests

### 10. Deliverables

Report:

1. Files created or changed.
2. Database migration.
3. Payment services and routes.
4. Allocation and lifecycle rules.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended milestone.

Implement Phase 5.4 only.

Do not implement supplier credits, landed costs, operating expenses, or fuel workflows yet.

Do not migrate the main `smartstock` database without explicit approval.