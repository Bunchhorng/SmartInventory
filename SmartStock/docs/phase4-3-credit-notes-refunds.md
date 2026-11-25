# Phase 4.3 credit notes and refunds

Phase 4.3 adds an Owner-only, immutable financial-correction workflow to Phase 4.2 receivables. It covers financial credit notes and refunds only. It does not add physical returns, inventory restocking, COGS reversal, supplier payments, operating expenses, or payment-provider integration.

## Main database preflight

The local main `smartstock` database was inspected read-only during the preceding phases. No Phase 4.3 migration, backfill, or business-data modification was made to it. Apply the migration only after reviewing and backing up the intended database.

## Migration

Import `database/phase4_3_refunds_credit_notes.sql` once after the base schema/seed and Phase 1 through Phase 4.2 migrations. It is forward-only and not idempotent.

It extends the existing `payments.payment_purpose` enum for controlled outgoing customer-credit refunds and adds:

- `credit_notes` — immutable financial documents linked to one confirmed customer sale;
- `credit_note_receivable_applications` — at most one explicit reduction applied to the sale receivable;
- `credit_note_reversals` — one compensating reversal record for a posted credit note;
- `refunds` — Owner-approved outgoing cash, bank, or KHQR refund documents linked to refundable credit.

All money is stored as fixed-point KHR snapshots. Refunds also persist the original currency, original amount, and six-place conversion rate.

## Financial posting rules

- A credit-note draft records a confirmed, non-voided customer sale, a positive KHR amount, a reason, an idempotency key, and its creator. Only an Owner can create, approve, post, or reverse it.
- Posting locks the credit note, sale, receivable, and existing posted notes. The combined posted credit cannot exceed the original sale amount. A replay returns the existing posted document without another application.
- A posted note first reduces the active invoice receivable. Any remaining note value becomes `excess_credit_khr`, a controlled refundable customer-credit balance tied to that note. Receivables cannot become negative.
- Invoice payments waiting for manual bank/KHQR verification block credit-note posting. They must be verified or rejected first, preventing a later payment verification from over-settling the invoice.
- A credit note changes net sales revenue, but never creates a stock movement, valuation entry, inventory return, or COGS reversal. Sales financial reconciliation reports net revenue as confirmed sales less posted credit notes; COGS remains unchanged.
- A posted credit note can be reversed only with a reason and only when it has no approved or released refund. Its original document and receivable-application history remain intact; a reversal row is added instead.
- A sale with a posted credit note cannot be voided. Reverse the credit note first, then use the existing full-sale void workflow. Credit notes cannot be created or posted against voided sales.

## Refund controls

- A refund starts as a draft against a posted note with excess credit. It needs an idempotency key, method, currency/rate snapshot, reason, and a reference for bank/KHQR.
- Approval reserves the amount while holding the originating note lock. Approved and posted refunds cannot exceed that note's excess credit, including under concurrent requests.
- Releasing a refund writes one outgoing `payments` record with purpose `customer_credit_refund`. Cash is verified immediately; bank and KHQR remain pending until an Owner manually verifies completion.
- A pending bank/KHQR refund can be explicitly cancelled/reversed, marking its outgoing payment rejected and retaining the refund document/audit history. A verified/cash release is not silently reversed.
- Standard payment verification deliberately rejects outgoing refunds; use the refund verification endpoint so the correct refund history is updated.

## Routes and access

All routes below require an active Owner and CSRF protection for state changes. The backend checks the Owner role directly; hiding the link is not relied upon.

| Route | Method | Action |
| --- | --- | --- |
| `?r=credit-notes` | GET | Credit-note/refund register |
| `?r=credit-note-create` | POST | Create credit-note draft |
| `?r=credit-note-approve` | POST | Approve draft |
| `?r=credit-note-post` | POST | Post immutable credit note |
| `?r=credit-note-reverse` | POST | Reverse eligible posted note |
| `?r=refund-create` | POST | Create refund draft |
| `?r=refund-approve` | POST | Reserve/approve refund |
| `?r=refund-post` | POST | Release refund to outgoing payment |
| `?r=refund-verify` | POST | Verify or reject pending bank/KHQR refund |
| `?r=refund-reverse` | POST | Cancel pending bank/KHQR refund |

## Tests

All commands require an empty disposable database whose name ends in `_test`. Import the Phase 4.3 migration into that disposable database first; never substitute the configured business database.

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    php tests/phase1_auth_integration.php
    php tests/phase2_product_domain_integration.php
    php tests/phase3_inventory_integration.php
    php tests/phase32_moving_average_integration.php
    php tests/phase41_sales_integration.php
    php tests/phase42_payments_integration.php
    php tests/phase43_credit_note_integration.php

The Phase 4.3 suite covers Owner/Staff boundaries, CSRF, draft/approval/post idempotency, partial and fully paid invoices, over-credit prevention, pending-invoice-payment protection, customer-credit excess, cash and manually verified bank refunds, invalid rates, refund reversal, reversal-transaction rollback, concurrent refund reservations, sale-void interaction, audit events, no-stock/no-COGS behavior, and net-sales/reconciliation invariants.

## Remaining limitations

Credit notes remain financial corrections and do not themselves move stock or COGS. Phase 4.4 now supplies the separate, linked physical-return workflow; see [the Phase 4.4 notes](phase4-4-physical-sales-returns.md). There is still no automatic bank/KHQR verification, refund-provider integration, or supplier credit/refund workflow.
