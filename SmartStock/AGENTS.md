# SmartStock Agent Guide

This file is the working guide for contributors and coding agents in this repository. It describes the code and database that exist today, plus the constraints for extending them safely.

## Product and scope

SmartStock is a single-business inventory and operations system for one physical business location with multiple internal stock locations. It is not a multi-tenant SaaS product.

The intended product covers products, inventory, purchasing, sales, credit, expenses, reporting, and fuel operations. The current PHP application includes Phase 1 through Phase 5.8 within the deliberately limited scopes below:

- authentication, session protection, and account lockout;
- Owner and Staff roles with server-side permissions;
- first Owner creation from a CLI setup command;
- Owner-created Staff accounts with temporary-password flow;
- forced first-login password change;
- server-side session records and basic audit events;
- Owner and Staff dashboard placeholders;
- category hierarchy, unit master data, and non-destructive product archival;
- product-specific unit conversions, optional barcodes, and retail/wholesale selling-price history.
- valued opening/purchase receipt drafts, immutable stock movements, per-location stock balances, and receipt reversals;
- product-wide moving weighted-average receipt valuation and immutable valuation entries;
- draft and confirmed retail/wholesale sales with immutable pricing and COGS snapshots;
- Owner-only full-sale voids that post compensating stock and valuation entries.
- customer credit profiles, due terms, receivables, and credit-limit enforcement;
- KHR/USD cash plus manually verified bank/KHQR allocation payments that remain distinct from revenue.
- Owner-only financial credit notes, controlled refundable customer credit, and immutable outgoing cash/bank/KHQR refunds.
- Staff-created, Owner-approved physical sales returns with immutable resellable restock, non-resellable write-off, and quarantine-hold postings linked to posted credit notes.
- supplier master records plus non-posting draft/submitted/approved/rejected/cancelled purchase orders with immutable approval snapshots; Staff can make quantity-only requests while Owner pricing/approval remains restricted.
- purchase-bound goods receipt drafts and Owner confirmation through the existing inventory/valuation engine, partial/full PO fulfillment, immutable receipt-cost variance approvals, read-only reconciliation, and eligible PO closing.
- supplier invoice metadata/pricing, three-way receipt matching, immutable Owner approval, invoice-level payable recognition in the existing AP ledger, signed price/rounding variance records, and read-only outstanding-balance reconciliation.
- confidential supplier payment requests, Owner pricing/approval, KHR/USD and cross-currency cash, manually verified bank/KHQR, immutable outgoing payment/AP allocations, separate signed FX/rounding evidence, and read-only reconciliation.
- Owner-only supplier credits, unpaid-first AP reduction and separate recoverables, evidenced full posted-payment compensating corrections, same-invoice recoverable reapplications, and ordered read-only financial reconciliation.
- Staff quantity-only physical purchase-return requests, immutable Owner approval, atomic dispatch/stock confirmation at current moving-average carrying value, optional posted supplier-credit attribution, and read-only physical/financial reconciliation.
- Owner-only linked supplier-credit compensating corrections that preserve the original credit, restore only eligible AP/recoverable effects, never create cash or inventory movements, and release exact linked quantity only to the separate Phase 5.7 workflow.

Do not represent the wider schema as a completed inventory or purchasing module. Stock transfers, adjustments, batches/expiry, broader posted supplier-credit reversals, partial supplier payment corrections, landed-cost allocation, expenses, fuel workflows, broader reports, account resets, audit-log viewing, and quarantine disposition are not implemented in PHP yet. Phase 5.1 manages supplier data and purchase-order intent only; Phase 5.2 separately posts purchase-bound receipts to stock and valuation. Phase 5.3 recognizes supplier invoice payables without changing inventory or creating payments. Phase 5.4 separately settles those payables without creating expenses or changing inventory. Phase 5.5 adds financial supplier credits and evidenced full-payment corrections without rewriting original invoice, cash, or inventory history. Phase 5.6 separately confirms physical purchase returns and attributes existing posted credits without creating another financial adjustment. Phase 5.7 separately posts proven physical return restoration. Phase 5.8 separately compensates exact linked supplier-credit financial effects and then releases only matching physical quantity to Phase 5.7. Phase 4.3 adds customer-facing financial credit notes/refunds; Phase 4.4 separately links them to controlled physical sales returns. Staff purchasing/receiving/invoice/payment/return responses never expose COGS, purchase prices, receipt valuation, payable amounts, or profit. All Phase 5.5/5.8 financial adjustment reads and mutations are Owner-only.

## Platform constraints

- PHP 8.2+ with the repository's custom MVC structure; current local setup uses PHP 8.3.
- MySQL 8+ with InnoDB, PDO, prepared statements, and `utf8mb4`.
- Standard shared-hosting compatible. The prototype targets InfinityFree.
- HTML, CSS, Bootstrap 5, JavaScript/jQuery, JSON AJAX, Chart.js, SweetAlert2, and barcode/QR libraries are permitted when a feature needs them.
- Do not introduce Laravel, React, Next.js, Node.js, Docker, Redis, WebSockets, queue workers, cron-dependent functionality, PWA requirements, or a build step unless explicitly requested.
- Do not assume production shell access. `bin/create_owner.php` is a deployment/setup aid, not a runtime feature.

## Repository map

```text
app/
  bootstrap.php                 Autoloader, configuration, PDO, session start
  Core/                         App container, controller base, router, response, session, view
  Controllers/                  Auth, dashboards, product, inventory receipt, sales, physical-return, payment, credit-note, supplier, and purchase-order endpoints
  Middleware/                   Authentication and permission checks
  Models/                       User, audit, catalog, supplier, purchase-order, receipt, sale, sale-return, customer, payment, credit-note, and inventory-cost persistence
  Services/                     Authentication, product-domain, supplier, purchasing, receipt, sales, physical-return, payment, credit-note, and moving-average transactions
  Views/                        PHP templates and layouts
routes/web.php                  Query-string route registration
public/index.php                Front controller and PHP built-in-server static-file handling
public/assets/css/app.css       Shared styles and the current login experience
public/assets/images/           Public image assets, including the login warehouse background
database/phase1_additions.sql   Phase 1 user-table migration
  database/phase1_staff_statuses.sql  Phase 1 Staff suspension-status migration
  database/phase2_product_domain.sql  Phase 2 catalog integrity migration
  database/phase3_inventory_ledger.sql Phase 3 receipt/ledger migration
  database/phase3_2_moving_average_costing.sql Phase 3.2 valuation migration
  database/phase4_1_sales_posting.sql Phase 4.1 sales-posting migration
  database/phase4_2_payments_credit.sql Phase 4.2 payment/credit migration
  database/phase4_3_refunds_credit_notes.sql Phase 4.3 credit-note/refund migration
  database/phase4_4_sales_returns.sql Phase 4.4 physical-return migration
  database/phase5_1_supplier_purchase_orders.sql Phase 5.1 supplier/purchase-order migration
  database/phase5_2_goods_receiving.sql Phase 5.2 linked goods receiving/fulfillment migration
  database/phase5_3_supplier_invoices.sql Phase 5.3 invoice matching/payable migration
  database/phase5_4_supplier_payments.sql Phase 5.4 supplier payment/allocation migration
  database/phase5_5_supplier_credits_corrections.sql Phase 5.5 supplier financial correction migration
  database/phase5_6_purchase_returns.sql Phase 5.6 physical purchase return/credit attribution migration
  database/phase5_7_purchase_return_corrections.sql Phase 5.7 physical correction migration
  database/phase5_8_linked_supplier_credit_corrections.sql Phase 5.8 linked supplier-credit correction migration
bin/create_owner.php            CLI-only initial Owner creation
bin/valuation_preflight_report.php Read-only historical valuation report
  tests/phase1_auth_integration.php  CLI integration coverage for Phase 1 auth
  tests/phase2_product_domain_integration.php CLI integration coverage for Phase 2
  tests/phase3_inventory_integration.php CLI integration coverage for Phase 3
  tests/phase32_moving_average_integration.php CLI integration coverage for Phase 3.2
  tests/phase41_sales_integration.php CLI integration coverage for Phase 4.1 sales
  tests/phase42_payments_integration.php CLI integration coverage for Phase 4.2 payments/credit
  tests/phase43_credit_note_integration.php CLI integration coverage for Phase 4.3 credit notes/refunds
  tests/phase44_sales_returns_integration.php CLI integration coverage for Phase 4.4 physical sales returns
  tests/phase51_supplier_purchase_orders_integration.php CLI integration coverage for Phase 5.1 suppliers/purchase orders
  tests/phase52_goods_receiving_integration.php CLI integration coverage for Phase 5.2 goods receiving
  tests/phase53_supplier_invoices_integration.php CLI integration coverage for Phase 5.3 invoices/payables
  tests/phase54_supplier_payments_integration.php CLI integration coverage for Phase 5.4 supplier payments
  tests/phase55_supplier_adjustments_integration.php CLI integration coverage for Phase 5.5 credits/corrections
  tests/phase56_purchase_returns_integration.php CLI integration coverage for Phase 5.6 physical purchase returns
  tests/phase58_linked_supplier_credit_corrections_integration.php CLI integration coverage for Phase 5.8 financial corrections
  tests/phase56_migration_smoke.php Guarded fresh base/seed/all-migrations import check
docs/phase1-manual-test.md      Required Phase 1 manual test checklist
config.example.php              Tracked configuration template
config.php                      Local secret configuration; ignored by Git
```

The draft base schema and reference seed are deliberately stored outside this module at:

```text
../All-documents/SmartStock_Step_1_13_Database_Pack/
  smartstock_schema.sql
  smartstock_seed_reference.sql
```

Do not silently duplicate those files into `database/` or create tables that already exist in the base schema. If the project is later split into its own repository, move those schema files deliberately and update the setup documentation in the same change.

## Current request flow and routes

The app uses `public/index.php` and the `?r=<route>` query parameter. `routes/web.php` is the source of truth.

| Route | Methods | Handler | Access |
| --- | --- | --- | --- |
| `?r=login` | GET, POST | `AuthController::showLogin/login` | Guest |
| `?r=change-password` | GET, POST | `AuthController::showChangePassword/changePassword` | Signed-in user |
| `?r=logout` | POST | `AuthController::logout` | Signed-in user + CSRF |
| `?r=staff-create` | GET, POST | `AuthController::showCreateStaff/createStaff` | Owner permission `users.manage` |
| `?r=staff-status` | POST | `AuthController::changeStaffStatus` | Owner permission `users.manage` + CSRF |
| `?r=dashboard` | GET | `OwnerDashboardController::show` | Signed-in user; delegates Staff users to `StaffDashboardController` |

| Product catalog, categories, units | GET | ProductController list actions | products.view; Staff receives active non-sensitive records |
| Product/category/unit mutations | POST | ProductController mutation actions | products.manage + CSRF |
| Product unit, barcode, and price mutations | POST | ProductController mutation actions | products.manage + CSRF |
| Suppliers | GET/POST | PurchasingController supplier actions | suppliers.view; supplier writes require suppliers.manage + CSRF |
| Purchase orders | GET/POST | PurchasingController purchase-order actions | purchases.view/request; Owner-only price, approval, rejection, and cancellation + CSRF |
| Goods receipts and PO details | GET/POST | GoodsReceivingController JSON endpoints | purchases.view/receive; Owner-only confirmation, cost variance, closing, reconciliation; every POST requires CSRF |
| Supplier invoices/payables | GET/POST | SupplierInvoiceController JSON endpoints | supplier_invoices.view/record for permitted metadata; Owner-only prices, approval, posting, rejection, cancellation, and reconciliation; every POST requires CSRF |
| Supplier payments | GET/POST | SupplierPaymentController JSON endpoints | supplier_payments.view/request for own confidential metadata; active Owner required for pricing, approval, manual verification, posting, rejection, cancellation, and reconciliation; every POST requires CSRF |
| Supplier credits/payment corrections | GET/POST | SupplierAdjustmentController JSON endpoints | active Owner plus supplier_credits or supplier_payment_corrections permissions for every read/mutation; every POST requires CSRF; combined financial reconciliation is read-only |
| Physical purchase returns | GET/POST | PurchaseReturnController JSON endpoints | Staff quantity-only view/request/own submission; active Owner approval, atomic dispatch/confirmation, credit attribution and reconciliation; every POST requires CSRF |
| Inventory receipt register | GET | InventoryController::listReceipts | stock.view; no cost fields exposed |
| Receipt draft creation | POST | InventoryController::createReceipt | stock.receive + CSRF; Staff drafts are quantity-only |
| Receipt valuation, confirmation, and reversal | POST | InventoryController mutation actions | stock.adjust.approve + CSRF; confirmation also checks stock.receive |
| Sales register | GET | SalesController::list | sales.create; Staff receives no COGS or profit fields |
| Sale draft and confirmation | POST | SalesController::createSale/confirmSale | sales.create or sales.confirm + CSRF |
| Sale void | POST | SalesController::voidSale | sales.void + CSRF; Owner confirms all stock is resellable |
| Physical sales returns | GET/POST | SalesReturnController actions | Staff may draft with `sales.create`; Owner approves/confirms; CSRF for every mutation; Staff has no cost fields |
| Payments and receivables | GET | PaymentController::listPayments | payments.record; Staff receives no credit-limit, COGS, or profit fields |
| Payment recording | POST | PaymentController::recordPayment | payments.record + CSRF |
| Customer credit and payment verification | POST | PaymentController mutation actions | credit.manage + CSRF; Owner only |
| Credit notes and refunds | GET/POST | CreditNoteController actions | Owner only + CSRF for every mutation |

Route names must remain lowercase letters, digits, and hyphens; `public/index.php` rejects other route values. Add both the route and an authorization check when adding an authenticated feature. Never infer access control from a hidden navigation item.

## Architecture rules

Keep the existing layers separate.

- **Controllers** validate request input, require CSRF/authorization, call services, and select views or redirects. Keep them thin.
- **Services** own business rules, multi-step workflows, audit events, and transactions.
- **Models** own PDO access and return arrays/value data; use prepared statements only.
- **Middleware** owns current-user/session checks and permission checks.
- **Views** render escaped output through `e()` and contain no business, authorization, or database logic.
- **Client code** is progressive enhancement only. Enforce every rule in PHP even if a future UI hides an action.

Use `App` as the dependency container. New feature services/models should be constructed there and exposed through a clearly named accessor, rather than being instantiated ad hoc in templates.

## Authentication and authorization: preserve these invariants

The current implementation is security-sensitive. Do not bypass or weaken it.

1. `Session::start()` sets `smartstock_session`, `HttpOnly`, `SameSite=Lax`, strict session mode, and sets the `Secure` flag when the request is HTTPS.
2. On a successful login, `AuthService` starts a transaction, clears failed attempts, writes a hashed session token to `auth_sessions`, writes an audit event, commits, then regenerates the PHP session ID.
3. The browser stores only the random session token. The database stores `sha256(token)`, an expiry, and optional revocation time.
4. Every `currentUser()` check verifies the database session and that the user is still active. A disabled account loses access on its next request.
5. Five failed active-account attempts lock the account for 15 minutes. Login errors must remain generic.
6. New Owners and Staff have `must_change_password = 1`; `AuthMiddleware::enforcePasswordChange()` allows only password change and logout until the password changes.
7. Password changes validate 12-128 characters, use Argon2id when available (otherwise PHP's default), revoke all existing DB sessions, then create a new session.
8. Every state-changing form needs `Session::verifyCsrf()` before it changes data.
9. Owner access is granted by the `owner` role. Other privileges are checked through `PermissionMiddleware` and `User::hasPermission()`.

Never log, render, commit, or return passwords, raw session tokens, database credentials, or password hashes. Never add a public registration route.

## Account lifecycle: current versus planned

Implemented:

- `bin/create_owner.php USERNAME "FULL NAME"` creates exactly one initial Owner after an interactive password prompt.
- Owner-only Staff creation validates username/full name/password, creates the Staff user and role in a transaction, and records an audit event.
- Staff must change the initial temporary password before normal navigation.
- Owner can activate, suspend, or disable Staff on the Staff Accounts screen. Each change is transactional, revokes all of the target's sessions, and creates an audit event.
- Logout revokes the current server-side session.

Not implemented yet:

- Password-reset delivery and recovery workflow.
- Audit-log browser and per-user permission override UI.
- IP-based rate limiting beyond the per-account lockout.

The base/migration schema has `users.status` and `users.disabled_at`; future account lifecycle work must preserve historical transaction references and use services plus audit events. Do not add another users table.

## Database source of truth and migration rules

### Setup order

1. Import `../All-documents/SmartStock_Step_1_13_Database_Pack/smartstock_schema.sql`.
2. Import `../All-documents/SmartStock_Step_1_13_Database_Pack/smartstock_seed_reference.sql`.
3. Import `database/phase1_additions.sql` once.
4. Import `database/phase1_staff_statuses.sql` once.
5. Import `database/phase2_product_domain.sql` once.
6. Import `database/phase3_inventory_ledger.sql` once.
7. Import `database/phase3_2_moving_average_costing.sql` once.
8. Import `database/phase4_1_sales_posting.sql` once.
9. Import `database/phase4_2_payments_credit.sql` once.
10. Import `database/phase4_3_refunds_credit_notes.sql` once.
11. Import `database/phase4_4_sales_returns.sql` once.
12. Import `database/phase5_1_supplier_purchase_orders.sql` once.
13. Import `database/phase5_2_goods_receiving.sql` once.
14. Import `database/phase5_3_supplier_invoices.sql` once.
15. Import `database/phase5_4_supplier_payments.sql` once.
16. Import `database/phase5_5_supplier_credits_corrections.sql` once.
17. Import `database/phase5_6_purchase_returns.sql` once.
18. Import `database/phase5_7_purchase_return_corrections.sql` once.
19. Import `database/phase5_8_linked_supplier_credit_corrections.sql` once.
20. Configure the dedicated database user in ignored `config.php`.
19. Run `php bin/create_owner.php owner "Shop Owner"` locally to create the first account.

On MySQL 8.4+, create the local application account with `caching_sha2_password`; `mysql_native_password` is disabled by default. See `README_KM.md` for the account commands. Do not place a real password in `config.example.php`, documentation, or source control.

### Existing schema

The base schema contains 63 draft InnoDB tables covering these domains:

- identity/RBAC: `users`, `roles`, `permissions`, `user_roles`, `role_permissions`, `auth_sessions`, `audit_logs`;
- catalog and inventory: products, categories, brands, units, pricing, barcodes, locations, batches, balances, movements, costs, transfers, and adjustments;
- purchasing and payables;
- sales, payments, receivables, and returns;
- expenses and budgets;
- fuel tanks, pumps, nozzles, shifts, sales segments, and reconciliation;
- notifications, approval requests, and system settings.

`database/phase1_additions.sql` adds `must_change_password`, `password_changed_at`, and `disabled_at` to `users`. `database/phase1_staff_statuses.sql` extends `users.status` with `suspended`. `database/phase2_product_domain.sql` enforces category sibling uniqueness, unit lifecycle/name uniqueness, current-price uniqueness, and catalog indexes. `database/phase3_inventory_ledger.sql` adds valued receipt documents and items plus a stock-movement reporting index. `database/phase3_2_moving_average_costing.sql` extends the existing valuation ledger and protects materialized product costs. `database/phase4_1_sales_posting.sql` adds sale confirmation/void metadata and immutable sale-line snapshots. `database/phase4_2_payments_credit.sql` extends the existing customer, payment, receivable, and allocation ledgers with credit/verification/void metadata. `database/phase4_3_refunds_credit_notes.sql` adds immutable credit notes, receivable-reduction history, compensating reversals, and controlled outgoing customer-credit refunds. `database/phase4_4_sales_returns.sql` extends the base return documents with financial-credit linkage, approval/idempotency/posting attribution, condition and cost snapshots, plus immutable write-off and quarantine-hold records. `database/phase5_1_supplier_purchase_orders.sql` extends the base supplier/purchase tables with supplier codes/lifecycle, PO statuses, immutable line/header approval snapshots, and purchasing permissions. These migrations are **not idempotent**: do not rerun them against a database where they have already been applied. The Phase 2 migration requires existing duplicate sibling names, unit names, or current product-unit/type/currency prices to be resolved before import. Any new migration must be forward-only, named clearly, importable once, and avoid destructive changes.

For future financial/inventory work:

Phase 5.2 extends the existing goods receipt documents and links them one-to-one to Phase 3 inventory documents. Its migration does not backfill stock/valuation history. Review legacy receipt costs, supplier attribution, and unit precision before upgrading. See `docs/phase5-2-goods-receiving.md` for upgrade prerequisites and rounding policy.

Phase 5.3 adds supplier invoice/match/approval/variance documents and extends the existing `payables` table. The former unique PO key becomes a purchase index; one-invoice posting uniqueness replaces it, while a generated key preserves legacy PO payable uniqueness. Legacy liabilities must be reviewed before invoice posting on their PO. No liability, currency, or receipt history is invented. See `docs/phase5-3-supplier-invoices.md`.

Phase 5.4 extends existing payments/payable allocations with supplier attribution and immutable settlement snapshots, plus supplier payment request/approval/verification/difference documents. It does not backfill legacy allocations. Review legacy records before upgrading; no main-database migration is authorized implicitly by a phase implementation request. See `docs/phase5-4-supplier-payments.md` for lifecycle, locking, currency, and deployment rules.

Phase 5.5 adds supplier credit/correction documents, approvals, immutable AP/recoverable/correction allocations, and a monetary-free ordered provenance index. Existing supplier allocations remain version 1, without historical amount/verification backfill; new postings use version 2 and atomic activity references. Review legacy attribution before upgrading. Main database writes still require explicit authorization. See `docs/phase5-5-supplier-credits-corrections.md`.

Phase 5.6 adds physical purchase-return documents, immutable approvals/postings and optional attribution to posted financial supplier credits. It does not backfill history or automatically create supplier credits/payables/refunds. Main database migration still requires explicit authorization. See `docs/phase5-6-purchase-returns.md` for deployment, gross receiving, costing and credit-quantity policies.

- use `DECIMAL`, never binary floating point, for money and quantities;
- confirm business state in a transaction and use row locks where concurrent stock/cost records are changed;
- preserve confirmed historical transactions; make reversals/corrections rather than direct edits;
- keep stock in each product's defined base unit;
- prohibit negative stock unless an explicitly approved rule permits it;
- enforce unique movement/posting keys to prevent duplicate submissions;
- store transaction-time cost, sell-price, unit-conversion, and exchange-rate snapshots;
- keep payments/cash flow separate from sales revenue.

The intended costing policy is moving weighted average cost. Phase 4.1 uses the lock-scoped COGS contract in `MovingAverageCostService` for confirmed sales and full-sale voids; Phase 4.4 adds confirmed resellable returns back at the original sale-line COGS snapshot. Future transfers, adjustments, and fuel must write their movement, valuation entry, and source document atomically under the same product-cost lock; do not implement them in a controller or SQL trigger.

## Domain rules to carry forward

- One business, one physical site, multiple internal stock locations; no tenant scoping should be invented.
- Owner has full access. Staff must never receive purchase cost, COGS, profit/margin, sensitive finance data, user administration, or owner approval data in pages or JSON responses.
- Price overrides and approvals are backend permission checks, not UI flags.
- Inventory is affected only by confirmed postings. Draft sales, purchases, and adjustments must not change balances or financial totals.
- Phase 3 opening/purchase receipt drafts do not change stock. Confirmation posts immutable base-unit movements and balances in one transaction; repeated confirmation must not create a second posting.
- Receipt costs are explicit per-base-unit source snapshots. Phase 3.2 posts them to locked product_costs state and immutable inventory_cost_entries with four-place KHR value rounding and six-place half-up average rounding.
- Staff may create quantity-only receipt drafts but must never receive or submit cost details. Owner confirmation requires stock.adjust.approve. Batch-tracked products require a later dedicated batch receipt workflow.
- Posted receipt corrections use offsetting reversal movements after sufficient-stock checks; Phase 3.2 values a reversal at the current average when later receipts exist, never restores an obsolete average, and never edits or deletes a stock_movements or inventory_cost_entries row.
- Transfers move stock between locations only: no revenue and no COGS.
- Confirmed sales must preserve COGS snapshots so future cost changes do not rewrite history.
- Phase 4.1 sales are KHR-only retail/wholesale drafts. Confirmation locks product costs in product-ID order before stock, posts a negative stock movement and sale COGS entry atomically, and is idempotent. A price below locked COGS requires `pricing.below_cost.approve`.
- A Phase 4.1 void is a full-sale, Owner-only correction, never a deletion. It requires a reason plus explicit confirmation that every item is resellable, then writes compensating movements and valuation entries at original COGS. Partial/non-resellable returns use the Phase 4.4 workflow instead.
- Phase 4.2 creates one receivable only when a sale is confirmed. Cash sales may be anonymous; credit sales require an active credit-enabled customer. Only verified inbound allocations reduce debt or customer exposure, and payment allocation must exactly equal the converted payment amount.
- Bank and KHQR are manually verified only: do not claim provider integration. Cash can be verified on receipt. Record payment currency, six-place exchange rate, KHR conversion, reference, receiver, verification data, and a unique idempotency key. Customer repayments settle receivables, not revenue.
- Never delete payment or allocation history. Rejected pending payment allocations are reversal-marked. A sale with any active allocation cannot be voided until an Owner completes a future immutable refund/credit-adjustment process.
- Phase 4.3 credit notes are Owner-only financial corrections. A posted credit note reduces an invoice without moving inventory or COGS; any amount beyond the invoice's collectible balance is customer credit controlled by that note. A sale with a posted note must have that note reversed before it can be voided. Refunds consume only the originating excess credit, use idempotency keys and currency/rate snapshots, and bank/KHQR refunds remain pending until Owner verification.
- Phase 4.4 physical returns are Staff-created, Owner-approved/confirmed documents linked to a posted same-sale credit note. They use immutable original sale-line revenue and COGS snapshots. Resellable goods restore available stock and product valuation at original COGS; damaged/expired goods create write-off records without restocking; quarantined goods create unavailable holds. Confirmation never creates another credit note or refund and prevents credit-note reversal.
- Phase 5.1 purchase orders are commercial intent, not goods receipts. Only active suppliers, products, and configured product units can enter new drafts. Staff requests cannot carry purchase prices or discounts; Owner-supplied fixed-point price/conversion snapshots are required before submission and approval. Approval is idempotent and writes one immutable header snapshot; later receipt, stock, cost, payable, invoice, and supplier-payment posting is a separate milestone. Suppliers are never deleted, and cancellation is refused once a goods receipt or payable reference exists.
- Phase 5.2 goods receipts use approved PO quantity/conversion/price/discount/FX snapshots, with three-place base quantities and six-place receipt unit costs. Staff drafts cannot submit costs. Owner confirmation composes `InventoryService::postLockedReceipt()` in the same PO transaction; it never calls a nested transaction or writes a parallel ledger. PO locks serialize remaining-quantity checks; product-cost and balance locks follow product order. READ COMMITTED posting reads prevent stale balances after waiting on another PO. Approval alone still does not post inventory.
- Receiving moves approved POs to partially_received/received. Only an exactly fulfilled, reconciled PO with no unresolved draft/legacy receipts can close. Linked confirmed receipt reversal is blocked until a dedicated purchase correction workflow exists. Standalone inventory confirmation/cost-setting must never bypass the PO flow. Cost variances require an immutable Owner reason/approval; no landed costs or supplier payables are created.
- Phase 5.3 supplier invoices consume only eligible confirmed receipt quantities. Draft/approved invoices do not reserve quantities; posting rechecks them under the PO lock. Owner approval saves an immutable content hash and totals. Price/discount or receipt-cost differences require an explicit Owner reason; currency/FX/unit/supplier mismatches cannot be waived. Posting atomically creates one invoice payable, immutable matches, signed variance records, and audit events, without touching inventory or cash. Staff can record metadata only and never receive invoice prices or payable balances.
- Supplier invoice price differences remain a separate AP variance log, not inventory revaluation or an implemented operating-expense/general-ledger workflow. Cumulative receipt-value allocation preserves the original valuation exactly across partial invoices. Posted invoices are never cancelled/repriced in place; Phase 5.5 supplier credits adjust liability separately, without freeing matched receiving quantities or reversing stock. Prepayments, non-PO invoices, tax, and attachments remain deferred.
- Phase 5.4 payment approval reserves original-currency invoice debt but does not reduce AP. Only atomic posting of verified outgoing supplier cash flow and immutable payable allocations settles posted invoices. Staff creates own metadata-only requests; active Owner checks protect pricing/approval/verification/posting/reconciliation. No supplier payment appears in customer registers or modifies expenses, inventory, costing, or original payable rows.
- Supplier payment locking is supplier → payment document → invoices ascending ID → payables ascending ID, under READ COMMITTED. Approvals hash financial/reference/line content; bank/KHQR needs a separate immutable manual evidence record. Reject/cancel can release only unverified/unposted reservations. Verified transfers and posted payments cannot be cancelled/rejected/reallocated; Phase 5.5 separately compensates eligible full posted payments with evidence.
- KHR is the settlement base. Same-currency shares are exact; cross-currency shares follow explicit settlement USD rates. Cumulative historical AP/cash allocation preserves original totals. Signed FX and rounding records separately explain cash minus liability released; never rewrite invoice FX, revalue stock, or disguise cumulative rounding as FX. Read-only reconciliation reports inconsistencies without repairing them.
- Phase 5.5 credits apply only to eligible posted invoices, preserve original currency/FX, and cannot exceed original invoice less prior posted credits. Unpaid-first allocation never makes AP negative: excess is a distinct same-invoice supplier recoverable, not customer credit, a prepayment, automatic future offset or cash refund. Approved payment reservations cannot be consumed. Credit approval alone does not reserve/apply; posting rechecks remaining capacity.
- Full supplier payment corrections require an active Owner, reason, full original-currency confirmation, independent evidence, and funds_returned/proven_not_settled resolution. Approval binds correction content and the original payment/verification/allocation fingerprint. Never infer bank return from an accounting-only reversal. Original outgoing cash, allocations, approvals and verification remain immutable. Partial corrections, fees/return-time FX, unposted-transfer abandonment and posted credit reversals remain deferred.
- Financial correction locks are supplier → adjustment → original payment document (when applicable) → invoices ascending ID → payables ascending ID. Restored debt first consumes existing recoverable on the SAME invoice through immutable reapplications. New payments use combined net payment/credit discharge carrying value. Ordered activity references carry no money; source allocations remain authoritative. Explicit rounding records preserve exact totals after non-last corrections. Read-only replay flags missing provenance and makes unreconstructable balances unavailable/null; it never repairs them.
- Cash, bank/KHQR, credit, and partial payments must not create duplicate revenue.
- Phase 5.6 physical purchase returns use original confirmed receipt relationships/conversion/precision, gross received quantity less immutable previous returns, and available non-batch stock at the original active retail/storage location. Quarantine holds are outside available balances; no stock reservation subsystem is implemented. Draft/submission/approval do not reserve/deduct. Dispatch and confirmation commit together, with no durable in-transit status. Current locked moving-average carrying value, full-depletion residual clearing and separate original-receipt differences reuse InventoryService/MovingAverageCostService. Never overwrite receipt costs or PO gross fulfillment. Physical returns may precede invoicing; gross receipt matching remains commercial history and unmatched returns need explicit supplier-credit follow-up. Credit attribution requires an intact confirmed return plus a posted same-supplier/PO/invoice-receipt-match credit. Cap quantities per return and match, and amounts per credit; linking creates no second AP/cash/inventory posting. Reconciliation is read-only.
- Phase 5.7 restoration writes separate positive compensating stock/valuation postings. A credit-linked source is eligible only to the exact posted Phase 5.8 release quantity for that exact attribution. Phase 5.8 preserves the original credit and posts an ordered compensating AP/recoverable entry in original currency/FX; it never creates cash or inventory. A later settlement/reapplication/adjustment blocks it pending a separately verified settlement adjustment. Both reconciliation reports are read-only.
- Operating expenses are distinct from inventory purchases. Present operating profit as revenue minus COGS minus operating expenses; do not call it final after-tax net profit.
- Fuel is shift-based. The known physical facts are one pump and two nozzles for gasoline/diesel; tank count, capacities, and nozzle-to-tank mapping are unknown. Never invent them.
- Fuel shifts require meter boundaries for price changes, reconciliation, owner review of variance, immutable approved shifts, and reversal/correction rather than direct modification.

Consult the database-package files `DATABASE_GUIDE_KM.md`, `PERMISSIONS_KM.md`, and `OPEN_DECISIONS_KM.md` before starting inventory, finance, or fuel work.

## UI and frontend rules

- Preserve the existing login experience and public asset paths unless a user explicitly requests a redesign.
- The guest login layout currently loads Bootstrap 5.3.3 from jsDelivr and project styles from `/assets/css/app.css`. Authenticated Phase 1 screens currently use the local CSS only.
- Use Bootstrap 5 components and reusable local CSS for new screens. Keep forms, tables, buttons, validation, badges, empty/loading states, confirmation dialogs, and responsive layouts accessible and practical for daily store use.
- Use root-relative public assets (`/assets/...`) because routes may be served through the front controller.
- Keep assets under `public/assets/`; keep all secrets and non-public PHP/database files outside `public/`.
- Add AJAX only where it materially improves a feature. Return JSON with clear success/error shapes and still validate CSRF, authentication, authorization, and input in PHP.

## Development workflow

Before editing:

1. Inspect the affected controller, service, model, route, view, migration, and related documentation.
2. Confirm whether the base schema already has the needed table/columns.
3. State the smallest safe plan and identify authorization, transaction, and audit implications.
4. Preserve working code, current login styling, and unrelated user changes.

While implementing:

1. Add or reuse a route with the correct HTTP method.
2. Enforce authentication, permission, CSRF, validation, and ownership/business scope on the server.
3. Put multi-write workflows in a service transaction; roll back on every error path.
4. Write meaningful audit events for security-sensitive or committed business actions.
5. Escape every rendered value with `e()` and use prepared PDO statements for every query.
6. Do not add placeholder UI that claims a business workflow is complete.

Before handoff:

1. Run PHP syntax checks on changed PHP files with the installed PHP binary.
2. Test happy path, authorization failure, invalid/expired CSRF, malformed input, and duplicate/replay behavior appropriate to the change.
3. For Phase 1 changes, run the applicable checks in `docs/phase1-manual-test.md`.
4. Check the response status, redirects, and visible error/success states.
5. Summarize affected files, database changes, tests actually run, assumptions, and remaining work.

## Current risks and compatibility notes

- The broad SQL schema is explicitly draft and has not been matched by completed PHP workflows. Validate MySQL 8 foreign keys, indexes, locks, and rollback behavior as each module is built.
- The repository has CLI integration coverage for Phases 1, 2, 3, 3.2, 4.1, 4.2, 4.3, 4.4, 5.1, 5.2, 5.3, 5.4, 5.5, and 5.6, including receiving/invoice/payment/credit/physical-purchase-return concurrency, immutable corrections, rollback, evidence safeguards, and Staff privacy. Tests must set `SMARTSTOCK_TEST_DB_NAME` to a disposable database whose name ends in `_test`; they refuse to use the configured business database. Install every current migration before running regressions. Do not state that browser/manual coverage is complete unless the documented manual tests have also been run against the target environment.
- Production must use HTTPS and set the web server document root to `public/` only. The PHP built-in server configuration is for local development.
- `date_default_timezone_set('Asia/Phnom_Penh')` is currently set in `app/bootstrap.php`; treat a timezone change as a business/configuration decision, not a casual code edit.
- The public `.htaccess` only supplies basic directory/index protection. Review deployment-specific web-server configuration before exposing the application.

## Useful local commands

```powershell
# Run the local development server from SmartStock/
php -S localhost:8000 -t public public/index.php

# Create the first Owner account after database setup
php bin/create_owner.php owner "Shop Owner"

# Syntax-check all current PHP sources (PowerShell)
Get-ChildItem app, public, routes, bin -Recurse -Filter *.php |
  ForEach-Object { php -l $_.FullName }

# Run Phase 1 through Phase 5.8 integration checks only against an empty disposable test database
$env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
php tests/phase1_auth_integration.php
php tests/phase2_product_domain_integration.php
php tests/phase3_inventory_integration.php
php tests/phase32_moving_average_integration.php
php tests/phase41_sales_integration.php
php tests/phase42_payments_integration.php
php tests/phase43_credit_note_integration.php
php tests/phase44_sales_returns_integration.php
php tests/phase51_supplier_purchase_orders_integration.php
php tests/phase52_goods_receiving_integration.php
php tests/phase53_supplier_invoices_integration.php
php tests/phase54_supplier_payments_integration.php
php tests/phase55_supplier_adjustments_integration.php
php tests/phase56_purchase_returns_integration.php
php tests/phase58_linked_supplier_credit_corrections_integration.php
php tests/phase56_migration_smoke.php
```

Do not use the development server, this guide, or a successful syntax check as evidence that a financial/inventory workflow is correct. Verify each workflow against its authorization, ledger, and transaction invariants.
