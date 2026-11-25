You are a Senior PHP Backend Engineer and Financial Systems Architect working on the SmartStock project.

Read `AGENTS.md` and inspect the existing Phase 1–4.2 implementation, particularly SalesService, PaymentService, MovingAverageCostService, immutable stock and valuation ledgers, customer receivables, and payment allocations.

## Task: Phase 4.3 — Immutable Refunds & Credit Notes

Focus exclusively on backend business logic, financial integrity, security, and automated integration testing.

Do not redesign UI or introduce new frontend frameworks.

### 1. Preflight Inspection

- Inspect all existing sales, payment, receivable, and allocation tables.
- Inspect existing sale void behavior.
- Identify reusable financial services and transaction contracts.
- Preserve backward compatibility with existing confirmed sales.
- Do not duplicate existing tables or business logic.
- Do not migrate the main `smartstock` database automatically.

### 2. Credit Note Management

Implement:

- Draft credit notes
- Credit note approval
- Credit note posting
- Credit note reversal where permitted
- Customer and invoice references
- Credit note reasons
- Owner-only approval
- Immutable posted credit note ledger

Business rules:

- Only confirmed, eligible sales can receive credit notes.
- Credit notes must not exceed the remaining creditable sale amount.
- Prevent duplicate credit note posting.
- Prevent credit notes against voided sales.
- Record posting timestamps and actor IDs.
- Use compensating entries instead of editing immutable history.

### 3. Customer Receivable Integration

Credit notes must reduce the customer's collectible balance.

Apply approved credit notes in a clearly defined order:

1. Reduce outstanding invoice debt.
2. Recognize any excess as a refundable customer liability or controlled unapplied credit.
3. Never create negative customer receivables.
4. Prevent duplicate allocation.
5. Preserve full allocation history.

Do not automatically issue cash refunds.

### 4. Refund Management

Support:

- Cash refunds
- Bank transfer refunds
- Refund approval
- Refund posting
- Refund reversal or correction where valid
- Manual verification of bank refund completion

Rules:

- Only Owner can approve and release refunds.
- Refunds must be linked to legitimate refundable customer balances.
- Prevent refund amounts exceeding available refundable credit.
- Prevent duplicate refunds.
- Use idempotency keys.
- Preserve currency and exchange-rate snapshots.
- Record actor, timestamp, reason, and references.

### 5. Accounting Consistency

- Credit notes must adjust net sales revenue correctly.
- Payments and refunds must not generate new sales revenue.
- Do not treat a refund as an operating expense.
- Do not reverse COGS or return inventory merely because a financial credit note was issued.
- Inventory returns must be handled in the later physical returns milestone.
- Gross profit reports must reflect both financial credits and inventory adjustments correctly.

### 6. Transaction Safety

- Use database transactions for multi-table financial postings.
- Use deterministic row-lock ordering.
- Prevent concurrent over-refunds and over-crediting.
- Handle retries safely.
- Preserve all posted financial records.
- Use fixed-point DECIMAL arithmetic, not PHP floats.

### 7. Security

- Owner-only credit note approvals and refund releases.
- Staff can only perform explicitly authorized operational actions.
- Enforce RBAC on every endpoint.
- Validate CSRF for every state-changing action.
- Protect financial details and cost information from unauthorized users.
- Record sensitive actions in the audit log.

### 8. Integration Tests

Implement repeatable tests covering:

- Draft credit note
- Credit note approval
- Partial credit note
- Full credit note
- Over-credit rejection
- Unpaid invoice
- Partially paid invoice
- Fully paid invoice
- Credit and refundable balance calculation
- Refund approval and posting
- Duplicate credit note confirmation
- Duplicate refund posting
- Concurrent refunds
- Invalid currency and exchange rate
- Sales void interactions
- Authorization and CSRF
- Transaction rollback
- Revenue and receivable reconciliation
- All Phase 1–4.2 regression suites

Use only a disposable test database.

### 9. Completion Report

Provide:

1. Files created or changed.
2. Database migration.
3. Credit note and refund services implemented.
4. Financial posting rules.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended milestone.

Implement Phase 4.3 only.

Do not implement physical partial returns, supplier purchasing, operating expenses, or fuel management in this milestone.