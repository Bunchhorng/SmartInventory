# Phase 4.4 physical sales returns

Phase 4.4 adds the physical counterpart to Phase 4.3 financial corrections. It supports controlled, line-level goods returns without changing historical sales, credit notes, stock movements, or COGS entries.

## Main database status

No Phase 4.4 migration or business-data change is made automatically to the configured `smartstock` database. Apply the migration only after a backup and a review of existing return records.

## Migration

Import `database/phase4_4_sales_returns.sql` once after the base schema/seed and Phase 1 through Phase 4.3 migrations. It is forward-only and not idempotent.

The migration extends the base-schema `sale_returns` and `sale_return_items` documents rather than creating duplicate return tables. It adds idempotency, approval/posting attribution, the linked credit note, requested/approved quantities, immutable posted financial/cost snapshots, condition codes, and indexes/constraints. It also adds:

- `sale_return_writeoffs` for damaged and expired goods;
- `sale_return_quarantine_holds` for traceable unavailable goods.

## Return and financial controls

- Staff with `sales.create` may submit a draft with a 16–80 character idempotency key. Only an Owner may approve or confirm it.
- A return must reference a confirmed, non-voided sale, its original sale line, and a posted credit note for that same sale. Requests and confirmations reject quantities beyond the original line less already confirmed returns.
- The physical-return amount is derived from the immutable original sale-line revenue snapshot. Confirmed physical returns on a credit note cannot exceed that note’s unused financial value.
- No credit note, receivable application, payment, or refund is created by return approval or confirmation. Refunds remain the separate Phase 4.3 workflow.
- A posted credit note with a confirmed physical return cannot be reversed. A sale with a posted credit note or confirmed return cannot use the full-sale void workflow.

## Stock and COGS policy

- A resellable confirmed return creates one positive `sale_return` stock movement linked to the return document/item, updates the original active location balance, and writes one immutable `sale_return_restock` valuation entry.
- Restock valuation uses the original sale line’s immutable COGS and unit-cost snapshots. It adds that cost basis to the current product valuation state, then recomputes the moving average; it never rewrites the original sale COGS.
- Confirmed resellable return cost is subtracted from sales COGS reporting. Financial revenue remains governed by the already-posted credit note.
- Damaged and expired goods never re-enter available stock or product valuation. Their original sale-line COGS is retained in a separate immutable write-off record.
- Quarantined goods never re-enter available stock or product valuation. They remain as a traceable unavailable hold until a later authorized disposition workflow.

All create, approve, and confirm workflows use MySQL transactions and row locks. The unique return key, unique return-line/original-sale-line pairing, status transition, movement key, and valuation-movement uniqueness protect retry and concurrency paths. Failed stock, valuation, or write-off posting rolls the entire confirmation back.

## Routes and access

| Route | Method | Access |
| --- | --- | --- |
| `?r=sales-returns` | GET | Owner or Staff with `sales.create`; Staff output excludes cost fields |
| `?r=sales-return-create` | POST | Owner or Staff with `sales.create` + CSRF |
| `?r=sales-return-approve` | POST | Owner + CSRF |
| `?r=sales-return-confirm` | POST | Owner + CSRF |

The small register form intentionally accepts base-unit quantities and one return line. The service accepts up to 100 lines for a future multi-line client, but all authorization, quantity, credit, and posting rules are enforced server-side.

## Tests

Use only an empty disposable database whose name ends in `_test`. Import the base schema/reference seed and every migration through Phase 4.4 into that database; never substitute the configured business database.

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    php tests/phase1_auth_integration.php
    php tests/phase2_product_domain_integration.php
    php tests/phase3_inventory_integration.php
    php tests/phase32_moving_average_integration.php
    php tests/phase41_sales_integration.php
    php tests/phase42_payments_integration.php
    php tests/phase43_credit_note_integration.php
    php tests/phase44_sales_returns_integration.php

The Phase 4.4 suite covers partial/full returns, idempotency, overflow and prior-return guards, resellable restock, damaged/expired write-offs, quarantine holds, original-cost snapshots, moving-average reconciliation, credit-note linkage and limits, refund separation, concurrent confirmation, rollback, authorization, CSRF, Staff cost privacy, and the Phase 1–4.3 regressions.

## Remaining limitations

There is no return disposition workflow for releasing, destroying, or selling quarantine stock, no batch/expiry tracking integration, no supplier-return workflow, and no automated bank/KHQR verification. The register uses ID/base-quantity entry and has no multi-line interactive editor yet.
