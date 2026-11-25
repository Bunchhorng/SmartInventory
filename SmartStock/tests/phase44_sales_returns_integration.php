<?php
declare(strict_types=1);

use App\Controllers\SalesReturnController;
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
$prefix = 'P44 ' . $suffix;
$keyPrefix = 'P44-' . strtoupper($suffix) . '-';
$ownerUsername = 'phase44_owner_' . $suffix;
$staffUsername = 'phase44_staff_' . $suffix;
$ownerPassword = 'Phase44Owner!' . $suffix;
$staffPassword = 'Phase44Staff!' . $suffix;
$failures = [];
$ownerId = null;
$staffId = null;
$categoryId = null;
$productId = null;
$receiptIds = [];
$saleIds = [];
$customerIds = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase44-test-sessions';

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase44Check(bool $condition, string $message): void
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
function phase44ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase44Check(false, $message);
    } catch (DomainException) {
        phase44Check(true, $message);
    }
}

/** @param list<int> $saleIds @param list<int> $receiptIds @param list<int> $customerIds */
function phase44Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $categoryId, ?int $productId, array $receiptIds, array $saleIds, array $customerIds, string $keyPrefix): void
{
    foreach ($saleIds as $saleId) {
        $deleteWriteoffs = $pdo->prepare(
            'DELETE wo FROM sale_return_writeoffs wo
             JOIN sale_return_items sri ON sri.id = wo.sale_return_item_id
             JOIN sale_returns sr ON sr.id = sri.sale_return_id WHERE sr.sale_id = ?'
        );
        $deleteWriteoffs->execute([$saleId]);
        $deleteHolds = $pdo->prepare(
            'DELETE qh FROM sale_return_quarantine_holds qh
             JOIN sale_return_items sri ON sri.id = qh.sale_return_item_id
             JOIN sale_returns sr ON sr.id = sri.sale_return_id WHERE sr.sale_id = ?'
        );
        $deleteHolds->execute([$saleId]);
        $deleteReturnCosts = $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_id IN (SELECT id FROM sale_returns WHERE sale_id = ?) AND source_type = 'sale_return'");
        $deleteReturnCosts->execute([$saleId]);
        $deleteReturnMovements = $pdo->prepare("DELETE FROM stock_movements WHERE source_id IN (SELECT id FROM sale_returns WHERE sale_id = ?) AND source_type = 'sale_return'");
        $deleteReturnMovements->execute([$saleId]);
        $deleteReturnItems = $pdo->prepare('DELETE sri FROM sale_return_items sri JOIN sale_returns sr ON sr.id = sri.sale_return_id WHERE sr.sale_id = ?');
        $deleteReturnItems->execute([$saleId]);
        $deleteReturns = $pdo->prepare('DELETE FROM sale_returns WHERE sale_id = ?');
        $deleteReturns->execute([$saleId]);

        $deleteRefunds = $pdo->prepare('DELETE r FROM refunds r JOIN credit_notes cn ON cn.id = r.credit_note_id WHERE cn.sale_id = ?');
        $deleteRefunds->execute([$saleId]);
        $deleteApplications = $pdo->prepare('DELETE a FROM credit_note_receivable_applications a JOIN credit_notes cn ON cn.id = a.credit_note_id WHERE cn.sale_id = ?');
        $deleteApplications->execute([$saleId]);
        $deleteReversals = $pdo->prepare('DELETE cr FROM credit_note_reversals cr JOIN credit_notes cn ON cn.id = cr.credit_note_id WHERE cn.sale_id = ?');
        $deleteReversals->execute([$saleId]);
        $deleteNotes = $pdo->prepare('DELETE FROM credit_notes WHERE sale_id = ?');
        $deleteNotes->execute([$saleId]);
        $deleteAllocations = $pdo->prepare('DELETE ra FROM receivable_allocations ra JOIN receivables r ON r.id = ra.receivable_id WHERE r.sale_id = ?');
        $deleteAllocations->execute([$saleId]);
        $deleteReceivable = $pdo->prepare('DELETE FROM receivables WHERE sale_id = ?');
        $deleteReceivable->execute([$saleId]);
        $deleteSaleCosts = $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_id = ? AND source_type IN ('sale', 'sale_void')");
        $deleteSaleCosts->execute([$saleId]);
        $deleteSaleMovements = $pdo->prepare("DELETE FROM stock_movements WHERE source_id = ? AND source_type IN ('sale', 'sale_void')");
        $deleteSaleMovements->execute([$saleId]);
        $deleteItems = $pdo->prepare('DELETE FROM sale_items WHERE sale_id = ?');
        $deleteItems->execute([$saleId]);
        $deleteSale = $pdo->prepare('DELETE FROM sales WHERE id = ?');
        $deleteSale->execute([$saleId]);
    }
    foreach ($receiptIds as $receiptId) {
        $deleteReceiptCosts = $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')");
        $deleteReceiptCosts->execute([$receiptId]);
        $deleteReceiptMovements = $pdo->prepare("DELETE FROM stock_movements WHERE source_id = ? AND source_type IN ('inventory_receipt', 'inventory_receipt_reversal')");
        $deleteReceiptMovements->execute([$receiptId]);
        $deleteReceiptItems = $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id = ?');
        $deleteReceiptItems->execute([$receiptId]);
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
    $deletePayments = $pdo->prepare('DELETE FROM payments WHERE payment_key LIKE ?');
    $deletePayments->execute([$keyPrefix . '%']);
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

/** @param list<int> $saleIds */
function phase44ConfirmedSale(App\Core\App $app, int $actorId, int $customerId, int $productUnitId, int $locationId, string $quantity, array &$saleIds): int
{
    $saleId = $app->salesService()->createDraftSale($actorId, [
        'sale_channel' => 'retail', 'settlement_type' => 'credit', 'customer_id' => $customerId,
        'items' => [['product_unit_id' => $productUnitId, 'location_id' => $locationId, 'quantity' => $quantity]],
    ]);
    $saleIds[] = $saleId;
    $app->salesService()->confirmSale($actorId, $saleId);
    return $saleId;
}

function phase44PostedCreditNote(App\Core\App $app, int $ownerId, int $saleId, string $amount, string $key, string $reason): int
{
    $draft = $app->creditNoteService()->createDraft($ownerId, [
        'credit_note_key' => $key, 'sale_id' => $saleId, 'amount_khr' => $amount, 'reason' => $reason,
    ]);
    $app->creditNoteService()->approve($ownerId, $draft['credit_note_id']);
    $app->creditNoteService()->post($ownerId, $draft['credit_note_id']);
    return $draft['credit_note_id'];
}

/** @return array<string, mixed> */
function phase44OnlySaleItem(App\Core\App $app, int $saleId): array
{
    $items = $app->sales()->items($saleId);
    if (count($items) !== 1) {
        throw new RuntimeException('Expected one sale item.');
    }
    return $items[0];
}

/** @return array<string, mixed> */
function phase44OnlyReturnItem(App\Core\App $app, int $returnId): array
{
    $items = $app->saleReturns()->items($returnId);
    if (count($items) !== 1) {
        throw new RuntimeException('Expected one return item.');
    }
    return $items[0];
}

/** @return array{return_id:int,return_item_id:int} */
function phase44CreateAndApprove(App\Core\App $app, int $staffId, int $ownerId, int $saleId, int $creditNoteId, int $saleItemId, string $quantity, string $condition, string $key, string $reason): array
{
    $draft = $app->salesReturnService()->createDraft($staffId, [
        'return_key' => $key, 'sale_id' => $saleId, 'credit_note_id' => $creditNoteId, 'reason' => $reason,
        'items' => [['sale_item_id' => $saleItemId, 'requested_qty_base' => $quantity, 'condition_code' => $condition]],
    ]);
    $returnItem = phase44OnlyReturnItem($app, $draft['return_id']);
    $app->salesReturnService()->approve($ownerId, $draft['return_id'], [
        'approved_items' => [['return_item_id' => (int) $returnItem['id'], 'approved_qty_base' => $quantity]],
    ]);
    return ['return_id' => $draft['return_id'], 'return_item_id' => (int) $returnItem['id']];
}

/** @return array{0:string,1:string,2:int,3:int} */
function phase44RunConcurrentConfirmation(int $ownerId, int $firstReturnId, int $secondReturnId): array
{
    $worker = __DIR__ . '/phase44_return_confirm_worker.php';
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $first = proc_open([PHP_BINARY, $worker, (string) $ownerId, (string) $firstReturnId], $descriptors, $firstPipes, dirname(__DIR__));
    $second = proc_open([PHP_BINARY, $worker, (string) $ownerId, (string) $secondReturnId], $descriptors, $secondPipes, dirname(__DIR__));
    if (!is_resource($first) || !is_resource($second)) {
        throw new RuntimeException('Unable to start concurrent return-confirmation workers.');
    }
    fclose($firstPipes[0]); fclose($secondPipes[0]);
    $firstOut = trim(stream_get_contents($firstPipes[1])); $firstErr = trim(stream_get_contents($firstPipes[2]));
    $secondOut = trim(stream_get_contents($secondPipes[1])); $secondErr = trim(stream_get_contents($secondPipes[2]));
    fclose($firstPipes[1]); fclose($firstPipes[2]); fclose($secondPipes[1]); fclose($secondPipes[2]);
    $firstExit = proc_close($first); $secondExit = proc_close($second);
    if ($firstErr !== '' || $secondErr !== '') {
        throw new RuntimeException(trim($firstErr . ' ' . $secondErr));
    }
    return [$firstOut, $secondOut, $firstExit, $secondExit];
}

try {
    $phase44Tables = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
         AND table_name IN ('sale_return_writeoffs', 'sale_return_quarantine_holds')"
    )->fetchColumn();
    $phase44Columns = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND
         ((table_name = 'sale_returns' AND column_name IN ('return_key', 'credit_note_id', 'requested_by', 'approved_by', 'confirmed_by', 'financial_amount_khr'))
          OR (table_name = 'sale_return_items' AND column_name IN ('approved_qty_base', 'condition_code', 'financial_amount_khr', 'cost_basis_khr')))"
    )->fetchColumn();
    phase44Check($phase44Tables === 2 && $phase44Columns === 10, 'Phase 4.4 physical-return migration is present in the disposable test database');

    Session::start();
    phase44Check($app->auth()->createInitialOwner($ownerUsername, 'Phase 4.4 Test Owner', $ownerPassword) === null, 'initial Owner can be created for return authorization');
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    if ($ownerId === null) { throw new RuntimeException('Owner creation failed.'); }
    phase44Check($app->auth()->createStaff($ownerId, $staffUsername, 'Phase 4.4 Test Staff', $staffPassword) === null, 'Owner can create Staff for return-request authorization');
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    if ($staffId === null) { throw new RuntimeException('Staff creation failed.'); }

    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN' AND is_active = 1 LIMIT 1")->fetchColumn();
    $pieceUnitId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs' AND status = 'active' LIMIT 1")->fetchColumn();
    phase44Check($locationId > 0 && $pieceUnitId > 0, 'seeded location and base unit are available');
    $categoryId = $app->productDomain()->createCategory($ownerId, $prefix . ' Returns', null);
    $productId = $app->productDomain()->createProduct($ownerId, [
        'sku' => 'P44-' . strtoupper($suffix), 'name' => $prefix . ' Product', 'category_id' => $categoryId,
        'base_unit_id' => $pieceUnitId, 'kind' => 'physical',
    ]);
    $baseUnit = $app->products()->findProductUnitByProductAndUnit($productId, $pieceUnitId);
    if ($baseUnit === null) { throw new RuntimeException('Base product unit was not created.'); }
    $productUnitId = (int) $baseUnit['id'];
    $app->productDomain()->setPrice($ownerId, $productUnitId, 'retail', 'KHR', '10.0000');
    $openingReceipt = $app->inventory()->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening', 'reference_no' => $keyPrefix . 'OPEN', 'location_id' => $locationId,
        'items' => [['product_unit_id' => $productUnitId, 'quantity' => '150', 'cost_per_base_khr' => '10.000000']],
    ]);
    $receiptIds[] = $openingReceipt;
    $app->inventory()->confirmReceipt($ownerId, $openingReceipt);
    $customerId = $app->paymentService()->createCustomer($ownerId, [
        'name' => $prefix . ' Customer', 'credit_limit_khr' => '100000.0000', 'credit_enabled' => '1', 'payment_terms_days' => '0',
    ]);
    $customerIds[] = $customerId;

    $returns = $app->salesReturnService();
    $creditNotes = $app->creditNoteService();
    $stockQuantity = $pdo->prepare('SELECT qty_base FROM stock_balances WHERE product_id = ? AND location_id = ? AND batch_id IS NULL');
    $stockQuantity->execute([$productId, $locationId]);

    $partialSale = phase44ConfirmedSale($app, $staffId, $customerId, $productUnitId, $locationId, '10', $saleIds);
    $partialSaleItem = phase44OnlySaleItem($app, $partialSale);
    $partialNoteId = phase44PostedCreditNote($app, $ownerId, $partialSale, '100.0000', $keyPrefix . 'PARTIAL-CN-001', 'Partial and full return authorization');
    $laterReceipt = $app->inventory()->createDraftReceipt($ownerId, [
        'receipt_type' => 'purchase', 'reference_no' => $keyPrefix . 'LATER-COST', 'location_id' => $locationId,
        'items' => [['product_unit_id' => $productUnitId, 'quantity' => '10', 'cost_per_base_khr' => '20.000000']],
    ]);
    $receiptIds[] = $laterReceipt;
    $app->inventory()->confirmReceipt($ownerId, $laterReceipt);
    $stockQuantity->execute([$productId, $locationId]);
    $stockBeforePartial = (string) $stockQuantity->fetchColumn();
    $totalsBeforePartial = $app->sales()->confirmedTotals();
    $partialDraft = $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'PARTIAL-RETURN-01', 'sale_id' => $partialSale, 'credit_note_id' => $partialNoteId, 'reason' => 'Customer returned three units',
        'items' => [['sale_item_id' => (int) $partialSaleItem['id'], 'requested_qty_base' => '4', 'condition_code' => 'resellable']],
    ]);
    $partialDuplicate = $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'PARTIAL-RETURN-01', 'sale_id' => $partialSale, 'credit_note_id' => $partialNoteId, 'reason' => 'Duplicate browser retry',
        'items' => [['sale_item_id' => (int) $partialSaleItem['id'], 'requested_qty_base' => '4', 'condition_code' => 'resellable']],
    ]);
    $partialReturnItem = phase44OnlyReturnItem($app, $partialDraft['return_id']);
    phase44ExpectDomain(fn () => $returns->approve($staffId, $partialDraft['return_id'], ['approved_items' => [['return_item_id' => (int) $partialReturnItem['id'], 'approved_qty_base' => '3']]]), 'Staff cannot approve a physical return');
    $partialApproved = $returns->approve($ownerId, $partialDraft['return_id'], ['approved_items' => [['return_item_id' => (int) $partialReturnItem['id'], 'approved_qty_base' => '3']]]);
    phase44ExpectDomain(fn () => $returns->confirm($staffId, $partialDraft['return_id']), 'Staff cannot confirm a physical return');
    $partialConfirmed = $returns->confirm($ownerId, $partialDraft['return_id']);
    $partialConfirmedAgain = $returns->confirm($ownerId, $partialDraft['return_id']);
    $stockQuantity->execute([$productId, $locationId]);
    $stockAfterPartial = (string) $stockQuantity->fetchColumn();
    $partialMovement = $pdo->prepare("SELECT qty_delta, unit_cost_khr FROM stock_movements WHERE source_type = 'sale_return' AND source_id = ?");
    $partialMovement->execute([$partialDraft['return_id']]);
    $partialMovementRow = $partialMovement->fetch();
    $partialCost = $pdo->prepare("SELECT qty_delta, value_delta_khr, unit_cost_snapshot_khr, costing_type FROM inventory_cost_entries WHERE source_type = 'sale_return' AND source_id = ?");
    $partialCost->execute([$partialDraft['return_id']]);
    $partialCostRow = $partialCost->fetch();
    $totalsAfterPartial = $app->sales()->confirmedTotals();
    phase44Check($partialDraft['created'] && !$partialDuplicate['created'] && $partialApproved['approved'] && $partialConfirmed['confirmed'] && !$partialConfirmedAgain['confirmed']
        && $stockAfterPartial === Decimal::add($stockBeforePartial, '3.000', 3)
        && is_array($partialMovementRow) && $partialMovementRow['qty_delta'] === '3.000' && $partialMovementRow['unit_cost_khr'] === '10.000000'
        && is_array($partialCostRow) && $partialCostRow['qty_delta'] === '3.000' && $partialCostRow['value_delta_khr'] === '30.0000' && $partialCostRow['unit_cost_snapshot_khr'] === '10.000000' && $partialCostRow['costing_type'] === 'sale_return_restock'
        && Decimal::compare($totalsAfterPartial['cogs'], Decimal::subtract($totalsBeforePartial['cogs'], '30.0000', 4), 4) === 0,
        'partial resellable return is idempotent, restores available stock, uses the original COGS snapshot, and reverses report COGS once');
    phase44ExpectDomain(fn () => $creditNotes->reverse($ownerId, $partialNoteId, 'Cannot reverse physical posting'), 'a posted credit note cannot be reversed after its physical return is confirmed');

    phase44ExpectDomain(fn () => $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'OVERFLOW-RETURN-1', 'sale_id' => $partialSale, 'credit_note_id' => $partialNoteId, 'reason' => 'Too many units',
        'items' => [['sale_item_id' => (int) $partialSaleItem['id'], 'requested_qty_base' => '8', 'condition_code' => 'resellable']],
    ]), 'return request cannot exceed quantity remaining after confirmed returns');
    $fullReturn = phase44CreateAndApprove($app, $staffId, $ownerId, $partialSale, $partialNoteId, (int) $partialSaleItem['id'], '7', 'resellable', $keyPrefix . 'FULL-RETURN-0001', 'Return the remaining sale quantity');
    $fullConfirmed = $returns->confirm($ownerId, $fullReturn['return_id']);
    $totalsAfterFull = $app->sales()->confirmedTotals();
    phase44ExpectDomain(fn () => $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'ALREADY-RETURNED1', 'sale_id' => $partialSale, 'credit_note_id' => $partialNoteId, 'reason' => 'No quantity remains',
        'items' => [['sale_item_id' => (int) $partialSaleItem['id'], 'requested_qty_base' => '1', 'condition_code' => 'resellable']],
    ]), 'previously returned quantity prevents over-returning a fully returned sale line');
    phase44Check($fullConfirmed['confirmed'] && $fullConfirmed['financial_amount_khr'] === '70.0000' && Decimal::compare($totalsAfterFull['cogs'], Decimal::subtract($totalsBeforePartial['cogs'], '100.0000', 4), 4) === 0, 'full return reconciles the final COGS and financial rounding amount to the original sale line');

    $financialSale = phase44ConfirmedSale($app, $ownerId, $customerId, $productUnitId, $locationId, '2', $saleIds);
    $financialItem = phase44OnlySaleItem($app, $financialSale);
    $limitedNoteId = phase44PostedCreditNote($app, $ownerId, $financialSale, '10.0000', $keyPrefix . 'LIMITED-CN-001', 'Limited financial credit');
    phase44ExpectDomain(fn () => $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'NO-MATCH-CN-0001', 'sale_id' => $financialSale, 'credit_note_id' => $partialNoteId, 'reason' => 'Wrong financial document',
        'items' => [['sale_item_id' => (int) $financialItem['id'], 'requested_qty_base' => '1', 'condition_code' => 'resellable']],
    ]), 'return must use a posted credit note belonging to the same sale');
    phase44ExpectDomain(fn () => $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'OVER-CREDIT-RETURN', 'sale_id' => $financialSale, 'credit_note_id' => $limitedNoteId, 'reason' => 'Financial credit too small',
        'items' => [['sale_item_id' => (int) $financialItem['id'], 'requested_qty_base' => '2', 'condition_code' => 'resellable']],
    ]), 'physical return cannot exceed the linked financial credit-note amount');

    $paymentCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    foreach (['damaged', 'expired', 'quarantine'] as $condition) {
        $saleId = phase44ConfirmedSale($app, $ownerId, $customerId, $productUnitId, $locationId, '1', $saleIds);
        $saleItem = phase44OnlySaleItem($app, $saleId);
        $noteId = phase44PostedCreditNote($app, $ownerId, $saleId, '10.0000', $keyPrefix . strtoupper($condition) . '-CN-0001', ucfirst($condition) . ' return credit');
        $stockQuantity->execute([$productId, $locationId]);
        $before = (string) $stockQuantity->fetchColumn();
        $return = phase44CreateAndApprove($app, $staffId, $ownerId, $saleId, $noteId, (int) $saleItem['id'], '1', $condition, $keyPrefix . strtoupper($condition) . '-RETURN1', ucfirst($condition) . ' goods');
        $confirmed = $returns->confirm($ownerId, $return['return_id']);
        $stockQuantity->execute([$productId, $locationId]);
        $after = (string) $stockQuantity->fetchColumn();
        $returnMovements = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type = 'sale_return' AND source_id = ?");
        $returnMovements->execute([$return['return_id']]);
        if ($condition === 'quarantine') {
            $held = $pdo->prepare('SELECT qty_base, cost_basis_khr, status FROM sale_return_quarantine_holds WHERE sale_return_item_id = ?');
            $held->execute([$return['return_item_id']]);
            $heldRow = $held->fetch();
            phase44Check($confirmed['confirmed'] && $before === $after && (int) $returnMovements->fetchColumn() === 0 && is_array($heldRow) && $heldRow['qty_base'] === '1.000' && $heldRow['cost_basis_khr'] === $saleItem['cogs_khr'] && $heldRow['status'] === 'held', 'quarantined return stays in a traceable unavailable hold with no stock restoration');
        } else {
            $writeoff = $pdo->prepare('SELECT condition_code, amount_khr FROM sale_return_writeoffs WHERE sale_return_item_id = ?');
            $writeoff->execute([$return['return_item_id']]);
            $writeoffRow = $writeoff->fetch();
            phase44Check($confirmed['confirmed'] && $before === $after && (int) $returnMovements->fetchColumn() === 0 && is_array($writeoffRow) && $writeoffRow['condition_code'] === $condition && $writeoffRow['amount_khr'] === $saleItem['cogs_khr'], ucfirst($condition) . ' return creates a separate original-COGS write-off and never restores available stock');
        }
    }
    $paymentCountAfter = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    phase44Check($paymentCountBefore === $paymentCountAfter, 'physical return approval and confirmation do not create or post a refund payment');

    $raceSale = phase44ConfirmedSale($app, $ownerId, $customerId, $productUnitId, $locationId, '10', $saleIds);
    $raceItem = phase44OnlySaleItem($app, $raceSale);
    $raceNoteId = phase44PostedCreditNote($app, $ownerId, $raceSale, '100.0000', $keyPrefix . 'RACE-CN-0000001', 'Concurrent return guard');
    $raceOne = phase44CreateAndApprove($app, $staffId, $ownerId, $raceSale, $raceNoteId, (int) $raceItem['id'], '7', 'resellable', $keyPrefix . 'RACE-RETURN-0001', 'Race one');
    $raceTwo = phase44CreateAndApprove($app, $staffId, $ownerId, $raceSale, $raceNoteId, (int) $raceItem['id'], '7', 'resellable', $keyPrefix . 'RACE-RETURN-0002', 'Race two');
    [$firstResult, $secondResult, $firstExit, $secondExit] = phase44RunConcurrentConfirmation($ownerId, $raceOne['return_id'], $raceTwo['return_id']);
    $raceResults = [$firstResult, $secondResult]; sort($raceResults);
    phase44Check($firstExit === 0 && $secondExit === 0 && $raceResults === ['confirmed', 'rejected'], 'concurrent return confirmations serialize and cannot over-return stock or financial credit');

    $rollbackSale = phase44ConfirmedSale($app, $ownerId, $customerId, $productUnitId, $locationId, '1', $saleIds);
    $rollbackItem = phase44OnlySaleItem($app, $rollbackSale);
    $rollbackNoteId = phase44PostedCreditNote($app, $ownerId, $rollbackSale, '10.0000', $keyPrefix . 'ROLLBACK-CN-001', 'Rollback return');
    $rollbackReturn = phase44CreateAndApprove($app, $staffId, $ownerId, $rollbackSale, $rollbackNoteId, (int) $rollbackItem['id'], '1', 'damaged', $keyPrefix . 'ROLLBACK-RETURN1', 'Inject write-off conflict');
    $seedWriteoff = $pdo->prepare('INSERT INTO sale_return_writeoffs (sale_return_item_id, condition_code, amount_khr, reason, recorded_by) VALUES (?, ?, ?, ?, ?)');
    $seedWriteoff->execute([$rollbackReturn['return_item_id'], 'damaged', '10.0000', 'Injected uniqueness conflict', $ownerId]);
    phase44ExpectDomain(fn () => $returns->confirm($ownerId, $rollbackReturn['return_id']), 'posting conflict rolls back the entire physical-return transaction');
    $rollbackHeader = $app->saleReturns()->find($rollbackReturn['return_id']);
    $rollbackReturnItem = phase44OnlyReturnItem($app, $rollbackReturn['return_id']);
    phase44Check($rollbackHeader !== null && $rollbackHeader['status'] === 'approved' && $rollbackReturnItem['financial_amount_khr'] === '0.0000' && $rollbackReturnItem['cost_basis_khr'] === '0.0000', 'failed return posting leaves the approved document and its financial amounts unchanged');
    $deleteSeed = $pdo->prepare('DELETE FROM sale_return_writeoffs WHERE sale_return_item_id = ?');
    $deleteSeed->execute([$rollbackReturn['return_item_id']]);

    $voidSale = phase44ConfirmedSale($app, $ownerId, $customerId, $productUnitId, $locationId, '1', $saleIds);
    $app->salesService()->voidSale($ownerId, $voidSale, 'Void before any return request', true);
    $voidItem = phase44OnlySaleItem($app, $voidSale);
    phase44ExpectDomain(fn () => $returns->createDraft($staffId, [
        'return_key' => $keyPrefix . 'VOIDED-RETURN-001', 'sale_id' => $voidSale, 'credit_note_id' => $partialNoteId, 'reason' => 'Voided sale',
        'items' => [['sale_item_id' => (int) $voidItem['id'], 'requested_qty_base' => '1', 'condition_code' => 'resellable']],
    ]), 'voided sales cannot enter the physical-return workflow');

    $staffRows = $returns->returnsForViewer($staffId);
    $ownerRows = $returns->returnsForViewer($ownerId);
    phase44Check($staffRows !== [] && !array_key_exists('cost_basis_khr', $staffRows[0]) && $ownerRows !== [] && array_key_exists('cost_basis_khr', $ownerRows[0]), 'Staff return views omit cost information while Owner views retain it');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id = ? AND action IN ('sale_return.draft_created', 'sale_return.approved', 'sale_return.confirmed')");
    $audit->execute([$ownerId]);
    phase44Check((int) $audit->fetchColumn() >= 3, 'Owner approval and confirmation actions retain an audit trail');

    Session::clearAuthentication();
    phase44Check($app->auth()->attemptLogin($ownerUsername, $ownerPassword) !== null, 'Owner session is available for sales-return CSRF testing');
    $_POST = ['_csrf' => 'invalid-token'];
    try {
        (new SalesReturnController($app))->createReturn();
        phase44Check(false, 'sales-return POST rejects invalid CSRF tokens');
    } catch (HttpException $exception) {
        phase44Check($exception->statusCode() === 419, 'sales-return POST rejects invalid CSRF tokens');
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
    phase44Cleanup($pdo, $ownerId, $staffId, $categoryId, $productId, $receiptIds, $saleIds, $customerIds, $keyPrefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 4.4 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 4.4 physical sales-return integration checks passed.' . PHP_EOL);
