# Prompt: Create AGENTS.md for SmartStock

You are a Senior PHP Software Architect, Full-Stack Developer, Database Engineer, and Security Engineer.

I am developing **SmartStock — Smart Inventory & Business Management System**, a real-world business application for a single business owner operating a retail store, inventory storage areas, agricultural product sales, and a fuel station at one physical location.

Your task is to inspect the existing project and create a professional, comprehensive `AGENTS.md` file in the project root directory.

## 1. Project Overview

SmartStock is designed to manage:

- Products and categories
- Inventory across multiple storage locations
- Purchase and supplier management
- Retail and wholesale sales
- Flexible product pricing
- Customer credit and partial payments
- Business income and expenses
- Profit and loss
- Fuel tanks, pumps, nozzles, and shifts
- Fuel meter readings and reconciliation
- Owner approval workflows
- Smart stock alerts and business analytics
- Owner and staff accounts

This is **not a multi-tenant SaaS**.

The system serves one business owner at one physical location, with multiple internal storage areas.

## 2. Technology Stack

Use the following stack:

- PHP 8.2+ with Object-Oriented Programming
- Custom MVC architecture
- MySQL with InnoDB
- PDO and prepared statements
- HTML5 and CSS3
- Bootstrap 5
- JavaScript and jQuery
- AJAX with JSON responses
- Chart.js
- SweetAlert2
- Barcode and QR scanning libraries when needed

The application must remain compatible with standard PHP shared hosting, targeting InfinityFree for prototype deployment.

Do not introduce Laravel, React, Next.js, Node.js, Docker, Redis, WebSockets, or PWA unless explicitly requested.

Do not assume that cron jobs, queue workers, or shell access are available in production.

## 3. Existing Project Preservation

Before making any changes:

1. Inspect the existing project structure.
2. Read the current authentication implementation.
3. Inspect database schemas and migrations.
4. Identify existing services, models, controllers, and routes.
5. Reuse working components whenever possible.
6. Never overwrite working implementations unnecessarily.
7. Preserve existing login UI and styling.
8. Avoid introducing duplicate business logic or database tables.
9. Prefer incremental, testable changes.
10. Document assumptions and compatibility risks.

Do not claim a feature is complete unless it has been implemented and tested.

## 4. Architecture Rules

Follow a clean MVC architecture.

Responsibilities:

- Controllers: Validate requests and coordinate operations.
- Models/Repositories: Database access.
- Services: Business rules and transactions.
- Middleware: Authentication, authorization, CSRF, and access control.
- Views: HTML and Bootstrap templates.
- JavaScript: jQuery AJAX and frontend interactions.

Use a consistent naming convention and keep controllers thin.

Never write critical business logic directly inside views or jQuery scripts.

## 5. Authentication and Authorization

Support two roles:

### Owner

The Owner has full access to:

- Business dashboards
- Products and selling prices
- Purchase costs
- Inventory and warehouses
- Sales and purchases
- Customer credit
- Expenses
- Profit and loss
- Fuel management
- Staff management
- Approval workflows
- Settings and audit logs

### Staff

Staff can perform authorized daily operations.

Default access may include:

- Viewing products
- Viewing selling prices
- Creating ordinary sales
- Recording payments
- Receiving inventory
- Requesting stock adjustments
- Entering fuel meter readings
- Submitting fuel shifts

Staff must not access:

- Purchase costs
- Cost of goods sold
- Profit margins
- Owner financial dashboards
- Sensitive financial reports
- User administration
- Owner-only approval actions

Enforce all permissions on the PHP backend.

Never rely only on hidden UI elements for security.

## 6. Staff Account Lifecycle

Only the Owner can create Staff accounts.

Required behavior:

1. Owner creates a Staff account.
2. Staff receives a temporary password through a secure channel.
3. Staff signs in.
4. Staff must change the temporary password.
5. Account becomes active for normal operations.
6. Owner can suspend, reactivate, reset, or disable accounts.
7. Preserve historical transactions when an account is disabled.

There must be no public registration page.

Use secure password hashing, CSRF protection, session regeneration, login rate limiting, secure cookies, and server-side permission checks.

## 7. Product Management Rules

Products must support:

- SKU
- Product name
- Category and subcategory
- Brand
- Barcode
- Product images
- Purchase cost
- Retail selling price
- Wholesale selling price
- Flexible price overrides
- Minimum stock
- Product status
- Optional batch and expiration tracking

Support product-specific unit conversion.

Examples:

- Bottle and Case
- Can and Carton
- Bag and Kilogram
- Piece and Box
- Litre for fuel

Store inventory quantities in a defined base unit.

Never assume all cases contain the same number of items.

## 8. Inventory Management Rules

Inventory is tracked by storage location.

Support:
    
- Stock receiving
- Stock issuing
- Internal transfers
- Stock adjustment
- Physical counting
- Damaged inventory
- Expiration tracking
- Stock movement history

Maintain an auditable stock ledger.

Use MySQL transactions and row locking when needed.

Never allow negative stock unless explicitly approved by a future business rule.

Internal transfers must not create sales revenue or COGS.

## 9. Inventory Costing

Use **Moving Weighted Average Cost** as the default costing method.

Rules:

- Recalculate average cost when qualifying inventory is received.
- Store purchase costs historically.
- Convert purchase quantities into base units.
- Preserve COGS snapshots when confirming sales.
- Prevent historical sales profits from changing when current product costs change.
- Handle purchase returns and sales returns using documented reversal logic.
- Avoid duplicate cost postings.
- Handle inventory and valuation updates atomically.

Keep cost calculations inside dedicated PHP services.

## 10. Sales and Payments

Support:

- Retail sales
- Wholesale sales
- Flexible pricing
- Cash payments in KHR and USD
- Bank transfer and manually verified KHQR payments
- Split payments
- Credit sales
- Partial payments
- Customer credit limits
- Due dates
- Returns and refunds
- Receipt printing

Important:

- Deduct inventory only when the sale is confirmed.
- Draft sales must not affect inventory.
- Keep payment status separate from sale status.
- Repayment of customer debt must not create duplicate sales revenue.
- Save the actual selling price and exchange rate with each transaction.
- Require appropriate permissions for selling-price overrides.

## 11. Expenses and Profit

Support:

- Expense categories
- Daily expenses
- Staff salaries
- Electricity and water
- Transport
- Maintenance
- Packaging
- Bank fees
- Expense budgets
- Recurring expense drafts
- Expense trends and alerts

Separate inventory purchases from operating expenses.

Calculate:

Gross Profit = Net Sales Revenue - Cost of Goods Sold

Operating Profit = Gross Profit - Operating Expenses

Do not confuse cash received with sales revenue.

Do not present operating profit as final after-tax net profit.

## 12. Fuel Management

The current business has:

- One known fuel pump
- Two known nozzles
- Gasoline and diesel
- An unknown number of tanks
- Flexible shift schedules

Do not invent actual tank counts or capacities.

Use shift-based meter readings.

Fuel workflow:

1. Open shift.
2. Record opening readings for each nozzle.
3. Record special transactions, including credit sales and bank payments.
4. Record closing meter readings.
5. Calculate litres sold.
6. Calculate expected revenue using accurate price periods.
7. Reconcile recorded payments and credit.
8. Submit the shift for owner review.
9. Owner approves or returns the shift.
10. On confirmation, post inventory and financial transactions once.

Fuel reconciliation policy:

**Owner Review & Approval**

Any shortage or overage must be recorded with a reason and owner approval.

Never post duplicate fuel stock deductions.

Approved shifts must not be directly editable.

Corrections must preserve the audit trail.

## 13. Dashboard Requirements

Create separate Owner and Staff dashboards.

Owner dashboard includes:

- Sales revenue
- COGS
- Gross profit
- Operating expenses
- Operating profit or loss
- Inventory valuation
- Customer receivables
- Supplier payables
- Fuel sales and stock
- Pending approvals
- Low stock alerts
- Expiration warnings
- Sales analytics

Staff dashboard includes:

- Daily operational tasks
- Authorized sales information
- Product lookup
- Stock alerts
- Stock receiving
- Fuel shift status
- Pending requests

Never send confidential financial data to Staff API responses.

Dashboard KPIs must use actual committed transaction records, not hardcoded values.

## 14. Database Standards

Use:

- MySQL InnoDB
- Primary and foreign keys
- Appropriate unique constraints
- DECIMAL for money and quantities
- Indexed transaction dates and statuses
- Audit timestamps
- Transaction-safe stock updates
- Migration files for schema changes

Avoid destructive schema changes.

Never modify historical financial or inventory transactions directly.

Use reversals, adjustments, or corrections when appropriate.

## 15. UI/UX Standards

Follow a modern, responsive design with:

- Blue and white primary theme
- Clean typography
- Consistent spacing
- Clear navigation
- Responsive tables
- Accessible buttons and forms
- Loading states
- Empty states
- Confirmation dialogs
- Validation messages
- Status badges
- Mobile-friendly layouts

Use Bootstrap 5 and maintain reusable components.

Do not redesign existing screens unless requested.

Keep interfaces practical for everyday business users.

## 16. Coding Agent Workflow

For every development task:

1. Inspect relevant existing files.
2. Explain the planned change briefly.
3. Identify affected controllers, services, views, scripts, and tables.
4. Implement the smallest complete solution.
5. Follow security and database transaction rules.
6. Test success and failure cases.
7. Check for regressions.
8. Summarize the completed changes.
9. Mention any unfinished work or assumptions.

Do not silently modify unrelated features.

Do not create placeholder features and claim they are production-ready.

## 17. Phase 1 Priorities

The current priority is completing and verifying:

- Owner login
- Forced first-login password change
- Owner dashboard access
- Owner-created Staff accounts
- Staff login
- Staff permissions
- Logout and session protection
- Account suspension and disabling
- Audit logs

After Phase 1 is verified, proceed to Product and Inventory modules.

## Final Instructions

Create `AGENTS.md` in the project root.

Make it clear, comprehensive, and actionable for future AI coding agents.

Adapt references to the actual repository instead of inventing filenames, routes, or existing classes.

If there are conflicts between these requirements and the current implementation, document them before changing code.

Do not implement new project features as part of this task. Only inspect the repository and create or update `AGENTS.md`.