<?php
declare(strict_types=1);

use App\Controllers\SalesController;
use App\Core\HttpException;
use App\Core\Session;
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
$prefix = 'P41 ' . $suffix;
$ownerUsername = 'phase41_owner_' . $suffix;
$staffUsername = 'phase41_staff_' . $suffix;
$ownerPassword = 'Phase41Owner!' . $suffix;
$staffPassword = 'Phase41Staff!' . $suffix;
$failures = [];
$ownerId = null;
$staffId = null;
$categoryId = null;
$productIds = [];
$receiptIds = [];
$saleIds = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase41-test-sessions';

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase41Check(bool $condition, string $message): void
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
function phase41ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase41Check(false, $message);
    } catch (DomainException) {
        phase41Check(true, $message);
    }
}

/** @return array{0: string, 1: string, 2: int, 3: int} */
function phase41RunConcurrentConfirmation(int $userId, int $firstSaleId, int $secondSaleId): array
{
    $worker = __DIR__ . '/phase41_confirm_worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $first = proc_open([PHP_BINARY, $worker, (string) $userId, (string) $firstSaleId], $descriptors, $firstPipes, dirname(__DIR__));
    $second = proc_open([PHP_BINARY, $worker, (string) $userId, (string) $secondSaleId], $descriptors, $secondPipes, dirname(__DIR__));
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Unable to start concurrent sale confirmation workers.');
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

/** @param list<int> $receiptIds @param list<int> $saleIds @param list<int> $productIds */
function phase41Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $categoryId, array $receiptIds, array $saleIds, array $productIds, string $prefix): void
{
    foreach ($saleIds as $saleId) {
        $deleteAllocations = $pdo->prepare(
            'DELETE ra FROM receivable_allocations ra
             JOIN receivables r ON r.id = ra.receivable_id WHERE r.sale_id = ?'
        );
        $deleteAllocations->execute([$saleId]);
        $deleteReceivable = $pdo->prepare('DELETE FROM receivables WHERE sale_id = ?');
        $deleteReceivable->execute([$saleId]);
        $deleteEntries = $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_id = ? AND source_type IN ('sale', 'sale_void')");
        $deleteEntries->execute([$saleId]);
        $deleteMovements = $pdo->prepare("DELETE FROM stock_movements WHERE source_id = ? AND source_type IN ('sale', 'sale_void')");
        $deleteMovements->execute([$saleId]);
        $deleteItems = $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?');
        $deleteItems->execute([$saleId]);
        $deleteSale = $pdo->prepare('DELETE FROM sales WHERE id = ?');
        $deleteSale->execute([$saleId]);
    }
    foreach ($receiptIds as $receiptId) {
        $deleteEntries = $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')");
        $deleteEntries->execute([$receiptId]);
        $deleteMovements = $pdo->prepare("DELETE FROM stock_movements WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')");
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
        $deleteCosts = $pdo->prepare('DELETE FROM product_costs WHERE product_id = ?');
        $deleteCosts->execute([$productId]);
        $deletePrices = $pdo->prepare('DELETE pp FROM product_prices pp JOIN product_units pu ON pu.id = pp.product_unit_id WHERE pu.product_id = ?');
        $deletePrices->execute([$productId]);
        $deleteBarcodes = $pdo->prepare('DELETE pb FROM product_barcodes pb JOIN product_units pu ON pu.id = pb.product_unit_id WHERE pu.product_id = ?');
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

/** @param list<int> $receiptIds */
function phase41Receive(App\Core\App $app, int $ownerId, int $locationId, int $productUnitId, string $quantity, string $cost, string $reference, array &$receiptIds): int
{
    $receiptId = $app->inventory()->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening',
        'reference_no' => $reference,
        'location_id' => $locationId,
        'items' => [[
            'product_unit_id' => $productUnitId,
            'quantity' => $quantity,
            'cost_per_base_khr' => $cost,
        ]],
    ]);
    $receiptIds[] = $receiptId;
    $app->inventory()->confirmReceipt($ownerId, $receiptId);
    return $receiptId;
}

try {
    $migrationColumns = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'sales'
           AND column_name IN ('confirmed_by', 'voided_at', 'voided_by', 'void_reason', 'void_restock_confirmed')"
    )->fetchColumn();
    phase41Check((int) $migrationColumns === 5, 'Phase 4.1 sales migration is present in the disposable test database');
    $preflight = $app->valuationPreflight()->report();
    phase41Check(
        $preflight['has_phase31_receipts'] === 1 && $preflight['has_phase32_movement_link'] === 1
        && $preflight['movements_without_valuation'] === 0 && $preflight['products_with_qty_mismatch'] === 0,
        'read-only valuation preflight reports a reconciled disposable database',
    );
    phase41Check(Decimal::signedSubtract('1.0000', '2.0000', 4) === '-1.0000', 'gross-profit reporting preserves a loss instead of rejecting it');

    Session::start();
    phase41Check($app->auth()->createInitialOwner($ownerUsername, 'Phase 4.1 Test Owner', $ownerPassword) === null, 'initial Owner can be created in the disposable test database');
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) {
        throw new RuntimeException('Owner creation failed.');
    }
    phase41Check($app->auth()->createStaff($ownerId, $staffUsername, 'Phase 4.1 Test Staff', $staffPassword) === null, 'Owner can create Staff for sales authorization checks');
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) {
        throw new RuntimeException('Staff creation failed.');
    }

    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN' AND is_active = 1 LIMIT 1")->fetchColumn();
    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    $caseUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'case' AND status = 'active' LIMIT 1")->fetchColumn();
    phase41Check($locationId > 0 && $pieceUnitId > 0 && $caseUnitId > 0, 'seeded sale location and units are available');

    $products = $app->productDomain();
    $sales = $app->salesService();
    $categoryId = $products->createCategory($ownerId, $prefix . ' Sales', null);
    $primaryProductId = $products->createProduct($ownerId, [
        'sku' => 'P41-' . strtoupper($suffix), 'name' => $prefix . ' Retail Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $productIds[] = $primaryProductId;
    $primaryCaseUnitId = $products->addProductUnit($ownerId, $primaryProductId, $caseUnitId, '12.000000');
    $products->setPrice($ownerId, $primaryCaseUnitId, 'retail', 'KHR', '50.0000');
    $products->setPrice($ownerId, $primaryCaseUnitId, 'wholesale', 'KHR', '40.0000');
    phase41Receive($app, $ownerId, $locationId, $primaryCaseUnitId, '5', '2.500000', 'P41-OPEN-' . strtoupper($suffix), $receiptIds);

    $retailSaleId = $sales->createDraftSale($staffId, [
        'sale_channel' => 'retail',
        'items' => [['product_unit_id' => $primaryCaseUnitId, 'location_id' => $locationId, 'quantity' => '2', 'discount_khr' => '0']],
    ]);
    $saleIds[] = $retailSaleId;
    $beforeConfirm = $app->inventory()->reconcileProductValuation($ownerId, $primaryProductId);
    $draft = $app->sales()->find($retailSaleId);
    phase41Check(
        is_array($draft) && $draft['status'] === 'draft' && $draft['total_khr'] === '100.0000'
        && $beforeConfirm['balance_qty'] === '60.000' && $beforeConfirm['cost_value'] === '150.0000',
        'retail draft snapshots configured price without stock, value, revenue, or COGS impact',
    );
    $retailConfirmed = $sales->confirmSale($staffId, $retailSaleId);
    $retailItems = $app->sales()->items($retailSaleId);
    $afterRetail = $app->inventory()->reconcileProductValuation($ownerId, $primaryProductId);
    phase41Check(
        $retailConfirmed['confirmed'] && count($retailItems) === 1 && $retailItems[0]['qty_base'] === '24.000'
        && $retailItems[0]['unit_price_khr'] === '50.0000' && $retailItems[0]['line_revenue_khr'] === '100.0000'
        && $retailItems[0]['cogs_khr'] === '60.0000' && $retailItems[0]['cogs_unit_cost_khr'] === '2.500000'
        && $afterRetail['reconciled'] && $afterRetail['balance_qty'] === '36.000' && $afterRetail['cost_value'] === '90.0000',
        'confirmed retail sale converts units, deducts stock, and preserves locked COGS',
    );
    $duplicateRetail = $sales->confirmSale($staffId, $retailSaleId);
    $retailMovementCount = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'sale' AND source_id = ?");
    $retailMovementCount->execute([$retailSaleId]);
    phase41Check(!$duplicateRetail['confirmed'] && (int) $retailMovementCount->fetchColumn() === 1, 'duplicate sale confirmation is idempotent');

    $wholesaleSaleId = $sales->createDraftSale($staffId, [
        'sale_channel' => 'wholesale',
        'items' => [['product_unit_id' => $primaryCaseUnitId, 'location_id' => $locationId, 'quantity' => '1', 'discount_khr' => '0']],
    ]);
    $saleIds[] = $wholesaleSaleId;
    $sales->confirmSale($staffId, $wholesaleSaleId);
    $wholesaleItem = $app->sales()->items($wholesaleSaleId)[0];
    phase41Check($wholesaleItem['price_type'] === 'wholesale' && $wholesaleItem['unit_price_khr'] === '40.0000' && $wholesaleItem['cogs_khr'] === '30.0000', 'wholesale sale uses the current wholesale price and base-unit COGS');

    phase41ExpectDomain(
        fn () => $sales->createDraftSale($staffId, [
            'sale_channel' => 'retail',
            'items' => [['product_unit_id' => $primaryCaseUnitId, 'location_id' => $locationId, 'quantity' => '1', 'override_price_khr' => '45.0000']],
        ]),
        'Staff cannot create a flexible price override without pricing.override',
    );
    $overrideSaleId = $sales->createDraftSale($ownerId, [
        'sale_channel' => 'retail',
        'items' => [['product_unit_id' => $primaryCaseUnitId, 'location_id' => $locationId, 'quantity' => '1', 'override_price_khr' => '45.0000']],
    ]);
    $saleIds[] = $overrideSaleId;
    $sales->confirmSale($ownerId, $overrideSaleId);
    $overrideItem = $app->sales()->items($overrideSaleId)[0];
    phase41Check((int) $overrideItem['is_price_override'] === 1 && $overrideItem['unit_price_khr'] === '45.0000' && $overrideItem['cogs_khr'] === '30.0000', 'authorized Owner override is stored as an immutable sale price snapshot');

    phase41Receive($app, $ownerId, $locationId, $primaryCaseUnitId, '1', '5.000000', 'P41-AVG-' . strtoupper($suffix), $receiptIds);
    $afterAverageChange = $app->inventory()->reconcileProductValuation($ownerId, $primaryProductId);
    $retailItemAfterReceipt = $app->sales()->items($retailSaleId)[0];
    phase41Check($afterAverageChange['average_cost'] === '3.750000' && $retailItemAfterReceipt['cogs_khr'] === '60.0000', 'later receipt average changes never rewrite confirmed sale COGS');

    $insufficientSaleId = $sales->createDraftSale($staffId, [
        'sale_channel' => 'retail',
        'items' => [['product_unit_id' => $primaryCaseUnitId, 'location_id' => $locationId, 'quantity' => '99']],
    ]);
    $saleIds[] = $insufficientSaleId;
    phase41ExpectDomain(fn () => $sales->confirmSale($staffId, $insufficientSaleId), 'insufficient stock prevents confirmation');
    $insufficientStatus = $app->sales()->find($insufficientSaleId);
    phase41Check(is_array($insufficientStatus) && $insufficientStatus['status'] === 'draft', 'insufficient-stock failure leaves the sale unposted');

    $archivedProductId = $products->createProduct($ownerId, [
        'sku' => 'P41A-' . strtoupper($suffix), 'name' => $prefix . ' Archived Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $productIds[] = $archivedProductId;
    $archivedBaseUnit = $app->products()->findProductUnitByProductAndUnit($archivedProductId, $pieceUnitId);
    if ($archivedBaseUnit === null) {
        throw new RuntimeException('Archived test product base unit was not created.');
    }
    $products->setPrice($ownerId, (int) $archivedBaseUnit['id'], 'retail', 'KHR', '10.0000');
    $archivedSaleId = $sales->createDraftSale($ownerId, [
        'sale_channel' => 'retail',
        'items' => [['product_unit_id' => (int) $archivedBaseUnit['id'], 'location_id' => $locationId, 'quantity' => '1']],
    ]);
    $saleIds[] = $archivedSaleId;
    $products->archiveProduct($ownerId, $archivedProductId);
    phase41ExpectDomain(fn () => $sales->confirmSale($ownerId, $archivedSaleId), 'archived products cannot be confirmed for sale');

    $unreconciledProductId = $products->createProduct($ownerId, [
        'sku' => 'P41U-' . strtoupper($suffix), 'name' => $prefix . ' Unreconciled Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $productIds[] = $unreconciledProductId;
    $unreconciledBaseUnit = $app->products()->findProductUnitByProductAndUnit($unreconciledProductId, $pieceUnitId);
    if ($unreconciledBaseUnit === null) {
        throw new RuntimeException('Unreconciled test product base unit was not created.');
    }
    $products->setPrice($ownerId, (int) $unreconciledBaseUnit['id'], 'retail', 'KHR', '10.0000');
    $rawBalance = $pdo->prepare('INSERT INTO stock_balances (product_id, location_id, batch_id, qty_base) VALUES (?, ?, NULL, 1.000)');
    $rawBalance->execute([$unreconciledProductId, $locationId]);
    $rollbackSaleId = $sales->createDraftSale($ownerId, [
        'sale_channel' => 'retail',
        'items' => [['product_unit_id' => (int) $unreconciledBaseUnit['id'], 'location_id' => $locationId, 'quantity' => '1']],
    ]);
    $saleIds[] = $rollbackSaleId;
    phase41ExpectDomain(fn () => $sales->confirmSale($ownerId, $rollbackSaleId), 'valuation preflight mismatch rejects sale posting atomically');
    $rollbackMovements = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'sale' AND source_id = ?");
    $rollbackMovements->execute([$rollbackSaleId]);
    $rollbackEntries = $pdo->prepare("SELECT COUNT(*) FROM inventory_cost_entries WHERE source_type = 'sale' AND source_id = ?");
    $rollbackEntries->execute([$rollbackSaleId]);
    phase41Check((int) $rollbackMovements->fetchColumn() === 0 && (int) $rollbackEntries->fetchColumn() === 0, 'failed sale posting rolls back stock movement and COGS entry together');

    $concurrentProductId = $products->createProduct($ownerId, [
        'sku' => 'P41C-' . strtoupper($suffix), 'name' => $prefix . ' Concurrent Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $productIds[] = $concurrentProductId;
    $concurrentBaseUnit = $app->products()->findProductUnitByProductAndUnit($concurrentProductId, $pieceUnitId);
    if ($concurrentBaseUnit === null) {
        throw new RuntimeException('Concurrent test product base unit was not created.');
    }
    $products->setPrice($ownerId, (int) $concurrentBaseUnit['id'], 'retail', 'KHR', '10.0000');
    phase41Receive($app, $ownerId, $locationId, (int) $concurrentBaseUnit['id'], '10', '3.000000', 'P41-CON-STOCK-' . strtoupper($suffix), $receiptIds);
    $concurrentOne = $sales->createDraftSale($staffId, ['sale_channel' => 'retail', 'items' => [['product_unit_id' => (int) $concurrentBaseUnit['id'], 'location_id' => $locationId, 'quantity' => '6']]]);
    $concurrentTwo = $sales->createDraftSale($staffId, ['sale_channel' => 'retail', 'items' => [['product_unit_id' => (int) $concurrentBaseUnit['id'], 'location_id' => $locationId, 'quantity' => '6']]]);
    $saleIds[] = $concurrentOne;
    $saleIds[] = $concurrentTwo;
    [$firstOut, $secondOut, $firstExit, $secondExit] = phase41RunConcurrentConfirmation($staffId, $concurrentOne, $concurrentTwo);
    $concurrentResult = [$firstOut, $secondOut];
    sort($concurrentResult);
    $concurrentValue = $app->inventory()->reconcileProductValuation($ownerId, $concurrentProductId);
    phase41Check(
        $firstExit === 0 && $secondExit === 0 && $concurrentResult === ['confirmed', 'rejected']
        && $concurrentValue['reconciled'] && $concurrentValue['balance_qty'] === '4.000' && $concurrentValue['cost_value'] === '12.0000',
        'concurrent sales cannot oversell limited stock and retain correct locked COGS',
    );

    $voidProductId = $products->createProduct($ownerId, [
        'sku' => 'P41V-' . strtoupper($suffix), 'name' => $prefix . ' Void Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $productIds[] = $voidProductId;
    $voidBaseUnit = $app->products()->findProductUnitByProductAndUnit($voidProductId, $pieceUnitId);
    if ($voidBaseUnit === null) {
        throw new RuntimeException('Void test product base unit was not created.');
    }
    $products->setPrice($ownerId, (int) $voidBaseUnit['id'], 'retail', 'KHR', '5.0000');
    phase41Receive($app, $ownerId, $locationId, (int) $voidBaseUnit['id'], '10', '2.000000', 'P41-VOID-STOCK-' . strtoupper($suffix), $receiptIds);
    $voidSaleId = $sales->createDraftSale($staffId, ['sale_channel' => 'retail', 'items' => [['product_unit_id' => (int) $voidBaseUnit['id'], 'location_id' => $locationId, 'quantity' => '2']]]);
    $saleIds[] = $voidSaleId;
    $sales->confirmSale($staffId, $voidSaleId);
    phase41ExpectDomain(fn () => $sales->voidSale($ownerId, $voidSaleId, 'No automatic restock', false), 'sale void requires explicit resellable-stock confirmation');
    $voided = $sales->voidSale($ownerId, $voidSaleId, 'Customer cancelled before collection', true);
    $afterVoid = $app->inventory()->reconcileProductValuation($ownerId, $voidProductId);
    $voidEntry = $pdo->prepare("SELECT COUNT(*) FROM inventory_cost_entries WHERE source_type = 'sale_void' AND source_id = ?");
    $voidEntry->execute([$voidSaleId]);
    phase41Check($voided['voided'] && $afterVoid['reconciled'] && $afterVoid['balance_qty'] === '10.000' && $afterVoid['cost_value'] === '20.0000' && (int) $voidEntry->fetchColumn() === 1, 'Owner void posts compensating immutable stock and valuation entries at original COGS');
    $duplicateVoid = $sales->voidSale($ownerId, $voidSaleId, 'Duplicate void', true);
    phase41Check(!$duplicateVoid['voided'], 'duplicate sale void is idempotent');

    $staffSales = $sales->listSalesForViewer($staffId, 1, 100);
    $ownerSales = $sales->listSalesForViewer($ownerId, 1, 100);
    phase41Check(
        $staffSales !== [] && count(array_filter($staffSales, static fn (array $sale): bool => array_key_exists('cogs_khr', $sale) || array_key_exists('gross_profit_khr', $sale))) === 0
        && $ownerSales !== [] && array_key_exists('cogs_khr', $ownerSales[0]) && array_key_exists('gross_profit_khr', $ownerSales[0]),
        'Staff sale responses omit COGS and gross profit while Owner responses include them',
    );
    phase41ExpectDomain(fn () => $sales->financialReconciliation($staffId), 'Staff cannot access sales profit reconciliation');
    $financial = $sales->financialReconciliation($ownerId);
    phase41Check($financial['revenue'] === '245.0000' && $financial['cogs'] === '138.0000' && $financial['gross_profit'] === '107.0000', 'confirmed revenue, immutable COGS, and gross profit reconcile without payment posting');

    Session::clearAuthentication();
    phase41Check($app->auth()->attemptLogin($ownerUsername, $ownerPassword) !== null, 'Owner session is available for sales CSRF testing');
    $_POST = ['_csrf' => 'invalid-token'];
    try {
        (new SalesController($app))->createSale();
        phase41Check(false, 'sales POST rejects invalid CSRF tokens');
    } catch (HttpException $exception) {
        phase41Check($exception->statusCode() === 419, 'sales POST rejects invalid CSRF tokens');
    }
    $_POST = [];
} catch (Throwable $exception) {
    $failures[] = 'unexpected exception: ' . $exception->getMessage();
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception . PHP_EOL);
} finally {
    $_POST = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        Session::destroy();
    }
    phase41Cleanup($pdo, $ownerId, $staffId, $categoryId, $receiptIds, $saleIds, $productIds, $prefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 4.1 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 4.1 sales integration checks passed.' . PHP_EOL);
