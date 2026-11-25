<?php
declare(strict_types=1);

use App\Controllers\CreditNoteController;
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
$prefix = 'P43 ' . $suffix;
$keyPrefix = 'P43-' . strtoupper($suffix) . '-';
$ownerUsername = 'phase43_owner_' . $suffix;
$staffUsername = 'phase43_staff_' . $suffix;
$ownerPassword = 'Phase43Owner!' . $suffix;
$staffPassword = 'Phase43Staff!' . $suffix;
$failures = [];
$ownerId = null;
$staffId = null;
$categoryId = null;
$productId = null;
$receiptId = null;
$saleIds = [];
$customerIds = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase43-test-sessions';

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase43Check(bool $condition, string $message): void
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
function phase43ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase43Check(false, $message);
    } catch (DomainException) {
        phase43Check(true, $message);
    }
}

/** @param list<int> $saleIds @param list<int> $customerIds */
function phase43Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $categoryId, ?int $productId, ?int $receiptId, array $saleIds, array $customerIds, string $keyPrefix): void
{
    foreach ($saleIds as $saleId) {
        $deleteRefunds = $pdo->prepare(
            'DELETE r FROM refunds r JOIN credit_notes cn ON cn.id = r.credit_note_id WHERE cn.sale_id = ?'
        );
        $deleteRefunds->execute([$saleId]);
        $deleteApplications = $pdo->prepare(
            'DELETE a FROM credit_note_receivable_applications a JOIN credit_notes cn ON cn.id = a.credit_note_id WHERE cn.sale_id = ?'
        );
        $deleteApplications->execute([$saleId]);
        $deleteReversals = $pdo->prepare(
            'DELETE cr FROM credit_note_reversals cr JOIN credit_notes cn ON cn.id = cr.credit_note_id WHERE cn.sale_id = ?'
        );
        $deleteReversals->execute([$saleId]);
        $deleteNotes = $pdo->prepare('DELETE FROM credit_notes WHERE sale_id = ?');
        $deleteNotes->execute([$saleId]);
        $deleteAllocations = $pdo->prepare('DELETE ra FROM receivable_allocations ra JOIN receivables r ON r.id = ra.receivable_id WHERE r.sale_id = ?');
        $deleteAllocations->execute([$saleId]);
    }
    $deletePayments = $pdo->prepare('DELETE FROM payments WHERE payment_key LIKE ?');
    $deletePayments->execute([$keyPrefix . '%']);
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

/** @param array<string, mixed> $extra @param list<int> $saleIds */
function phase43ConfirmedSale(App\Core\App $app, int $actorId, int $productUnitId, int $locationId, array $extra, array &$saleIds): int
{
    $saleId = $app->salesService()->createDraftSale($actorId, array_replace([
        'sale_channel' => 'retail',
        'settlement_type' => 'credit',
        'items' => [['product_unit_id' => $productUnitId, 'location_id' => $locationId, 'quantity' => '1']],
    ], $extra));
    $saleIds[] = $saleId;
    $app->salesService()->confirmSale($actorId, $saleId);
    return $saleId;
}

/** @return array{0: string, 1: string, 2: int, 3: int} */
function phase43RunConcurrentApproval(int $ownerId, int $firstRefundId, int $secondRefundId): array
{
    $worker = __DIR__ . '/phase43_refund_approve_worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $first = proc_open([PHP_BINARY, $worker, (string) $ownerId, (string) $firstRefundId], $descriptors, $firstPipes, dirname(__DIR__));
    $second = proc_open([PHP_BINARY, $worker, (string) $ownerId, (string) $secondRefundId], $descriptors, $secondPipes, dirname(__DIR__));
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Unable to start concurrent refund workers.');
    }
    fclose($firstPipes[0]);
    fclose($secondPipes[0]);
    $firstOut = trim(stream_get_contents($firstPipes[1]));
    $firstErr = trim(stream_get_contents($firstPipes[2]));
    $secondOut = trim(stream_get_contents($secondPipes[1]));
    $secondErr = trim(stream_get_contents($secondPipes[2]));
    fclose($firstPipes[1]); fclose($firstPipes[2]); fclose($secondPipes[1]); fclose($secondPipes[2]);
    $firstExit = proc_close($first);
    $secondExit = proc_close($second);
    if ($firstErr !== '' || $secondErr !== '') {
        throw new RuntimeException(trim($firstErr . ' ' . $secondErr));
    }
    return [$firstOut, $secondOut, $firstExit, $secondExit];
}

try {
    $phase43Tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name IN ('credit_notes', 'credit_note_receivable_applications', 'credit_note_reversals', 'refunds')"
    )->fetchColumn();
    phase43Check($phase43Tables === 4, 'Phase 4.3 financial-document migration is present in the disposable test database');

    Session::start();
    phase43Check($app->auth()->createInitialOwner($ownerUsername, 'Phase 4.3 Test Owner', $ownerPassword) === null, 'initial Owner can be created for financial authorization');
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) {
        throw new RuntimeException('Owner creation failed.');
    }
    phase43Check($app->auth()->createStaff($ownerId, $staffUsername, 'Phase 4.3 Test Staff', $staffPassword) === null, 'Owner can create Staff for authorization boundaries');
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) {
        throw new RuntimeException('Staff creation failed.');
    }

    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN' AND is_active = 1 LIMIT 1")->fetchColumn();
    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    phase43Check($locationId > 0 && $pieceUnitId > 0, 'seeded location and unit are available');
    $categoryId = $app->productDomain()->createCategory($ownerId, $prefix . ' Financial', null);
    $productId = $app->productDomain()->createProduct($ownerId, [
        'sku' => 'P43-' . strtoupper($suffix), 'name' => $prefix . ' Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $baseUnit = $app->products()->findProductUnitByProductAndUnit($productId, $pieceUnitId);
    if ($baseUnit === null) {
        throw new RuntimeException('Phase 4.3 base product unit was not created.');
    }
    $productUnitId = (int) $baseUnit['id'];
    $app->productDomain()->setPrice($ownerId, $productUnitId, 'retail', 'KHR', '100.0000');
    $receiptId = $app->inventory()->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening', 'reference_no' => $keyPrefix . 'OPEN', 'location_id' => $locationId,
        'items' => [['product_unit_id' => $productUnitId, 'quantity' => '30', 'cost_per_base_khr' => '10.000000']],
    ]);
    $app->inventory()->confirmReceipt($ownerId, $receiptId);
    $customerId = $app->paymentService()->createCustomer($ownerId, [
        'name' => $prefix . ' Customer', 'credit_limit_khr' => '10000.0000', 'credit_enabled' => '1', 'payment_terms_days' => '0',
    ]);
    $customerIds[] = $customerId;

    $creditNotes = $app->creditNoteService();
    $payments = $app->paymentService();
    $partialSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $stockStatement = $pdo->prepare('SELECT qty_base FROM stock_balances WHERE product_id = ? AND location_id = ? AND batch_id IS NULL');
    $stockStatement->execute([$productId, $locationId]);
    $stockBefore = (string) $stockStatement->fetchColumn();
    $costCountStatement = $pdo->prepare("SELECT COUNT(*) FROM inventory_cost_entries WHERE source_id = ? AND source_type = 'sale'");
    $costCountStatement->execute([$partialSale]);
    $costCountBefore = (int) $costCountStatement->fetchColumn();
    phase43ExpectDomain(fn () => $creditNotes->createDraft($staffId, ['credit_note_key' => $keyPrefix . 'STAFF-CN-00000001', 'sale_id' => $partialSale, 'amount_khr' => '1', 'reason' => 'Unauthorized']), 'Staff cannot create a credit note');
    $partialNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'PARTIAL-CN-000001', 'sale_id' => $partialSale, 'amount_khr' => '40.0000', 'reason' => 'Partial customer adjustment']);
    $partialDuplicate = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'PARTIAL-CN-000001', 'sale_id' => $partialSale, 'amount_khr' => '40.0000', 'reason' => 'Partial customer adjustment']);
    $creditNotes->approve($ownerId, $partialNote['credit_note_id']);
    $partialPosted = $creditNotes->post($ownerId, $partialNote['credit_note_id']);
    $partialPostDuplicate = $creditNotes->post($ownerId, $partialNote['credit_note_id']);
    $partialStatus = $payments->receivableStatus($staffId, $partialSale);
    $stockStatement->execute([$productId, $locationId]);
    $stockAfter = (string) $stockStatement->fetchColumn();
    $costCountStatement->execute([$partialSale]);
    $costCountAfter = (int) $costCountStatement->fetchColumn();
    phase43Check($partialNote['created'] && !$partialDuplicate['created'] && $partialPosted['receivable_reduction_khr'] === '40.0000' && $partialPosted['excess_credit_khr'] === '0.0000' && !$partialPostDuplicate['posted'] && $partialStatus['status'] === 'partial' && $partialStatus['outstanding_khr'] === '60.0000' && $stockBefore === $stockAfter && $costCountBefore === $costCountAfter, 'posted credit note is idempotent, reduces the receivable, and never moves stock or COGS');
    $overNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'OVER-CN-000000001', 'sale_id' => $partialSale, 'amount_khr' => '61.0000', 'reason' => 'Must be rejected at posting']);
    $creditNotes->approve($ownerId, $overNote['credit_note_id']);
    phase43ExpectDomain(fn () => $creditNotes->post($ownerId, $overNote['credit_note_id']), 'credit note cannot exceed the invoice amount remaining after prior credit notes');

    $pendingInvoiceSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $pendingInvoicePayment = $payments->recordPayment($staffId, [
        'payment_key' => $keyPrefix . 'PENDING-IN-0000001', 'payment_method' => 'bank', 'currency' => 'KHR',
        'amount_original' => '100', 'exchange_rate_to_khr' => '1', 'reference_no' => 'P43-PENDING-IN', 'customer_id' => $customerId,
        'allocations' => [['sale_id' => $pendingInvoiceSale, 'amount_khr' => '100']],
    ]);
    $pendingInvoiceNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'PENDING-IN-CN-001', 'sale_id' => $pendingInvoiceSale, 'amount_khr' => '100', 'reason' => 'Await payment verification']);
    $creditNotes->approve($ownerId, $pendingInvoiceNote['credit_note_id']);
    phase43ExpectDomain(fn () => $creditNotes->post($ownerId, $pendingInvoiceNote['credit_note_id']), 'credit note cannot post while an invoice payment is awaiting verification');
    $payments->verifyPayment($ownerId, $pendingInvoicePayment['payment_id'], false, 'Transfer did not clear');
    $pendingInvoicePosted = $creditNotes->post($ownerId, $pendingInvoiceNote['credit_note_id']);
    phase43Check($pendingInvoicePosted['receivable_reduction_khr'] === '100.0000', 'credit note can post safely after the pending invoice payment is rejected');

    $voidSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $voidNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'VOID-CN-000000001', 'sale_id' => $voidSale, 'amount_khr' => '20.0000', 'reason' => 'Void interaction']);
    $creditNotes->approve($ownerId, $voidNote['credit_note_id']);
    $creditNotes->post($ownerId, $voidNote['credit_note_id']);
    phase43ExpectDomain(fn () => $app->salesService()->voidSale($ownerId, $voidSale, 'Cannot void with financial credit', true), 'sale void is blocked while a posted credit note exists');
    $creditNotes->reverse($ownerId, $voidNote['credit_note_id'], 'Reverse before sale cancellation');
    $voided = $app->salesService()->voidSale($ownerId, $voidSale, 'Customer cancellation after correction reversal', true);
    phase43ExpectDomain(fn () => $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'VOIDED-CN-0000001', 'sale_id' => $voidSale, 'amount_khr' => '1', 'reason' => 'Invalid voided sale']), 'credit note cannot be created for a voided sale');
    phase43Check($voided['voided'], 'reversing a posted credit note preserves the valid compensating path to sale void');

    $rollbackSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $rollbackNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'ROLLBACK-CN-000001', 'sale_id' => $rollbackSale, 'amount_khr' => '10.0000', 'reason' => 'Rollback guard']);
    $creditNotes->approve($ownerId, $rollbackNote['credit_note_id']);
    $creditNotes->post($ownerId, $rollbackNote['credit_note_id']);
    $seedReversal = $pdo->prepare('INSERT INTO credit_note_reversals (credit_note_id, amount_khr, reason, reversed_by) VALUES (?, ?, ?, ?)');
    $seedReversal->execute([$rollbackNote['credit_note_id'], '10.0000', 'Injected test conflict', $ownerId]);
    phase43ExpectDomain(fn () => $creditNotes->reverse($ownerId, $rollbackNote['credit_note_id'], 'This update must roll back'), 'failed reversal write rolls back the credit-note status change');
    $rollbackAfterFailure = $app->creditNotes()->find($rollbackNote['credit_note_id']);
    phase43Check($rollbackAfterFailure !== null && $rollbackAfterFailure['status'] === 'posted', 'failed reversal leaves the original posted credit note intact');

    $paidSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $cashPayment = $payments->recordPayment($staffId, [
        'payment_key' => $keyPrefix . 'PAID-CASH-0000001', 'payment_method' => 'cash', 'currency' => 'KHR',
        'amount_original' => '100.0000', 'exchange_rate_to_khr' => '1.000000', 'customer_id' => $customerId,
        'allocations' => [['sale_id' => $paidSale, 'amount_khr' => '100.0000']],
    ]);
    $excessNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'EXCESS-CN-0000001', 'sale_id' => $paidSale, 'amount_khr' => '100.0000', 'reason' => 'Paid-sale customer credit']);
    $creditNotes->approve($ownerId, $excessNote['credit_note_id']);
    $excessPosted = $creditNotes->post($ownerId, $excessNote['credit_note_id']);
    phase43Check($cashPayment['verification_status'] === 'verified' && $excessPosted['receivable_reduction_khr'] === '0.0000' && $excessPosted['excess_credit_khr'] === '100.0000', 'paid invoice credit note becomes controlled refundable customer credit without a negative receivable');

    phase43ExpectDomain(fn () => $creditNotes->createRefundDraft($staffId, ['refund_key' => $keyPrefix . 'STAFF-RF-00000001', 'credit_note_id' => $excessNote['credit_note_id'], 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '1', 'exchange_rate_to_khr' => '1', 'reason' => 'Unauthorized']), 'Staff cannot create a refund');
    phase43ExpectDomain(fn () => $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'BAD-RATE-00000001', 'credit_note_id' => $excessNote['credit_note_id'], 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '1', 'exchange_rate_to_khr' => '2', 'reason' => 'Bad rate']), 'KHR refunds require an exchange rate of exactly one');
    $cashRefund = $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'CASH-RF-000000001', 'credit_note_id' => $excessNote['credit_note_id'], 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '40.0000', 'exchange_rate_to_khr' => '1.000000', 'reason' => 'Cash refund']);
    $cashRefundDuplicate = $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'CASH-RF-000000001', 'credit_note_id' => $excessNote['credit_note_id'], 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '40.0000', 'exchange_rate_to_khr' => '1.000000', 'reason' => 'Cash refund']);
    $creditNotes->approveRefund($ownerId, $cashRefund['refund_id']);
    $cashReleased = $creditNotes->postRefund($ownerId, $cashRefund['refund_id']);
    $cashReleasedAgain = $creditNotes->postRefund($ownerId, $cashRefund['refund_id']);
    phase43Check($cashRefund['created'] && !$cashRefundDuplicate['created'] && $cashReleased['verification_status'] === 'verified' && !$cashReleasedAgain['posted'], 'cash refund is idempotent and posts an immutable verified outgoing payment');

    $bankRefund = $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'BANK-RF-000000001', 'credit_note_id' => $excessNote['credit_note_id'], 'payment_method' => 'bank', 'currency' => 'KHR', 'amount_original' => '50.0000', 'exchange_rate_to_khr' => '1.000000', 'reference_no' => 'P43-BANK-REF', 'reason' => 'Bank refund']);
    $creditNotes->approveRefund($ownerId, $bankRefund['refund_id']);
    $bankReleased = $creditNotes->postRefund($ownerId, $bankRefund['refund_id']);
    $bankPayment = $app->creditNotes()->findRefund($bankRefund['refund_id']);
    phase43ExpectDomain(fn () => $payments->verifyPayment($ownerId, (int) $bankPayment['payment_id'], true), 'incoming-payment verification route cannot verify an outgoing refund');
    $bankVerified = $creditNotes->verifyRefund($ownerId, $bankRefund['refund_id'], true);
    phase43Check($bankReleased['verification_status'] === 'pending' && $bankVerified['changed'] && $bankVerified['verification_status'] === 'verified', 'bank refund remains pending until Owner manually verifies completion');

    $pendingSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $payments->recordPayment($staffId, ['payment_key' => $keyPrefix . 'PENDING-CASH-0001', 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '100', 'exchange_rate_to_khr' => '1', 'customer_id' => $customerId, 'allocations' => [['sale_id' => $pendingSale, 'amount_khr' => '100']]]);
    $pendingNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'PENDING-CN-0000001', 'sale_id' => $pendingSale, 'amount_khr' => '100', 'reason' => 'Pending refund credit']);
    $creditNotes->approve($ownerId, $pendingNote['credit_note_id']); $creditNotes->post($ownerId, $pendingNote['credit_note_id']);
    $pendingRefund = $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'PENDING-RF-0000001', 'credit_note_id' => $pendingNote['credit_note_id'], 'payment_method' => 'khqr', 'currency' => 'KHR', 'amount_original' => '20', 'exchange_rate_to_khr' => '1', 'reference_no' => 'P43-KHQR-REF', 'reason' => 'Cancel before completion']);
    $creditNotes->approveRefund($ownerId, $pendingRefund['refund_id']); $creditNotes->postRefund($ownerId, $pendingRefund['refund_id']);
    $pendingReversed = $creditNotes->reversePendingRefund($ownerId, $pendingRefund['refund_id'], 'KHQR did not complete');
    phase43Check($pendingReversed['reversed'], 'unverified bank or KHQR refund can be explicitly reversed without deleting history');

    $raceSale = phase43ConfirmedSale($app, $staffId, $productUnitId, $locationId, ['customer_id' => $customerId], $saleIds);
    $payments->recordPayment($staffId, ['payment_key' => $keyPrefix . 'RACE-CASH-00000001', 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '100', 'exchange_rate_to_khr' => '1', 'customer_id' => $customerId, 'allocations' => [['sale_id' => $raceSale, 'amount_khr' => '100']]]);
    $raceNote = $creditNotes->createDraft($ownerId, ['credit_note_key' => $keyPrefix . 'RACE-CN-000000001', 'sale_id' => $raceSale, 'amount_khr' => '100', 'reason' => 'Concurrent refund guard']);
    $creditNotes->approve($ownerId, $raceNote['credit_note_id']); $creditNotes->post($ownerId, $raceNote['credit_note_id']);
    $raceOne = $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'RACE-RF-000000001', 'credit_note_id' => $raceNote['credit_note_id'], 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '70', 'exchange_rate_to_khr' => '1', 'reason' => 'Concurrent one']);
    $raceTwo = $creditNotes->createRefundDraft($ownerId, ['refund_key' => $keyPrefix . 'RACE-RF-000000002', 'credit_note_id' => $raceNote['credit_note_id'], 'payment_method' => 'cash', 'currency' => 'KHR', 'amount_original' => '70', 'exchange_rate_to_khr' => '1', 'reason' => 'Concurrent two']);
    [$first, $second, $firstExit, $secondExit] = phase43RunConcurrentApproval($ownerId, $raceOne['refund_id'], $raceTwo['refund_id']);
    $raceOutcomes = [$first, $second]; sort($raceOutcomes);
    phase43Check($firstExit === 0 && $secondExit === 0 && $raceOutcomes === ['approved', 'rejected'], 'concurrent refunds cannot reserve more than the originating customer credit');

    $financial = $creditNotes->financialReconciliation($ownerId);
    phase43Check(Decimal::compare($financial['net_sales_revenue'], Decimal::signedSubtract($financial['gross_sales_revenue'], $financial['credit_notes_khr'], 4), 4) === 0 && $financial['verified_refunds_khr'] === '90.0000' && $financial['refundable_customer_credit'] === '210.0000', 'financial reconciliation reports net sales adjustments and only verified refunds reduce controlled customer credit');
    $auditStatement = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action IN ('credit_note.posted', 'credit_note.reversed', 'refund.posted', 'refund.verified', 'refund.reversed') AND actor_id = ?");
    $auditStatement->execute([$ownerId]);
    phase43Check((int) $auditStatement->fetchColumn() >= 5, 'credit-note and refund actions retain an Owner audit trail');

    Session::clearAuthentication();
    phase43Check($app->auth()->attemptLogin($ownerUsername, $ownerPassword) !== null, 'Owner session is available for credit-note CSRF testing');
    $_POST = ['_csrf' => 'invalid-token'];
    try {
        (new CreditNoteController($app))->createCreditNote();
        phase43Check(false, 'credit-note POST rejects invalid CSRF tokens');
    } catch (HttpException $exception) {
        phase43Check($exception->statusCode() === 419, 'credit-note POST rejects invalid CSRF tokens');
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
    phase43Cleanup($pdo, $ownerId, $staffId, $categoryId, $productId, $receiptId, $saleIds, $customerIds, $keyPrefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 4.3 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 4.3 credit-note and refund integration checks passed.' . PHP_EOL);
