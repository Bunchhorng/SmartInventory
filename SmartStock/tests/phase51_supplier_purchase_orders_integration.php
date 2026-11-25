<?php
declare(strict_types=1);

use App\Core\Session;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/tests/TestDatabase.php';
requireTestDatabase();

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $app->pdo();
$suffix = strtoupper(bin2hex(random_bytes(5)));
$prefix = 'P51-' . $suffix;
$ownerUsername = 'phase51_owner_' . strtolower($suffix);
$staffUsername = 'phase51_staff_' . strtolower($suffix);
$ownerPassword = 'Phase51Owner!' . strtolower($suffix);
$staffPassword = 'Phase51Staff!' . strtolower($suffix);
$failures = [];
$ownerId = null;
$staffId = null;
$categoryId = null;
$productId = null;
$supplierIds = [];
$purchaseIds = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase51-test-sessions';

function phase51Check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "PASS: {$message}\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: {$message}\n");
}

/** @param callable(): mixed $operation */
function phase51ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase51Check(false, $message);
    } catch (DomainException) {
        phase51Check(true, $message);
    }
}

/** @param list<int> $purchaseIds @param list<int> $supplierIds */
function phase51Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $categoryId, ?int $productId, array $purchaseIds, array $supplierIds): void
{
    foreach ($purchaseIds as $purchaseId) {
        $statement = $pdo->prepare('DELETE FROM purchase_order_approval_snapshots WHERE purchase_id = ?');
        $statement->execute([$purchaseId]);
        $statement = $pdo->prepare('DELETE FROM goods_receipt_items WHERE receipt_id IN (SELECT id FROM goods_receipts WHERE purchase_id = ?)');
        $statement->execute([$purchaseId]);
        $statement = $pdo->prepare('DELETE FROM goods_receipts WHERE purchase_id = ?');
        $statement->execute([$purchaseId]);
        $statement = $pdo->prepare('DELETE FROM payables WHERE purchase_id = ?');
        $statement->execute([$purchaseId]);
        $statement = $pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = ?');
        $statement->execute([$purchaseId]);
        $statement = $pdo->prepare('DELETE FROM purchases WHERE id = ?');
        $statement->execute([$purchaseId]);
    }
    foreach ($supplierIds as $supplierId) {
        $statement = $pdo->prepare('DELETE FROM suppliers WHERE id = ?');
        $statement->execute([$supplierId]);
    }
    if ($productId !== null) {
        $statement = $pdo->prepare('DELETE FROM product_units WHERE product_id = ?');
        $statement->execute([$productId]);
        $statement = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $statement->execute([$productId]);
    }
    if ($categoryId !== null) {
        $statement = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $statement->execute([$categoryId]);
    }
    foreach (array_filter([$ownerId, $staffId]) as $userId) {
        $statement = $pdo->prepare('DELETE FROM audit_logs WHERE actor_id = ?');
        $statement->execute([$userId]);
        $statement = $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?');
        $statement->execute([$userId]);
        $statement = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $statement->execute([$userId]);
        $statement = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $statement->execute([$userId]);
    }
}

try {
    $migrationTables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name = 'purchase_order_approval_snapshots'"
    )->fetchColumn();
    $migrationColumns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND
         ((table_name = 'suppliers' AND column_name IN ('supplier_code','contact_person','email','payment_terms_days'))
          OR (table_name = 'purchases' AND column_name IN ('purchase_key','expected_delivery_date','estimated_total','approved_by'))
          OR (table_name = 'purchase_items' AND column_name IN ('discount_percent','qty_base_snapshot','unit_to_base_factor_snapshot','line_total')))"
    )->fetchColumn();
    phase51Check($migrationTables === 1 && $migrationColumns === 12, 'Phase 5.1 supplier and purchase-order migration is present in the disposable test database');
    if ($migrationTables !== 1 || $migrationColumns !== 12) {
        exit(2);
    }
    phase51Check($app->users()->countOwners() === 0, 'disposable test database has no existing Owner account');
    if ($app->users()->countOwners() !== 0) {
        exit(2);
    }

    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Unable to create the Phase 5.1 integration-test session directory.');
    }
    session_save_path($sessionDirectory);
    Session::start();
    phase51Check(!Session::verifyCsrf('invalid-token'), 'invalid CSRF token is rejected before purchase mutations');

    $app->auth()->createInitialOwner($ownerUsername, 'Phase 5.1 Test Owner', $ownerPassword);
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) {
        throw new RuntimeException('Unable to create Phase 5.1 Owner.');
    }
    $app->auth()->createStaff($ownerId, $staffUsername, 'Phase 5.1 Test Staff', $staffPassword);
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) {
        throw new RuntimeException('Unable to create Phase 5.1 Staff user.');
    }
    phase51Check(
        $app->users()->hasPermission($staffId, 'suppliers.view')
        && $app->users()->hasPermission($staffId, 'purchases.view')
        && $app->users()->hasPermission($staffId, 'purchases.request')
        && !$app->users()->hasPermission($staffId, 'purchases.approve'),
        'new Staff receives viewing/request permissions but no purchasing approval permission',
    );

    $supplierService = $app->supplierService();
    $purchasing = $app->purchasingService();
    $supplierCode = 'SUP-' . $suffix;
    $supplierId = $supplierService->create($ownerId, [
        'supplier_code' => $supplierCode,
        'name' => 'Phase 5.1 Supplier',
        'contact_person' => 'Supplier Contact',
        'phone' => '+855 12 345 678',
        'email' => 'supplier-' . strtolower($suffix) . '@example.test',
        'address' => 'Test purchasing address',
        'payment_terms_days' => '30',
    ]);
    $supplierIds[] = $supplierId;
    $supplierService->update($ownerId, $supplierId, [
        'supplier_code' => $supplierCode,
        'name' => 'Phase 5.1 Updated Supplier',
        'contact_person' => 'Updated Contact',
        'phone' => '+855 12 345 679',
        'email' => 'updated-' . strtolower($suffix) . '@example.test',
        'address' => 'Updated purchasing address',
        'payment_terms_days' => '45',
    ]);
    $supplier = $app->suppliers()->find($supplierId);
    phase51Check(
        is_array($supplier) && $supplier['name'] === 'Phase 5.1 Updated Supplier'
        && (int) $supplier['payment_terms_days'] === 45,
        'Owner can create and update supplier master data',
    );
    phase51ExpectDomain(fn () => $supplierService->create($ownerId, [
        'supplier_code' => $supplierCode, 'name' => 'Duplicate supplier', 'contact_person' => 'Duplicate contact',
        'phone' => '+855 12 111 222', 'address' => 'Duplicate address', 'payment_terms_days' => '0',
    ]), 'duplicate supplier code is rejected');
    $staffSuppliers = $supplierService->listForViewer($staffId, $supplierCode, 'active', 1, 25);
    $staffSupplier = $staffSuppliers['items'][0] ?? [];
    phase51Check(
        !array_key_exists('email', $staffSupplier) && !array_key_exists('payment_terms_days', $staffSupplier),
        'Staff supplier response omits confidential payment-term and email fields',
    );

    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    $caseUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'case' AND status = 'active' LIMIT 1")->fetchColumn();
    phase51Check($pieceUnitId > 0 && $caseUnitId > 0, 'seeded product units are available for purchase-order conversion tests');
    if ($pieceUnitId < 1 || $caseUnitId < 1) {
        throw new RuntimeException('Required seeded units are missing.');
    }
    $categoryId = $app->productDomain()->createCategory($ownerId, $prefix . ' Category', null);
    $productId = $app->productDomain()->createProduct($ownerId, [
        'sku' => $prefix,
        'name' => 'Phase 5.1 Purchase Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $baseProductUnit = $app->products()->findProductUnitByProductAndUnit($productId, $pieceUnitId);
    if ($baseProductUnit === null) {
        throw new RuntimeException('Base product unit was not created.');
    }
    $caseProductUnitId = $app->productDomain()->addProductUnit($ownerId, $productId, $caseUnitId, '12.000000');
    $stockMovementBefore = (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
    $payableBefore = (int) $pdo->query('SELECT COUNT(*) FROM payables')->fetchColumn();

    $staffDraft = $purchasing->createDraft($staffId, [
        'purchase_key' => $prefix . '-STAFF-DRAFT-01',
        'supplier_id' => $supplierId,
        'currency' => 'KHR',
        'exchange_rate_to_khr' => '1.000000',
        'expected_delivery_date' => '2030-01-15',
        'notes' => 'Staff requested stock',
        'items' => [[
            'product_unit_id' => $caseProductUnitId,
            'ordered_qty' => '2',
        ]],
    ]);
    $purchaseIds[] = $staffDraft['purchase_id'];
    $staffDraftReplay = $purchasing->createDraft($staffId, [
        'purchase_key' => $prefix . '-STAFF-DRAFT-01', 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '2']],
    ]);
    $staffDraftItem = $app->purchaseOrders()->items($staffDraft['purchase_id'])[0] ?? null;
    phase51Check(
        $staffDraft['created'] && !$staffDraftReplay['created']
        && is_array($staffDraftItem) && $staffDraftItem['qty_base_snapshot'] === '24.000000'
        && $staffDraftItem['unit_purchase_price'] === null,
        'Staff draft is idempotent, preserves a 12-to-1 conversion snapshot, and has no cost',
    );
    phase51ExpectDomain(fn () => $purchasing->createDraft($staffId, [
        'purchase_key' => $prefix . '-STAFF-COST-01', 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '1', 'unit_purchase_price' => '1000']],
    ]), 'Staff cannot submit purchasing prices');

    $purchasing->updateDraft($ownerId, $staffDraft['purchase_id'], [
        'supplier_id' => $supplierId,
        'currency' => 'KHR',
        'exchange_rate_to_khr' => '1.000000',
        'expected_delivery_date' => '2030-01-15',
        'notes' => 'Owner-priced multi-line request',
        'items' => [
            ['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '2', 'unit_purchase_price' => '2500.0000', 'discount_percent' => '10'],
            ['product_unit_id' => (int) $baseProductUnit['id'], 'ordered_qty' => '3', 'unit_purchase_price' => '100.0000', 'discount_percent' => '0'],
        ],
    ]);
    $pricedItems = $app->purchaseOrders()->items($staffDraft['purchase_id']);
    $draftHeader = $app->purchaseOrders()->find($staffDraft['purchase_id']);
    phase51Check(
        count($pricedItems) === 2 && $pricedItems[0]['line_total'] === '4500.0000'
        && $pricedItems[1]['line_total'] === '300.0000'
        && is_array($draftHeader) && $draftHeader['estimated_total'] === '4800.0000',
        'Owner can price a multi-item draft and persist fixed-point discounts and estimated totals',
    );
    $submitted = $purchasing->submit($staffId, $staffDraft['purchase_id']);
    phase51ExpectDomain(fn () => $purchasing->approve($staffId, $staffDraft['purchase_id']), 'Staff cannot approve a submitted purchase order');
    $approved = $purchasing->approve($ownerId, $staffDraft['purchase_id']);
    $approvedAgain = $purchasing->approve($ownerId, $staffDraft['purchase_id']);
    $snapshot = $pdo->prepare('SELECT supplier_code_snapshot, estimated_total_snapshot FROM purchase_order_approval_snapshots WHERE purchase_id = ?');
    $snapshot->execute([$staffDraft['purchase_id']]);
    $approvalSnapshot = $snapshot->fetch();
    $staffOrder = $purchasing->forViewer($staffId, $staffDraft['purchase_id']);
    phase51Check(
        $submitted['submitted'] && $approved['approved'] && !$approvedAgain['approved']
        && is_array($approvalSnapshot) && $approvalSnapshot['supplier_code_snapshot'] === $supplierCode
        && $approvalSnapshot['estimated_total_snapshot'] === '4800.0000'
        && !array_key_exists('estimated_total', $staffOrder)
        && !array_key_exists('unit_purchase_price', $staffOrder['items'][0]),
        'Owner approval creates one immutable snapshot and Staff never receives purchase costs',
    );
    phase51Check(
        (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === $stockMovementBefore
        && (int) $pdo->query('SELECT COUNT(*) FROM payables')->fetchColumn() === $payableBefore,
        'drafting, submitting, and approving purchase orders do not post stock or supplier payables',
    );
    phase51ExpectDomain(fn () => $purchasing->updateDraft($ownerId, $staffDraft['purchase_id'], [
        'supplier_id' => $supplierId, 'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '2', 'unit_purchase_price' => '1']],
    ]), 'prices and lines cannot change after approval');
    phase51ExpectDomain(fn () => $purchasing->submit($staffId, $staffDraft['purchase_id']), 'invalid status transition from approved back to submitted is rejected');

    $rejected = $purchasing->createDraft($ownerId, [
        'purchase_key' => $prefix . '-REJECT-01', 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '1', 'unit_purchase_price' => '50']],
    ]);
    $purchaseIds[] = $rejected['purchase_id'];
    $purchasing->submit($ownerId, $rejected['purchase_id']);
    $purchasing->reject($ownerId, $rejected['purchase_id'], 'Supplier quotation needs correction');
    phase51ExpectDomain(fn () => $purchasing->approve($ownerId, $rejected['purchase_id']), 'rejected order cannot be approved without a new draft workflow');

    $cancelled = $purchasing->createDraft($ownerId, [
        'purchase_key' => $prefix . '-CANCEL-01', 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '1', 'unit_purchase_price' => '50']],
    ]);
    $purchaseIds[] = $cancelled['purchase_id'];
    $purchasing->cancel($ownerId, $cancelled['purchase_id'], 'Supplier request is no longer needed');
    $cancelledHeader = $app->purchaseOrders()->find($cancelled['purchase_id']);
    phase51Check(is_array($cancelledHeader) && $cancelledHeader['status'] === 'cancelled', 'Owner can safely cancel an unreceived draft order');

    $receiptGuard = $purchasing->createDraft($ownerId, [
        'purchase_key' => $prefix . '-RECEIPT-GUARD-01', 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '1', 'unit_purchase_price' => '50']],
    ]);
    $purchaseIds[] = $receiptGuard['purchase_id'];
    $purchasing->submit($ownerId, $receiptGuard['purchase_id']);
    $purchasing->approve($ownerId, $receiptGuard['purchase_id']);
    $receipt = $pdo->prepare("INSERT INTO goods_receipts (receipt_no, purchase_id, status) VALUES (?, ?, 'draft')");
    $receipt->execute([$prefix . '-GR-01', $receiptGuard['purchase_id']]);
    phase51ExpectDomain(
        fn () => $purchasing->cancel($ownerId, $receiptGuard['purchase_id'], 'Attempting unsafe cancellation'),
        'cancellation is blocked once a goods-receipt reference exists',
    );

    $rollbackKey = $prefix . '-ROLLBACK-01';
    phase51ExpectDomain(fn () => $purchasing->createDraft($ownerId, [
        'purchase_key' => $rollbackKey, 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [
            ['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '1', 'unit_purchase_price' => '10'],
            ['product_unit_id' => 999999999, 'ordered_qty' => '1', 'unit_purchase_price' => '10'],
        ],
    ]), 'invalid multi-item draft is rejected');
    $rollbackLookup = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE purchase_key = ?');
    $rollbackLookup->execute([$rollbackKey]);
    phase51Check((int) $rollbackLookup->fetchColumn() === 0, 'failed multi-item draft rolls back without creating a purchase order');

    $supplierService->setStatus($ownerId, $supplierId, 'inactive');
    phase51ExpectDomain(fn () => $purchasing->createDraft($ownerId, [
        'purchase_key' => $prefix . '-INACTIVE-01', 'supplier_id' => $supplierId,
        'currency' => 'KHR', 'exchange_rate_to_khr' => '1.000000',
        'items' => [['product_unit_id' => $caseProductUnitId, 'ordered_qty' => '1', 'unit_purchase_price' => '10']],
    ]), 'deactivated supplier cannot be used for a new purchase order');
    $supplierService->setStatus($ownerId, $supplierId, 'active');
    phase51Check($app->suppliers()->find($supplierId)['status'] === 'active', 'Owner can reactivate a supplier without losing historical references');
    phase51ExpectDomain(
        fn () => $supplierService->delete($ownerId, $supplierId),
        'supplier deletion is prevented so referenced transaction history is retained',
    );
} finally {
    phase51Cleanup($pdo, $ownerId, $staffId, $categoryId, $productId, $purchaseIds, $supplierIds);
}

if ($failures !== []) {
    fwrite(STDERR, "\nPhase 5.1 integration failures:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nPhase 5.1 supplier/purchase-order integration checks passed.\n");
