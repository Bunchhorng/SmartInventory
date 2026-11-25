You are a Senior PHP Backend Engineer and Inventory Accounting Architect working on SmartStock.

Read `AGENTS.md` and inspect the complete Phase 1–5.6 codebase, migrations, documentation, ledger posting services, and integration tests.

## Task: Phase 5.7 — Controlled Purchase Return Corrections & Immutable Compensating Postings

Focus exclusively on backend business logic, inventory accounting, database integrity, authorization, and automated testing.

Do not redesign UI.

### 1. Preflight

Inspect existing purchase returns, supplier credits, stock movements, valuation entries, receipt quantities, and reconciliation contracts.

Identify which correction cases are safe to support without changing historical posted records.

Do not automatically migrate the main database.

### 2. Correction Workflow

Implement:

- Draft correction requests
- Original posted purchase-return reference
- Correction reason
- Supporting evidence references
- Owner approval
- Confirmed compensating posting
- Immutable correction history

Suggested statuses:

DRAFT → SUBMITTED → APPROVED → POSTED

Allow rejection and cancellation before posting.

### 3. Correction Rules

- Never edit or delete posted purchase-return movements.
- Never modify the original return quantity directly.
- Only correct proven operational errors.
- Do not allow corrections greater than the eligible original returned quantity.
- Account for all previously posted corrections.
- Prevent duplicate correction posting.
- Reject unsupported historical states.
- Require documented Owner approval.

### 4. Stock and Valuation

For an approved restoration correction:

- Restore only physically verified, eligible inventory.
- Use immutable positive compensating stock movements.
- Update stock balances transactionally.
- Use a documented valuation basis consistent with the existing MovingAverageCostService.
- Preserve the original return's costing snapshot.
- Recalculate the current moving average safely when appropriate.
- Prevent negative or inconsistent inventory values.

If the physical goods remain with the supplier, do not restore on-hand stock merely to reverse a financial document.

### 5. Supplier Credit Consistency

- Inspect linked supplier credits and allocations.
- Prevent physical-return corrections that leave incompatible financial claims.
- Do not automatically reverse posted supplier credits.
- Require an explicit financial correction or reconciliation policy where needed.
- Keep supplier payables, recoverable balances, and physical return corrections separately auditable.

### 6. Transaction Safety

Use:

- MySQL InnoDB transactions
- Deterministic row-lock ordering
- Fixed-point arithmetic
- Idempotency keys
- Unique posting references
- Immutable stock and valuation ledger entries
- Atomic commit and rollback

### 7. Authorization

- Staff may submit quantity-only correction requests.
- Owner must approve and post corrections.
- Enforce backend RBAC and CSRF.
- Protect inventory costs and supplier financial details from Staff.
- Record all approval and posting events in the audit log.

### 8. Reconciliation

Implement read-only checks for:

- Original purchase-return quantities
- Corrected quantities
- Net physically returned quantities
- Stock ledger movements
- Inventory valuation effects
- Linked supplier credits
- Unresolved financial discrepancies

Do not automatically fix reconciliation errors.

### 9. Tests

Create integration tests covering:

- Valid full correction
- Valid partial correction
- Multiple corrections
- Over-correction rejection
- Duplicate confirmation
- Concurrent corrections
- Unsupported historical states
- Missing evidence
- Owner approval
- Staff cost privacy
- Supplier credit mismatch
- Transaction rollback
- CSRF and RBAC
- Stock/valuation reconciliation
- All Phase 1–5.6 regression suites

Use only disposable databases.

### 10. Deliverables

Provide:

1. Files created or changed.
2. Migration.
3. Correction services and routes.
4. Business rules and valuation policy.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended milestone.

Implement Phase 5.7 only.

Do not implement general stock transfers, expenses, fuel, supplier refunds, or automatic financial-credit reversals.

Do not modify the main `smartstock` database without explicit authorization.