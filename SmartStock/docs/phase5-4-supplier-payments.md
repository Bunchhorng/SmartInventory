# Phase 5.4 — Supplier payments and AP allocation

Backend only. No new UI, supplier credits, inventory corrections, landed costs, operating expenses, fuel, or general ledger is implemented.

## Deployment and database safety

Apply `database/phase5_4_supplier_payments.sql` **once**, after every migration through Phase 5.3. It is forward-only and contains `USE smartstock`; importing it into the main database requires explicit approval. Back up and review existing payment/allocation history before deployment. MySQL DDL is not transactional: an interrupted import needs inspection, not a blind rerun.

This implementation was migrated/tested only on disposable databases. The main `smartstock` database was not migrated or modified. A separate, newly created `_test` database also passed a fresh import of the base schema, reference seed, and all 13 phase migrations; only that temporary migration-test database was then removed.

The migration:

- Extends existing `payments` with nullable supplier attribution and `supplier_payment` purpose. A CHECK requires supplier payments to be outgoing, supplier-attributed, and customer-free; customer workflows remain unchanged.
- Adds `supplier_payments`, draft `supplier_payment_items`, immutable `supplier_payment_approvals`, and immutable `supplier_payment_verifications`.
- Extends existing `payable_allocations` with source-document, invoice-currency, payment-currency, cash-value, and posting attribution snapshots. Existing `amount_khr` is the historical AP carrying value released, **not** cash value.
- Adds `supplier_payment_differences` with separate signed FX and rounding amounts per posted allocation.
- Adds seven supplier-payment permissions. No legacy allocation, FX, verification, or supplier history is invented/backfilled. Legacy/unattributed allocation history is flagged and blocks further settlement until reviewed through a deliberate correction process.

## Lifecycle, authorization, and immutability

`draft → submitted → approved → posted`

Staff with `supplier_payments.request` can create metadata requests: `payment_key`, `supplier_id`, optional `reference_no` and `notes`. They cannot supply financial fields or invoice allocations. An Owner must price the draft before its creator or an Owner submits it. Only drafts are editable; Staff can edit only their own unpriced metadata drafts. Owner pricing requires `supplier_payments.manage`.

Approval/posting/verification/reconciliation and rejection/cancellation each require an active, explicitly checked Owner plus their corresponding permission, even if a Staff role is accidentally granted that permission. Every mutating route checks authentication, RBAC, and CSRF before calling the service. An unpriced metadata request cannot be submitted.

Approval saves an immutable hash covering supplier, payment method, amounts, currencies/rates, reference, payment date, notes, and ordered invoice allocations. Verification/posting recheck that hash. All significant transitions and draft changes are audited inside their transaction. Repeated creation with the same original payload, repeated approval, repeated identical verification, and repeated posting are idempotent; changed content/evidence is rejected.

Approval reserves invoice debt in its original currency but does **not** create cash flow or settle AP. Bank/KHQR verification also does not settle AP. Only posting creates a verified outgoing `payments` row and immutable allocations, differences, posting timestamp/actor, and audit event together. Cash is verified by the posting Owner in that same transaction. Only posted **and** verified, correctly attributed supplier allocations reduce reported AP.

Owner rejection is allowed from submitted/approved; Owner cancellation from draft/submitted/approved/rejected. A reason is required. Unverified approval reservations are released on rejection/cancellation. Once manual bank/KHQR verification exists, neither rejection nor cancellation is allowed: retain the reservation and complete posting or await a dedicated compensating-payment correction workflow. Posted records cannot be edited, rejected, cancelled, reallocated, or deleted through application routes. Rejected/cancelled requests are terminal; create a new request rather than rewriting their history.

Staff reads are an explicit allowlist, limited to their own request IDs, key, supplier ID, status, reference, and timestamps. They receive no pricing, currency/rates, invoice/allocation detail, payable balance, approval actor, verification evidence, or free-text Owner notes. Owner-created requests are unavailable to Staff. Supplier outgoing cash flow never enters the Staff customer-payment register or customer revenue/receivable reconciliation.

## Allocation and locking

An Owner supplies one to 100 unique invoice allocations. Each has:

```text
invoice_id
invoice_amount_original   # debt settled, in the invoice's original currency, 4 decimals
payment_amount_original   # cash share, in the payment's original currency, 4 decimals
```

All invoices must be posted and belong to the payment supplier, with matching immutable invoice payables. One payment can settle several invoices; multiple payments can partially settle one invoice. Original-currency allocation totals cannot exceed posted outstanding debt less other approved reservations. The sum of payment-currency shares must exactly equal the payment amount. There is no unallocated cash, supplier prepayment, arbitrary settlement discount, or overpayment tolerance.

Draft/submit/approve/verify/post revalidate eligibility, conversions, totals, existing allocation attribution, and remaining capacity as relevant. Drafts/submitted requests do not reserve debt. Competing submitted requests may coexist, but only available original debt can be approved. Existing invoice debt can be settled after supplier deactivation; new requests require an active supplier.

Transactions use READ COMMITTED and lock: supplier → payment document → all invoices in ascending invoice ID → all payables in ascending payable ID. The supplier lock also serializes same-supplier request/approval operations. The service never nests a transaction. Unique document, reference, cash posting, and allocation keys provide a second duplicate-posting barrier. No stock/cost locks are needed because payments never call inventory or moving-average services.

## Currency and rounding policy

KHR is the base/reporting currency. All amounts are DECIMAL(18,4), rates DECIMAL(18,6), calculated with the existing string-based `Decimal` helper; no PHP financial floats or BCMath dependency.

An Owner-priced request requires `payment_method` (`cash`, `bank`, `khqr`), `currency` (`KHR`, `USD`), `amount_original`, `exchange_rate_to_khr`, `paid_at` (`YYYY-MM-DD HH:MM:SS`), and `allocations`. Payment dates are explicit so retries do not silently change their snapshot. KHR payment FX must be exactly one. USD settlement rate equals the payment FX.

Same-currency invoice/payment shares must be identical. Cross-currency settlement is supported:

- USD debt paid in KHR: require explicit `settlement_usd_rate_to_khr`; cash share is invoice USD amount × that rate, rounded half up to four places. Payment's own KHR FX remains one.
- KHR debt paid in USD: cash share is invoice KHR amount ÷ payment USD rate, rounded half up to four places.
- One payment may allocate across both KHR and USD invoices for the same supplier. Every share must satisfy the applicable conversion above, not just a grand-total comparison.

Cash value is payment original × payment FX, rounded half up to four KHR decimals. Within a payment, cash KHR shares are cumulative converted cash shares minus previous cumulative cash, preserving the exact header total. Historical AP carrying value is released as:

```text
round(invoice total KHR × cumulative settled invoice original / invoice total original, 4)
  − previously released carrying KHR
```

This preserves the exact historical liability across partial payments, including invoice per-line rounding. Allocation carrying/cash values must remain positive; amounts below supported precision are rejected, not silently rounded away.

For each allocation, FX difference is the USD debt share converted at settlement FX minus that share converted at historical invoice FX (zero for KHR debt). Rounding difference is cash KHR minus carrying KHR minus FX difference. Thus:

```text
cash KHR = AP carrying KHR released + signed FX difference + signed rounding difference
```

Positive FX is additional cash cost; negative FX is a gain. Recognition/cumulative-allocation and cross-currency rounding are logged as rounding, not mislabelled FX. Invoice rates and original payable rows are never modified. These are separate financial evidence records, not implemented expense/general-ledger postings, and have no effect on receipt cost or inventory value.

## Manual bank/KHQR verification and references

There is no provider API or automatic confirmation. Bank/KHQR requires a reference and a separate approved-state `supplier-payment-verify` call with a non-empty `evidence` note (up to 1,000 characters), such as the statement/transaction reference checked by the Owner. The immutable record binds that evidence to the approved content hash and verification actor/time. Cash does not use this endpoint.

References preserve their original spelling for display. Trimmed, whitespace-collapsed, case-normalized references are unique per supplier and method once the method is assigned; cancelled/rejected records continue to retain their reference. Unpriced requests can have duplicate references because no method is assigned yet, but reconciliation reports them rather than silently choosing one. The Owner can correct metadata only while draft. Use a new request/reference when an immutable historical reference was wrong.

## JSON routes

Routes use `?r=<name>`. Every POST needs `_csrf`. Mutations identify existing requests using `supplier_payment_id`; `supplier_id` is immutable after creation. Responses use `success/data` or `success/error`; validation errors are HTTP 422, RBAC 403, CSRF 419. Reads disable caching.

| Route | Method | Access/input |
| --- | --- | --- |
| `supplier-payments` | GET | `supplier_payments.view`; `supplier_id`, optional `page`; 50 requests/page, Staff own metadata only |
| `supplier-payment-detail` | GET | Same; `supplier_payment_id` |
| `supplier-payment-create` | POST | `supplier_payments.request`; metadata above; Owner may also provide full pricing/allocations with manage permission |
| `supplier-payment-update` | POST | Same; own unpriced metadata or Owner manage pricing; pricing updates must supply the complete financial plan |
| `supplier-payment-submit` | POST | Request permission; creator or Owner; priced draft only |
| `supplier-payment-approve` | POST | Owner + `supplier_payments.approve` |
| `supplier-payment-verify` | POST | Owner + `supplier_payments.verify`; `evidence`; approved bank/KHQR only |
| `supplier-payment-post` | POST | Owner + `supplier_payments.post` |
| `supplier-payment-reject` | POST | Owner + `supplier_payments.approve`; `reason` |
| `supplier-payment-cancel` | POST | Owner + `supplier_payments.manage`; `reason` |
| `supplier-payment-reconciliation` | GET | Owner + `supplier_payments.reconcile`; `supplier_id` |

Existing `supplier-payables` remains Owner-only and now returns invoice original-currency paid/outstanding amounts as well as KHR carrying balances. `SupplierPaymentService` composes the existing `AccountsPayableService`; it does not use customer receivable allocations.

## Reconciliation

The report uses a consistent REPEATABLE READ, READ ONLY transaction and never writes or repairs discrepancies. It covers invoice/payable/match attribution and balances; posted/verified settlement eligibility; planned/actual allocation equality; approved reservations against remaining debt; payment/approval/verification snapshots; missing cash source documents; duplicate normalized references; cumulative AP/cash conversion; and separate signed FX/rounding totals. Draft/rejected/cancelled requests appear as non-settling history. Missing/legacy allocation attribution is flagged, never inferred to be supplier settlement from a customer refund.

## Files and verification

Created: the Phase 5.4 migration; `app/Models/SupplierPayment.php`; `app/Services/SupplierPaymentService.php`; `app/Controllers/SupplierPaymentController.php`; `tests/phase54_supplier_payments_integration.php`; `tests/phase54_payment_worker.php`; this document.

Updated: `app/Models/Payable.php`, `app/Models/Payment.php`, `app/Services/AccountsPayableService.php`, `app/Services/PaymentService.php` (reject outgoing-key replay in customer recording), `app/Core/App.php`, `routes/web.php`, `AGENTS.md`, `README_KM.md`, and Phase 5.3's follow-on link.

Run only on a fully migrated, disposable `_test` database with no Owner or business data:

```powershell
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase54_supplier_payments_integration.php
```

The suite covers full/partial/multi-invoice/multi-payment settlement; KHR/USD and cross-currency cash; exact cumulative rounding; signed FX gains/losses; bank/KHQR manual verification; invalid invoices/statuses; duplicate keys/references/posting; real competing approval and duplicate-posting workers; reservation release; immutable approval drift; late-write rollback; read-only discrepancy detection; Staff confidentiality; backend RBAC; malformed input; and CSRF on all mutation routes. A narrowly scoped temporary trigger injects a second-allocation failure and is removed in cleanup. Test fixtures are deleted in FK-safe order only in the dedicated test database.

All eleven Phase 1–5.3 regression suites and the new Phase 5.4 suite (113 checks) passed. PHP syntax checks passed across 101 files; a fresh full-schema migration import also passed. Browser/manual UI coverage is not claimed; there is no Phase 5.4 UI.

## Remaining limitations and next milestone

No automatic bank/KHQR integration, attachments, prepayments, settlement discounts, supplier credit notes, posted payment reversal, purchase-return corrections, landed costs, taxes, operating expenses, or double-entry/FX general-ledger integration. Verified-but-unposted transfers cannot be abandoned; investigate discrepancies and finish posting, or use a future explicitly approved compensating correction. Runtime services preserve posted history, but direct SQL administrative access must still be restricted operationally.

Follow-on: [Phase 5.5](phase5-5-supplier-credits-corrections.md) now implements supplier financial credits and evidenced full posted-payment corrections with immutable compensating records, same-invoice recoverables and AP reconciliation. It does not implement partial corrections, physical purchase returns, prepayments, cross-invoice offsets, or an automatic supplier cash-refund receipt. The original Phase 5.4 payment/allocation/verification records remain unchanged.
