# Phase 3.2 moving weighted-average costing

Phase 3.2 adds a narrow, product-wide moving weighted-average valuation engine to the confirmed opening and purchase receipt workflow. It does not add purchasing orders, sales, transfers, adjustments, batch costing, landed-cost allocation, or financial reporting.

## Migration and deployment boundary

Import database/phase3_2_moving_average_costing.sql once after the base schema, seed, Phase 1 and Phase 2 migrations, and database/phase3_inventory_ledger.sql. It extends the existing inventory_cost_entries ledger with a stock-movement link, average-cost snapshots, posting type, actor, and indexes. It also adds non-negative integrity checks to product_costs.

The migration does not backfill historical Phase 3.1 stock. For every product, Phase 3.2 refuses a new valued posting when the product-wide physical balance differs from product_costs.qty_valued. Before production deployment, back up the business database and reconcile or deliberately backfill all historical stock and value with an approved, separately reviewed migration. The main smartstock database was not migrated by this implementation.

## Valuation rules

- product_costs is the locked materialized company-wide valuation state: base quantity, KHR inventory value, and average cost. It spans every internal stock location.
- Each confirmed receipt writes its stock balance update, stock_movements row, product_costs update, and inventory_cost_entries row in one transaction.
- All receipt quantities are already fixed base units. Receipt costs are per base unit and must be greater than zero.
- Receipt value is quantity times explicit cost, rounded half-up to four KHR decimals. Average cost is inventory value divided by quantity, rounded half-up to six decimals. No PHP floats are used.
- Zero quantity, zero/missing cost, zero-quantity division, negative value, and an inconsistent product cost state are rejected.
- Full depletion sets quantity, value, and average to zero, clearing only the accumulated precision residual. A partial quantity/value rounding residual remains materialized and the next average is derived from that exact state.
- inventory_cost_entries are immutable. Each entry has one unique linked stock movement and posting reference. Corrections write a new negative quantity/value entry.

## Reversal policy

Receipt reversal still requires sufficient stock at the receipt location. It also locks the product-wide cost state. When a later receipt exists, the reversing quantity is valued at the current average before reversal, then the new average is calculated from the remaining quantity/value. The implementation never restores the old pre-receipt average, so newer receipt valuation is not overwritten.

## Access boundary and future contracts

Staff can create quantity-only drafts but cannot submit, inspect, reconcile, or post valuation data. Owner-only stock.adjust.approve authorization is enforced in services and controllers before cost data is processed. Receipt lists and Staff responses omit cost fields.

MovingAverageCostService now uses its lock-scoped quoteOutgoingCogs contract for Phase 4.1 confirmed sales. Fuel, transfer, return, and adjustment services remain future work and must write their own immutable stock movement, valuation entry, and source snapshot in the same transaction while holding the product-cost lock.

## Tests

All checks run only when SMARTSTOCK_TEST_DB_NAME names a disposable database ending in _test.

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase1_auth_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase2_product_domain_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase3_inventory_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase32_moving_average_integration.php

The Phase 3.2 suite covers opening value, base conversion, multiple receipt averages, immutable entries, duplicate confirmation, zero/missing valuation, full depletion, rollback, concurrent distinct receipts, legacy-state preflight, reversal after subsequent receipts, owner-only valuation, Staff no-cost leakage, and the future COGS quote contract.
