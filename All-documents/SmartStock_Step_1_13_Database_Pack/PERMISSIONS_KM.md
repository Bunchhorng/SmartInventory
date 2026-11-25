# Authentication, Permissions & Approval Policy

## Authentication
- Owner និង Staff មាន username/password ផ្ទាល់ខ្លួន។ គ្មានការចែករំលែក Account។
- `users.password_hash`: Argon2id ឬ bcrypt; មិនរក្សា password plaintext ឬ MD5។
- Login: generic error, account throttling/temporary lock, regenerate PHP session ID, same-site secure HTTP-only cookie, server-side session expiration.
- Logout និងការប្ដូរ password ត្រូវ revoke session ដោយគោរព security controls។
- ផ្នែកមុខងារក្នុង UI ដែល Hide មិនមែន Permission Check ទេ; ត្រូវធ្វើ Authorization នៅ PHP Controller/Service រាល់ request។
- Every mutating form/API: CSRF token + validation + PDO prepared statement + permission check + ownership/business check.

## Permission defaults
| Action | Owner | Staff (suggested) |
|---|---|---|
| View products & stock | Yes | Yes |
| Create/update product | Yes | No by default |
| Price override | Yes | No by default |
| Approve sale below cost | Yes | No |
| Record regular sale | Yes | Yes |
| Void confirmed sale | Yes (reversal) | No |
| Receive stock | Yes | Yes |
| Stock adjustment | Approve | Request |
| Record customer payment | Yes | Yes |
| Change credit limit | Yes | No |
| Create expense | Yes | Yes |
| Approve expense / budget | Yes | No |
| Open fuel shift | Yes | Yes |
| Enter opening/closing meter | Yes | Yes |
| Submit shift review | Yes | Yes |
| Approve shift (no variance) | Yes | Optional, disabled by default |
| Approve shortage or overage | Yes | No |
| Manage fuel price/tank link | Yes | No |
| View profit & loss | Yes | No |
| Manage staff, permissions, settings | Yes | No |
| View audit logs | Yes | No |

## Approval workflow (fuel)
OPEN -> PENDING_REVIEW -> CONFIRMED or RETURNED; from RETURNED, after correction submit PENDING_REVIEW again. VOIDED is a special approved reversal/cancellation state.

1. Staff enters opening meter for each active nozzle.
2. Staff records price-change boundaries and receipt references (cash/bank/credit).
3. Staff enters closing meter and submits.
4. System calculates sold liters and expected sales; verifies segments cover meter range without gaps/overlaps.
5. System reconciles expected sales vs accounted sales separately from actual cash counted.
6. Any variance requires reason and Owner approval; never hide/auto-write-off it.
7. Owner confirms; commit shift, fuel stock movements, COGS, sales and allocations atomically.
8. Confirmed shift corrections require reversal/correction, not direct SQL editing.

## Critical server rules
- Staff must not be able to approve own shortage by sending fabricated `approved_by` or changed `status` fields.
- Owner must be authenticated and checked against `fuel.variance.approve` permission at approval time.
- Database `UNIQUE` movement keys plus transactions prevent double posting.
- Close/submit validation must reject overlapping shifts on the same nozzle (service-layer lock).
- If price changes mid-shift, require a meter boundary reading at that moment, else show unverifiable sales revenue.
- A fuel credit entry must be linked to a known customer; register receivable and payment settlements correctly without double sales.
