# Phase 5.6 — Controlled physical purchase returns

Backend only: receipt-bound supplier returns, physical stock/valuation posting, optional attribution to existing posted supplier credits, and read-only reconciliation. No UI redesign, transfers, expenses, fuel, prepayments or automatic refunds.

## Migration and deployment

Import `database/phase5_6_purchase_returns.sql` **once after Phase 5.5**, following backup and historical receiving/valuation review. It is forward-only and not idempotent. MySQL DDL is not transactional; inspect an interrupted import before resuming. The application does not run migrations.

The base schema contained no physical purchase-return tables. This migration adds:

- `purchase_returns`: original supplier/PO/confirmed receipt/location, request fingerprint, reasons, lifecycle and attribution.
- `purchase_return_items`: original receipt/PO/product/unit references, purchasing quantity, base quantity and saved conversion/precision.
- `purchase_return_approvals`: immutable Owner content hash.
- `purchase_return_postings`: one immutable physical posting per line, unique stock movement/key, current-average carrying value, original receipt value share and signed carrying difference.
- `purchase_return_credit_links`: immutable quantity/amount attribution to posted same-invoice receipt matches and supplier credits; **not another AP or cash ledger**.

No historical rows, costs, liability, financial credit, or cash flow are backfilled. Only disposable `_test` databases were migrated. The main `smartstock` database was **not migrated or modified**; deployment there requires explicit authorization.

## Lifecycle and scope

`draft → submitted → approved → confirmed`

The suggested dispatched/confirmed steps are deliberately **one atomic physical handover**: an Owner supplies a dispatch reference and the transaction records both dispatch and confirmation timestamps, stock/valuation postings and both audit events. There is no durable `dispatched` status with goods already gone but still sellable. `purchase-return-dispatch` and `purchase-return-confirm` are aliases for this same operation, not two independent stock postings. Supplier delivery acknowledgement/in-transit tracking is not implemented.

Staff may create quantity-only drafts and submit their own requests. Owner may submit any request. Draft/submission/approval do not reserve or deduct inventory, returnable receipt quantities, credit, or AP. Competing approved returns are revalidated at physical handover; an approval is not a guarantee that stock will still be available.

Owner may reject submitted/approved requests with a reason. Owner may cancel draft/submitted/approved/rejected requests with a reason, provided no physical postings exist. Rejected/cancelled documents are terminal; cancel and create a new request for corrections. There is no draft-update endpoint in this milestone. Confirmed documents, approvals, source lines, physical postings and credit links have no edit/delete/reversal API. A subsequent erroneous-posting correction requires a separately designed compensating workflow, never manual application edits.

Creation and credit linking bind idempotency keys to creator and canonical payload. Submit/approve/confirm retries are idempotent. A confirmation replay must preserve the dispatch reference and have intact posting evidence. Every sensitive transition is audited in its transaction.

## Receipt quantities and available inventory

Each document selects one original confirmed goods receipt, its PO and supplier. Partial, full and multiple returns are supported. Each received line appears at most once per document, with a positive quantity at its **approved purchasing-unit precision** and a reason. Its base quantity uses the original receiving conversion, not the current catalog factor. Receipt, PO, inventory receipt, movement, valuation, supplier, product, unit and currency/FX relationships must agree. Missing/unsupported legacy source evidence blocks posting.

Returnable quantity is original confirmed receiving **minus immutable physical return postings**, irrespective of mutable header statuses. Both purchasing and base quantities are capped. Drafts/approvals do not consume this allowance. Confirmed receiving and PO `received_qty` remain **gross historical fulfillment**; returns neither reopen fulfilled POs nor authorize replacement receipts against their original order quantity. Closed POs may still have physical returns.

Dispatch is only from the original, active receipt location of type `retail` or `storage`, using non-batch available balances. Damage, agriculture/other unavailable locations and batch/expiry workflows are deliberately unsupported. Existing quarantine goods live in separate unavailable holds, **outside stock balances and product valuation**, and cannot supplement available stock. There is no stock-reservation subsystem in the current application; draft sales/returns and approvals are not reservations. A future reservation/status module must integrate its available-quantity guard before this workflow can consume such stock. This milestone does not release or bypass reservations, relocate goods, or dispose of quarantined goods.

Insufficient stock at the original location fails even when another location holds the same product. Missing/mismatched stock and valuation ledger totals also block dispatch; reconciliation never repairs them.

## Locking and inventory valuation

Mutations use READ COMMITTED with supplier → PO → return/receipt/inventory source locks, then **all distinct product costs in ascending product ID before balances**. Credit attribution also locks its credit, original invoice and payable under the supplier lock. This coordinates competing returns, invoice/credit posting and same-product outbound transactions. A database conflict rolls back fully and can be retried with the same key. No nested write transaction is opened.

`PurchaseReturnService` coordinates business validation. `InventoryService::postLockedPurchaseReturn()` composes the existing balance and stock-ledger model with `MovingAverageCostService::postPurchaseReturn()`. The latter reuses the same outgoing quote/update/valuation engine as sales, with separate `purchase_return` provenance, not sale revenue or COGS attribution. Multiple return lines using different purchasing units of one product reuse the evolving locked state and location balance.

Carrying basis is the **current locked product-wide moving average**, not original receipt cost and not supplier credit value. Base quantities have three decimals, KHR carrying values four, costs/averages six; all calculations use decimal strings, never floats. Partial depletion rounds quantity × locked average half-up to four places; full depletion removes the exact remaining inventory value and leaves zero quantity/value/average. Original receipt and historical valuation entries are never rewritten.

Original receipt value is apportioned cumulatively across physical postings in posting order, preserving its exact final four-place value. Each return posting records `carrying_value_khr − receipt_value_khr` separately. Credit attribution similarly records `credit_value_khr − attributed carrying_value_khr`. These are explanatory subledger differences, **not automatic expense, inventory revaluation, revenue, tax, or double-entry GL postings**.

## Before/after invoice and credit policy

Physical return does not require an invoice or credit, and creates neither. Before invoicing, return the available goods and retain an unmatched physical return. Gross receiving remains invoice-matchable under Phase 5.3: physical departure alone does not invent a supplier commercial cancellation or reduce a legally issued invoice. If the supplier subsequently invoices the original gross receipt, post that valid invoice and separately record the actual supplier credit under Phase 5.5. No automatic liability recognition/netting occurs. A net-only invoice that omits returned quantities leaves those gross receipt quantities commercially unmatched for review; this milestone does not invent a receiving cancellation to resolve them.

After invoice posting, physical dispatch likewise does not change AP. Only Phase 5.5 posting of an eligible supplier credit reduces unpaid AP or recognizes its separate same-invoice recoverable. Credit posting may precede or follow physical dispatch. Linking is allowed only **after physical confirmation and financial credit posting**, with intact approvals, invoice matches, payable/credit evidence and sound financial replay. Linking itself changes no financial balance.

Each link selects a return item, a posted same-supplier/same-PO credit, that credit's posted invoice receipt match, a positive purchasing quantity, positive original-currency amount and Owner reason. The match must reference the exact original received line. Caps protect:

- total linked purchasing/base quantity on the physical return line;
- total linked purchasing/base quantity across all credits on the original invoice match;
- total attributed original-currency amount across all physical returns on the supplier credit.

Phase 5.5 credits are amount-only documents, not supplier-issued quantity documents. Link quantity is an explicit Owner attribution against the invoice match; it does not assert independently verified credit-note line quantities. An Owner may allocate a negotiated amount different from PO price × quantity, within the posted credit cap, explaining the difference in the reason. Currency/FX are inherited from the original posted credit/invoice, never supplied or changed through linking. KHR credit value and return carrying value are allocated cumulatively, preserving exact totals when fully attributed. Reapplications of recoverable credit remain Phase 5.5 financial history, not another physical credit allocation. No supplier refund is inferred.

## Backend services and routes

Wiring: `App::purchaseReturns()` and `App::purchaseReturnService()`; thin `PurchaseReturnController`. Existing session authentication, permissions, password-change enforcement and PDO prepared statements are preserved. All POSTs require a string `_csrf` token; invalid/array tokens return 419. JSON follows existing `success/data` or 422 `success/error` conventions and `Cache-Control: no-store`.

| Route | Method | Access / input |
| --- | --- | --- |
| `purchase-returns` | GET | `purchase_returns.view`; `purchase_id`, optional `page` (50/page). |
| `purchase-return-detail` | GET | Same permission; `return_id`. |
| `purchase-return-create` | POST | `purchase_returns.request`; `document_key`, `supplier_id`, `purchase_id`, `goods_receipt_id`, `reason`, `items`. |
| `purchase-return-submit` | POST | Same permission; requester or Owner; `return_id`. |
| `purchase-return-approve` | POST | Active Owner + `.approve`; `return_id`. |
| `purchase-return-dispatch`, `purchase-return-confirm` | POST | Active Owner + `.confirm`; `return_id`, `dispatch_reference`. Same atomic operation. |
| `purchase-return-reject`, `purchase-return-cancel` | POST | Active Owner + `.approve`; `return_id`, `reason`. |
| `purchase-return-link-credit` | POST | Active Owner + `.link_credit`; `return_id`, `link_key`, `return_item_id`, `credit_id`, `invoice_match_id`, `quantity`, `amount_original`, `reason`. |
| `purchase-return-reconciliation` | GET | Active Owner + `.reconcile`; `purchase_id`. |

Create items accept only `goods_receipt_item_id`, `quantity`, `reason`. Unknown fields, costs, financial amounts, forged statuses and approval actors are rejected. Permissions default to Owner for all actions and Staff for view/request only. Explicit active-Owner service/controller checks still protect sensitive operations if a Staff permission is accidentally granted. Credit evidence also requires the existing Owner financial-reconciliation permissions.

Staff list/detail responses use explicit quantity/identifier/status/time allowlists. They omit costs, values, AP, currency/FX, credit links, approval hashes, Owner dispatch/termination evidence and free-text reasons. No UI or navigation screen is claimed.

## Read-only reconciliation

A consistent REPEATABLE READ, READ ONLY report includes existing receiving, stock/location and product valuation reconciliation, gross received/returned/remaining quantities, original snapshots, outbound movement/cost-entry/posting attribution, immutable approval hashes, cumulative original receipt value shares, posted credit/invoice evidence, linked quantity/amount caps, cumulative credit/carrying values and separate signed differences. Physical returns with no attributed credit and posted credits with no attributed physical return are listed separately. Unmatched business items are not automatically ledger corruption: `reconciled` can be true while unmatched lists need commercial follow-up.

Missing or inconsistent evidence produces issues; there is no repair endpoint or automatic materialization. Privileged direct database modifications remain outside application immutability guarantees and operational database access must be restricted.

## Files and verification

Created: the migration; `app/Models/PurchaseReturn.php`; `app/Services/PurchaseReturnService.php`; `app/Controllers/PurchaseReturnController.php`; `tests/phase56_purchase_returns_integration.php`; `tests/phase56_return_worker.php`; `tests/phase56_migration_smoke.php`; this document.

Changed: `app/Services/InventoryService.php`, `MovingAverageCostService.php` (shared outgoing engine), `SupplierAdjustmentService.php` (read-only posted-credit evidence guard), `app/Core/App.php`, `routes/web.php`, `AGENTS.md`, `README_KM.md`, and the Phase 5.5 follow-on note.

Run only on an empty, disposable database ending `_test`, with every migration installed and no Owner/business fixtures:

```powershell
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase56_purchase_returns_integration.php
php tests/phase56_migration_smoke.php
```

The suite uses real competing PHP worker processes and a narrowly scoped, temporary second-line failure trigger. Fixtures and triggers are removed in FK-safe order from the guarded test database only. The migration smoke test creates a uniquely named `_test` database, imports the base schema, reference seed and all fifteen migrations, then removes **only that newly created database**. Existing test data and the main database are not dropped. Browser/manual UI testing is not claimed.

Verified on 2026-11-25: **120 Phase 5.6 checks passed**, all thirteen Phase 1–5.5 regression suites passed, PHP syntax passed across **114 files**, and the fresh base/seed/all-fifteen-migrations import passed. Coverage includes partial/full/multiple and competing returns, same-product/multi-unit state, original-location insufficiency, damage/quarantine exclusion, saved conversions, exact current-average depletion, pre-invoice and post-invoice returns, wrong/duplicate/over-attributed credits and invoice-match quantities, USD cumulative rounding, immutable approvals/evidence, late-write rollback, read-only discrepancy/missing-history reporting, HTTP RBAC/CSRF and Staff privacy. Cleanup left no test Owner, purchase return, supplier credit/correction, quarantine hold or temporary trigger. The main database remains unchanged.

## Remaining limits and next milestone

Deferred: durable in-transit/supplier-acknowledgement states, editing drafts, posted return/credit-link compensating corrections, supplier credit quantity-line ingestion, automated net invoice cancellation, batch/expiry and additional unavailable-stock disposition, actual supplier refund receipts, posted financial-credit reversals, transfers/reservations, landed costs, operating expenses, fuel, attachments/provider integration and general ledger posting.

Recommended next backend milestone: explicitly scoped immutable correction/reversal handling for posted purchase returns and credit attributions, with proven goods-received-back evidence and separate financial-credit reversal rules. Do not infer a stock transfer, cash refund or payable correction from it.
