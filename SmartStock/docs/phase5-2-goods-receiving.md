# Phase 5.2: Goods receiving and purchase fulfillment

Phase 5.2 extends the existing `goods_receipts` / `goods_receipt_items` documents and links each to the existing Phase 3 `inventory_receipts` / `inventory_receipt_items`. It uses `InventoryService` and `MovingAverageCostService` for the actual stock and valuation postings. No separate inventory engine is introduced.

## Migration and upgrade review

Import `database/phase5_2_goods_receiving.sql` once after Phase 5.1. It is forward-only and not idempotent. The application never applies migrations automatically.

Implementation verification applied this migration only to `smartstock_test`. The main `smartstock` database was not modified or migrated.

The migration adds received-quantity counters, closing metadata, purchasing-unit precision snapshots, supplier identity in the PO approval snapshot, receiving attribution/delivery references, inventory-document links, and immutable Owner cost-variance approvals. It widens the existing receipt unit-cost field to six decimal places and adds receiving/closing permissions and uniqueness constraints.

Before deploying to business data:

- Review existing purchase-bound goods receipts. Historical receipts are not automatically posted or reconciled. An order with legacy receiving documents is blocked from further posting until a separately reviewed migration/correction resolves that history.
- Existing Phase 5.1 approval snapshots omitted supplier IDs and unit precision. The migration captures the existing PO supplier ID and existing unit precision. If those records were changed outside the supported service since approval, review them before migration. New POs store precision when the draft line is created and freeze it with approval.
- The positive-cost constraint requires historical goods-receipt item costs to be positive. Review any zero/negative historical cost rows before importing. No purchase, receipt, stock, or valuation history is silently synthesized.

## Backend endpoints

Endpoints use the existing `?r=` router. GETs return JSON and POSTs accept form-encoded fields, including `_csrf` from the authenticated session. Success is HTTP 200 with `{"success":true,"data":...}`. Domain validation failures return HTTP 422 with `{"success":false,"error":...}`. Authentication, forbidden access, and expired CSRF use the existing redirect/403/419 handling. Cost-bearing JSON uses `Cache-Control: no-store`.

| Route | Method | Permission / behavior |
| --- | --- | --- |
| `purchase-order-detail` | GET | `purchases.view`; `purchase_id`; Staff receives quantities without cost fields. |
| `goods-receipts` | GET | `purchases.view`; `purchase_id`, optional `page`; maximum 100 results per service call. |
| `goods-receipt-detail` | GET | `purchases.view`; `receipt_id`; Staff fields are explicitly allowlisted. |
| `goods-receipt-create` | POST | `purchases.receive`, `purchases.view`, `stock.receive`; quantity-only draft. |
| `goods-receipt-confirm` | POST | Owner plus `purchases.receive.confirm`; `receipt_id`; also enforces inventory receive/valuation permissions. |
| `goods-receipt-cost-variance` | POST | Owner plus `purchases.cost_variance.approve`; `receipt_id`, `receipt_item_id`, `actual_cost_per_base_khr`, `reason`. |
| `goods-receipt-cancel` | POST | Owner plus `purchases.receive.confirm`; `receipt_id`, `reason`; draft only. |
| `purchase-order-close` | POST | Owner plus `purchases.close`; `purchase_id`; fully received and reconciled only. |
| `purchase-order-reconciliation` | GET | Owner plus `purchases.receive.confirm`; `purchase_id`; read-only. |

Example draft POST fields:

```text
_csrf=<session token>
purchase_id=123
receipt_key=<unique 8-80 character request key>
reference_no=DELIVERY-2026-001
location_id=2
received_at=2026-11-24 09:30:00
delivery_notes=Supplier delivery note
items[0][purchase_item_id]=456
items[0][quantity]=2
items[1][purchase_item_id]=457
items[1][quantity]=3
```

`received_at` is optional and defaults to server business time. `received_by` is always the authenticated actor. Clients cannot supply prices, conversions, product substitutions, confirmation users, or statuses. One destination location applies to the entire receipt; a separate receipt may deliver to a different location.

## Receiving and state rules

| Transition | Rule |
| --- | --- |
| `approved` → `partially_received` | At least one confirmed delivery; at least one line remains outstanding. |
| `approved` / `partially_received` → `received` | All ordered quantities have been confirmed exactly. |
| `received` → `closed` | Owner only; exact fulfillment, no unresolved drafts/legacy receipts, and successful reconciliation. Retry is idempotent. |
| Goods receipt `draft` → `confirmed` | Owner confirms derived costs and quantities in the same transaction as stock/value/PO updates. Retry is idempotent. |
| Goods receipt `draft` → `cancelled` | Owner supplies a reason. Both linked documents are cancelled without posting. |

Only approved/partially received orders accept new drafts. Drafts do not reserve quantities; remaining quantities are checked again under the PO lock at confirmation. Competing drafts can exist, but only confirmations within the remaining quantity can commit. An excess/stale draft must be cancelled before closing.

PO cancellation/rejection remains prohibited after receiving. A PO with any goods-receipt reference cannot use the Phase 5.1 cancellation route, including references to cancelled drafts. Confirmed goods receipts cannot be edited, cancelled, repriced, or reversed through standalone inventory routes. A future purchase-return/correction workflow must preserve both fulfillment and valuation history.

The normalized supplier delivery reference is unique per supplier across POs. The request key is globally unique and tied to its creator and canonical payload hash. Replaying an identical request returns its existing receipt; a changed payload or a second key for the same supplier delivery is rejected. Cancelled references remain reserved for auditability. The backend relies on the business using the same supplier reference for the same physical delivery.

## Quantity, price, and rounding policy

- Received quantities use the approved PO unit and its saved precision, never a client-selected unit. Base quantity is received quantity times the saved conversion, regardless of later catalog factor changes.
- Ledger base quantities have three decimal places. Conversions requiring additional nonzero base decimals are rejected rather than rounded. Batch/expiry receipt handling remains deferred.
- Receipt cost per base unit is the approved unit purchase price after its percentage discount, multiplied by the approved exchange rate to KHR, divided by the approved conversion. No freight, duties, or other landed costs are invented.
- Unit cost rounds half up to six KHR decimals; the existing valuation engine rounds received base quantity times this cost to four decimals, then computes the product-wide moving average at six decimals. Small differences from the PO's estimated four-place line totals are expected when a unit-cost division cannot be represented exactly. These are inventory valuation amounts, not supplier invoice/payable balances.
- Existing inventory valuation requires positive cost/value. A 100% discounted/free line or a cost that rounds to zero requires a future zero-cost policy and is rejected.
- Owner cost variance approval records the original derived cost, actual cost, approver, timestamp, and reason once per draft line. It changes only the draft receipt's cost; the PO price/FX/conversion and approval snapshot remain intact. After confirmation the receipt cost is immutable. To correct an erroneous variance approval, cancel that draft and record a corrected reference with an audit trail.

## Transaction and reconciliation contract

Each confirmation locks the PO, goods receipt, and affected PO/inventory lines before validating remaining quantities. Catalog-backed lines are processed in product order. `InventoryService::postLockedReceipt()` then locks each distinct product costing row in ascending product ID order, locks destination balances, and posts each movement and valuation entry. Multiple lines using different purchasing units for one product reuse the updated product costing state. Both receipt statuses, received counters, fulfillment status, and audit events commit together. Any failure rolls back the entire operation.

Posting transactions use READ COMMITTED plus explicit row locks so a transaction waiting for another product posting reads the newly committed balance. Movement keys, linked inventory IDs, item links, request keys, supplier references, and one-to-one valuation/movement constraints prevent duplicate posting. The existing standalone receipt confirmation, cost setter, and reversal reject purchase-linked documents.

Read-only reconciliation uses a consistent read-only transaction when called on its own. It compares approved/received/remaining PO quantities, materialized counters, conversion totals, linked document statuses, movement product/location/quantity/cost, valuation source/value/cost, destination balances, and product-wide valuation totals/average. It reports inconsistencies without creating cost rows or repairing data. Closing additionally runs this reconciliation while holding the PO lock.

## Verification and limits

```powershell
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase52_goods_receiving_integration.php
```

The suite covers full/partial/multiple deliveries, multiple units and products, destination locations, conversion/price/FX snapshots, over-receipts, duplicate requests and confirmations, real concurrent receiving processes, explicit cost variances, malformed input, CSRF/RBAC, Staff response privacy, rollback after a later-line posting conflict, reconciliation discrepancies, and closing restrictions. Existing Phase 1–5.1 suites are also required regressions. Browser/manual UI coverage is not claimed; this phase supplies backend JSON endpoints without redesigning screens.

Supplier invoices, payables/payment accounting, landed-cost allocation, zero-cost/batch receipts, purchase returns, confirmed-receipt corrections, and partial-order short-closing remain outside Phase 5.2. The next backend milestone is supplier invoice matching and payable recognition against confirmed goods receipts, followed by supplier-payment allocation.

Phase 5.3 now implements the separate [supplier invoice matching and payable recognition milestone](phase5-3-supplier-invoices.md). Goods receiving itself still creates no supplier payable or payment, and invoice posting preserves the valuation recorded here. Supplier payment allocation remains deferred.

## Change inventory

New files:

- `database/phase5_2_goods_receiving.sql`
- `app/Models/GoodsReceipt.php`
- `app/Services/GoodsReceivingService.php`
- `app/Controllers/GoodsReceivingController.php`
- `tests/phase52_goods_receiving_integration.php`
- `tests/phase52_receive_worker.php`
- `docs/phase5-2-goods-receiving.md`

Updated files:

- `app/Models/InventoryReceipt.php`: purchase-link detection for standalone-route bypass prevention.
- `app/Models/PurchaseOrder.php`: saved unit precision, received-quantity reads, supplier approval identity, product-order line locking.
- `app/Services/InventoryService.php`: transaction-composable common receipt posting, product-keyed cost states, linked-document guards, current-read posting transactions.
- `app/Services/PurchasingService.php`: store purchasing-unit precision with draft lines.
- `app/Core/App.php`, `routes/web.php`: dependency wiring and authenticated backend endpoints.
- `AGENTS.md`, `README_KM.md`, `docs/phase5_1_supplier_purchase_orders.md`: current scope, migration order, routes, and Phase 5.2 references.

Verified on the disposable `smartstock_test` database on 2026-11-24: Phase 5.2 integration checks and all nine existing Phase 1, 2, 3, 3.2, 4.1, 4.2, 4.3, 4.4, and 5.1 integration suites passed. The main database was not migrated or modified. No browser/manual UI test is claimed.
