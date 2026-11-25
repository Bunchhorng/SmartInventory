# Phase 2 product-domain backend

Phase 2 adds secured category, unit, product, conversion, barcode, and selling-price backend workflows. Inventory posting, purchases, sales, COGS, and product-cost maintenance are still out of scope.

## Import order

Import the base schema, reference seed, both Phase 1 migrations, and then database/phase2_product_domain.sql, once, in that order. The Phase 2 migration is not idempotent. Before importing it into an existing database, resolve duplicate sibling category names, duplicate unit names, and duplicate current product-unit/type/currency prices, otherwise its uniqueness indexes will reject the migration.

The migration:

- makes root and child category names unique within the same parent;
- adds unit status/timestamps and a unique unit name;
- permits price history while allowing only one current price per product-unit/type/currency;
- adds product/catalog listing indexes.

## Authorization

products.view is required for the catalog, categories, and units. Staff only receive active records, selling prices, and non-sensitive product fields.

products.manage is required for every product-domain mutation. Default Staff roles do not receive it. pricing.override is separately checked by ProductDomainService::resolveSellingPrice() when a caller supplies an override amount. Purchase-cost tables are never queried by Phase 2 product listings or price resolution.

All POST routes require a signed-in user, the server-side permission check, and a CSRF token:

| Route | Method | Purpose |
| --- | --- | --- |
| ?r=categories, ?r=units, ?r=products | GET | Paginated/searchable catalog reads |
| ?r=category-create, category-update, category-archive | POST | Category management |
| ?r=unit-create, unit-update, unit-archive | POST | Unit management |
| ?r=product-create, product-update, product-archive | POST | Product management |
| ?r=product-unit-create, product-unit-update | POST | Product-specific conversions |
| ?r=product-barcode-create, product-price-set | POST | Barcodes and current default prices |

Products are archived rather than deleted. The service deliberately rejects product deletion. Product base units are immutable after creation because all future inventory ledger quantities use that base unit. Conversion factors are fixed-point DECIMAL(18,6) values and cannot be zero or negative.

## Integration tests

The test scripts refuse to run unless SMARTSTOCK_TEST_DB_NAME names an explicit database ending in _test; they never fall back to the configured business database.

Create and import a separate disposable database using the documented import order, substituting its name for smartstock. Then run:

    $env:SMARTSTOCK_TEST_DB_NAME = 'smartstock_test'
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase1_auth_integration.php
    & 'C:\laragon\bin\php\php-8.3.28-Win32-vs16-x64\php.exe' tests\phase2_product_domain_integration.php

The Phase 2 test covers successful and rejected CRUD paths, uniqueness, category-cycle prevention, archive behavior, fixed-point conversions, current-price history, authorization, CSRF rejection, and transaction rollback after a duplicate barcode.
