You are a Senior PHP Backend Engineer, Financial Systems Architect, and MySQL Transaction Specialist working on SmartStock.

Read `AGENTS.md`, Phase 5.5–5.7 documentation, migrations, services, financial reconciliation logic, and integration tests.

## Task: Phase 5.8 — Linked Supplier Credit Compensating Corrections

Focus exclusively on backend correctness, immutable financial accounting, inventory consistency, security, and automated testing.

Do not redesign the UI or implement unrelated features.

### 1. Preflight Inspection

Inspect:

- SupplierCreditService and posted supplier credits
- Supplier credit allocations to payables
- Recoverable supplier balances
- Supplier payment and payment correction services
- PurchaseReturnService
- PurchaseReturnCorrectionService
- Purchase return-to-credit linkage records
- Inventory and valuation reconciliation
- RBAC, CSRF, and audit infrastructure

Document the existing data model and exact dependency that blocks physical restoration when a linked supplier credit exists.

Reuse existing services and ledgers. Do not introduce a parallel accounting engine.

### 2. Financial Correction Workflow

Implement controlled compensating corrections for linked supplier credits.

Required capabilities:

- Draft correction request
- Original posted supplier-credit reference
- Linked purchase-return reference
- Correction reason
- Supporting evidence reference
- Owner approval
- Immutable posting
- Rejection and cancellation before posting

Support partial or full correction only where safe and fully reconciled.

### 3. Supplier Credit Accounting

On approved correction posting:

- Preserve the original posted supplier credit.
- Create immutable compensating financial entries.
- Reverse only the eligible corrected amount.
- Restore payable liabilities when previously reduced by the credit, where appropriate.
- Reduce recoverable supplier balances where they were created by the credit.
- Prevent negative recoverables or invalid allocations.
- Reject corrections that would invalidate already settled or externally refunded amounts unless supported by a separate verified settlement adjustment.

Use original currency and exchange-rate snapshots and record any required FX differences explicitly.

### 4. Physical Return Integration

- Link financial corrections to the exact original purchase return.
- Track the corrected financial amount and eligible physical quantity independently.
- Do not infer financial correction amounts from quantity alone.
- Release Phase 5.7's linked-credit block only for the exact quantity and value that are fully reconciled.
- Prevent unrelated supplier credits from unlocking a physical correction.
- Prevent double corrections, over-corrections, and cross-supplier linking.
- Do not automatically restore inventory when posting a financial correction.
- Require the separate Phase 5.7 approved physical correction workflow for any stock restoration.

### 5. Accounting and Transaction Safety

- Keep financial and inventory changes separately auditable.
- Preserve immutable posted transactions.
- Use compensating ledger entries.
- Use MySQL InnoDB transactions and deterministic row locking.
- Use fixed-point DECIMAL arithmetic.
- Enforce idempotency with database constraints.
- Prevent concurrent over-corrections.
- Roll back all writes on failure.
- Do not manufacture supplier cash refunds.

### 6. Security

- Owner approval and posting only.
- Staff may access only explicitly authorized quantity-only information.
- Enforce server-side RBAC and CSRF.
- Never expose purchase costs, supplier credit amounts, recoverables, or profit data through Staff responses.
- Audit every financial correction decision and posting.

### 7. Reconciliation

Implement read-only reconciliation covering:

- Original supplier credit amount
- Financial corrections posted
- Net effective supplier credit
- Payable adjustments
- Recoverable adjustments
- Linked purchase-return quantities
- Eligible physical restoration quantities
- Remaining unmatched financial and physical amounts

Never silently modify discrepancies.

### 8. Automated Tests

Create disposable-database integration tests covering:

- Partial linked-credit correction
- Full linked-credit correction
- Duplicate correction rejection
- Concurrent correction posting
- Over-correction rejection
- Cross-supplier rejection
- Payable restoration
- Recoverable balance reduction
- Already-settled allocation safety
- Evidence validation
- Correct physical-restoration unlock
- Unrelated-credit isolation
- No automatic inventory changes
- Transaction rollback injection
- Actual correction-route RBAC and CSRF
- Staff financial privacy
- Full Phase 1–5.7 regression suites
- Fresh full-schema migration smoke test

Use independent MySQL connections for concurrency testing.

### 9. Database Safety

- Use `smartstock_test` only for destructive tests.
- Do not migrate or modify the main `smartstock` database.
- Reject embedded database-selection directives targeting the main database.
- Preserve test isolation and clean all test fixtures afterward.

### 10. Deliverables

Report:

1. Files created or changed.
2. Database migrations.
3. Financial correction rules.
4. Phase 5.7 linkage and eligibility integration.
5. Tests executed and results.
6. Concurrency and rollback results.
7. Reconciliation status.
8. Test database cleanup.
9. Main database preservation.
10. Remaining limitations.

Implement Phase 5.8 only. Do not start Phase 6, expenses, fuel, or UI redesign.