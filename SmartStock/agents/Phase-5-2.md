You are a Senior PHP Backend Engineer and Inventory Accounting Architect working on SmartStock.

Read `AGENTS.md` and inspect the complete Phase 1–5.1 implementation, migrations, documentation, and automated tests.

## Task: Phase 5.2 — Goods Receiving, Partial PO Fulfillment & Locked Moving-Average Valuation

Focus exclusively on PHP backend, inventory accounting, business logic, database integrity, authorization, and automated tests.

Do not redesign UI or introduce unrelated modules.

### 1. Preflight Inspection

Inspect:

- Purchase order approval snapshots and status transitions.
- Existing inventory receipts from Phase 3.1.
- InventoryService and immutable stock ledger.
- MovingAverageCostService.
- Product-specific unit conversion.
- Supplier and purchase models.
- Existing stock locations and balances.
- Authentication, RBAC, CSRF, and audit infrastructure.

Reuse existing receipt, valuation, and stock-posting services. Do not create a separate conflicting inventory engine.

### 2. Purchase Order Goods Receiving

Implement receiving against approved purchase orders.

Support:

- Draft goods receipts.
- Multiple receipts per purchase order.
- Partial deliveries.
- Receiving different quantities for individual PO lines.
- Destination stock locations.
- Receipt references and delivery notes.
- Received-by and confirmed-by users.
- Received timestamps.

Business rules:

- Only approved or partially received POs can accept new receipts.
- Draft receipts do not modify inventory.
- Only confirmed receipts affect stock.
- A receipt must reference valid PO lines.
- Prevent receiving the same physical delivery twice.
- Reject receipt quantities exceeding remaining approved PO quantities.
- Do not silently accept unauthorized over-deliveries.

### 3. Quantity and Unit Validation

- Use stored purchase order unit-conversion snapshots.
- Convert received quantities into product base units.
- Use fixed-point DECIMAL-safe arithmetic.
- Reject zero or negative quantities.
- Validate product, supplier, and PO ownership relationships.
- Prevent changing purchase units after approval.
- Ensure received quantities reconcile to the approved PO quantity.

### 4. Inventory and Costing Integration

At confirmation:

1. Begin a MySQL database transaction.
2. Lock the affected PO and PO lines.
3. Validate remaining quantities.
4. Lock product costing records and destination balances.
5. Create immutable inventory movement entries.
6. Update stock balances.
7. Post inventory valuation using the existing MovingAverageCostService.
8. Recalculate product-wide weighted-average cost.
9. Update PO received quantities and statuses.
10. Record audit events.
11. Commit atomically.

Use deterministic lock ordering and database uniqueness constraints.

Never post duplicate stock or valuation changes.

### 5. Purchase Cost Snapshot

- Use approved PO price and currency/exchange-rate snapshots.
- Store actual receipt cost according to documented purchasing rules.
- Do not silently change approved prices.
- Owner must approve any permitted cost variance.
- Preserve original purchase order and receipt cost history.
- Do not invent landed costs.
- Keep future landed-cost allocation explicitly out of scope.

### 6. Purchase Order Status

Implement safe transitions:

- APPROVED → PARTIALLY_RECEIVED
- APPROVED → RECEIVED
- PARTIALLY_RECEIVED → RECEIVED
- RECEIVED → CLOSED, when eligible

Define cancellation, rejection, and closing restrictions after receiving.

Prevent invalid state transitions and accidental quantity changes.

### 7. Staff and Owner Permissions

Staff may:

- View authorized purchase order details without costs.
- Create quantity-only receipt drafts.
- Record received quantities.

Owner may:

- View purchasing costs.
- Review received quantities.
- Approve valid cost variances.
- Confirm valued receipts.
- Close eligible purchase orders.

Enforce permissions through backend services and routes.

### 8. Reconciliation

Implement read-only reconciliation for:

- Approved ordered quantities.
- Confirmed received quantities.
- Remaining PO quantities.
- Inventory ledger movements.
- Receipt valuation entries.
- Product-wide inventory values.

The report must identify inconsistencies without silently modifying data.

### 9. Automated Integration Tests

Test:

- Full goods receipt.
- Partial goods receipt.
- Multiple receipts per PO.
- Multiple PO lines.
- Unit conversion snapshots.
- Over-receipt rejection.
- Duplicate confirmation.
- Concurrent receiving against the same PO.
- Invalid PO status.
- Invalid product or stock location.
- Cost snapshot preservation.
- Unauthorized cost variance.
- Moving-average valuation.
- Ledger reconciliation.
- Rollback on failure.
- Owner and Staff RBAC.
- CSRF rejection.
- Staff cost privacy.
- Phase 1–5.1 regression suites.

Run destructive tests only on a disposable database.

### 10. Completion Report

Provide:

1. Files created or changed.
2. Database migrations.
3. Services and routes implemented.
4. PO state-transition rules.
5. Inventory and valuation integration details.
6. Tests executed and results.
7. Main database migration status.
8. Remaining limitations.
9. Next recommended milestone.

Do not migrate or modify the main `smartstock` database.

Implement Phase 5.2 only.