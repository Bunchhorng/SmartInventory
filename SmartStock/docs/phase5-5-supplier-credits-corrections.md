# Phase 5.5 — Supplier credits and controlled payment corrections

Backend financial workflows only. No UI redesign, supplier prepayments, inventory purchase returns, landed costs, operating expenses, fuel, or general ledger is added.

## Deployment and migration status

`database/phase5_5_supplier_credits_corrections.sql` is a forward-only, **one-time** migration after Phase 5.4. It contains `USE smartstock`; importing it into the main database requires explicit authorization, a backup, and review of existing financial history. MySQL DDL is not transactional: inspect any interrupted import before continuing; do not blindly rerun migrations.

Only disposable databases were migrated/tested. The main `smartstock` database was not migrated or modified. The base schema, reference seed, and all 14 phase migrations also imported successfully in a new, separately named `_test` database; that newly created migration-test database was then removed.

New tables:

- `supplier_credit_notes`: supplier-issued numbers, invoice/supplier/currency/FX snapshots, reason, lifecycle and attribution.
- `supplier_payment_corrections`: original payment, immutable source fingerprint, resolution/evidence and lifecycle. A generated unique active-payment key prevents competing live or posted full corrections; rejected/cancelled requests remain historical but allow a new request.
- `supplier_financial_approvals`: immutable content hash and Owner approval for each credit/correction.
- `supplier_credit_allocations`: immutable unpaid/recoverable split and recognition-rounding evidence, unique per credit.
- `supplier_payment_correction_allocations`: immutable full reversal of each original payment allocation, original cash/FX snapshots, and explicit AP rounding alignment, unique per original allocation.
- `supplier_credit_reapplications`: immutable use of existing recoverable credit against debt reopened on its original invoice by a proven payment correction.
- `supplier_payable_activities`: ordered provenance references to those records and existing payable allocations. It stores **no additional monetary amounts** and is not a replacement AP ledger.

The existing `payable_allocations` gets a `posting_version`: existing rows retain version 1 without changing/backfilling money, verification, or posting evidence. New supplier payments use version 2 plus an atomic activity reference. Valid pre-5.5 allocations form the starting state before new ordered activities. Unattributed or inconsistent legacy records are reported and block financial posting, not invented or silently repaired.

## Explicit accounting policies

### Supplier credits

All Phase 5.5 credit/correction operations, including reads and drafts, are active-Owner-only. No Staff financial operation or response is enabled in this milestone, even if a Staff permission is accidentally granted. Existing Staff purchase/invoice/payment metadata routes remain confidential.

Credit lifecycle: `draft → submitted → approved → posted`, with `rejected` and `cancelled` terminal alternatives. Owner may edit only a draft. Owner may reject submitted/approved records or cancel draft/submitted/approved/rejected records with a reason. Posted credits cannot be edited, cancelled, deleted, or reversed through these routes. Supplier-issued credit numbers are trimmed, whitespace-collapsed, case-normalized, and unique per supplier, including rejected/cancelled history.

Only posted invoices with matching immutable approvals, approved lines, posted match evidence and payables can receive credit. The existing invoice service provides that read-only evidence check; supplier, invoice currency, and historical exchange rate must match. Total posted credits cannot exceed the original invoice amount in invoice currency, regardless of how much was paid. Approval does not reserve or settle credit: posting rechecks remaining creditable amount under locks. Idempotent retries do not create a second credit allocation.

At posting:

1. Apply credit to actual unpaid invoice debt first.
2. Record any excess as a **recoverable supplier debit balance**, retained in the originating invoice currency and historical KHR carrying value.
3. Never create negative invoice AP, customer credit, a cash refund, or an automatic offset against another invoice/supplier.

Approved payment reservations are protected: a credit cannot consume debt reserved by an approved payment. Complete the payment or cancel/reject an eligible unverified request before posting an encroaching credit. If the payment is completed, credit can instead become recoverable under the unpaid-first policy. A supplier can retain both unrelated AP and an originating-invoice recoverable balance; no automatic global netting occurs.

An approved invoice-price correction is a separate non-stock supplier financial adjustment: it reduces AP or recognizes the recoverable claim, preserving original invoice totals, receipt matches, PO prices, inventory quantities, and moving-average cost. Credit recognition/split/rounding remains traceable in the supplier financial ledger. This application does not post its contra-entry to inventory, COGS, operating expenses, or a general ledger; external/general-ledger classification remains a later explicit integration, not a claim of complete financial statements.

### Full posted-payment corrections

Correction lifecycle follows the same draft/submission/Owner-approval/posting/terminal rules. Only a fully posted, verified, reconciled supplier payment can be corrected. One correction reverses **all** original allocations; partial corrections and replacement/edit-in-place allocation changes are refused.

Creation requires a reason, independent evidence reference, evidence note, and `confirm_full_reversal=1`. The Owner must confirm the **full original-currency amount** either:

- `funds_returned`: demonstrably returned, with cash handover/statement/transaction evidence; or
- `proven_not_settled`: demonstrably never disbursed/settled, with evidence explaining the erroneous original recording/verification.

An accounting-only intention to reverse, a pending bank inquiry, or an unsupported resolution cannot restore AP. Bank/KHQR requires the original manual verification history to remain intact plus the new independent correction evidence. The correction's source hash binds the original document, approval, verification record (including its evidence), cash record, allocations and FX/rounding snapshots. Owner approval binds the correction reason/resolution/evidence to that source. Both hashes are checked before posting. Changed evidence cannot be silently adopted after approval.

Posting appends compensating records; the original `payments`, `supplier_payments`, verification, allocation, and FX rows remain unchanged and historically posted. It restores original invoice-currency debt and original carrying value, with an explicit fixed-point rounding adjustment when reversing a non-last partial settlement requires alignment. Unique source-allocation keys and the active-document key prevent duplicate restoration. Draft/approval/rejection/cancellation alone never restores debt or implies a cash return.

If earlier credits left recoverables on an affected invoice, those existing recoverables are reapplied FIFO to reopened debt **on that same invoice** before a new payment can reserve it. Each movement from recoverable to AP reduction is a separate immutable reapplication linked to its credit and correction; it cannot be consumed twice. This prevents simultaneously showing a reopened payable and an unused credit that should already extinguish it. No cross-invoice future-offset workflow is implemented.

No new incoming `payments` record, supplier cash refund, revenue, expense, stock movement, or cost entry is automatically created. A correction records proven settlement facts separately from the original outgoing cash history. Reconciliation distinguishes gross original cash, evidence-confirmed returned cash, proven non-settlement, and net settled cash. These KHR cash values use **original payment FX/book values**, not a new bank conversion/revaluation. Different-currency/partial returns, bank fees, and return-time FX accounting require a later dedicated workflow. Do not treat the report as a newly posted bank deposit or assume that liability restoration alone means money returned.

## Precision, ordering and financial integrity

KHR remains the base currency. Monetary values use DECIMAL(18,4), FX snapshots DECIMAL(18,6), and existing string-based `Decimal` calculations with `FinancialMath` for signed sums and proportional values. No financial PHP floats or BCMath dependency is introduced.

Historical carrying value for cumulative discharged invoice currency is:

```text
round(original invoice KHR × cumulative discharged original / original invoice amount, 4)
```

Discharge includes net posted payment allocations and AP-applied credits. New payments release the next cumulative carrying amount minus previously discharged KHR, not an obsolete payment-only balance. The ordered provenance index makes that calculation reproducible after interleaved credits, reversals and replacement payments.

Credit recognition uses cumulative credited original at original invoice carrying value; the unpaid/recoverable split and any rounding difference are recorded separately. Corrections negate original payment FX and cash-allocation rounding evidence, separately record AP carrying-value alignment, and preserve exact cumulative recoverable consumption. A final settlement/credit cannot leave phantom rounding debt. Original invoice FX is never overwritten; supplier credits do not introduce a new settlement FX rate.

Mutations use READ COMMITTED transactions and deterministic locks:

```text
supplier → adjustment → original payment document (corrections only)
         → affected invoices ascending ID → payables ascending ID
```

The existing supplier-payment flow still locks supplier → payment document → invoices → payables. Same-supplier operations serialize on the supplier row. Invoice/payable rows serialize financial capacity and approval reservations; no inventory/cost locks are needed. Source rows, immutable allocations, activity references, state transitions and audit events commit atomically. Every failure rolls back the entire operation. No nested write transaction is used.

## Services, routes and permissions

`SupplierAdjustmentService` owns supplier credit/correction rules, approvals, posting, evidence validation, read-only reconciliation and audits. `SupplierPayableBalanceService` replays financial allocation history without writes. Existing `AccountsPayableService` and `SupplierPaymentService` now use that same balance contract; customer receivables/refunds remain independent.

All routes use `?r=<name>`, require an authenticated active Owner, and return JSON `success/data` or `success/error`. Every POST checks CSRF before mutation. Validation is HTTP 422, denied access 403, invalid/array CSRF 419. Reads disable caching. Existing account/session/password-change middleware remains unchanged.

For both route families:

| Route family | GET | POST |
| --- | --- | --- |
| `supplier-credit-*` | `supplier-credits`, `supplier-credit-detail` | `supplier-credit-create/update/submit/approve/post/reject/cancel` |
| `supplier-payment-correction-*` | `supplier-payment-corrections`, `supplier-payment-correction-detail` | `supplier-payment-correction-create/update/submit/approve/post/reject/cancel` |

Lists accept `supplier_id` and optional `page` (50/page). Detail and existing-document mutations use `adjustment_id`. All state-changing requests need `_csrf`. Reject/cancel requires `reason`.

Credit creation fields: `document_key`, `supplier_id`, `invoice_id`, supplier-issued `credit_no`, `amount_original`, `reason`. Optional `currency`/`exchange_rate_to_khr` must exactly match the posted invoice; stored snapshots always come from that invoice. Draft updates accept `credit_no`, `amount_original`, and/or `reason`; supplier and source invoice never change.

Correction creation fields: `document_key`, `supplier_id`, `supplier_payment_id` (the supplier payment **document ID**, not the cash-ledger ID), `reason`, `resolution`, `evidence_reference`, `evidence`, and string `confirm_full_reversal=1`. The immutable request fingerprint preserves that full-reversal confirmation. Draft updates accept reason/resolution/evidence fields only; original payment and source fingerprint never change. Unsupported partial-amount fields are rejected.

Credit permission prefix: `supplier_credits.view/record/approve/post/reconcile`. Correction prefix: `supplier_payment_corrections.view/record/approve/post`. The nine permissions default to Owner only. Explicit active-Owner checks remain mandatory independently of permission grants. Create/update/submit/cancel require record; approve/reject require approve; posting requires post.

`supplier-financial-reconciliation` GET accepts `supplier_id`, requires Owner + `supplier_credits.reconcile`, and includes existing payment/AP reconciliation, credit allocations, payment correction allocations, recoverable reapplications, original totals, outstanding debt, recoverables, net FX/payment rounding, credit-recognition rounding, recoverable-reapplication rounding, and AP correction rounding. Aggregate AP discharge and credit/recoverable totals are checked independently. Existing `supplier-payables`, supplier invoice reconciliation and supplier payment reconciliation now use net corrected/credited AP as well.

## Read-only reconciliation and anomaly handling

The combined report uses a consistent REPEATABLE READ, READ ONLY transaction. It checks existing invoice/payable/match/payment evidence; source and approval fingerprints; posted document/allocation state; supplier/currency/FX attribution; remaining creditability; every historical cumulative carrying calculation; original-to-correction amounts; recoverable consumption; rounding; missing/duplicate activity and posting provenance; and payment reservations. It exposes credit/correction evidence and every recoverable reapplication separately.

There is no reconciliation repair path. Incomplete/ineligible ordered history is reported, and unreconstructable outstanding balances are **unavailable/null**, not guessed to be zero. Financial writes require a sound replay. Operational administrative SQL access must still be restricted: application immutability does not claim to prevent a privileged database administrator from changing records.

## Files and verification

Created:

- `database/phase5_5_supplier_credits_corrections.sql`
- `app/Models/SupplierAdjustment.php`
- `app/Services/SupplierAdjustmentService.php`
- `app/Services/SupplierPayableBalanceService.php`
- `app/Support/FinancialMath.php`
- `app/Controllers/SupplierAdjustmentController.php`
- `tests/phase55_supplier_adjustments_integration.php`, `tests/phase55_adjustment_worker.php`
- This document.

Updated: `app/Models/Payable.php`, `app/Models/SupplierPayment.php`, `app/Services/AccountsPayableService.php`, `app/Services/SupplierPaymentService.php`, `app/Services/SupplierInvoiceService.php` (reused read-only posted evidence guard), `app/Core/App.php`, `routes/web.php`, the Phase 5.4 test's new-provenance FK cleanup, `AGENTS.md`, `README_KM.md`, and the Phase 5.4 follow-on note.

Run on an empty disposable database ending in `_test`, with all current migrations installed and no Owner/business fixtures:

```powershell
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase55_supplier_adjustments_integration.php
```

The Phase 5.5 suite covers full/partial/unpaid/partly paid/fully paid credits; caps and duplicate supplier references; wrong supplier/status/currency/FX; concurrent competing and duplicate posting; immutable approvals/evidence; approved payment reservations; recoverable reapplication; full correction approval/duplicate prevention; proven bank/KHQR resolution; original verification integrity; pre-5.5 allocations; USD/cumulative rounding; late-write credit/multi-invoice correction rollback; replacement payments; no inventory/expense/customer/cash-history mutations; read-only missing-history detection; Owner/RBAC/disabled-user/accidental-Staff-grant safeguards; malformed input; every mutation's CSRF; and audit events.

Tests use real worker processes for concurrency and a narrowly scoped temporary trigger for rollback injection; fixtures/triggers are removed in FK-safe order from the dedicated test database only. Full Phase 1–5.4 regressions, syntax checks and fresh schema import are part of verification. No browser/manual UI completion is claimed.

Verified results: **138 Phase 5.5 checks passed**, all twelve Phase 1–5.4 regression suites passed, PHP syntax passed across **108 files**, and a fresh base/seed/all-14-migrations import passed. Disposable fixture cleanup left no test Owner, adjustment document, or temporary trigger. The main database remains unchanged.

## Remaining limits and next milestone

Deferred: partial payment corrections; posted credit reversal; cross-invoice future offsets; actual supplier refund receipt posting; bank return-time FX/fees or cross-currency/partial returns; attachments/provider APIs; supplier prepayments; physical inventory purchase returns; landed costs; operating expenses; fuel; double-entry/export integration. Verified-but-unposted bank/KHQR requests remain protected under Phase 5.4; Phase 5.5 corrects posted payments only, not an abandoned unposted transfer.

Follow-on: [Phase 5.6](phase5-6-purchase-returns.md) now separately implements controlled physical purchase returns and optional posted-credit attribution, preserving immutable inventory and valuation history. Financial credit posting still does not imply a physical return. Posted physical-return/link corrections remain deferred.
