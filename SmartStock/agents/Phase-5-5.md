You are a Senior PHP Backend Engineer and Financial Accounting Systems Architect working on SmartStock.

Read `AGENTS.md` and inspect the complete Phase 1–5.4 implementation, migrations, services, reconciliation routines, audit logs, and integration tests.

## Task: Phase 5.5 — Supplier Credit Notes & Controlled Compensating Corrections

Focus exclusively on backend financial integrity, database transactions, authorization, immutable accounting records, and automated testing.

Do not redesign the UI or introduce unrelated frameworks.

### 1. Preflight Inspection

Inspect:

- Supplier invoices and posted payables
- Supplier payments and allocations
- Payment verification workflow
- Existing supplier invoice matching
- Customer credit-note patterns from Phase 4.3
- Inventory valuation and receipt posting
- Supplier reconciliation services
- RBAC, CSRF, and audit infrastructure

Reuse existing patterns when safe.

Do not automatically migrate the main database.

### 2. Supplier Credit Notes

Implement:

- Draft supplier credits
- Submission
- Owner approval
- Credit posting
- Rejection and cancellation
- Credit reasons
- Original supplier invoice references
- Supplier-issued credit note numbers
- Immutable posted credit entries

Rules:

- Only posted eligible supplier invoices can receive credit.
- Prevent credits exceeding the remaining creditable invoice amount.
- Prevent duplicate supplier-issued credit numbers.
- Do not silently modify posted invoice amounts.
- Preserve original currency and exchange-rate information.
- Prevent credits against cancelled or ineligible invoices.

### 3. Accounts Payable Adjustments

A posted supplier credit must:

1. Reduce the unpaid invoice balance first.
2. If credit exceeds outstanding payable, record excess as a recoverable supplier debit balance or approved future offset, according to an explicit policy.
3. Never create negative invoice payables.
4. Preserve credit allocations.
5. Prevent duplicate credit application.
6. Keep supplier credit balances distinct from customer credit balances.

Do not automatically issue or record supplier cash refunds.

### 4. Posted Supplier Payment Corrections

Implement controlled compensating corrections for erroneous supplier payments.

Requirements:

- Owner-only approval.
- Explicit correction reason.
- Reference original payment and allocations.
- Immutable reversal/correction ledger entries.
- Prevent duplicate corrections.
- Restore payable balances only when the original payment was genuinely reversed or proven not settled.
- Never assume that an accounting reversal means money has returned to the bank.
- Preserve bank verification references and audit history.
- Require appropriate evidence/status for bank/KHQR corrections.

Handle partial payment corrections only when the accounting and allocation model can support them safely; otherwise document this as deferred.

### 5. Financial Integrity

- Supplier credits adjust payables without reposting purchase costs.
- Supplier payments and their corrections must not create operating expenses.
- Do not automatically change inventory quantities or moving-average cost.
- Define and document accounting treatment for approved invoice-price corrections.
- Keep cash/bank settlement history separate from liability corrections.
- Maintain fixed-point currency and FX precision.

### 6. Concurrency and Idempotency

- Use MySQL transactions.
- Deterministic row-lock ordering.
- Unique posting references.
- Idempotency keys.
- Atomic allocation and ledger updates.
- Rollback on partial failures.

### 7. Authorization

- Owner approves and posts supplier credits.
- Owner authorizes financial corrections.
- Staff access is restricted to explicitly permitted non-sensitive operations.
- Enforce backend RBAC and CSRF.
- Prevent purchase cost and profit leakage.
- Audit all sensitive actions.

### 8. Reconciliation

Extend supplier-level read-only reconciliation to include:

- Original posted invoice totals
- Supplier credits
- Credit allocations
- Posted supplier payments
- Payment corrections
- Remaining outstanding payables
- Supplier debit/recoverable balances
- Currency and exchange-rate differences

Never silently alter historical transactions during reconciliation.

### 9. Integration Tests

Test:

- Full and partial supplier credits
- Credits on unpaid invoices
- Credits on partially paid invoices
- Credits on fully settled invoices
- Over-credit rejection
- Duplicate credit reference
- Concurrent credit posting
- Supplier mismatch
- Invalid invoice status
- Supplier credit cancellation rules
- Payment correction approval
- Duplicate correction rejection
- Payable restoration
- Bank/KHQR correction safeguards
- Rollback on failure
- Unauthorized access
- CSRF rejection
- Staff financial privacy
- Full Phase 1–5.4 regression suites

Use only disposable databases for destructive testing.

### 10. Completion Report

Provide:

1. Files created or changed.
2. Migration created.
3. Services and routes implemented.
4. Financial correction policies.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended backend milestone.

Implement Phase 5.5 only.

Do not introduce supplier prepayments, landed costs, inventory purchase returns, operating expenses, or fuel workflows in this milestone.

Do not modify the main `smartstock` database without explicit authorization.