# Phase 4.1 confirmed sales posting

Phase 4.1 adds draft and confirmed retail/wholesale sales to the existing inventory and moving weighted-average ledger. It does not post payments, customer credit, receivables, fuel sales, partial returns, transfers, adjustments, or landed costs.

## Main database preflight

The read-only command below reports the configured database's receipt migration state, historical receipt movements without matching valuation entries, and physical-versus-valued quantity mismatches. It never runs a migration, backfill, or write.

    php bin/valuation_preflight_report.php

The preflight run on the local main smartstock database found no Phase 3.1 receipt tables, no Phase 3.2 movement link, zero receipt movements, zero missing valuation entries, and zero quantity mismatches. No historical backfill is currently required because no Phase 3.1 receipt postings exist. The main database was not migrated.

If a future production preflight finds receipt movements without valuation, do not derive or invent costs. First back up the database, export the affected immutable movements and source documents, have an Owner review explicit per-base-unit source costs, prepare a product-by-product chronological backfill proposal, independently reconcile resulting quantity/value totals, and obtain deployment approval. Only then should a separately reviewed, transaction-safe backfill migration be written and executed. This Phase 4.1 implementation does not include or run such a backfill.

## Migration

Import database/phase4_1_sales_posting.sql once after the base schema, seed, and Phase 1 through Phase 3.2 migrations. It adds confirmed/void metadata and timestamps to sales, plus immutable pricing and COGS snapshot columns and integrity/index support to sale_items. The migration is forward-only and does not post historical sales.

## Posting rules

- Draft sales contain one to one hundred active, non-batch-tracked product lines and have no stock, valuation, revenue, or COGS impact.
- Each sale line snapshots its selected unit, base-unit factor, retail or wholesale price type, actual unit price, override flag, discount, net revenue, base quantity, and eventually its COGS amount and per-base-unit rate.
- Sales are KHR-only in this milestone. Retail and wholesale lines use the configured current KHR price at draft creation. An explicit price override requires pricing.override.
- Confirmation locks each product-wide costing row before its location balance, in ascending product order. It validates stock, writes a negative stock movement, writes a negative immutable inventory_cost_entries COGS entry, updates product_costs, stores COGS on the line, and marks the sale confirmed in one transaction.
- A confirmed sale is idempotent: a repeat confirmation creates no second posting. Insufficient stock, archived products, missing price, an invalid snapshot, or an unreconciled valuation state rolls back every posting write.
- A sale whose net line revenue is below locked COGS requires pricing.below_cost.approve. Default Staff do not have this permission.
- Revenue is the sum of confirmed sales.total_khr. COGS is the sum of immutable confirmed sale-item snapshots. Gross profit is revenue minus COGS. No payment, bank, KHQR, cash, customer-credit, or receivable record is created.

## Privacy and void policy

Staff can create and confirm sales under the existing sales.create and sales.confirm permissions, but list responses, pages, and service methods never expose COGS, inventory value, or gross profit to them. Owner-only reports.profit exposes financial reconciliation; sales.void is also Owner-only.

Phase 4.1 supports a full-sale void, not a customer return. The Owner must supply a reason and explicitly confirm every item is physically resellable. The void writes new positive stock and valuation entries using each line's original immutable COGS snapshot, then marks the sale voided. It never edits the original sale, movement, valuation entry, price, revenue, or COGS snapshot. Partial returns and non-resellable returned goods require a dedicated returns/condition workflow.

## Routes

| Route | Method | Permission |
| --- | --- | --- |
| ?r=sales | GET | sales.create; Staff list excludes cost fields |
| ?r=sale-create | POST | sales.create + CSRF |
| ?r=sale-confirm | POST | sales.confirm + CSRF |
| ?r=sale-void | POST | sales.void + CSRF + explicit resellable confirmation |

## Tests

All checks require SMARTSTOCK_TEST_DB_NAME to name a disposable database ending in _test.

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    php tests/phase1_auth_integration.php
    php tests/phase2_product_domain_integration.php
    php tests/phase3_inventory_integration.php
    php tests/phase32_moving_average_integration.php
    php tests/phase41_sales_integration.php

The Phase 4.1 suite covers read-only valuation preflight, drafts, base conversion, retail/wholesale pricing, authorized override, locked COGS, insufficient stock, archive checks, duplicate confirmation, concurrent limited stock sales, rollback, financial reconciliation, Staff privacy, CSRF, and safe void postings.
