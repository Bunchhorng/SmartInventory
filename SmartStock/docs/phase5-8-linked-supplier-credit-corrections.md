# Phase 5.8 — linked supplier-credit compensating corrections

Phase 5.8 is a backend-only financial correction workflow for an already-posted supplier credit that was attributed to a confirmed Phase 5.6 purchase return. It preserves the original credit, invoice, payable, return, stock, valuation, and cash history. It posts an immutable compensating entry instead of editing or deleting history.

## Scope and safety rules

Each request is `draft → submitted → approved → posted`; it may be rejected or cancelled only before posting. An active Owner with the dedicated permission approves, posts, terminates, views, or reconciles it. Every mutation uses CSRF and is audited. Staff receive no Phase 5.8 permission or response that exposes supplier financial information.

The correction must reference one exact posted `purchase_return_credit_link`, its confirmed return, original posted supplier credit, invoice match, payable, currency, and exchange-rate snapshot. Amount and physical quantity are separate fixed-point inputs. Neither is inferred from the other. Posted totals may not exceed the exact linked amount/quantity or the original supplier-credit allocation. A document key is content-bound and idempotent.

Posting writes one `supplier_credit_return_correction_posting` plus one ordered `supplier_payable_activity`. It restores only the original credit allocation's AP-applied share and reduces only that allocation's recoverable share. The balance replay rejects a negative recoverable, AP over-settlement, missing provenance, or a later payment/reapplication/adjustment that would require a separate verified settlement adjustment. No cash, bank, KHQR, supplier payment, stock movement, inventory-cost entry, or valuation entry is created.

## Phase 5.7 integration

Phase 5.7 continues to be the only workflow that can restore stock. A linked-credit source is eligible only up to the quantity released by a posted Phase 5.8 correction for that exact link; unlinked portions remain independently eligible. Phase 5.8 itself never restores inventory. This keeps financial compensation and physical correction separately approved, posted, and auditable.

## Migration and routes

Import `database/phase5_8_linked_supplier_credit_corrections.sql` once after Phase 5.7, following backup/preflight review. It is forward-only and is never applied automatically. The implementation did not migrate the main `smartstock` database.

| Route | Method | Access |
| --- | --- | --- |
| `supplier-credit-return-corrections`, `supplier-credit-return-correction-detail` | GET | active Owner + `.view` |
| `supplier-credit-return-correction-create`, `-submit` | POST | active Owner + `.record` + CSRF |
| `supplier-credit-return-correction-approve`, `-reject`, `-cancel` | POST | active Owner + `.approve` + CSRF |
| `supplier-credit-return-correction-post` | POST | active Owner + `.post` + CSRF |
| `supplier-credit-return-correction-reconciliation` | GET | active Owner + `.reconcile`; read-only |

The reconciliation report exposes original linked credit amount, financial correction totals, net effective credit, AP and recoverable adjustments, linked quantity, released physical quantity, unmatched amounts, and discrepancies. It never repairs data.

## Verification

`tests/phase58_linked_supplier_credit_corrections_integration.php` uses a disposable `_test` database. It covers partial/full correction, AP and recoverable effects, capacity/cross-supplier/settlement guards, two independent MySQL processes, idempotency, post-write rollback/retry, Phase 5.7 release separation, HTTP RBAC/CSRF/GET semantics, unauthenticated behavior, and Staff financial privacy. `tests/phase56_migration_smoke.php` imports the full schema through Phase 5.8 into a temporary disposable database.
