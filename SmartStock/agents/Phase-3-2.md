You are a Senior PHP Backend Engineer specializing in inventory accounting, MySQL transactions, and financial data integrity.

Read the existing `AGENTS.md` and inspect all Phase 1, Phase 2, and Phase 3.1 implementations before modifying code.

## Task: Phase 3.2 — Moving Weighted Average Costing Engine

Focus only on backend business logic, database integrity, security, and automated integration testing.

### 1. Preflight inspection

- Inspect the existing immutable inventory ledger.
- Inspect stock receipts, reversals, and balance locking.
- Inspect how receipt costs are currently stored.
- Inspect product base-unit conversion rules.
- Identify existing valuation and costing tables.
- Avoid duplicating existing functionality.
- Do not migrate the main business database automatically.

### 2. Moving Weighted Average Cost

Implement product-level moving weighted-average costing across all internal stock locations.

Required behavior:

- Track total available quantity and inventory value per product.
- Convert all received quantities and costs into base units.
- Recalculate average cost on qualifying valued receipts.
- Preserve sufficient decimal precision.
- Implement explicit rounding rules.
- Prevent zero-quantity division.
- Prevent negative inventory value.
- Ensure internal transfers do not change the company-wide average cost.

### 3. Inventory Valuation Ledger

Implement immutable inventory valuation entries linked to stock movements.

Record:

- Product ID
- Stock movement reference
- Quantity change
- Value change
- Average cost before and after posting
- Costing transaction type
- Actor ID
- Timestamp
- Unique posting reference

Use reversal or compensating entries for corrections.

Do not edit historical valuation records.

### 4. Opening Stock and Receipts

Support:

- Opening stock with an explicit opening cost.
- Owner-valued confirmed stock receipts.
- Supplier purchase costs.
- Future landed-cost allocation.

Important:

- Staff must not view or submit purchase costs.
- Unvalued receipts must not silently affect valued inventory.
- Validate that all required costs are present before posting.
- Keep the existing idempotent stock receipt confirmation behavior.

### 5. Costing Consistency

All inventory quantity and valuation changes must happen in the same database transaction.

Use row-level locking to prevent concurrent weighted-average updates.

Avoid floating-point arithmetic for money.

Handle:

- Concurrent stock receipts
- Duplicate confirmation requests
- Receipt reversal
- Full stock depletion
- Rounding residuals
- Invalid and zero costs
- Product unit conversions

For reversals, preserve historical cost integrity and define explicit policies for periods where later transactions exist. Never simply restore an outdated average cost.

### 6. Future Sales Integration

Prepare the costing service to provide an immutable COGS snapshot when future confirmed sales deduct inventory.

Do not implement sales posting in this phase.

Design clear service contracts for future:

- Purchase receiving
- Sales posting
- Sales returns
- Stock transfers
- Stock adjustments
- Fuel sales

### 7. Security

- Enforce Owner-only valuation access.
- Preserve Staff quantity-only workflows.
- Enforce backend RBAC and CSRF.
- Never expose cost or inventory valuation through Staff responses.

### 8. Testing

Create disposable-database integration tests covering:

- Opening stock valuation
- Multiple receipts at different costs
- Base-unit conversion
- Moving weighted-average calculations
- Zero stock
- Full stock depletion
- Invalid cost inputs
- Duplicate confirmation
- Concurrent receipts
- Rollback on failures
- Valuation reconciliation
- Reversal after subsequent receipts
- Owner and Staff authorization
- No cost leakage
- Phase 1–3.1 regression tests

Do not alter the main `smartstock` database during tests.

### 9. Completion report

Provide:

- Files changed
- Database migrations
- Costing services implemented
- Valuation rules
- Tests executed and results
- Remaining limitations
- Main database migration status
- Next recommended backend milestone

Implement Phase 3.2 only. Do not redesign UI or introduce unrelated modules.