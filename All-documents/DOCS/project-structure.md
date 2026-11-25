# SmartStock project structure

## Current Phase 1 implementation

    SmartInventory/
    ├── DOCS/
    │   ├── development-plan.md
    │   └── project-structure.md
    ├── SmartStock_Phase1/
    │   ├── app/
    │   │   ├── Controllers/
    │   │   │   ├── AuthController.php
    │   │   │   ├── OwnerDashboardController.php
    │   │   │   └── StaffDashboardController.php
    │   │   ├── Core/
    │   │   │   ├── App.php
    │   │   │   ├── Router.php
    │   │   │   ├── Session.php
    │   │   │   └── View.php
    │   │   ├── Middleware/
    │   │   │   ├── AuthMiddleware.php
    │   │   │   └── PermissionMiddleware.php
    │   │   ├── Models/
    │   │   │   ├── AuditLog.php
    │   │   │   └── User.php
    │   │   ├── Services/
    │   │   │   └── AuthService.php
    │   │   └── Views/
    │   │       ├── auth/
    │   │       ├── owner/
    │   │       ├── staff/
    │   │       └── layouts/
    │   ├── bin/
    │   │   └── create_owner.php
    │   ├── database/
    │   │   └── phase1_additions.sql
    │   ├── docs/
    │   │   └── phase1-manual-test.md
    │   ├── public/
    │   │   ├── assets/css/app.css
    │   │   └── index.php
    │   ├── routes/
    │   │   └── web.php
    │   ├── storage/
    │   ├── config.example.php
    │   └── README_KM.md
    └── SmartStock_Step_1_13_Database_Pack/

## Planned additions

Phase 2 will add Product, Category, Unit, Price, and Barcode controllers, models, services, and views. Inventory, purchasing, sales, expenses, fuel, reporting, and smart features will follow the development plan in order.
