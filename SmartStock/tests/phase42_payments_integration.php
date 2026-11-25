<?php
declare(strict_types=1);

use App\Controllers\PaymentController;
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
$prefix = 'P42 ' . $suffix;
$paymentPrefix = 'P42-' . strtoupper($suffix) . '-';
$ownerUsername = 'phase42_owner_' . $suffix;
$staffUsername = 'phase42_staff_' . $suffix;
$ownerPassword = 'Phase42Owner!' . $suffix;
$staffPassword = 'Phase42Staff!' . $suffix;
$failures = [];
$ownerId = null;
$staffId = null;
$categoryId = null;
$productId = null;
$productUnitId = null;
$receiptId = null;
$saleIds = [];
$customerIds = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase42-test-sessions';

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase42Check(bool $condition, string $message): void
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
function phase42ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase42Check(false, $message);
    } catch (DomainException) {
        phase42Check(true, $message);
    }
}

/** @return array{0: string, 1: string, 2: int, 3: int} */
function phase42RunConcurrentPayment(int $staffId, int $saleId, string $firstKey, string $secondKey): array
{
    $worker = __DIR__ . '/phase42_payment_worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $first = proc_open([PHP_BINARY, $worker, (string) $staffId, (string) $saleId, $firstKey], $descriptors, $firstPipes, dirname(__DIR__));
    $second = proc_open([PHP_BINARY, $worker, (string) $staffId, (string) $saleId, $secondKey], $descriptors, $secondPipes, dirname(__DIR__));
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Unable to start concurrent payment workers.');
    }
    fclose($firstPipes[0]);
    fclose($secondPipes[0]);
    $firstOut = trim(stream_get_contents($firstPipes[1]));
    $firstErr = trim(stream_get_contents($firstPipes[2]));
    $secondOut = trim(stream_get_contents($secondPipes[1]));
    $secondErr = trim(stream_get_contents($secondPipes[2]));
    fclose($firstPipes[1]);
    fclose($firstPipes[2]);
    fclose($secondPipes[1]);
    fclose($secondPipes[2]);
    $firstExit = proc_close($first);
    $secondExit = proc_close($second);
    if ($firstErr !== '' || $secondErr !== '') {
        throw new RuntimeException(trim($firstErr . ' ' . $secondErr));
    }
    return [$firstOut, $secondOut, $firstExit, $secondExit];
}

/** @param list<int> $saleIds @param list<int> $customerIds */
function phase42Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $categoryId, ?int $productId, ?int $receiptId, array $saleIds, array $customerIds, string $paymentPrefix): void
{
    $deletePayments = $pdo->prepare('DELETE FROM payments WHERE payment_key LIKE ?');
    foreach ($saleIds as $saleId) {
        $deleteAllocations = $pdo->prepare('DELETE ra FROM receivable_allocations ra JOIN receivables r ON r.id = ra.receivable_id WHERE r.sale_id = ?');
        $deleteAllocations->execute([$saleId]);
    }
    $deletePayments->execute([$paymentPrefix . '%']);
    foreach ($saleIds as $saleId) {
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
    if ($receiptId !== null) {
        $deleteEntries = $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')");
        $deleteEntries->execute([$receiptId]);
        $deleteMovements = $pdo->prepare("DELETE FROM stock_movements WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')");
        $deleteMovements->execute([$receiptId]);
        $deleteItems = $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id = ?');
        $deleteItems->execute([$receiptId]);
        $deleteReceipt = $pdo->prepare('DELETE FROM inventory_receipts WHERE id = ?');
        $deleteReceipt->execute([$receiptId]);
    }
    if ($productId !== null) {
        $deleteEntries = $pdo->prepare('DELETE FROM inventory_cost_entries WHERE product_id = ?');
        $deleteEntries->execute([$productId]);
        $deleteBalances = $pdo->prepare('DELETE FROM stock_balances WHERE product_id = ?');
        $deleteBalances->execute([$productId]);
        $deleteCosts = $pdo->prepare('DELETE FROM product_costs WHERE product_id = ?');
        $deleteCosts->execute([$productId]);
        $deletePrices = $pdo->prepare('DELETE pp FROM product_prices pp JOIN product_units pu ON pu.id = pp.product_unit_id WHERE pu.product_id = ?');
        $deletePrices->execute([$productId]);
        $deleteUnits = $pdo->prepare('DELETE FROM product_units WHERE product_id = ?');
        $deleteUnits->execute([$productId]);
        $deleteProduct = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $deleteProduct->execute([$productId]);
    }
    foreach ($customerIds as $customerId) {
        $deleteCustomer = $pdo->prepare('DELETE FROM customers WHERE id = ?');
        $deleteCustomer->execute([$customerId]);
    }
    if ($categoryId !== null) {
        $deleteCategory = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $deleteCategory->execute([$categoryId]);
    }
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

/** @param array<string, mixed> $extra */
function phase42ConfirmedSale(App\Core\App $app, int $actorId, int $productUnitId, int $locationId, array $extra, array &$saleIds): int
{
    $input = array_replace([
        'sale_channel' => 'retail',
        'settlement_type' => 'cash',
        'items' => [['product_unit_id' => $productUnitId, 'location_id' => $locationId, 'quantity' => '1']],
    ], $extra);
    $saleId = $app->salesService()->createDraftSale($actorId, $input);
    $saleIds[] = $saleId;
    $app->salesService()->confirmSale($actorId, $saleId);
    return $saleId;
}

try {
    $migrationColumns = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
         AND ((table_name = 'sales' AND column_name = 'settlement_type')
           OR (table_name = 'payments' AND column_name IN ('payment_key', 'verified_at', 'customer_id'))
           OR (table_name = 'receivables' AND column_name IN ('credit_terms_days', 'voided_at')))"
    )->fetchColumn();
    phase42Check((int) $migrationColumns === 6, 'Phase 4.2 payment and credit migration is present in the disposable test database');

    Session::start();
    phase42Check($app->auth()->createInitialOwner($ownerUsername, 'Phase 4.2 Test Owner', $ownerPassword) === null, 'initial Owner can be created for payment authorization');
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) {
        throw new RuntimeException('Owner creation failed.');
    }
    phase42Check($app->auth()->createStaff($ownerId, $staffUsername, 'Phase 4.2 Test Staff', $staffPassword) === null, 'Owner can create Staff who may record payments');
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) {
        throw new RuntimeException('Staff creation failed.');
    }

    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN' AND is_active = 1 LIMIT 1")->fetchColumn();
    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    phase42Check($locationId > 0 && $pieceUnitId > 0, 'seeded location and unit are available');
    $products = $app->productDomain();
    $categoryId = $products->createCategory($ownerId, $prefix . ' Payments', null);
    $productId = $products->createProduct($ownerId, [
        'sku' => 'P42-' . strtoupper($suffix), 'name' => $prefix . ' Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $baseUnit = $app->products()->findProductUnitByProductAndUnit($productId, $pieceUnitId);
    if ($baseUnit === null) {
        throw new RuntimeException('Phase 4.2 base product unit was not created.');
    }
    $productUnitId = (int) $baseUnit['id'];
    $products->setPrice($ownerId, $productUnitId, 'retail', 'KHR', '100.0000');
    $receiptId = $app->inventory()->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening', 'reference_no' => 'P42-OPEN-' . strtoupper($suffix), 'location_id' => $locationId,
        'items' => [['product_unit_id' => $productUnitId, 'quantity' => '30', 'cost_per_base_khr' => '10.000000']],
    ]);
    $app->inventory()->confirmReceipt($ownerId, $receiptId);

    $payments = $app->paymentService();
    $customerA = $payments->createCustomer($ownerId, [
        'name' => $prefix . ' Credit Customer', 'phone' => '012345678', 'credit_limit_khr' => '1000.0000',
        'credit_enabled' => '1', 'payment_terms_days' => '0',
    ]);
    $customerIds[] = $customerA;
    $limitedCustomer = $payments->createCustomer($ownerId, [
        'name' => $prefix . ' Limited Customer', 'credit_limit_khr' => '50.0000',
        'credit_enabled' => '1', 'payment_terms_days' => '7',
    ]);
    $customerIds[] = $limitedCustomer;
    phase42ExpectDomain(fn () => $payments->createCustomer($staffId, ['name' => 'Unauthorized', 'credit_limit_khr' => '0', 'payment_terms_days' => '0']), 'Staff cannot manage customer credit profiles');

    $cashSale = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, [], $saleIds);
    $cashBefore = $payments->receivableStatus($staffId, $cashSale);
    $cashPayment = $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'CASH-000000000001', 'payment_method' => 'cash', 'currency' => 'KHR',
        'amount_original' => '100.0000', 'exchange_rate_to_khr' => '1.000000',
        'allocations' => [['sale_id' => $cashSale, 'amount_khr' => '100.0000']],
    ]);
    $cashDuplicate = $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'CASH-000000000001', 'payment_method' => 'cash', 'currency' => 'KHR',
        'amount_original' => '100.0000', 'exchange_rate_to_khr' => '1.000000',
        'allocations' => [['sale_id' => $cashSale, 'amount_khr' => '100.0000']],
    ]);
    $cashAfter = $payments->receivableStatus($staffId, $cashSale);
    phase42Check($cashBefore['status'] === 'unpaid' && $cashPayment['recorded'] && $cashPayment['verification_status'] === 'verified' && !$cashDuplicate['recorded'] && $cashAfter['status'] === 'paid', 'full KHR cash payment settles an anonymous cash invoice exactly once');

    $khqrSale = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    $khqr = $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'KHQR-000000000001', 'payment_method' => 'khqr', 'currency' => 'KHR',
        'amount_original' => '100.0000', 'exchange_rate_to_khr' => '1.000000', 'reference_no' => 'KHQR-P42-REF',
        'payment_purpose' => 'customer_repayment', 'customer_id' => $customerA,
        'allocations' => [['sale_id' => $khqrSale, 'amount_khr' => '100.0000']],
    ]);
    phase42ExpectDomain(fn () => $payments->verifyPayment($staffId, $khqr['payment_id'], true), 'Staff cannot manually verify KHQR payments');
    $khqrPending = $payments->receivableStatus($staffId, $khqrSale);
    $khqrVerified = $payments->verifyPayment($ownerId, $khqr['payment_id'], true);
    $khqrAfter = $payments->receivableStatus($staffId, $khqrSale);
    phase42Check($khqr['verification_status'] === 'pending' && $khqrPending['status'] === 'unpaid' && $khqrVerified['changed'] && $khqrAfter['status'] === 'paid', 'KHQR payment remains manually pending until Owner verification');

    $splitSale = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'SPLIT-000000000001', 'payment_method' => 'cash', 'currency' => 'KHR',
        'amount_original' => '40.0000', 'exchange_rate_to_khr' => '1.000000', 'customer_id' => $customerA,
        'allocations' => [['sale_id' => $splitSale, 'amount_khr' => '40.0000']],
    ]);
    $splitPartial = $payments->receivableStatus($staffId, $splitSale);
    $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'SPLIT-000000000002', 'payment_method' => 'cash', 'currency' => 'USD',
        'amount_original' => '0.0150', 'exchange_rate_to_khr' => '4000.000000', 'customer_id' => $customerA,
        'allocations' => [['sale_id' => $splitSale, 'amount_khr' => '60.0000']],
    ]);
    $splitPaid = $payments->receivableStatus($staffId, $splitSale);
    phase42Check($splitPartial['status'] === 'partial' && $splitPartial['outstanding_khr'] === '60.0000' && $splitPaid['status'] === 'paid', 'split KHR/USD partial payments preserve exchange-rate snapshots and settle one invoice');

    $overdueSale = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    $overdue = $payments->receivableStatus($staffId, $overdueSale, (new DateTimeImmutable('tomorrow'))->format('Y-m-d'));
    phase42Check($overdue['status'] === 'overdue' && $overdue['outstanding_khr'] === '100.0000', 'credit terms establish a due date and overdue status without changing revenue');

    $multiOne = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    $multiTwo = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    $multiPayment = $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'MULTI-000000000001', 'payment_method' => 'bank', 'currency' => 'KHR',
        'amount_original' => '200.0000', 'exchange_rate_to_khr' => '1.000000', 'reference_no' => 'BANK-P42-REF', 'customer_id' => $customerA,
        'allocations' => [['sale_id' => $multiOne, 'amount_khr' => '100.0000'], ['sale_id' => $multiTwo, 'amount_khr' => '100.0000']],
    ]);
    $payments->verifyPayment($ownerId, $multiPayment['payment_id'], true);
    phase42Check($payments->receivableStatus($staffId, $multiOne)['status'] === 'paid' && $payments->receivableStatus($staffId, $multiTwo)['status'] === 'paid', 'one explicitly allocated bank payment settles multiple eligible customer invoices');

    $rejectedSale = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    $rejectedPayment = $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'REJECT-00000000001', 'payment_method' => 'bank', 'currency' => 'KHR',
        'amount_original' => '100.0000', 'exchange_rate_to_khr' => '1.000000', 'reference_no' => 'BANK-REJECT-P42', 'customer_id' => $customerA,
        'allocations' => [['sale_id' => $rejectedSale, 'amount_khr' => '100.0000']],
    ]);
    $rejected = $payments->verifyPayment($ownerId, $rejectedPayment['payment_id'], false, 'Bank reference could not be verified');
    $reversedAllocations = $pdo->prepare('SELECT COUNT(*) FROM receivable_allocations WHERE payment_id = ? AND reversed_at IS NOT NULL');
    $reversedAllocations->execute([$rejectedPayment['payment_id']]);
    phase42Check($rejected['verification_status'] === 'rejected' && $payments->receivableStatus($staffId, $rejectedSale)['status'] === 'unpaid' && (int) $reversedAllocations->fetchColumn() === 1, 'rejected bank payment preserves a reversal-marked allocation ledger without settling debt');
    $app->salesService()->voidSale($ownerId, $rejectedSale, 'Rejected bank payment sale cancellation', true);

    $limitDraft = $app->salesService()->createDraftSale($staffId, [
        'sale_channel' => 'retail', 'settlement_type' => 'credit', 'customer_id' => $limitedCustomer,
        'items' => [['product_unit_id' => $productUnitId, 'location_id' => $locationId, 'quantity' => '1']],
    ]);
    $saleIds[] = $limitDraft;
    phase42ExpectDomain(fn () => $app->salesService()->confirmSale($staffId, $limitDraft), 'credit confirmation cannot silently exceed the approved customer limit');
    $overrideSale = phase42ConfirmedSale($app, $ownerId, $productUnitId, $locationId, [
        'settlement_type' => 'credit', 'customer_id' => $limitedCustomer, 'credit_limit_override' => '1',
        'credit_override_reason' => 'Owner-approved one-off customer delivery',
    ], $saleIds);
    phase42Check($payments->receivableStatus($ownerId, $overrideSale)['status'] === 'unpaid', 'Owner-approved credit override is auditable and preserves an outstanding receivable');

    $voided = $app->salesService()->voidSale($ownerId, $overrideSale, 'Customer cancelled before collection', true);
    phase42ExpectDomain(fn () => $app->salesService()->voidSale($ownerId, $cashSale, 'Unsafe paid sale void', true), 'a sale with settled payment allocations cannot be voided without compensation');
    phase42Check($voided['voided'] && $payments->receivableStatus($ownerId, $overrideSale)['status'] === 'voided', 'unpaid credit sale voids mark the receivable voided without deleting history');

    $concurrentSale = phase42ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['settlement_type' => 'credit', 'customer_id' => $customerA], $saleIds);
    [$firstPayment, $secondPayment, $firstExit, $secondExit] = phase42RunConcurrentPayment($staffId, $concurrentSale, $paymentPrefix . 'RACE-000000000001', $paymentPrefix . 'RACE-000000000002');
    $race = [$firstPayment, $secondPayment];
    sort($race);
    $concurrentStatus = $payments->receivableStatus($staffId, $concurrentSale);
    phase42Check($firstExit === 0 && $secondExit === 0 && $race === ['recorded', 'rejected'] && $concurrentStatus['status'] === 'partial' && $concurrentStatus['outstanding_khr'] === '30.0000', 'concurrent payment allocations cannot over-settle an invoice');

    phase42ExpectDomain(fn () => $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'BADCUR-00000000001', 'payment_method' => 'cash', 'currency' => 'EUR',
        'amount_original' => '1', 'exchange_rate_to_khr' => '1', 'allocations' => [['sale_id' => $concurrentSale, 'amount_khr' => '1']],
    ]), 'unsupported payment currency is rejected');
    phase42ExpectDomain(fn () => $payments->recordPayment($staffId, [
        'payment_key' => $paymentPrefix . 'BADRATE-0000000001', 'payment_method' => 'cash', 'currency' => 'KHR',
        'amount_original' => '1', 'exchange_rate_to_khr' => '2', 'allocations' => [['sale_id' => $concurrentSale, 'amount_khr' => '2']],
    ]), 'KHR payment with an invalid exchange rate is rejected');

    $staffReceivables = $payments->listReceivablesForViewer($staffId);
    phase42Check($staffReceivables !== [] && !array_key_exists('credit_limit', $staffReceivables[0]), 'Staff payment responses exclude customer credit-limit data and all COGS fields');
    phase42ExpectDomain(fn () => $payments->financialReconciliation($staffId), 'Staff cannot access payment and credit reconciliation');
    $financial = $payments->financialReconciliation($ownerId);
    phase42Check($financial['sales_revenue'] === '700.0000' && $financial['cash_received'] === '270.0000' && $financial['bank_received'] === '300.0000' && $financial['customer_credit_outstanding'] === '130.0000', 'reconciliation keeps sales revenue distinct from cash, bank, and outstanding customer credit');

    Session::clearAuthentication();
    phase42Check($app->auth()->attemptLogin($ownerUsername, $ownerPassword) !== null, 'Owner session is available for payment CSRF testing');
    $_POST = ['_csrf' => 'invalid-token'];
    try {
        (new PaymentController($app))->recordPayment();
        phase42Check(false, 'payment POST rejects invalid CSRF tokens');
    } catch (HttpException $exception) {
        phase42Check($exception->statusCode() === 419, 'payment POST rejects invalid CSRF tokens');
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
    phase42Cleanup($pdo, $ownerId, $staffId, $categoryId, $productId, $receiptId, $saleIds, $customerIds, $paymentPrefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 4.2 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 4.2 payments and credit integration checks passed.' . PHP_EOL);
