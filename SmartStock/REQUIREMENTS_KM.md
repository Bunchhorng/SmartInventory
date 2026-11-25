# SmartStock Step 1.15 — Confirmed & Proposed

## Confirmed
- Owner creates Staff accounts; no public registration.
- One business owner; one real-world location; several internal stock locations.
- Owner + Staff; staff cannot view cost, profit and sensitive finance reports.
- Moving Weighted Average Cost, Flexible Pricing, Smart Credit, Smart Expense.
- Fuel shift based on nozzle meter; 1 pump, 2 nozzles, gasoline and diesel; fuel tank count still unknown.
- Owner review and approval for shift differences.

## Proposed account lifecycle
1. Owner creates Staff with username, fullname, one-time temporary password.
2. Staff signs in and MUST change password before dashboard access.
3. Active Staff gets strictly role-based permissions, enforced in PHP.
4. Owner may disable account; preserve historical transaction references and audit trail.
5. Later add password reset, session revocation, per-user overrides and IP-based throttling.

## Defaults
- Owner: all permissions.
- Staff: products.view, stock.view, stock.receive, stock.adjust.request, sales.create, sales.confirm, payments.record, expenses.create, fuel.shift.open, fuel.meter.enter, fuel.shift.submit.
- Owner only: pricing.override, stock.adjust.approve, fuel.shift.approve, fuel.variance.approve, reports.profit, users.manage, settings.manage.

## Password policy
- 12–128 characters, hashed, never plaintext in DB.
- Change temporary password on first login. Lock 15 minutes after 5 unsuccessful attempts (starter implementation; strengthen in production).
- Restrict login endpoints to HTTPS production, CSRF for state-changing forms, SameSite and HttpOnly session cookie.

## Phase 1 acceptance tests
- Owner can log in, change password, create Staff.
- Staff changes temporary password at first login.
- Staff cannot access staff-create endpoint (HTTP 403).
- Invalid CSRF rejected (419).
- Disabled users cannot enter dashboards.
- Staff cannot reach owner financial endpoints when they are added.
