You are a Senior PHP Backend Engineer and Inventory Accounting Architect working on SmartStock.

Read `AGENTS.md` and inspect the existing Phase 1–4.3 implementation, migrations, integration tests, and documentation.

## Task: Phase 4.4 — Physical Sales Returns, Restocking & COGS Reversal

Focus on backend business logic, inventory accuracy, financial integrity, security, and automated testing.

Do not redesign the UI.

### 1. Preflight Inspection

Inspect:

- SalesService
- InventoryService
- MovingAverageCostService
- CreditNoteService
- PaymentService
- Stock ledger and valuation ledger
- Sales line item snapshots
- Existing credit note and refund logic
- RBAC and audit logs

Do not duplicate existing accounting functionality.

### 2. Sales Return Management

Implement:

- Draft return requests
- Partial returns
- Full returns
- Return reasons
- Original sale and line-item references
- Requested and approved quantities
- Return statuses
- Owner approval
- Immutable confirmed return records

Rules:

- Only eligible confirmed sales can be returned.
- Never return more than the original sold quantity.
- Account for quantities already returned.
- Reject duplicate return posting.
- Prevent returns against voided sales unless an explicit safe policy exists.

### 3. Product Condition

Support:

- Resellable
- Damaged
- Expired
- Quarantine

Business rules:

- Resellable stock may return to available inventory.
- Damaged or expired goods must not become available stock.
- Quarantined goods must remain unavailable until authorized disposition.
- Preserve traceability to the original sale.

### 4. Inventory Restocking

For approved returns:

- Create immutable stock movement records.
- Update stock balances atomically.
- Preserve base-unit conversion.
- Prevent duplicate stock increases.
- Link movements to original sale lines and return records.

Do not modify historical sale stock movements.

### 5. COGS Reversal & Costing

Use the original sale line's immutable unit COGS snapshot to determine the return's cost basis.

- Reconcile the original COGS amount against prior returns.
- Do not use current product selling prices to calculate return cost.
- Do not overwrite the original sales COGS.
- Handle return valuation and future moving-average calculations correctly.
- Treat non-resellable inventory according to a documented accounting policy.
- Record write-offs separately when required.
- Do not silently restore damaged goods to available stock.

### 6. Credit Note Integration

Integrate with Phase 4.3.

- A physical return must reference an appropriate financial credit decision.
- Reuse existing credit notes where valid.
- Never create duplicate financial credits.
- Prevent over-crediting the original sale.
- Keep refund posting separate from return approval.
- Preserve invoice, receivable, and credit-note reconciliation.

### 7. Transaction Safety

Use:

- MySQL transactions
- Deterministic row locking
- Idempotency keys
- Unique posting constraints
- Immutable ledger entries
- Safe rollback on failure

Avoid PHP floating-point money calculations.

### 8. Security

- Owner approves confirmed returns and exceptional write-offs.
- Staff can create authorized draft return requests.
- Enforce RBAC and CSRF on all routes.
- Staff must not access cost or profit fields.
- Record approval and posting actions in audit logs.

### 9. Integration Tests

Test:

- Partial return
- Full return
- Duplicate return rejection
- Return quantity overflow
- Previously returned quantity
- Resellable restock
- Damaged return
- Expired return
- Quarantine return
- Original COGS snapshot
- Moving-average valuation reconciliation
- Financial credit-note integration
- Refund separation
- Concurrent returns
- Rollback
- Authorization
- CSRF
- Staff cost privacy
- Phase 1–4.3 regression tests

Use only a disposable test database.

### 10. Deliverables

Provide:

1. Files changed.
2. Database migrations.
3. Return services and routes.
4. Inventory and COGS accounting rules.
5. Credit-note integration behavior.
6. Tests executed and results.
7. Main database migration status.
8. Remaining limitations.
9. Next recommended backend milestone.

Do not automatically migrate the main database.

Implement Phase 4.4 only.