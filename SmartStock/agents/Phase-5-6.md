You are a Senior PHP Backend Engineer and Inventory Accounting Architect working on SmartStock.

First read `AGENTS.md`, the full Phase 1–5.5 documentation, migrations, services, and integration tests.

## Task: Phase 5.6 — Controlled Physical Purchase Returns & Supplier Credit Integration

Focus exclusively on backend business logic, inventory integrity, financial consistency, authorization, and automated testing.

Do not redesign UI or introduce unrelated frameworks.

### 1. Preflight Inspection

Inspect:

- Confirmed purchase receipts and PO lines
- InventoryService and immutable stock ledger
- MovingAverageCostService and valuation ledger
- Supplier invoices and matching allocations
- Supplier credit notes and recoverable balances
- Existing customer return patterns
- Product units and conversion snapshots
- Inventory locations and quantity balances
- Owner/Staff permissions and audit infrastructure

Reuse existing posting services rather than building a separate inventory engine.

### 2. Purchase Return Workflow

Implement:

- Draft purchase return requests
- Partial and full purchase returns
- Return line items
- Supplier and original receipt references
- Return quantities and reasons
- Staff draft submission
- Owner review and approval
- Physical dispatch confirmation
- Immutable posted purchase return records
- Rejection and safe cancellation

Suggested statuses:

DRAFT → SUBMITTED → APPROVED → DISPATCHED → CONFIRMED

Document valid alternative/rejection transitions.

Do not deduct inventory for drafts, submissions, or approvals.

### 3. Quantity Validation

- Return quantities must not exceed confirmed received quantities minus previous physical returns.
- Validate original supplier, receipt, product, and PO relationships.
- Use product base-unit conversion snapshots.
- Reject zero or negative quantities.
- Ensure the requested quantity is available in the correct location and stock status.
- Do not consume reserved, quarantined, or otherwise unavailable stock without an explicit approved workflow.
- Prevent duplicate dispatch or confirmation.

### 4. Inventory Posting

When physical return is confirmed:

1. Begin a MySQL transaction.
2. Lock affected return, receipt, product cost, and location stock records.
3. Validate remaining returnable quantity.
4. Validate available inventory.
5. Create immutable outbound stock movements.
6. Update available stock balances.
7. Post valuation changes using the existing costing service.
8. Record approval, dispatch, and confirmation audit events.
9. Commit atomically.

No negative inventory.

Use deterministic lock ordering and idempotent posting references.

### 5. Moving-Average Costing

Use the current locked moving-average cost as the default inventory carrying-value basis for returned goods, unless the existing documented accounting policy requires another supported basis.

- Never overwrite historical receipt costs.
- Preserve historical average-cost snapshots.
- Reconcile quantity and valuation movements.
- Handle full stock depletion and rounding safely.
- Keep supplier credit amounts separate from inventory carrying values.
- Record purchase-price differences separately where needed.

### 6. Supplier Credit Integration

- Link purchase returns to eligible supplier credit notes where applicable.
- Prevent credit amounts or quantities being applied twice.
- Do not automatically create financial credits on physical dispatch.
- Do not change supplier payables until a valid supplier credit is posted.
- Handle returns before and after supplier invoice posting through explicit business rules.
- Do not invent a supplier refund when none occurred.
- Preserve full physical and financial reconciliation history.

### 7. Security

- Staff can create authorized quantity-only return requests.
- Owner approves and confirms physical returns.
- Staff must not access purchase costs or supplier financial balances.
- Enforce RBAC and CSRF on every protected endpoint.
- Use PDO prepared statements.
- Record every sensitive action in audit logs.

### 8. Reconciliation

Create read-only reconciliation covering:

- Original confirmed received quantities
- Previously returned quantities
- Remaining returnable quantities
- Outbound stock ledger movements
- Inventory valuation deductions
- Supplier credit-note allocations
- Unmatched physical returns
- Unmatched supplier credits
- Quantity and financial differences

Never silently repair inconsistencies.

### 9. Automated Integration Tests

Test:

- Partial purchase return
- Full purchase return
- Multiple returns against one receipt
- Over-return prevention
- Wrong supplier rejection
- Invalid PO/receipt relationships
- Insufficient inventory
- Duplicate confirmation
- Concurrent purchase returns
- Moving-average valuation
- Original receipt snapshots
- Physical return without credit note
- Valid supplier credit linkage
- Supplier credit mismatch
- Supplier invoice not yet posted
- Transaction rollback
- Owner/Staff permissions
- CSRF rejection
- Staff cost privacy
- Phase 1–5.5 regression suites

Use only a disposable test database.

### 10. Deliverables

Provide:

1. Files created or changed.
2. Database migration.
3. Services and routes implemented.
4. Purchase-return lifecycle rules.
5. Inventory valuation policy.
6. Supplier credit matching behavior.
7. Tests executed and results.
8. Main database migration status.
9. Remaining limitations.
10. Next recommended backend milestone.

Implement Phase 5.6 only.

Do not implement stock transfers, operating expenses, fuel workflows, supplier prepayments, or automatic supplier refunds.

Do not migrate the main `smartstock` database without explicit authorization.