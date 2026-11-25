You are a Senior PHP Backend Engineer and Inventory Systems Architect working on SmartStock.

Read `AGENTS.md`, the complete Phase 1–5.8 documentation, existing inventory/costing services, database migrations, and integration tests before making changes.

## Task: Phase 6.1 — Internal Stock Transfers Between Storage Locations

Focus exclusively on backend business logic, MySQL transaction safety, inventory consistency, authorization, and automated testing.

Do not redesign UI or introduce unrelated frameworks.

### 1. Preflight Inspection

Inspect:

- Existing stock locations and balances
- Immutable stock movement ledger
- InventoryService
- MovingAverageCostService
- Inventory valuation ledger
- Product base units and conversion snapshots
- Existing quarantine and unavailable-stock handling
- RBAC, CSRF, and audit infrastructure
- Phase 1–5.8 regression suites

Reuse the existing inventory posting engine.

Do not introduce a second conflicting stock ledger or costing engine.

### 2. Stock Transfer Workflow

Implement:

- Draft stock transfer
- Source and destination locations
- Transfer line items
- Product and unit selection
- Requested quantities
- Staff submission
- Owner approval
- Transfer confirmation
- Rejection and cancellation before confirmation
- Immutable confirmed transfer records

Suggested workflow:

DRAFT → SUBMITTED → APPROVED → CONFIRMED

Do not change stock when a transfer is drafted, submitted, or approved.

### 3. Inventory Transfer Business Rules

- Source and destination locations must be different.
- Both locations must be active and eligible.
- Products must be valid and eligible.
- Quantity must be positive.
- Convert quantities to product base units.
- Reject insufficient available stock.
- Prevent negative stock.
- Prevent duplicate confirmation.
- Preserve product-wide inventory quantity.
- Preserve product-wide inventory valuation.
- Preserve the current moving-average unit cost.
- Do not create sales revenue, COGS, expenses, or supplier/customer liabilities.

### 4. Atomic Stock Posting

On confirmed transfer:

1. Start a MySQL transaction.
2. Lock the transfer record and line items.
3. Lock affected stock balances in deterministic order.
4. Validate available quantities.
5. Create immutable outbound movements for source locations.
6. Create immutable inbound movements for destination locations.
7. Update both stock balances.
8. Preserve the net company-wide quantity change at zero.
9. Ensure the transfer has no net inventory valuation effect.
10. Update transfer status and audit events.
11. Commit atomically.

Use unique posting references and idempotency constraints.

Concurrent transfers must not produce negative stock.

### 5. Valuation Policy

- Do not recalculate moving-average cost for internal transfers.
- Do not create profit or loss.
- If the existing ledger requires valuation entries, post offsetting entries using the same locked carrying-value basis.
- Ensure the net valuation impact is exactly zero.
- Preserve existing inventory costing history.

### 6. Access Control

Staff may:

- Create quantity-only transfer drafts.
- View permitted location stock.
- Submit transfer requests.

Owner may:

- Approve transfers.
- Confirm transfers.
- Reject or cancel transfers.
- View sensitive valuation reconciliation.

Enforce backend RBAC and CSRF.

Staff must not receive cost, margin, or valuation fields.

### 7. Read-Only Reconciliation

Provide checks for:

- Source stock decrease
- Destination stock increase
- Transfer quantity consistency
- Company-wide stock conservation
- Zero net valuation change
- Duplicate posting detection
- Unmatched movement pairs
- Approval and confirmation audit history

Never silently repair discrepancies.

### 8. Integration Tests

Create disposable-database tests covering:

- Successful single-item transfer
- Multi-item transfer
- Multiple stock locations
- Product unit conversion
- Same source/destination rejection
- Inactive location rejection
- Insufficient stock
- Duplicate confirmation
- Concurrent transfers
- Transaction rollback injection
- Stock conservation
- Valuation conservation
- No impact on moving-average cost
- Owner and Staff RBAC
- CSRF rejection
- Staff cost privacy
- Read-only reconciliation
- Full Phase 1–5.8 regression suites
- Fresh full-schema migration smoke test

Use independent MySQL connections for concurrency tests.

### 9. Database Safety

- Use only `smartstock_test` for destructive tests.
- Reject or safely strip embedded `USE smartstock` directives when importing fixtures.
- Never migrate or modify the main `smartstock` database.
- Clean up test data after execution.

### 10. Completion Report

Provide:

1. Files created or changed.
2. Database migration.
3. Transfer services and routes.
4. Business rules implemented.
5. Concurrency and rollback verification.
6. Reconciliation results.
7. Tests executed and passed.
8. Main database preservation status.
9. Remaining limitations.
10. Next recommended milestone.

Implement Phase 6.1 only.

Do not implement stock adjustments, stock counting, expenses, fuel workflows, or UI redesign in this milestone.