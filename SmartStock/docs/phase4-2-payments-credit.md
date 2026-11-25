# Phase 4.2 payments and smart customer credit

Phase 4.2 adds customer credit profiles, receivables, payment allocation, and manual bank/KHQR verification to confirmed Phase 4.1 sales. It does not add purchasing payments, automatic payment-provider verification, customer advances, refunds, partial returns, or credit notes.

## Main database preflight

The local main `smartstock` database was inspected read-only. It already contains the base-schema `customers`, `payments`, `receivables`, and `receivable_allocations` tables. It has no Phase 4.2 `sales.settlement_type`, `payments.payment_key`, or receivable credit/void columns. No main database migration, backfill, or business-data change was made.

## Migration

Import `database/phase4_2_payments_credit.sql` once after the base schema/seed and Phase 1 through Phase 4.1 migrations. It extends the existing financial tables; it does not introduce parallel payment, customer-credit, or allocation tables.

The migration adds credit-enable and term fields to customers; settlement and Owner-approved limit-override snapshots to sales; payment idempotency, customer, purpose, and verification metadata; nullable-customer receivables with due/void metadata; and immutable allocation reversal metadata. It also adds the manually verified `khqr` method alongside the seeded `cash` and `bank` methods. It is forward-only and not idempotent.

## Business rules

- Every newly confirmed sale establishes one receivable. Cash sales may use an anonymous receivable and never need a customer profile. Credit sales require an active, credit-enabled customer.
- Credit exposure is the customer's active receivables less verified inbound allocations. A confirmation over the current limit is rejected unless an Owner recorded a reasoned `credit.manage` override on the draft. Terms are snapshotted into the receivable and determine the due date.
- Payment status is computed independently of the sale: `unpaid`, `partial`, `paid`, `overdue`, or `voided`. A draft creates neither revenue nor a receivable.
- Cash payments in KHR or USD are verified when received. KHR must use a `1.000000` exchange rate; USD stores the submitted six-place rate and four-place converted KHR amount. Bank transfer and KHQR require a manual reference and remain `pending` until an Owner verifies or rejects them. There is no automatic bank/KHQR provider claim.
- A payment has a caller-supplied idempotency key and one to one hundred explicit allocations. Allocations must exactly equal its converted KHR amount, can settle multiple eligible invoices for one customer, and are locked in deterministic receivable-ID order. Pending allocations reserve invoice capacity; only verified inbound allocations reduce debt.
- Customer debt repayments are payments allocated to identifiable-customer credit invoices. They settle receivables only and never post a second sale or revenue record.
- Payment rejection preserves the payment and allocation history, marking allocations reversed instead of deleting them.
- A full sale void with no payment allocations marks its receivable voided in the same transaction as the existing stock/COGS reversal. A sale with pending or verified payment allocation is blocked: an Owner must use a future dedicated immutable refund or credit-adjustment workflow. Allocations are never silently discarded.

## Routes and permissions

| Route | Method | Permission |
| --- | --- | --- |
| `?r=payments` | GET | `payments.record`; Staff does not receive credit-limit, COGS, or profit data |
| `?r=payment-record` | POST | `payments.record` + CSRF |
| `?r=payment-verify` | POST | `credit.manage` + CSRF |
| `?r=customer-create` | POST | `credit.manage` + CSRF |
| `?r=customer-credit-update` | POST | `credit.manage` + CSRF |

Default Staff may record payment receipts but cannot create credit profiles, change limits, verify/reject bank/KHQR receipts, access credit reconciliation, or receive COGS/profit fields. Owner has the seeded `credit.manage` permission.

## Tests

All commands require a disposable database whose name ends in `_test`.

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    php tests/phase1_auth_integration.php
    php tests/phase2_product_domain_integration.php
    php tests/phase3_inventory_integration.php
    php tests/phase32_moving_average_integration.php
    php tests/phase41_sales_integration.php
    php tests/phase42_payments_integration.php

The Phase 4.2 suite covers full cash, KHR/USD split and partial payment, KHQR pending/Owner verification, due-date/overdue state, credit limits and Owner override, one payment across invoices, idempotency, concurrent over-settlement prevention, invalid currency/rate, Staff restrictions, CSRF, blocked settled-sale voids, unpaid voids, rollback, and revenue/cash/bank/credit reconciliation.
