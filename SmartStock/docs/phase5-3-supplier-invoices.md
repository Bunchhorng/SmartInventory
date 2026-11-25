# Phase 5.3: Supplier invoices and accounts payable recognition

Phase 5.3 adds supplier invoice documents and three-way matching against approved PO snapshots and confirmed goods receipts. It extends the existing `payables` ledger, keeping supplier liabilities separate from customer receivables. Invoice posting does not change stock, receipt valuation, moving-average cost, sales revenue, or cash.

## Migration and preflight findings

The base schema had `payables` and `payable_allocations`, but no supplier invoice or matching documents. `payables.purchase_id` was unique, permitting only one payable per PO. Phase 5.3 replaces this with a purchase index and one payable per supplier invoice, while retaining one legacy payable per PO through a generated unique legacy key.

Import `database/phase5_3_supplier_invoices.sql` once after Phase 5.2. It is forward-only and not idempotent. The application does not run migrations. During implementation it was applied only to `smartstock_test`; the main `smartstock` database was not migrated or modified.

Review legacy payables before deployment: existing original amounts must be positive. Legacy payables are preserved without invented invoice/currency attribution; recognition of new invoice liabilities for their PO is blocked until a separate reviewed conversion resolves the existing liability. Reconciliation flags such legacy records rather than hiding or repairing them. No inventory, invoice, payable, or payment history is synthesized.

No attachment storage, document authorization, or upload infrastructure exists in this repository. Attachment fields and uploads are therefore deferred; arbitrary attachment paths/URLs are not accepted as if they were secured documents.

## Documents, matching, and state rules

- Each invoice belongs to one supplier and one approved PO. It can contain up to 100 lines from multiple confirmed deliveries of that PO. Multiple invoices may consume different portions of the same received line.
- Supplier invoice numbers retain their original spelling/case. Duplicate detection uses a separate trimmed, uppercase, whitespace-normalized number per supplier, under the database's case-insensitive collation. Cancelled and rejected invoice numbers remain reserved for auditability. Supplier/PO identity and invoice number cannot be changed after creation.
- Staff with `supplier_invoices.record` may create metadata drafts and update/submit their own drafts. They cannot supply invoice lines, prices, discounts, currencies, exchange rates, attachment references, financial totals, or approvals. An Owner must complete pricing before submission. Staff list/detail responses use explicit metadata allowlists, excluding financial details and free-text Owner notes.
- New metadata includes invoice date, due date, supplier reference, PO, and notes. When omitted, due date defaults to invoice date plus the supplier's configured terms at draft creation. Due date cannot precede invoice date.
- Owner invoice lines reference a confirmed `goods_receipt_item_id`. Quantity is in that PO line's approved purchasing unit and precision. Product/unit substitution is prohibited. Base quantity comes from the saved conversion rather than the live catalog factor.
- Matching verifies supplier, PO, currency/FX, conversions, PO fulfillment, confirmed inventory-document state, and immutable movement/valuation evidence. Unconfirmed goods, unreceived ordered quantities, foreign PO lines, duplicate receipt lines, and unresolved ledger discrepancies are rejected.
- Only immutable posted matches consume received quantities. Drafts and approvals do not reserve quantities. Availability is checked on drafting, submission, approval, and again under locks at posting. Two approved invoices can compete, but only a posting within the remaining eligible quantity commits. A stale approved invoice can be cancelled; it cannot silently rewrite its approval.

| Transition | Authority and rule |
| --- | --- |
| `draft` → `submitted` | Creator or Owner; complete priced receipt matches required. |
| `submitted` → `approved` | Owner; saves a content hash and immutable monetary approval snapshot, including variance reason when required. |
| `approved` → `posted` | Owner; atomically creates one payable, immutable receipt matches, variance records, invoice posting metadata, and audit event. |
| `submitted` → `rejected` | Owner with reason; terminal until cancelled. |
| `draft`, `submitted`, `approved`, `rejected` → `cancelled` | Owner with reason; no payable or posted matches may exist. |

Submission, approval, and posting retries are idempotent. Creation also uses a unique request key tied to its creator and payload hash. A key replay with different content is rejected. Posted invoices cannot be edited, cancelled, or reversed in Phase 5.3; a future supplier credit-note/reversal workflow must preserve liability and matching history. PO closing from Phase 5.2 remains physical fulfillment only, so confirmed receipts on a closed PO can still be invoiced.

## Amounts and explicit variance policy

Supported currencies are KHR and USD. Invoice currency and six-place exchange rate must match the approved PO and confirmed receipts. Currency/FX variances, prepayments, non-PO invoices, taxes, landed costs, and zero-total invoices have no approved policy in this phase and are rejected/deferred.

Each line saves the quantity, purchasing-unit ID/code, conversion, PO unit price/discount, actual confirmed receipt unit cost, invoice unit price/discount, subtotal, discount amount, original-currency net total, and KHR net total. Decimal calculations use the existing fixed-point `Decimal` helper and never PHP floats.

Line subtotal is quantity × unit price rounded half-up to four decimals. Percentage discount is applied to that subtotal and rounded to four decimals. The resulting original-currency line total is translated at the approved FX rate and rounded to four KHR decimals. Invoice totals are sums of those line snapshots; payable recognition saves both currency totals and the approved FX snapshot. This per-line rounding policy may differ slightly from translating the aggregate invoice total once.

An invoice unit-price/discount difference from the PO, or an effective net unit cost different from the confirmed receipt cost at six decimals, requires an explicit Owner variance reason during invoice approval. Receipt-cost variance approval from Phase 5.2 does not silently authorize an invoice discrepancy. Supplier, quantity, unit, currency, and FX mismatches cannot be waived with a price-variance reason.

For partial matching, the original four-place receipt valuation is allocated cumulatively: rounded receipt value × cumulative matched purchasing quantity / received purchasing quantity, minus previously allocated receipt value. The final match therefore consumes the exact receipt value without accumulated splitting residue.

The signed difference between invoice line KHR amount and allocated receipt value is saved separately in `supplier_invoice_price_variances`, linked to the immutable approval. A price discrepancy is classified as `price`; a difference caused solely by supported rounding is `rounding`. These records are an AP variance subledger, not inventory revaluations, expense entries, or a claim of complete double-entry accounting. Inventory and historical weighted-average costs remain untouched. Recognition creates the liability once; the match and variance records provide its explanation and must not be summed as additional liabilities or purchases.

## Transactions and reconciliation

Mutations lock the PO first, then invoice, then receipt/PO posting evidence in receipt-line ID order. Competing postings against the same PO serialize on its lock. READ COMMITTED transactions see the newly committed match quantities after waiting. A unique payable invoice ID and deterministic posting key (`supplier-invoice:<id>`) prevent duplicate liabilities; unique invoice-line matches prevent duplicate line postings.

`SupplierInvoiceService` coordinates validation, approval, matching, audit, and the transaction. `AccountsPayableService::recognizeApprovedInvoice()` is an internal transaction-composition method called only after the coordinating service validates a locked approved invoice. It writes to the existing payable model without opening another transaction or creating a payment.

Owner read-only reconciliation reports confirmed/posted/unmatched quantities, allocated receipt values, approved invoice totals, posted payable totals, invoice outstanding amounts, and supplier outstanding amounts. It compares approval content hashes, payable attribution/amounts, line/match/variance records, and cumulative receipt-value allocation. Supplier-wide reconciliation also flags mismatched invoices, missing payables, legacy payables, and incompatible payment allocations. It never modifies history or materializes inventory rows.

Standalone reports run in a consistent read-only transaction. No supplier payments are created in Phase 5.3; new invoices therefore remain fully outstanding. Existing allocation history is not deleted. Only verified outgoing allocations under an eventual explicit `supplier_payment` policy could settle these liabilities; incompatible customer payments/refunds are flagged and never subtracted as supplier payments.

## Backend routes

All routes use the existing query router. GETs return JSON; POSTs use form-encoded input and the current `_csrf` token. Successful responses are HTTP 200 with `success/data`; validation failures are HTTP 422 with `success/error`. Authentication, forbidden access, and CSRF failures retain existing redirect/403/419 handling. Financial responses are marked `Cache-Control: no-store`.

| Route | Method | Access / fields |
| --- | --- | --- |
| `supplier-invoices` | GET | `supplier_invoices.view`; `supplier_id`, optional `page`; 25 rows/page. |
| `supplier-invoice-detail` | GET | Same permission; `invoice_id`; Staff gets metadata only. |
| `supplier-invoice-create` | POST | `supplier_invoices.record`; metadata fields below; Owner can additionally send pricing fields/lines. |
| `supplier-invoice-update` | POST | Same permission; `invoice_id`, optional date/due/notes; Owner can replace draft pricing lines. |
| `supplier-invoice-submit` | POST | Same permission; `invoice_id`; creator or Owner. |
| `supplier-invoice-approve` | POST | Owner + `supplier_invoices.approve`; `invoice_id`, optional/required `variance_reason`. |
| `supplier-invoice-post` | POST | Owner + `supplier_invoices.post`; `invoice_id`. |
| `supplier-invoice-reject` | POST | Owner + `supplier_invoices.approve`; `invoice_id`, `reason`. |
| `supplier-invoice-cancel` | POST | Owner + `supplier_invoices.manage`; `invoice_id`, `reason`. |
| `supplier-invoice-reconciliation` | GET | Owner + `supplier_invoices.reconcile`; `purchase_id`. |
| `supplier-payables` | GET | Owner + `supplier_invoices.reconcile`; `supplier_id`. |

Metadata fields: `invoice_key`, `supplier_invoice_no`, `supplier_id`, `purchase_id`, `invoice_date`, optional `due_date` and `notes`. Owner-only create/update fields additionally include `currency`, `exchange_rate_to_khr`, and `items`. Each item requires `goods_receipt_item_id` and `quantity`; optional `product_unit_id` must match the PO. Optional `unit_purchase_price` and `discount_percent` default to the PO snapshots. Missing price input never means a zero cost.

## Verification, files, and remaining scope

Run on a disposable database ending in `_test`, with every migration through Phase 5.3 installed:

```powershell
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase53_supplier_invoices_integration.php
```

The test suite checks full/partial invoices, multiple receipts/invoices per PO, duplicate references and posting, over/unreceived quantities, supplier/unit/currency/FX mismatches, positive/negative price variances, exact rounding allocation, concurrent competing and duplicate postings, approval snapshot protection, rollback after a later match write fails, legacy liability guards, Staff privacy, HTTP RBAC/CSRF, read-only discrepancies, and unchanged inventory/cash/receivable records. A narrowly scoped temporary trigger in the disposable database injects the rollback failure and is removed during cleanup.

New files:

- `database/phase5_3_supplier_invoices.sql`
- `app/Models/SupplierInvoice.php`, `app/Models/Payable.php`
- `app/Services/SupplierInvoiceService.php`, `app/Services/AccountsPayableService.php`
- `app/Controllers/SupplierInvoiceController.php`
- `tests/phase53_supplier_invoices_integration.php`, `tests/phase53_invoice_post_worker.php`
- `docs/phase5-3-supplier-invoices.md`

Updated files: `app/Core/App.php`, `routes/web.php`, `AGENTS.md`, `README_KM.md`, and the Phase 5.2 notes linking to this milestone. No UI redesign or attachment-upload system is included.

Verified on `smartstock_test`: Phase 5.3 integration checks and all ten Phase 1–5.2 regression suites passed. Browser/manual UI tests are not claimed. The main database was not migrated or modified.

At the Phase 5.3 boundary, supplier payment allocation and settlement FX were deferred. [Phase 5.4](phase5-4-supplier-payments.md) now implements Owner-controlled payment recording/allocation, currency snapshots, manual verification, idempotency, and read-only outstanding-balance reconciliation without changing invoice or inventory history. Supplier credits/reversals, attachments, prepayments/non-PO invoices, taxes, landed costs, and general-ledger/expense posting of variances remain deferred.
