# Phase 1 manual test record

Run these checks against a local MySQL 8 database after completing the setup in README_KM.md.

| Check | Expected result |
| --- | --- |
| Sign in with a newly created Owner | Redirected to password change |
| Submit a password shorter than 12 characters | Validation message; password stays unchanged |
| Change the Owner password | Redirected to dashboard; another open Owner session becomes invalid |
| Create a Staff account as Owner | Account is created and the staff user has must_change_password = 1 |
| Suspend a signed-in Staff account as Owner | The Staff account status is suspended and its next request redirects to sign-in |
| Disable a Staff account as Owner | The account has status disabled, disabled_at is recorded, and it cannot sign in |
| Activate a suspended or disabled Staff account as Owner | The account becomes active, disabled_at is cleared, and it can sign in again |
| Sign in as Staff | Redirected to password change |
| Request ?r=staff-create as Staff | HTTP 403 |
| POST ?r=staff-status as Staff | HTTP 403 |
| Send GET to ?r=logout | HTTP 405 |
| Submit a valid form with an altered CSRF token | HTTP 419 |
| Enter five invalid passwords for an active account | Further sign-in attempts are blocked for 15 minutes |

Do not mark Phase 1 runtime validation complete until each check passes using the target MySQL and PHP versions.
