You are a Senior PHP Backend Engineer specializing in inventory accounting, financial transactions, and MySQL data integrity.

Read `AGENTS.md`, Phase 1–3.2 documentation, migrations, services, and integration tests before making changes.

## Task: Historical Valuation Preflight + Phase 4.1 Confirmed Sales Posting

Focus exclusively on backend business logic, database integrity, security, and automated testing.

### 1. Preflight and migration safety

- Inspect the main database migration status without changing it.
- Verify whether historical Phase 3.1 inventory movements exist without valuation entries.
- Build a read-only valuation reconciliation report.
- If historical valuation is required, document an explicit, reviewable backfill plan.
- Never invent historical purchase costs.
- Do not execute backfill or main database migrations without approval.
- Use only a disposable database for destructive tests.

### 2. Sales domain

Implement:

- Draft sales
- Sale line items
- Product and unit selection
- Retail and wholesale pricing
- Authorized flexible price overrides
- Sale confirmation
- Immutable confirmed sale snapshots

Keep payment posting and customer credit integration out of scope unless required by existing service contracts.

### 3. Transaction-safe sales posting

On confirmation:

1. Validate all sale items.
2. Verify product and unit status.
3. Verify permissions and pricing rules.
4. Lock stock balances and the relevant product costing records.
5. Check available quantity.
6. Obtain the current locked moving-average cost.
7. Deduct inventory.
8. Post immutable stock ledger entries.
9. Post inventory valuation and COGS entries.
10. Store sale revenue and cost snapshots.
11. Mark the sale confirmed.
12. Commit all changes atomically.

Use deterministic row-lock ordering to reduce deadlocks.

Prevent duplicate confirmations through database constraints and idempotency.

### 4. Pricing and cost privacy

- Owners can view purchase costs and gross profit.
- Staff cannot access cost data or valuation fields.
- Staff must not override prices without `pricing.override`.
- Preserve actual selling prices and discounts.
- Never modify historical confirmed sale pricing directly.

### 5. Financial correctness

- Revenue = Sum of confirmed net sale line amounts.
- COGS = Sum of immutable confirmed cost entries.
- Gross Profit = Revenue - COGS.
- Do not confuse sales revenue with cash received.
- Do not post payments or credit balances in this milestone.
- Avoid double-counting fuel sales in future integrations.

### 6. Reversals and returns

Define an explicit confirmed-sale void/reversal policy.

- Never delete immutable ledger entries.
- Use compensating stock and valuation postings.
- Preserve original sale and cost snapshots.
- Prevent duplicate reversals.
- Do not automatically assume all returned products are resellable.

Implement safe reversal support if existing contracts permit; otherwise document it for a separate milestone.

### 7. Security

- Owner and Staff RBAC.
- CSRF protection.
- Server-side validation.
- PDO prepared statements.
- No cost leakage in Staff APIs.
- Restrict sensitive sales reports to authorized users.

### 8. Integration tests

Test:

- Draft sale has no stock or cost impact.
- Successful confirmed sale.
- Base-unit conversion.
- Retail and wholesale pricing.
- Flexible price overrides.
- Insufficient stock.
- Archived products.
- Duplicate confirmation.
- Concurrent sales against limited stock.
- Locked weighted-average COGS.
- Rollback on posting failure.
- Revenue and COGS reconciliation.
- Owner and Staff authorization.
- CSRF rejection.
- Cost-data leakage.
- Phase 1–3.2 regressions.

### 9. Deliverables

Report:

- Files changed
- New migrations
- Services and routes added
- Business rules enforced
- Automated tests executed
- Migration and backfill status
- Remaining limitations
- Next recommended backend milestone

Do not redesign UI.
Do not introduce unrelated modules.
Do not modify the main business database.

Implement the smallest safe and testable backend solution.