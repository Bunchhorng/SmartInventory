You are a Senior PHP Backend Engineer and Database Architect working on the existing SmartStock project.

Read `AGENTS.md` and inspect Phase 1–4.4 implementations, existing database migrations, business services, RBAC, and integration tests.

## Task: Phase 5.1 — Supplier Management & Purchase Order Backend

Focus on backend architecture, purchasing business logic, database integrity, authorization, and automated tests.

Do not redesign the UI.

### 1. Preflight Inspection

- Inspect existing supplier, purchase order, purchase item, and inventory receipt tables.
- Identify any pre-existing purchase schema.
- Reuse existing structures where appropriate.
- Avoid duplicate tables and conflicting business rules.
- Preserve the current inventory costing, sales, and payment workflows.
- Do not migrate the main `smartstock` database automatically.

### 2. Supplier Management

Implement supplier records with:

- Supplier code
- Supplier name
- Contact person
- Phone number
- Address
- Optional email
- Supplier status
- Payment terms
- Created and updated timestamps

Business rules:

- Supplier codes must be unique.
- Validate names and contact information.
- Prevent deleting suppliers referenced by transactions.
- Support activation and deactivation.
- Preserve historical supplier references.
- Support searching, filtering, and pagination.

### 3. Purchase Order Management

Implement:

- Draft purchase orders
- Purchase order line items
- Product and purchasing unit selection
- Ordered quantities
- Unit purchasing prices
- Discounts when supported
- Estimated totals
- Supplier references
- Expected delivery dates
- Purchase order notes
- Submission and Owner approval
- Purchase order cancellation under safe conditions

Use explicit purchase order statuses.

Suggested workflow:

DRAFT → SUBMITTED → APPROVED → PARTIALLY_RECEIVED → RECEIVED → CLOSED

Support rejection/cancellation with documented transition rules.

### 4. Purchasing Business Rules

- Only active products can be added to new purchase orders.
- Only valid product-specific units are accepted.
- Convert purchasing units into base quantities using fixed-point arithmetic.
- Preserve purchase price and conversion snapshots.
- Validate currency and exchange-rate snapshots.
- Reject invalid, zero, or negative quantities.
- Prevent duplicate purchase-order approval.
- Prevent unauthorized price changes after approval.
- Do not create inventory movements when creating or approving purchase orders.
- Do not create supplier payables before the appropriate receiving/invoicing event.

### 5. Permissions

- Staff can view permitted supplier information.
- Staff may create draft purchase requests if authorized.
- Owner controls purchasing prices, approvals, and cancellations.
- Staff must not access purchase costs or confidential profit data.
- Enforce RBAC on every route.

### 6. Database Transactions

- Use PDO prepared statements.
- Use MySQL InnoDB transactions.
- Protect multi-table operations.
- Use immutable approval snapshots.
- Prevent duplicate posting.
- Maintain audit events for sensitive actions.

### 7. Security

- Authentication required.
- Server-side validation.
- CSRF protection.
- Permission-based access control.
- No sensitive cost leakage to Staff.
- Never rely only on hidden frontend fields.

### 8. Automated Testing

Create repeatable integration tests for:

- Supplier creation and update
- Duplicate supplier code
- Supplier deactivation
- Purchase order drafting
- Multi-item purchase orders
- Product unit conversion
- Purchase price snapshots
- Owner approval
- Unauthorized approval
- Invalid status transitions
- Duplicate approval
- Cancellation safety
- Transaction rollback
- CSRF rejection
- Staff cost privacy
- Phase 1–4.4 regressions

Use `smartstock_test` or another disposable database.

### 9. Deliverables

Report:

1. Files created or changed.
2. Database migration.
3. Supplier and purchasing services.
4. Status transition rules.
5. Tests executed and results.
6. Main database migration status.
7. Remaining limitations.
8. Next recommended backend milestone.

Implement Phase 5.1 only.

Do not implement goods receipt posting, supplier invoice accounting, supplier payments, expenses, or fuel workflows yet.