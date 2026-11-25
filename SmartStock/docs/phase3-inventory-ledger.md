# Phase 3.1 inventory ledger and confirmed stock receipts

Phase 3.1 provides a narrow, transaction-safe stock-receipt workflow. It is not a purchasing module, a stock-adjustment module, or moving weighted-average costing.

## Migration and main database status

Import database/phase3_inventory_ledger.sql once after the base schema, reference seed, both Phase 1 migrations, and the Phase 2 migration. It creates inventory_receipts and inventory_receipt_items and adds a stock-movement reporting index.

The main smartstock database was not migrated by this implementation. Back it up and obtain the deployment owner's approval before importing the migration into business data.

## Receipt and ledger rules

- Receipts are opening or purchase documents with a unique external reference number.
- Drafts do not create balances or ledger rows.
- Each receipt line stores received quantity, converted base quantity, and an explicit cost per base unit.
- A receipt with any missing cost cannot be confirmed.
- Confirmation atomically locks/materializes the location balance, increases it, writes one immutable stock movement per line, marks the receipt confirmed, and records an audit event.
- Confirming an already-confirmed receipt is idempotent: it creates no additional ledger row.
- Reversal requires Owner approval, checks sufficient remaining stock, writes an offsetting immutable movement, and marks the original receipt reversed.
- Balances reconcile against the sum of non-batch stock movements for each product/location.

Stock movements use their existing source_type, source_id, source_line_id, and globally unique movement_key columns as the movement type, document reference, line reference, and posting identifier.

## Cost and access boundary

Phase 3.1 itself records explicit per-base-unit source costs. With Phase 3.2 installed, confirmation also updates product_costs and writes immutable inventory_cost_entries rows in the same transaction. See docs/phase3-2-moving-average.md for the weighted-average and reversal policies.

Staff with stock.receive can create quantity-only drafts. They cannot submit, view, set, or confirm valuation details. An Owner with stock.adjust.approve supplies costs and confirms receipts. This prevents unvalued inventory from being posted and prevents restricted cost data from reaching Staff.

Batch-tracked products are rejected until a dedicated batch/expiry receipt workflow exists.

## Routes

| Route | Method | Permission |
| --- | --- | --- |
| ?r=inventory-receipts | GET | stock.view |
| ?r=inventory-receipt-create | POST | stock.receive + CSRF |
| ?r=inventory-receipt-costs | POST | stock.adjust.approve + CSRF |
| ?r=inventory-receipt-confirm | POST | stock.receive, stock.adjust.approve + CSRF |
| ?r=inventory-receipt-reverse | POST | stock.adjust.approve + CSRF |

## Tests

All test scripts require SMARTSTOCK_TEST_DB_NAME to name a disposable database ending in _test.

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase1_auth_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase2_product_domain_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase3_inventory_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase32_moving_average_integration.php

The Phase 3 suite checks draft behavior, base-unit conversion, approval, CSRF, invalid inputs, archived products, rollback, idempotency, two-process confirmation, reconciliation, and immutable reversal entries.
