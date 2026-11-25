# SmartStock — Phase 5.6 physical purchase returns

This folder contains the secure Phase 1 account foundation, Phase 2 product catalog, Phase 3.2 transaction-safe receipt and moving-average costing engine, Phase 4.1 sales posting, Phase 4.2 payments/customer credit, Phase 4.3 financial credit notes/refunds, Phase 4.4 physical sales returns, Phase 5.1 supplier/purchase-order management, Phase 5.2 goods receiving with partial PO fulfillment, Phase 5.3 supplier invoice matching/payable recognition, Phase 5.4 supplier payment allocation, and Phase 5.5 supplier credits and evidenced full-payment corrections. It is a PHP 8.2+ application using the existing MySQL 8 SmartStock schema.

Phase 5.6 adds quantity-only Staff purchase-return requests, Owner-approved atomic physical dispatch/confirmation at current moving-average carrying value, optional attribution to existing posted supplier credits, and read-only reconciliation. Physical dispatch never automatically creates financial credit, AP adjustments or refunds. See [Phase 5.6 policies and verification](docs/phase5-6-purchase-returns.md).

Phase 5.7 adds separately approved, evidence-backed physical restoration corrections for posted purchase returns. It uses positive compensating stock/valuation postings at the original return carrying-value snapshot and never rewrites history. Linked-credit quantity remains blocked until the exact quantity is released by Phase 5.8. See [Phase 5.7 policy](docs/phase5-7-purchase-return-corrections.md).

Phase 5.8 adds Owner-only, immutable compensating financial corrections for exact posted supplier-credit/physical-return links. It restores only the original credit's eligible AP/recoverable effect, creates no cash or inventory movement, and releases no stock except through the separate Phase 5.7 workflow. See [Phase 5.8 policy](docs/phase5-8-linked-supplier-credit-corrections.md).

## Implemented

- PHP OOP MVC structure: controllers, models, services, middleware, routes, layouts, and views
- PDO MySQL connection with prepared statements
- Login/logout with five-failure, 15-minute account lockout
- Owner and Staff roles, enforced on the server
- Owner-only staff creation with temporary passwords
- Required password change on a first sign-in
- CSRF protection for every form that changes data
- Server-side session records in the auth_sessions table; logout revokes the session and password changes revoke all prior sessions
- Responsive application shell and role-aware navigation
- Category hierarchy with sibling uniqueness, cycle prevention, search, pagination, and archive lifecycle
- Configurable units with validated precision and archive protection
- Product catalog with generated SKUs, optional brands/barcodes/batch-expiry flags, and non-destructive archival
- Product-specific fixed-point unit conversions and versioned retail/wholesale selling prices
- Draft/confirmed opening and purchase receipts with immutable stock movements and atomic location balances
- Owner-only receipt valuation/confirmation, Staff quantity-only drafts, idempotent posting, and reversal entries
- Product-wide moving weighted-average costing with immutable valuation entries linked to stock movements
- Draft and confirmed retail/wholesale sales with immutable price, unit, revenue, and COGS snapshots
- Owner-only sale voids that create compensating stock and valuation entries; Staff never receive cost or profit fields
- Customer credit profiles, due terms, credit-limit enforcement, overdue receivables, and Owner-approved overrides
- KHR/USD cash, manually verified bank/KHQR, split/partial payment allocation, and payment reconciliation separate from sales revenue
- Owner-only immutable credit notes that adjust net sales and receivables without moving inventory or COGS
- Controlled excess customer credit plus idempotent cash/bank/KHQR refunds; bank/KHQR refund completion remains manually verified
- Staff-created, Owner-approved physical sales returns linked to posted same-sale credit notes, with immutable original-line references and quantity/financial guards
- Resellable restock movements and valuation entries at original sale-line COGS; separate damaged/expired write-offs and unavailable quarantine holds
- Supplier master data with unique codes, searchable lifecycle records, contact details, payment terms, and non-destructive deactivation
- Non-posting draft/submitted/approved/rejected/cancelled purchase orders with fixed-point unit/conversion/price snapshots and one immutable approval snapshot
- Staff quantity-only purchase requests; Owner-only purchasing prices, discounts, approval, rejection, and cancellation; Staff never receives purchase-cost totals
- Quantity-only goods receipt drafts against approved POs; Owner confirmation atomically posts existing stock/valuation ledgers and PO received quantities
- Partial/full deliveries, preserved unit/price/FX snapshots, explicit Owner cost variances, read-only receiving reconciliation, and eligible PO closing
- Supplier invoice metadata and Owner-only pricing, receipt quantity matching, immutable approvals, invoice payables, signed price/rounding variance records, and read-only supplier balances
- Confidential Staff supplier payment requests, Owner approval/posting, KHR/USD and cross-currency settlements, manual bank/KHQR verification, immutable AP allocation, and separate signed FX/rounding reconciliation
- Owner-only supplier credits with unpaid-first AP application, separate recoverable debit balances, full evidenced payment corrections, immutable same-invoice recoverable reapplication, and ordered financial reconciliation; original cash/invoice/inventory history is never rewritten

## Run locally

1. Install PHP 8.2+ with pdo_mysql and MySQL 8+.
2. Create a dedicated database account. On MySQL 8.4+, use `caching_sha2_password`; `mysql_native_password` is disabled by default:

       mysql -u root -p -e "CREATE USER 'smartstock_user'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'choose-a-strong-password'; GRANT ALL PRIVILEGES ON smartstock.* TO 'smartstock_user'@'127.0.0.1';"

   Use the same password in `config.php`. If the account already exists with `mysql_native_password`, replace the first statement with:

       mysql -u root -p -e "ALTER USER 'smartstock_user'@'127.0.0.1' IDENTIFIED WITH caching_sha2_password BY 'choose-a-new-strong-password';"

3. Create an empty MySQL database by importing the supplied files, in order:

       cmd /c "mysql -u root -p < ..\SmartStock_Step_1_13_Database_Pack\smartstock_schema.sql"
       cmd /c "mysql -u root -p smartstock < ..\SmartStock_Step_1_13_Database_Pack\smartstock_seed_reference.sql"
       cmd /c "mysql -u root -p smartstock < database\phase1_additions.sql"
       cmd /c "mysql -u root -p smartstock < database\phase1_staff_statuses.sql"
       cmd /c "mysql -u root -p smartstock < database\phase2_product_domain.sql"
       cmd /c "mysql -u root -p smartstock < database\phase3_inventory_ledger.sql"
       cmd /c "mysql -u root -p smartstock < database\phase3_2_moving_average_costing.sql"
       cmd /c "mysql -u root -p smartstock < database\phase4_1_sales_posting.sql"
       cmd /c "mysql -u root -p smartstock < database\phase4_2_payments_credit.sql"
       cmd /c "mysql -u root -p smartstock < database\phase4_3_refunds_credit_notes.sql"
       cmd /c "mysql -u root -p smartstock < database\phase4_4_sales_returns.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_1_supplier_purchase_orders.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_2_goods_receiving.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_3_supplier_invoices.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_4_supplier_payments.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_5_supplier_credits_corrections.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_6_purchase_returns.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_7_purchase_return_corrections.sql"
       cmd /c "mysql -u root -p smartstock < database\phase5_8_linked_supplier_credit_corrections.sql"

4. Copy config.example.php to config.php, then enter the credentials for the MySQL user above. `config.php` is ignored by Git and must stay outside public/.
5. Create the first Owner account from a terminal:

       php bin\create_owner.php owner "Shop Owner"

6. Start the local server:

       php -S localhost:8000 -t public public/index.php

7. Open http://localhost:8000/?r=login. The Owner must change the initial password before reaching the dashboard.

## Routes

| Route | Method | Access |
| --- | --- | --- |
| ?r=login | GET, POST | Guest |
| ?r=change-password | GET, POST | Signed-in user |
| ?r=dashboard | GET | Owner or Staff |
| ?r=staff-create | GET, POST | Owner (users.manage) |
| ?r=categories, ?r=units, ?r=products | GET | Owner or Staff (products.view; Staff sees active records) |
| ?r=category-create/update/archive | POST | Owner (products.manage) |
| ?r=unit-create/update/archive | POST | Owner (products.manage) |
| ?r=product-create/update/archive, ?r=product-barcode-create, ?r=product-price-set | POST | Owner (products.manage) |
| ?r=suppliers | GET | Owner or Staff (suppliers.view; Staff sees permitted non-financial supplier fields) |
| ?r=supplier-create/update/status | POST | Owner (suppliers.manage + CSRF) |
| ?r=purchase-orders | GET | Owner or Staff (purchases.view; Staff responses exclude costs) |
| ?r=purchase-order-create/update/submit | POST | Owner or authorized Staff (purchases.request + CSRF; Staff cannot send costs) |
| ?r=purchase-order-approve/reject/cancel | POST | Owner only + CSRF |
| ?r=goods-receipts, ?r=goods-receipt-detail, ?r=purchase-order-detail | GET | purchases.view; JSON responses omit costs for Staff |
| ?r=goods-receipt-create | POST | purchases.receive + stock.receive + purchases.view + CSRF; quantity-only |
| ?r=goods-receipt-confirm/cancel/cost-variance, ?r=purchase-order-close | POST | Owner with receiving/variance/closing permission + CSRF |
| ?r=purchase-order-reconciliation | GET | Owner with purchases.receive.confirm; read-only JSON |
| ?r=supplier-invoices, ?r=supplier-invoice-detail | GET | supplier_invoices.view; Staff receives metadata only |
| ?r=supplier-invoice-create/update/submit | POST | supplier_invoices.record + CSRF; invoice prices/lines require Owner |
| ?r=supplier-invoice-approve/post/reject/cancel | POST | Owner with supplier_invoices approval/post/manage permissions + CSRF |
| ?r=supplier-invoice-reconciliation, ?r=supplier-payables | GET | Owner with supplier_invoices.reconcile; read-only JSON |
| ?r=purchase-returns, ?r=purchase-return-detail | GET | purchase_returns.view; quantity-only Staff responses |
| ?r=purchase-return-create/submit | POST | purchase_returns.request + CSRF; Staff submits own requests |
| ?r=purchase-return-approve/reject/cancel | POST | Active Owner + purchase_returns.approve + CSRF |
| ?r=purchase-return-dispatch/confirm | POST | Active Owner + purchase_returns.confirm + CSRF; same atomic handover |
| ?r=purchase-return-link-credit | POST | Active Owner + purchase_returns.link_credit + CSRF; attribution only |
| ?r=purchase-return-reconciliation | GET | Active Owner + purchase_returns.reconcile; read-only JSON |
| ?r=purchase-return-corrections, ?r=purchase-return-correction-detail | GET | purchase_return_corrections.view; Staff responses are quantity-only |
| ?r=purchase-return-correction-create/submit | POST | purchase_return_corrections.request + CSRF; Staff submits own requests |
| ?r=purchase-return-correction-approve/reject/cancel | POST | Active Owner + purchase_return_corrections.approve + CSRF |
| ?r=purchase-return-correction-post | POST | Active Owner + purchase_return_corrections.post + CSRF |
| ?r=purchase-return-correction-reconciliation | GET | Active Owner + purchase_return_corrections.reconcile; read-only JSON |
| ?r=inventory-receipts | GET | Owner or Staff (stock.view; costs are not shown) |
| ?r=inventory-receipt-create | POST | Owner or Staff (stock.receive + CSRF; Staff drafts contain no cost) |
| ?r=inventory-receipt-costs/confirm/reverse | POST | Owner (stock.adjust.approve + CSRF) |
| ?r=sales | GET | Owner or Staff (sales.create; Staff list excludes cost/profit) |
| ?r=sale-create | POST | Owner or Staff (sales.create + CSRF) |
| ?r=sale-confirm | POST | Owner or Staff (sales.confirm + CSRF) |
| ?r=sale-void | POST | Owner (sales.void + CSRF + resellable stock confirmation) |
| ?r=sales-returns | GET | Owner or Staff (sales.create; Staff list excludes cost fields) |
| ?r=sales-return-create | POST | Owner or Staff (sales.create + CSRF) |
| ?r=sales-return-approve, ?r=sales-return-confirm | POST | Owner only + CSRF |
| ?r=payments | GET | Owner or Staff (payments.record; Staff has no credit-limit, COGS, or profit fields) |
| ?r=payment-record | POST | Owner or Staff (payments.record + CSRF) |
| ?r=payment-verify, ?r=customer-create, ?r=customer-credit-update | POST | Owner (credit.manage + CSRF) |
| ?r=credit-notes | GET | Owner only |
| ?r=credit-note-create/approve/post/reverse, ?r=refund-create/approve/post/verify/reverse | POST | Owner only + CSRF |
| ?r=logout | POST | Signed-in user |

## Phase 1 acceptance checks

After configuring PHP and MySQL, verify the following:

1. Owner can sign in, change the initial password, and create a Staff account.
2. New Staff must change the temporary password before reaching the dashboard.
3. A Staff account receives HTTP 403 at ?r=staff-create.
4. Submitting any form with an altered _csrf value returns HTTP 419.
5. Disabling a Staff account in Staff Accounts prevents a new dashboard request.
6. Sign in in two browsers, change the password in one, then confirm the other session is rejected.
7. As Owner, suspend or disable a Staff account; its existing session is rejected on the next request. Reactivate the account and confirm it can sign in again.

## Security and deployment

Use HTTPS in production and set the web-server document root to public/ only. Keep app/, bin/, database/, storage/, and config.php outside the public web root. Before a public deployment, add IP/account rate limiting, password-reset workflow, error monitoring, backups, integration tests, and a production security review.

See [Phase 2 product-domain notes](docs/phase2-product-domain.md), [Phase 3.1 receipt notes](docs/phase3-inventory-ledger.md), [Phase 3.2 costing notes](docs/phase3-2-moving-average.md), [Phase 4.1 sales notes](docs/phase4-1-sales.md), [Phase 4.2 payment/credit notes](docs/phase4-2-payments-credit.md), [Phase 4.3 credit-note/refund notes](docs/phase4-3-credit-notes-refunds.md), [Phase 4.4 physical-return notes](docs/phase4-4-physical-sales-returns.md), [Phase 5.1 supplier/purchase-order notes](docs/phase5_1_supplier_purchase_orders.md), [Phase 5.2 receiving notes](docs/phase5-2-goods-receiving.md), [Phase 5.3 invoice/payable notes](docs/phase5-3-supplier-invoices.md), [Phase 5.4 supplier-payment notes](docs/phase5-4-supplier-payments.md), [Phase 5.5 supplier financial-correction notes](docs/phase5-5-supplier-credits-corrections.md), and [Phase 5.6 physical purchase-return notes](docs/phase5-6-purchase-returns.md) for migration caveats, financial policies, permissions, routes and disposable-database tests. Partial supplier payment corrections, posted credit/physical-return reversals, cross-invoice offsets, stock transfers, landed costs and quarantine disposition remain deferred. The main database is not migrated by automated implementation/testing.

