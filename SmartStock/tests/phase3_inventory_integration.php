<?php
declare(strict_types=1);

use App\Controllers\InventoryController;
use App\Core\HttpException;
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
$suffix = bin2hex(random_bytes(5));
$ownerUsername = 'phase3_owner_' . $suffix;
$staffUsername = 'phase3_staff_' . $suffix;
$ownerPassword = 'Phase3Owner!' . $suffix;
$staffPassword = 'Phase3Staff!' . $suffix;
$prefix = 'P3 ' . $suffix;
$failures = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase3-test-sessions';
$ownerId = null;
$staffId = null;
$productIds = [];
$categoryId = null;
$receiptIds = [];

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase3Check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "PASS: {$message}\n");
        return;
    }
    $failures[] = $message;
    fwrite(STDERR, "FAIL: {$message}\n");
}

/** @param callable():mixed $operation */
function phase3ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase3Check(false, $message);
    } catch (DomainException) {
        phase3Check(true, $message);
    }
}

/** @param list<int> $receiptIds @param list<int> $productIds */
function phase3Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, array $productIds, ?int $categoryId, array $receiptIds, string $prefix): void
{
    foreach ($receiptIds as $receiptId) {
        $deleteCostEntries = $pdo->prepare(
            "DELETE FROM inventory_cost_entries
             WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')"
        );
        $deleteCostEntries->execute([$receiptId]);
        $deleteMovements = $pdo->prepare(
            "DELETE FROM stock_movements
             WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')"
        );
        $deleteMovements->execute([$receiptId]);
        $deleteItems = $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id = ?');
        $deleteItems->execute([$receiptId]);
        $deleteReceipt = $pdo->prepare('DELETE FROM inventory_receipts WHERE id = ?');
        $deleteReceipt->execute([$receiptId]);
    }
    foreach ($productIds as $productId) {
        $deleteBalances = $pdo->prepare('DELETE FROM stock_balances WHERE product_id = ?');
        $deleteBalances->execute([$productId]);
        $deleteProductCost = $pdo->prepare('DELETE FROM product_costs WHERE product_id = ?');
        $deleteProductCost->execute([$productId]);
        $deleteBarcodes = $pdo->prepare(
            'DELETE pb FROM product_barcodes pb JOIN product_units pu ON pu.id = pb.product_unit_id WHERE pu.product_id = ?'
        );
        $deleteBarcodes->execute([$productId]);
        $deletePrices = $pdo->prepare(
            'DELETE pp FROM product_prices pp JOIN product_units pu ON pu.id = pp.product_unit_id WHERE pu.product_id = ?'
        );
        $deletePrices->execute([$productId]);
        $deleteProductUnits = $pdo->prepare('DELETE FROM product_units WHERE product_id = ?');
        $deleteProductUnits->execute([$productId]);
        $deleteProduct = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $deleteProduct->execute([$productId]);
    }
    if ($categoryId !== null) {
        $deleteCategory = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $deleteCategory->execute([$categoryId]);
    }
    $deleteBrand = $pdo->prepare('DELETE FROM brands WHERE name LIKE ?');
    $deleteBrand->execute([$prefix . '%']);

    foreach (array_filter([$ownerId, $staffId]) as $userId) {
        $deleteAudit = $pdo->prepare('DELETE FROM audit_logs WHERE actor_id = ?');
        $deleteAudit->execute([$userId]);
        $deleteSessions = $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?');
        $deleteSessions->execute([$userId]);
        $deleteRoles = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $deleteRoles->execute([$userId]);
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $deleteUser->execute([$userId]);
    }
}

/** @return array{0: string, 1: string, 2: int, 3: int} */
function phase3RunConcurrentConfirmation(int $ownerId, int $receiptId): array
{
    $worker = __DIR__ . '/phase3_confirm_worker.php';
    $command = [PHP_BINARY, $worker, (string) $ownerId, (string) $receiptId];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $first = proc_open($command, $descriptors, $firstPipes, dirname(__DIR__));
    $second = proc_open($command, $descriptors, $secondPipes, dirname(__DIR__));
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Unable to start concurrent receipt confirmation workers.');
    }
    fclose($firstPipes[0]);
    fclose($secondPipes[0]);
    $firstOut = trim((string) stream_get_contents($firstPipes[1]));
    $firstErr = trim((string) stream_get_contents($firstPipes[2]));
    $secondOut = trim((string) stream_get_contents($secondPipes[1]));
    $secondErr = trim((string) stream_get_contents($secondPipes[2]));
    foreach ([$firstPipes[1], $firstPipes[2], $secondPipes[1], $secondPipes[2]] as $pipe) {
        fclose($pipe);
    }
    $firstExit = proc_close($first);
    $secondExit = proc_close($second);
    if ($firstErr !== '' || $secondErr !== '') {
        throw new RuntimeException(trim($firstErr . ' ' . $secondErr));
    }
    return [$firstOut, $secondOut, $firstExit, $secondExit];
}

try {
    $phase2Column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'categories' AND column_name = 'parent_key'"
    )->fetchColumn();
    phase3Check((int) $phase2Column === 1, 'Phase 2 migration state is present in the disposable test database');
    $phase3Table = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'inventory_receipts'"
    )->fetchColumn();
    phase3Check((int) $phase3Table === 1, 'Phase 3 receipt migration is present in the disposable test database');

    Session::start();
    phase3Check(
        $app->auth()->createInitialOwner($ownerUsername, 'Phase 3 Test Owner', $ownerPassword) === null,
        'initial Owner can be created in the disposable test database',
    );
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) {
        throw new RuntimeException('Owner creation failed.');
    }
    phase3Check(
        $app->auth()->createStaff($ownerId, $staffUsername, 'Phase 3 Test Staff', $staffPassword) === null,
        'Owner can create Staff for receipt authorization checks',
    );
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) {
        throw new RuntimeException('Staff creation failed.');
    }

    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN' AND is_active = 1 LIMIT 1")->fetchColumn();
    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    $caseUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'case' AND status = 'active' LIMIT 1")->fetchColumn();
    phase3Check($locationId > 0 && $pieceUnitId > 0 && $caseUnitId > 0, 'seeded active location and units are available');

    $products = $app->productDomain();
    $categoryId = $products->createCategory($ownerId, $prefix . ' Inventory', null);
    $productId = $products->createProduct($ownerId, [
        'sku' => 'P3-' . strtoupper($suffix),
        'name' => $prefix . ' Stock Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $productId;
    $caseProductUnitId = $products->addProductUnit($ownerId, $productId, $caseUnitId, '12.000000');
    phase3Check($caseProductUnitId > 0, 'a product-specific receipt conversion can be configured');

    $inventory = $app->inventory();
    $draftReceiptId = $inventory->createDraftReceipt($staffId, [
        'receipt_type' => 'opening',
        'reference_no' => 'P3-DRAFT-' . strtoupper($suffix),
        'location_id' => $locationId,
        'items' => [[
            'product_unit_id' => $caseProductUnitId,
            'quantity' => '2',
        ]],
    ]);
    $receiptIds[] = $draftReceiptId;
    $draftItems = $app->inventoryReceipts()->items($draftReceiptId);
    phase3Check(
        count($draftItems) === 1 && $draftItems[0]['qty_base'] === '24.000' && $draftItems[0]['cost_per_base_khr'] === null,
        'Staff can create an unposted quantity-only draft with decimal-safe unit conversion',
    );
    $draftReconciliation = $inventory->reconcileProductLocation($ownerId, $productId, $locationId);
    phase3Check($draftReconciliation['reconciled'] && $draftReconciliation['balance'] === '0.000', 'draft receipts do not affect ledger or balance');
    phase3ExpectDomain(
        fn () => $inventory->createDraftReceipt($staffId, [
            'receipt_type' => 'opening',
            'reference_no' => 'P3-STAFF-COST-' . strtoupper($suffix),
            'location_id' => $locationId,
            'items' => [[
                'product_unit_id' => $caseProductUnitId,
                'quantity' => '1',
                'cost_per_base_khr' => '2.500000',
            ]],
        ]),
        'Staff cannot submit restricted receipt cost data',
    );
    phase3ExpectDomain(
        fn () => $inventory->setReceiptCosts($staffId, $draftReceiptId, [[
            'receipt_item_id' => (int) $draftItems[0]['id'],
            'cost_per_base_khr' => '2.500000',
        ]]),
        'Staff cannot access or post receipt valuation details',
    );
    $inventory->setReceiptCosts($ownerId, $draftReceiptId, [[
        'receipt_item_id' => (int) $draftItems[0]['id'],
        'cost_per_base_khr' => '2.500000',
    ]]);
    $confirmation = $inventory->confirmReceipt($ownerId, $draftReceiptId);
    phase3Check($confirmation['confirmed'], 'Owner confirmation posts a valued receipt');
    $reconciled = $inventory->reconcileProductLocation($ownerId, $productId, $locationId);
    phase3Check(
        $reconciled['reconciled'] && $reconciled['balance'] === '24.000' && $reconciled['ledger'] === '24.000',
        'confirmed ledger movements reconcile exactly to the materialized balance',
    );
    $secondConfirmation = $inventory->confirmReceipt($ownerId, $draftReceiptId);
    $movementCount = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'inventory_receipt' AND source_id = ?");
    $movementCount->execute([$draftReceiptId]);
    phase3Check(
        !$secondConfirmation['confirmed'] && (int) $movementCount->fetchColumn() === 1,
        'duplicate confirmation is idempotent and cannot create a second movement',
    );

    phase3ExpectDomain(
        fn () => $inventory->createDraftReceipt($ownerId, [
            'receipt_type' => 'opening',
            'reference_no' => 'P3-ZERO-' . strtoupper($suffix),
            'location_id' => $locationId,
            'items' => [['product_unit_id' => $caseProductUnitId, 'quantity' => '0', 'cost_per_base_khr' => '1']],
        ]),
        'zero receipt quantities are rejected',
    );
    phase3ExpectDomain(
        fn () => $inventory->createDraftReceipt($ownerId, [
            'receipt_type' => 'purchase',
            'reference_no' => 'P3-BAD-LOCATION-' . strtoupper($suffix),
            'location_id' => 999999999,
            'items' => [['product_unit_id' => $caseProductUnitId, 'quantity' => '1', 'cost_per_base_khr' => '1']],
        ]),
        'invalid stock locations are rejected',
    );
    $rollbackReference = 'P3-ROLLBACK-' . strtoupper($suffix);
    phase3ExpectDomain(
        fn () => $inventory->createDraftReceipt($ownerId, [
            'receipt_type' => 'opening',
            'reference_no' => $rollbackReference,
            'location_id' => $locationId,
            'items' => [
                ['product_unit_id' => $caseProductUnitId, 'quantity' => '1', 'cost_per_base_khr' => '1'],
                ['product_unit_id' => 999999999, 'quantity' => '1', 'cost_per_base_khr' => '1'],
            ],
        ]),
        'invalid multi-line receipts are rejected',
    );
    $referenceCount = $pdo->prepare('SELECT COUNT(*) FROM inventory_receipts WHERE reference_no = ?');
    $referenceCount->execute([$rollbackReference]);
    phase3Check((int) $referenceCount->fetchColumn() === 0, 'failed receipt creation rolls back every document row');

    $products->archiveProduct($ownerId, $productId);
    phase3ExpectDomain(
        fn () => $inventory->createDraftReceipt($ownerId, [
            'receipt_type' => 'opening',
            'reference_no' => 'P3-ARCHIVED-' . strtoupper($suffix),
            'location_id' => $locationId,
            'items' => [['product_unit_id' => $caseProductUnitId, 'quantity' => '1', 'cost_per_base_khr' => '1']],
        ]),
        'archived products cannot be received',
    );

    Session::clearAuthentication();
    phase3Check($app->auth()->attemptLogin($ownerUsername, $ownerPassword) !== null, 'Owner session is available for receipt CSRF testing');
    $_POST = ['_csrf' => 'invalid-token'];
    try {
        (new InventoryController($app))->createReceipt();
        phase3Check(false, 'inventory receipt POST rejects invalid CSRF tokens');
    } catch (HttpException $exception) {
        phase3Check($exception->statusCode() === 419, 'inventory receipt POST rejects invalid CSRF tokens');
    }
    $_POST = [];

    // Restore a different active product for an actual two-process confirmation race.
    $concurrentProductId = $products->createProduct($ownerId, [
        'sku' => 'P3C-' . strtoupper($suffix),
        'name' => $prefix . ' Concurrent Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $concurrentProductId;
    $concurrentBaseUnit = $app->products()->findProductUnitByProductAndUnit($concurrentProductId, $pieceUnitId);
    if ($concurrentBaseUnit === null) {
        throw new RuntimeException('Concurrent product base unit was not created.');
    }
    $concurrentReceiptId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase',
        'reference_no' => 'P3-CONCURRENT-' . strtoupper($suffix),
        'location_id' => $locationId,
        'items' => [[
            'product_unit_id' => (int) $concurrentBaseUnit['id'],
            'quantity' => '12',
            'cost_per_base_khr' => '3.000000',
        ]],
    ]);
    $receiptIds[] = $concurrentReceiptId;
    [$firstOut, $secondOut, $firstExit, $secondExit] = phase3RunConcurrentConfirmation($ownerId, $concurrentReceiptId);
    $concurrentResult = [$firstOut, $secondOut];
    sort($concurrentResult);
    $concurrentReconciliation = $inventory->reconcileProductLocation($ownerId, $concurrentProductId, $locationId);
    phase3Check(
        $firstExit === 0 && $secondExit === 0
        && $concurrentResult === ['already-confirmed', 'confirmed']
        && $concurrentReconciliation['reconciled'] && $concurrentReconciliation['balance'] === '12.000',
        'two concurrent confirmation processes produce exactly one posting',
    );

    $inventory->reverseReceipt($ownerId, $draftReceiptId, 'Phase 3 integration correction');
    $afterReversal = $inventory->reconcileProductLocation($ownerId, $productId, $locationId);
    $reverseMovementCount = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'inventory_receipt_reversal' AND source_id = ?");
    $reverseMovementCount->execute([$draftReceiptId]);
    phase3Check(
        $afterReversal['reconciled'] && $afterReversal['balance'] === '0.000' && (int) $reverseMovementCount->fetchColumn() === 1,
        'receipt reversal posts an offsetting immutable movement and restores reconciliation',
    );
    phase3ExpectDomain(
        fn () => $inventory->reverseReceipt($ownerId, $draftReceiptId, 'Duplicate reversal'),
        'a confirmed receipt cannot be reversed twice',
    );
} catch (Throwable $exception) {
    $failures[] = 'unexpected exception: ' . $exception->getMessage();
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception . PHP_EOL);
} finally {
    $_POST = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        Session::destroy();
    }
    phase3Cleanup($pdo, $ownerId, $staffId, $productIds, $categoryId, $receiptIds, $prefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 3 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 3 inventory integration checks passed.' . PHP_EOL);
