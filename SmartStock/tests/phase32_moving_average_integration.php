<?php
declare(strict_types=1);

use App\Support\Decimal;

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
$prefix = 'P32 ' . $suffix;
$ownerUsername = 'phase32_owner_' . $suffix;
$staffUsername = 'phase32_staff_' . $suffix;
$ownerPassword = 'Phase32Owner!' . $suffix;
$staffPassword = 'Phase32Staff!' . $suffix;
$failures = [];
$ownerId = null;
$staffId = null;
$categoryId = null;
$productIds = [];
$receiptIds = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase32-test-sessions';

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase32Check(bool $condition, string $message): void
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
function phase32ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase32Check(false, $message);
    } catch (DomainException) {
        phase32Check(true, $message);
    }
}

/** @return array{0: string, 1: string, 2: int, 3: int} */
function phase32RunConcurrentConfirmation(int $ownerId, int $firstReceiptId, int $secondReceiptId): array
{
    $worker = __DIR__ . '/phase32_confirm_worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $first = proc_open([PHP_BINARY, $worker, (string) $ownerId, (string) $firstReceiptId], $descriptors, $firstPipes, dirname(__DIR__));
    $second = proc_open([PHP_BINARY, $worker, (string) $ownerId, (string) $secondReceiptId], $descriptors, $secondPipes, dirname(__DIR__));
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Unable to start concurrent valuation workers.');
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

/** @param list<int> $receiptIds @param list<int> $productIds */
function phase32Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $categoryId, array $receiptIds, array $productIds, string $prefix): void
{
    foreach ($receiptIds as $receiptId) {
        $deleteEntries = $pdo->prepare(
            "DELETE FROM inventory_cost_entries
             WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')"
        );
        $deleteEntries->execute([$receiptId]);
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
        $deleteEntries = $pdo->prepare('DELETE FROM inventory_cost_entries WHERE product_id = ?');
        $deleteEntries->execute([$productId]);
        $deleteBalances = $pdo->prepare('DELETE FROM stock_balances WHERE product_id = ?');
        $deleteBalances->execute([$productId]);
        $deleteProductCost = $pdo->prepare('DELETE FROM product_costs WHERE product_id = ?');
        $deleteProductCost->execute([$productId]);
        $deletePrices = $pdo->prepare(
            'DELETE pp FROM product_prices pp JOIN product_units pu ON pu.id = pp.product_unit_id WHERE pu.product_id = ?'
        );
        $deletePrices->execute([$productId]);
        $deleteBarcodes = $pdo->prepare(
            'DELETE pb FROM product_barcodes pb JOIN product_units pu ON pu.id = pb.product_unit_id WHERE pu.product_id = ?'
        );
        $deleteBarcodes->execute([$productId]);
        $deleteUnits = $pdo->prepare('DELETE FROM product_units WHERE product_id = ?');
        $deleteUnits->execute([$productId]);
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

try {
    $migrationColumns = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'inventory_cost_entries'
           AND column_name IN ('stock_movement_id', 'avg_unit_cost_before_khr', 'avg_unit_cost_after_khr', 'costing_type', 'created_by')"
    )->fetchColumn();
    phase32Check((int) $migrationColumns === 5, 'Phase 3.2 valuation migration is present in the disposable test database');
    phase32Check(
        Decimal::multiplyRounded('0.001', '0.050000', 4) === '0.0001'
        && Decimal::divide('1.0000', '6.000', 6) === '0.166667',
        'valuation math applies documented half-up rounding without binary floating point',
    );
    phase32ExpectDomain(
        fn () => Decimal::divide('1.0000', '0.000', 6),
        'valuation math rejects zero-quantity division',
    );

    phase32Check(
        $app->auth()->createInitialOwner($ownerUsername, 'Phase 3.2 Test Owner', $ownerPassword) === null,
        'initial Owner can be created in the disposable test database',
    );
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) {
        throw new RuntimeException('Owner creation failed.');
    }
    phase32Check(
        $app->auth()->createStaff($ownerId, $staffUsername, 'Phase 3.2 Test Staff', $staffPassword) === null,
        'Owner can create Staff for valuation authorization checks',
    );
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) {
        throw new RuntimeException('Staff creation failed.');
    }

    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN' AND is_active = 1 LIMIT 1")->fetchColumn();
    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    $caseUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'case' AND status = 'active' LIMIT 1")->fetchColumn();
    phase32Check($locationId > 0 && $pieceUnitId > 0 && $caseUnitId > 0, 'seeded location and unit references are available');

    $products = $app->productDomain();
    $inventory = $app->inventory();
    $categoryId = $products->createCategory($ownerId, $prefix . ' Costing', null);

    $primaryProductId = $products->createProduct($ownerId, [
        'sku' => 'P32-' . strtoupper($suffix),
        'name' => $prefix . ' Base Conversion Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $primaryProductId;
    $primaryCaseUnitId = $products->addProductUnit($ownerId, $primaryProductId, $caseUnitId, '12.000000');
    $primaryBaseUnit = $app->products()->findProductUnitByProductAndUnit($primaryProductId, $pieceUnitId);
    if ($primaryBaseUnit === null) {
        throw new RuntimeException('Primary product base unit was not created.');
    }

    $staffDraftId = $inventory->createDraftReceipt($staffId, [
        'receipt_type' => 'opening',
        'reference_no' => 'P32-STAFF-' . strtoupper($suffix),
        'location_id' => $locationId,
        'items' => [['product_unit_id' => $primaryCaseUnitId, 'quantity' => '2']],
    ]);
    $receiptIds[] = $staffDraftId;
    $staffDraftItems = $app->inventoryReceipts()->items($staffDraftId);
    phase32Check(
        count($staffDraftItems) === 1 && $staffDraftItems[0]['qty_base'] === '24.000' && $staffDraftItems[0]['cost_per_base_khr'] === null,
        'Staff can create a quantity-only base-unit draft without valuation data',
    );
    phase32ExpectDomain(
        fn () => $inventory->setReceiptCosts($staffId, $staffDraftId, [[
            'receipt_item_id' => (int) $staffDraftItems[0]['id'],
            'cost_per_base_khr' => '2.500000',
        ]]),
        'Staff cannot submit purchase cost details',
    );
    phase32ExpectDomain(
        fn () => $inventory->reconcileProductValuation($staffId, $primaryProductId),
        'Staff cannot access inventory valuation reconciliation',
    );
    $staffRows = $inventory->listReceiptsForViewer($staffId, 'all', 1, 10);
    phase32Check(
        $staffRows !== [] && !array_key_exists('cost_per_base_khr', $staffRows[0]) && !array_key_exists('inventory_value_khr', $staffRows[0]),
        'Staff receipt responses do not leak cost or valuation fields',
    );
    $inventory->setReceiptCosts($ownerId, $staffDraftId, [[
        'receipt_item_id' => (int) $staffDraftItems[0]['id'],
        'cost_per_base_khr' => '2.500000',
    ]]);
    $firstPosting = $inventory->confirmReceipt($ownerId, $staffDraftId);
    $firstReconciliation = $inventory->reconcileProductValuation($ownerId, $primaryProductId);
    phase32Check(
        $firstPosting['confirmed']
        && $firstReconciliation['reconciled']
        && $firstReconciliation['balance_qty'] === '24.000'
        && $firstReconciliation['cost_qty'] === '24.000'
        && $firstReconciliation['cost_value'] === '60.0000'
        && $firstReconciliation['average_cost'] === '2.500000',
        'opening stock posts one valued base-unit receipt at its explicit cost',
    );
    $entryCount = $pdo->prepare('SELECT COUNT(*) FROM inventory_cost_entries WHERE source_id = ?');
    $entryCount->execute([$staffDraftId]);
    $firstEntry = $pdo->prepare(
        'SELECT ice.stock_movement_id, ice.qty_delta, ice.value_delta_khr,
                ice.avg_unit_cost_before_khr, ice.avg_unit_cost_after_khr, ice.costing_type, ice.created_by
         FROM inventory_cost_entries ice WHERE ice.source_id = ? LIMIT 1'
    );
    $firstEntry->execute([$staffDraftId]);
    $entry = $firstEntry->fetch();
    phase32Check(
        (int) $entryCount->fetchColumn() === 1 && is_array($entry)
        && (int) $entry['stock_movement_id'] > 0 && $entry['qty_delta'] === '24.000'
        && $entry['value_delta_khr'] === '60.0000' && $entry['avg_unit_cost_before_khr'] === '0.000000'
        && $entry['avg_unit_cost_after_khr'] === '2.500000' && $entry['costing_type'] === 'receipt_opening'
        && (int) $entry['created_by'] === $ownerId,
        'immutable valuation entry links the stock movement and weighted-average snapshots',
    );
    $duplicate = $inventory->confirmReceipt($ownerId, $staffDraftId);
    $entryCount->execute([$staffDraftId]);
    phase32Check(!$duplicate['confirmed'] && (int) $entryCount->fetchColumn() === 1, 'duplicate confirmation cannot create a second valuation entry');

    $secondReceiptId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase',
        'reference_no' => 'P32-SECOND-' . strtoupper($suffix),
        'location_id' => $locationId,
        'items' => [[
            'product_unit_id' => (int) $primaryBaseUnit['id'],
            'quantity' => '12',
            'cost_per_base_khr' => '3.500000',
        ]],
    ]);
    $receiptIds[] = $secondReceiptId;
    $inventory->confirmReceipt($ownerId, $secondReceiptId);
    $afterSecond = $inventory->reconcileProductValuation($ownerId, $primaryProductId);
    phase32Check(
        $afterSecond['reconciled'] && $afterSecond['cost_qty'] === '36.000'
        && $afterSecond['cost_value'] === '102.0000' && $afterSecond['average_cost'] === '2.833333',
        'multiple receipts at different costs recalculate the product-wide moving average',
    );

    $pdo->beginTransaction();
    try {
        $lockedPrimary = $app->movingAverageCosts()->lockProductForPosting($primaryProductId);
        $cogsQuote = $app->movingAverageCosts()->quoteOutgoingCogs($lockedPrimary, '1.000');
        $pdo->rollBack();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    phase32Check(
        $cogsQuote['qty_base'] === '1.000' && $cogsQuote['unit_cost_khr'] === '2.833333' && $cogsQuote['value_khr'] === '2.8333',
        'future outgoing workflows can obtain an immutable COGS snapshot from a locked average',
    );

    phase32ExpectDomain(
        fn () => $inventory->createDraftReceipt($ownerId, [
            'receipt_type' => 'purchase',
            'reference_no' => 'P32-ZERO-COST-' . strtoupper($suffix),
            'location_id' => $locationId,
            'items' => [[
                'product_unit_id' => (int) $primaryBaseUnit['id'],
                'quantity' => '1',
                'cost_per_base_khr' => '0',
            ]],
        ]),
        'zero receipt costs are rejected before a draft can be saved',
    );
    $unvaluedReceiptId = $inventory->createDraftReceipt($staffId, [
        'receipt_type' => 'purchase',
        'reference_no' => 'P32-UNVALUED-' . strtoupper($suffix),
        'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $primaryBaseUnit['id'], 'quantity' => '1']],
    ]);
    $receiptIds[] = $unvaluedReceiptId;
    phase32ExpectDomain(
        fn () => $inventory->confirmReceipt($ownerId, $unvaluedReceiptId),
        'unvalued receipts cannot silently affect valued inventory',
    );
    $unvaluedStatus = $app->inventoryReceipts()->find($unvaluedReceiptId);
    phase32Check(is_array($unvaluedStatus) && $unvaluedStatus['status'] === 'draft', 'failed valuation leaves the receipt unposted');

    $reversalProductId = $products->createProduct($ownerId, [
        'sku' => 'P32R-' . strtoupper($suffix),
        'name' => $prefix . ' Reversal Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $reversalProductId;
    $reversalBaseUnit = $app->products()->findProductUnitByProductAndUnit($reversalProductId, $pieceUnitId);
    if ($reversalBaseUnit === null) {
        throw new RuntimeException('Reversal product base unit was not created.');
    }
    $earlyReceiptId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening', 'reference_no' => 'P32-REV-A-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $reversalBaseUnit['id'], 'quantity' => '10', 'cost_per_base_khr' => '10.000000']],
    ]);
    $receiptIds[] = $earlyReceiptId;
    $inventory->confirmReceipt($ownerId, $earlyReceiptId);
    $laterReceiptId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase', 'reference_no' => 'P32-REV-B-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $reversalBaseUnit['id'], 'quantity' => '10', 'cost_per_base_khr' => '20.000000']],
    ]);
    $receiptIds[] = $laterReceiptId;
    $inventory->confirmReceipt($ownerId, $laterReceiptId);
    $inventory->reverseReceipt($ownerId, $earlyReceiptId, 'Phase 3.2 subsequent receipt correction');
    $afterReversal = $inventory->reconcileProductValuation($ownerId, $reversalProductId);
    $reversalEntry = $pdo->prepare(
        "SELECT value_delta_khr, avg_unit_cost_before_khr, avg_unit_cost_after_khr
         FROM inventory_cost_entries WHERE source_type = 'inventory_receipt_reversal' AND source_id = ? LIMIT 1"
    );
    $reversalEntry->execute([$earlyReceiptId]);
    $reversalValues = $reversalEntry->fetch();
    phase32Check(
        $afterReversal['reconciled'] && $afterReversal['cost_qty'] === '10.000'
        && $afterReversal['cost_value'] === '150.0000' && $afterReversal['average_cost'] === '15.000000'
        && is_array($reversalValues) && $reversalValues['value_delta_khr'] === '-150.0000'
        && $reversalValues['avg_unit_cost_before_khr'] === '15.000000' && $reversalValues['avg_unit_cost_after_khr'] === '15.000000',
        'reversal after later receipts uses current average rather than restoring an outdated cost',
    );

    $depletionProductId = $products->createProduct($ownerId, [
        'sku' => 'P32D-' . strtoupper($suffix),
        'name' => $prefix . ' Depletion Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $depletionProductId;
    $depletionBaseUnit = $app->products()->findProductUnitByProductAndUnit($depletionProductId, $pieceUnitId);
    if ($depletionBaseUnit === null) {
        throw new RuntimeException('Depletion product base unit was not created.');
    }
    $depletionReceiptId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening', 'reference_no' => 'P32-DEPLETE-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $depletionBaseUnit['id'], 'quantity' => '5', 'cost_per_base_khr' => '7.000000']],
    ]);
    $receiptIds[] = $depletionReceiptId;
    $inventory->confirmReceipt($ownerId, $depletionReceiptId);
    $inventory->reverseReceipt($ownerId, $depletionReceiptId, 'Full stock depletion test');
    $depleted = $inventory->reconcileProductValuation($ownerId, $depletionProductId);
    phase32Check(
        $depleted['reconciled'] && $depleted['balance_qty'] === '0.000' && $depleted['cost_value'] === '0.0000'
        && $depleted['average_cost'] === '0.000000',
        'full stock depletion clears quantity, value, and average without a rounding residual',
    );

    $unreconciledProductId = $products->createProduct($ownerId, [
        'sku' => 'P32U-' . strtoupper($suffix),
        'name' => $prefix . ' Unreconciled Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $unreconciledProductId;
    $unreconciledBaseUnit = $app->products()->findProductUnitByProductAndUnit($unreconciledProductId, $pieceUnitId);
    if ($unreconciledBaseUnit === null) {
        throw new RuntimeException('Unreconciled product base unit was not created.');
    }
    $rawBalance = $pdo->prepare('INSERT INTO stock_balances (product_id, location_id, batch_id, qty_base) VALUES (?, ?, NULL, 1.000)');
    $rawBalance->execute([$unreconciledProductId, $locationId]);
    $rollbackReceiptId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase', 'reference_no' => 'P32-ROLLBACK-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $unreconciledBaseUnit['id'], 'quantity' => '1', 'cost_per_base_khr' => '4.000000']],
    ]);
    $receiptIds[] = $rollbackReceiptId;
    phase32ExpectDomain(
        fn () => $inventory->confirmReceipt($ownerId, $rollbackReceiptId),
        'unreconciled legacy stock rejects a receipt posting atomically',
    );
    $rollbackMovementCount = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'inventory_receipt' AND source_id = ?");
    $rollbackMovementCount->execute([$rollbackReceiptId]);
    $rollbackEntryCount = $pdo->prepare("SELECT COUNT(*) FROM inventory_cost_entries WHERE source_type = 'inventory_receipt' AND source_id = ?");
    $rollbackEntryCount->execute([$rollbackReceiptId]);
    $rollbackStatus = $app->inventoryReceipts()->find($rollbackReceiptId);
    phase32Check(
        (int) $rollbackMovementCount->fetchColumn() === 0 && (int) $rollbackEntryCount->fetchColumn() === 0
        && is_array($rollbackStatus) && $rollbackStatus['status'] === 'draft',
        'failed valuation rolls back stock movement, valuation ledger, and receipt status together',
    );

    $concurrentProductId = $products->createProduct($ownerId, [
        'sku' => 'P32C-' . strtoupper($suffix),
        'name' => $prefix . ' Concurrent Costing Product',
        'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId,
        'kind' => 'physical',
    ]);
    $productIds[] = $concurrentProductId;
    $concurrentBaseUnit = $app->products()->findProductUnitByProductAndUnit($concurrentProductId, $pieceUnitId);
    if ($concurrentBaseUnit === null) {
        throw new RuntimeException('Concurrent product base unit was not created.');
    }
    $concurrentOneId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase', 'reference_no' => 'P32-CON-A-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $concurrentBaseUnit['id'], 'quantity' => '10', 'cost_per_base_khr' => '2.000000']],
    ]);
    $concurrentTwoId = $inventory->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase', 'reference_no' => 'P32-CON-B-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => (int) $concurrentBaseUnit['id'], 'quantity' => '10', 'cost_per_base_khr' => '4.000000']],
    ]);
    $receiptIds[] = $concurrentOneId;
    $receiptIds[] = $concurrentTwoId;
    [$firstOut, $secondOut, $firstExit, $secondExit] = phase32RunConcurrentConfirmation($ownerId, $concurrentOneId, $concurrentTwoId);
    $concurrentReconciliation = $inventory->reconcileProductValuation($ownerId, $concurrentProductId);
    phase32Check(
        $firstExit === 0 && $secondExit === 0 && $firstOut === 'confirmed' && $secondOut === 'confirmed'
        && $concurrentReconciliation['reconciled'] && $concurrentReconciliation['cost_qty'] === '20.000'
        && $concurrentReconciliation['cost_value'] === '60.0000' && $concurrentReconciliation['average_cost'] === '3.000000',
        'concurrent receipts serialize on the product cost row and preserve the weighted average',
    );
} catch (Throwable $exception) {
    $failures[] = 'unexpected exception: ' . $exception->getMessage();
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception . PHP_EOL);
} finally {
    phase32Cleanup($pdo, $ownerId, $staffId, $categoryId, $receiptIds, $productIds, $prefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 3.2 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 3.2 moving-average integration checks passed.' . PHP_EOL);
