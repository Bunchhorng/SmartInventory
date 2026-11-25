# Phase 5.7 — controlled purchase-return corrections

This backend-only milestone adds a separate immutable workflow for a proven physical error in a **confirmed** Phase 5.6 purchase return. It does not edit a return, its stock movement, valuation entry, PO fulfilment, supplier credit, payable, or cash entry.

## Safe correction policy

Only a verified physical restoration is supported: goods recorded as dispatched to a supplier but demonstrably still available for restock may be restored to the original active retail/storage location. A correction is `draft → submitted → approved → posted`; rejection and cancellation are possible only before posting. Each request has a creator-bound idempotency key, a reason, a header evidence reference, and a separate evidence reference for every line.

Staff may create and submit quantity-only requests. An active Owner is required for approval, posting, termination, and reconciliation. Approval stores a content hash; posting rechecks the hash, original return evidence, available source quantity, and all previous posted corrections.

The cumulative corrected purchasing/base quantity may never exceed the immutable quantity in the original `purchase_return_posting`. Competing drafts are not reservations; the second over-correction fails at posting under the original return lock. Duplicate post retries return the existing result and cannot create another movement.

## Stock, valuation, and finance

Posting creates a positive `purchase_return_correction` stock movement and immutable valuation entry in the same transaction. Its value is a cumulative share of the original return posting's carrying value, so complete corrections restore the exact original value (four-place KHR). The saved original return unit-cost snapshot is retained; the current product moving average is recalculated from the new quantity/value state. This is not a repricing of historical history or a supplier-finance action.

For a source line with a `purchase_return_credit_link`, Phase 5.7 permits only the exact quantity released by a posted Phase 5.8 correction for that exact link. Other linked quantity remains blocked; unlinked quantity remains independently eligible. Phase 5.7 never reverses credits, allocations, payables, recoverables, or cash.

Posting runs under `READ COMMITTED`, locks the correction and original return/source evidence, locks product-cost rows in ascending product ID order before location balances, verifies stock/valuation ledger reconciliation, then commits all correction/document/movement/valuation/audit rows atomically. The reconciliation endpoint is read-only and reports source, net corrected quantity/value, ledger evidence, and unresolved credit mismatches without repairing anything.

## Deployment and routes

Import `database/phase5_7_purchase_return_corrections.sql` once after Phase 5.6, only after backup and preflight review. It is forward-only and is **not** automatically applied. The main `smartstock` database has not been migrated by this implementation.

| Route | Method | Access |
| --- | --- | --- |
| `purchase-return-corrections`, `purchase-return-correction-detail` | GET | `purchase_return_corrections.view`; Staff response is quantity-only |
| `purchase-return-correction-create`, `purchase-return-correction-submit` | POST | `purchase_return_corrections.request` + CSRF; Staff may submit own request |
| `purchase-return-correction-approve/reject/cancel` | POST | active Owner + `.approve` + CSRF |
| `purchase-return-correction-post` | POST | active Owner + `.post` + CSRF |
| `purchase-return-correction-reconciliation` | GET | active Owner + `.reconcile`; read-only |

Deferred: broader supplier-credit reversals, corrected credit attribution, batch/expiry restoration, supplier acknowledgement/in-transit evidence, supplier refunds, reservations, transfers, landed cost and general-ledger posting.
