You are a Senior PHP Backend Engineer and Database Architect working on my existing SmartStock project.

Read `AGENTS.md` and inspect the existing Phase 1 and Phase 2 implementation before changing anything.

## Task: Phase 3.1 — Transaction-Safe Inventory Ledger & Confirmed Stock Receipts

Focus exclusively on PHP backend, business logic, database integrity, authorization, and automated testing.

### 1. Preflight checks

- Inspect the existing inventory-related schema and migrations.
- Verify the Phase 2 migration state.
- Do not assume the main database has been migrated.
- Back up the main database before any approved migration.
- Use a disposable database for destructive tests.
- Do not modify or reset production/business data.

### 2. Inventory Ledger

Implement an auditable stock movement ledger.

Each stock movement must record:

- Product ID
- Stock location ID
- Base-unit quantity delta
- Movement type
- Reference document and reference ID
- Actor ID
- Timestamp
- Unique posting identifier

Requirements:

- Never modify or delete posted ledger entries.
- Use reversal entries for corrections.
- Prevent duplicate ledger posting.
- Preserve historical stock movements.
- Support future purchases, sales, transfers, returns, and fuel operations.

### 3. Stock Balances

Implement location-based stock balances.

Rules:

- Stock quantities are stored in product base units.
- Use DECIMAL-safe arithmetic.
- No negative stock.
- Prevent race conditions using database transactions and row-level locks.
- Update stock balances atomically with ledger entries.
- Ensure ledger and balance totals can be reconciled.

### 4. Stock Receipts

Implement confirmed stock receipts with:

- Product selection
- Product unit selection
- Quantity
- Unit conversion
- Target stock location
- Reference number
- Draft and confirmed states

Business rules:

- Draft receipts do not modify stock.
- Confirmed receipts increase stock.
- Confirmation must be idempotent.
- Prevent confirming the same receipt twice.
- Use server-side validation.
- Require authorized permissions.
- Record audit events.

### 5. Important Costing Boundary

Do not invent purchase prices for stock receipts.

Do not silently post unvalued receipts into valued inventory.

Design a clear distinction between:

- Opening stock with an explicitly provided opening cost.
- Purchase receipts with known purchase and landed costs.
- Non-purchase adjustments requiring approved valuation rules.

Reserve the actual Moving Weighted Average Cost implementation for the costing milestone unless an existing, verified service already supports it.

### 6. Security

- Enforce RBAC on all inventory actions.
- Protect POST routes using CSRF.
- Use PDO prepared statements.
- Prevent Staff from receiving restricted cost information.
- Validate quantities and references on the backend.

### 7. Automated Testing

Create repeatable integration tests covering:

- Receipt creation
- Draft receipts
- Successful confirmation
- Unit conversion
- Duplicate confirmation
- Invalid quantities
- Archived products
- Invalid stock locations
- Unauthorized requests
- CSRF rejection
- Transaction rollback
- Concurrent posting
- Ledger-to-balance reconciliation
- Reversal behavior when implemented

Run Phase 1 and Phase 2 regression tests.

### 8. Implementation Constraints

- Maintain PHP OOP MVC architecture.
- Keep business logic in services.
- Do not introduce Laravel, React, Docker, Redis, or PWA.
- Do not redesign UI.
- Do not modify unrelated modules.
- Do not implement complete Sales or Fuel Management in this milestone.

### 9. Completion Report

Report:

1. Files changed.
2. Database migrations created.
3. Backend services and routes implemented.
4. Business rules enforced.
5. Tests actually executed.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended backend milestone.

Implement the smallest complete and testable solution.