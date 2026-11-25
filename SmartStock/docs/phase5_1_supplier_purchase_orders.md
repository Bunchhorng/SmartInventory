# Phase 5.1: Suppliers and Purchase Orders

Phase 5.1 adds supplier master data and a non-posting purchase-order workflow. It extends the base-schema `suppliers`, `purchases`, and `purchase_items` tables; it does not introduce duplicate supplier or purchase-order tables.

## Migration

Import `database/phase5_1_supplier_purchase_orders.sql` once, after `database/phase4_4_sales_returns.sql`.

The migration is forward-only and is not run by the application. In particular, it must not be applied automatically to the main `smartstock` database. It:

- adds supplier codes, contact fields, payment terms, timestamps, and active/inactive lifecycle support;
- expands `purchases` into the Phase 5.1 status lifecycle and adds request, delivery, attribution, and total fields;
- adds immutable product/unit/conversion/price/discount line snapshots to `purchase_items`;
- creates the one-row-per-approved-order `purchase_order_approval_snapshots` audit snapshot;
- adds `suppliers.*` and `purchases.*` permissions. Staff receive view/request permissions, while service code still requires an Owner for price-bearing changes, approval, rejection, and cancellation.

Historical base-schema supplier/purchase rows remain readable. The migration maps legacy `purchases.status = 'ordered'` to `submitted` before changing the status enumeration.

## Status transitions

| From | To | Authority | Rule |
| --- | --- | --- | --- |
| `draft` | `submitted` | Request creator or Owner | Every line needs a valid Owner-set purchasing price and immutable snapshots. |
| `submitted` | `approved` | Owner | Creates exactly one approval snapshot. A retry is idempotent. |
| `submitted` | `rejected` | Owner | Requires a reason; Phase 5.1 does not reopen it. Create a corrected draft instead. |
| `draft`, `submitted`, `rejected`, `approved` | `cancelled` | Owner | Requires a reason and is refused when any goods receipt or payable reference exists. |
| `approved` | `partially_received` → `received` → `closed` | Phase 5.2 | Now implemented by the linked goods-receiving service; see [Phase 5.2](phase5-2-goods-receiving.md). |

No Phase 5.1 action posts an `inventory_receipt`, `stock_movements` row, valuation entry, `goods_receipt`, `payable`, supplier invoice, or supplier payment.

Phase 5.2 adds the separate goods-receiving/stock/valuation flow. Existing Phase 5.1 PO drafting and approval remain non-posting.

## Security and data rules

- Supplier codes are unique; names, contact person, phone, address, and payment terms are required, while email is optional. Suppliers are never deleted. Deactivation preserves all references and blocks new purchase orders.
- New lines accept only active products and that product's active, configured purchasing unit.
- Quantities, conversions, prices, discounts, totals, and exchange rates use validated fixed-point decimal strings. The selected unit conversion is saved with each line.
- Staff can draft quantity-only requests but cannot submit price or discount fields and never receive cost/totals in service responses or views.
- Every mutating HTTP route checks authentication, RBAC, and CSRF. Services repeat all important authorization checks and use PDO transactions with row locks for state transitions.

## Test command

After importing all migrations, including Phase 5.1, into a disposable database ending in `_test`:

```powershell
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase51_supplier_purchase_orders_integration.php
```

The integration test covers supplier lifecycle and privacy, duplicate codes, staff quantity-only drafts, multi-line conversion/price snapshots, approval idempotency, unauthorized approval, invalid transitions, safe cancellation, rollback, CSRF rejection, and non-posting behavior.
