You are a Senior PHP Backend Engineer specializing in financial transactions, accounts receivable, payment allocation, and MySQL data integrity.

Read `AGENTS.md` and inspect the existing Phase 1–4.1 implementation, especially SalesService, InventoryService, MovingAverageCostService, migrations, RBAC, and integration tests.

## Task: Phase 4.2 — Payments & Smart Customer Credit

Focus on backend logic, database integrity, security, and automated tests. Do not redesign UI.

### 1. Preflight Inspection

- Inspect existing sales, customer, payment, and receivable tables.
- Inspect confirmed-sale and full-void behavior.
- Reuse existing schema and services where appropriate.
- Do not duplicate payment or credit tables.
- Never automatically migrate the main `smartstock` database.
- Use `smartstock_test` for destructive integration tests.

### 2. Customer Credit Management

Implement:

- Customer records and credit profiles.
- Configurable credit limits.
- Due dates and payment terms.
- Outstanding receivable balances.
- Credit exposure calculations.
- Overdue receivable detection.
- Owner-controlled credit-limit overrides.

Rules:

- Credit cannot silently exceed the approved limit.
- Cash sales should not require customer credit profiles.
- Credit sales must be linked to identifiable customers.
- Preserve credit history and audit events.
- Do not count customer debt repayments as new revenue.

### 3. Payment Methods

Support:

- Cash in KHR and USD.
- Manually verified Bank Transfer / KHQR.
- Split payments.
- Partial payments.
- Customer debt repayments.

Store:

- Payment reference
- Amount
- Currency
- Exchange-rate snapshot
- Payment method
- Transaction timestamp
- Receiving user
- Verification status

Do not claim automatic KHQR verification without a real authorized provider integration.

### 4. Payment Allocation

- Support multiple payments per invoice.
- Support one payment allocated to multiple eligible invoices when explicitly requested.
- Prevent over-allocation.
- Prevent negative allocations.
- Prevent duplicate payment posting.
- Make posting atomic and idempotent.
- Keep a traceable payment allocation ledger.

Use fixed-point DECIMAL-safe arithmetic.

### 5. Sales & Void Integration

Integrate with confirmed sales from Phase 4.1.

- Draft sales must not create receivables.
- Confirmed sales establish the amount owed.
- Payment status must be independent of sale status.
- Handle unpaid, partially paid, fully paid, and overdue invoices.
- Define refund and credit adjustment behavior for fully voided sales.
- Never delete immutable payment histories.
- Block unsafe voids or require compensating refund/credit transactions.

Do not silently discard payment allocations on void.

### 6. Financial Consistency

Maintain:

Sales Revenue != Cash Received

- Sales confirmation posts revenue once.
- Payments settle receivables.
- Credit repayments do not create new sales revenue.
- Reconciliation must distinguish revenue, cash, bank, credit, and refunds.
- Preserve historical exchange-rate snapshots.

### 7. Security

- Owner and Staff RBAC.
- Staff may record authorized payments.
- Owner controls credit limits, exceptional adjustments, and refunds.
- Enforce CSRF on state-changing routes.
- Restrict sensitive financial and profit data.
- Never expose COGS or purchase costs in Staff responses.

### 8. Integration Tests

Test:

- Full cash payment.
- Bank/KHQR payment requiring manual verification.
- Split payment.
- Partial payment.
- Credit sale.
- Due-date and overdue calculation.
- Credit-limit enforcement.
- Multiple payments on one invoice.
- Payment across multiple invoices.
- Duplicate confirmation.
- Concurrent payments.
- Invalid currency and exchange rate.
- Unauthorized access.
- CSRF rejection.
- Sales void with outstanding or settled payments.
- Rollback and reconciliation.
- Phase 1–4.1 regression suites.

### 9. Deliverables

Provide:

1. Files created or changed.
2. Database migration.
3. Services and routes implemented.
4. Credit and payment business rules.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended backend milestone.

Do not implement unrelated purchasing, fuel, or expense features in this phase.

Implement the smallest reliable, testable solution.