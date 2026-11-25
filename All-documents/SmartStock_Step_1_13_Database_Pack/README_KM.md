# SmartStock — Step 1.13 (Database & Access Control)

## បានបញ្ជាក់ពីម្ចាស់គម្រោង
- ម្ចាស់អាជីវកម្មតែមួយ និងទីតាំងអាជីវកម្មតែមួយ
- Owner + Staff; Owner មានសិទ្ធិអនុម័តប្រតិបត្តិការសំខាន់ៗ
- ទីតាំងផ្ទុកស្តុកច្រើន ប៉ុន្តែស្តុកសរុបទាំងអាជីវកម្មតែមួយ
- Product Unit Conversion, Flexible Pricing, Credit/Partial Payment
- Cash KHR/USD, Bank Transfer/KHQR, Split Payment
- Smart Expense Management និង Moving Weighted Average Cost
- Fuel: 1 pump, 2 nozzles, Gasoline និង Diesel; ចំនួន Fuel Tanks មិនទាន់ដឹង
- Fuel Shift: flexible; Shift-Based Meter Reading; Owner Review & Approval

## ឯកសារ
1. `smartstock_schema.sql` — គម្រោង MySQL 8 Database Schema មាន 63 តារាង
2. `smartstock_seed_reference.sql` — Role/Permission, Units, Locations និងការកំណត់ដំបូង (មិនមាន Account Password)
3. `PERMISSIONS_KM.md` — Authentication, Permission & Approval Matrix
4. `DATABASE_GUIDE_KM.md` — សេចក្តីពន្យល់ Domain, ERD និង Transaction Rules
5. `OPEN_DECISIONS_KM.md` — ព័ត៌មានដែលនៅមិនទាន់បញ្ជាក់

## How to import (MySQL 8)
```bash
mysql -u root -p < smartstock_schema.sql
mysql -u root -p smartstock < smartstock_seed_reference.sql
```

ឬប្រើ phpMyAdmin: import `smartstock_schema.sql` មុន រួច import `smartstock_seed_reference.sql`។ Import តែចូល Database ទទេ។

## ស្ថានភាព
នេះជា **Draft v1 schema** មិនមែន Database ដែលធ្វើតេស្តលើ MySQL Server ពិតប្រាកដរួចហើយទេ។ ត្រូវធ្វើ migration test និង application integration test។ មិនមាន triggers ឬ cron jobs។ មិនត្រូវ Deploy Production ដោយគ្មាន testing, backup, secure login និង authorization។

## ការប្រុងប្រយ័ត្ន
- មិនត្រូវប្រាប់ PHP អំពី password plaintext: បង្កើតម្ចាស់ដោយ `password_hash($password, PASSWORD_ARGON2ID)` ឬ bcrypt ហើយផ្ទៀងផ្ទាត់ដោយ `password_verify()`។
- ត្រូវ CSRF protection, HTTPS, secure session cookies, rate-limit login, PDO prepared statements និង role checks នៅ Backend។
- Stock Balance និង Cost Ledger ត្រូវសម្របសម្រួលដោយ `SELECT ... FOR UPDATE` នៅក្នុង MySQL Transaction ហើយបង្កើត Stock Movement តែម្តង។
- Permission in `seed_reference.sql` គ្រាន់តែជាសិទ្ធិលំនាំដើម។ Staff permissions ត្រូវ Owner កំណត់ពិតប្រាកដនៅពេលប្រើប្រាស់។
- Fuel reconciliation: payment / credit allocations និង cash count ត្រូវផ្ទៀងផ្ទាត់ដោយឡែក; meter difference មិនមែនជាប្រាក់ Cash។
- `fuel_tanks` និង `fuel_nozzles.tank_id` មិនត្រូវសន្មតពីរូបភាព។ ត្រូវកំណត់ការតភ្ជាប់ជាក់ស្តែងមុនបិទវេនពិត។
