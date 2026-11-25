You are a Senior PHP Backend Engineer and Database Architect working on SmartStock.

First, read `AGENTS.md` and inspect the existing project architecture, database schema, migrations, authentication, RBAC, and integration tests.

## Main Objective

Focus exclusively on backend development, business logic, database integrity, security, and automated tests.

Do not redesign UI, add animations, or introduce frontend frameworks.

## Task: Phase 2 — Product Domain Backend

Implement and verify the following backend modules incrementally.

### 1. Category Management
- Create, update, list, and archive categories.
- Support parent categories and subcategories.
- Prevent duplicate sibling categories.
- Prevent circular category relationships.
- Preserve categories referenced by products.
- Implement pagination, search, and status filters.

### 2. Unit Management
- Create and manage measurement units.
- Support Piece, Bottle, Case, Bag, Kilogram, Litre, and other configurable units.
- Validate unit precision and conversion factors.
- Prevent duplicate units.

### 3. Product Management
- Create, update, list, and archive products.
- Generate unique SKUs.
- Support categories, brands, and base units.
- Support optional barcode, batch, and expiration tracking.
- Validate product data on the backend.
- Prevent destructive deletion of products referenced by transactions.

### 4. Product Unit Conversion
- Support multiple units per product.
- Store stock quantities in base units.
- Prevent zero or negative conversion factors.
- Use DECIMAL-safe calculations.
- Do not assume a universal case or bag size.

### 5. Product Pricing
- Support retail and wholesale prices.
- Support prices per product unit.
- Preserve selling-price history.
- Allow authorized flexible price overrides.
- Prevent Staff from accessing purchase costs.
- Do not alter historical transaction prices when defaults change.

## Architecture Requirements

- Reuse the existing PHP OOP MVC architecture.
- Keep controllers thin.
- Put business rules in services.
- Use existing PDO database infrastructure.
- Use transactions for multi-table changes.
- Follow repository naming conventions.
- Add migrations only when necessary.
- Preserve Phase 1 authentication and RBAC behavior.

## Security Requirements

- Enforce backend permissions on every route.
- Validate all input server-side.
- Use CSRF protection for state-changing requests.
- Use PDO prepared statements.
- Prevent SQL injection and unauthorized data exposure.
- Restrict product purchase-cost access to Owner.
- Never rely solely on frontend restrictions.

## Testing Requirements

Create repeatable automated tests covering:

- CRUD success and failure
- Duplicate categories and SKUs
- Circular category relationships
- Unit conversion accuracy
- Invalid price and quantity values
- Owner and Staff authorization
- CSRF rejection
- Archived records
- Database rollback
- Regression tests for Phase 1

Use a disposable test database.

Do not execute destructive tests against real business data.

## Implementation Workflow

1. Inspect existing code and database schema.
2. Identify gaps and conflicts.
3. Prepare a small implementation plan.
4. Implement Category Management backend first.
5. Execute tests.
6. Continue with Units, Products, Conversions, and Pricing only after their dependencies are verified.
7. Avoid modifying unrelated features.

## Completion Report

Provide:
- Files changed
- Database migrations
- Services and routes implemented
- Permissions enforced
- Tests executed and results
- Remaining limitations
- Next recommended backend task

Do not claim completion without executing the relevant tests.

Prioritize correctness and maintainability over UI improvements.