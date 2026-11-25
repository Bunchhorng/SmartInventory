<?php
declare(strict_types=1);

use App\Core\{HttpException, Session};
use App\Support\Decimal;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/TestDatabase.php';
requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $app->pdo();
if ($app->users()->countOwners() !== 0) { fwrite(STDERR, 'Use an empty disposable database with no Owner.'); exit(2); }
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'goods_receipt_cost_variances'")->fetchColumn()) {
    fwrite(STDERR, 'Apply Phase 5.2 to the disposable test database first.'); exit(2);
}
$sessionDirectory = dirname(__DIR__) . '/storage/phase52-test-sessions';
if (!is_dir($sessionDirectory)) { mkdir($sessionDirectory, 0700, true); }
session_save_path($sessionDirectory);
ob_start();
Session::start();
$tag = 'P52-' . strtoupper(bin2hex(random_bytes(5)));
$ownerId = $staffId = $supplierId = $categoryId = null;
$productIds = $purchaseIds = $inventoryIds = [];
$failures = [];

function check52(bool $pass, string $message): void
{
    global $failures;
    if (!$pass) { $failures[] = $message; }
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
}
function reject52(callable $fn, string $message): void
{
    try { $fn(); check52(false, $message); } catch (DomainException) { check52(true, $message); }
}
function po52(array $items, string $currency = 'KHR', string $rate = '1.000000', bool $approve = true): array
{
    global $app, $ownerId, $supplierId, $tag, $purchaseIds;
    $po = $app->purchasingService()->createDraft($ownerId, [
        'purchase_key' => $tag . '-' . bin2hex(random_bytes(6)), 'supplier_id' => $supplierId,
        'currency' => $currency, 'exchange_rate_to_khr' => $rate, 'items' => $items,
    ]);
    $id = $po['purchase_id'];
    $purchaseIds[] = $id;
    if ($approve) { $app->purchasingService()->submit($ownerId, $id); $app->purchasingService()->approve($ownerId, $id); }
    return ['id' => $id, 'items' => $app->purchaseOrders()->items($id)];
}
function draft52(int $poId, array $items, ?string $ref = null, array $overrides = []): array
{
    global $tag, $locationId;
    return array_replace([
        'purchase_id' => $poId, 'receipt_key' => $tag . '-' . bin2hex(random_bytes(8)),
        'reference_no' => $ref ?? $tag . '-' . bin2hex(random_bytes(6)), 'location_id' => $locationId,
        'delivery_notes' => 'Supplier delivery', 'items' => $items,
    ], $overrides);
}
function receive52(array $input): int
{
    global $app, $staffId, $inventoryIds;
    $r = $app->goodsReceiving()->createDraft($staffId, $input);
    $inventoryIds[] = (int) $app->goodsReceipts()->find($r['receipt_id'])['inventory_receipt_id'];
    return $r['receipt_id'];
}
function race52(int $ownerId, int $firstId, int $secondId): array
{
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $processes = [];
    foreach ([$firstId, $secondId] as $id) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/phase52_receive_worker.php', (string) $ownerId, (string) $id], $descriptors, $pipes, dirname(__DIR__));
        if (!is_resource($process)) { throw new RuntimeException('Unable to launch concurrent receiving worker.'); }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0 || $error !== '') { throw new RuntimeException('Receiving worker failed: ' . $error); }
    }
    sort($results);
    return $results;
}
function cleanup52(): void
{
    global $pdo, $purchaseIds, $inventoryIds, $productIds, $categoryId, $supplierId, $ownerId, $staffId;
    foreach ($purchaseIds as $id) {
        $q = $pdo->prepare('SELECT inventory_receipt_id FROM goods_receipts WHERE purchase_id = ? AND inventory_receipt_id IS NOT NULL');
        $q->execute([$id]);
        $inventoryIds = array_merge($inventoryIds, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
        foreach ([
            'DELETE v FROM goods_receipt_cost_variances v JOIN goods_receipt_items gi ON gi.id = v.goods_receipt_item_id JOIN goods_receipts gr ON gr.id = gi.receipt_id WHERE gr.purchase_id = ?',
            'DELETE gi FROM goods_receipt_items gi JOIN goods_receipts gr ON gr.id = gi.receipt_id WHERE gr.purchase_id = ?',
            'DELETE FROM goods_receipts WHERE purchase_id = ?',
            'DELETE FROM purchase_order_approval_snapshots WHERE purchase_id = ?',
            'DELETE FROM purchase_items WHERE purchase_id = ?',
            'DELETE FROM purchases WHERE id = ?',
        ] as $sql) { $pdo->prepare($sql)->execute([$id]); }
    }
    foreach (array_unique($inventoryIds) as $id) {
        $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_type = 'inventory_receipt' AND source_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM stock_movements WHERE source_type = 'inventory_receipt' AND source_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_receipts WHERE id = ?')->execute([$id]);
    }
    foreach ($productIds as $id) {
        foreach (['inventory_cost_entries','stock_movements','stock_balances','product_costs','product_units'] as $table) {
            $pdo->prepare('DELETE FROM ' . $table . ' WHERE product_id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }
    if ($supplierId) { $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$supplierId]); }
    if ($categoryId) { $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]); }
    foreach (array_filter([$ownerId, $staffId]) as $id) {
        $pdo->prepare('DELETE FROM audit_logs WHERE actor_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}

try {
    $ownerName = strtolower($tag) . '_owner';
    $staffName = strtolower($tag) . '_staff';
    $password = 'ReceivingTest!' . $tag;
    $app->auth()->createInitialOwner($ownerName, 'Receiving Test Owner', $password);
    $ownerId = (int) $app->users()->findByUsername($ownerName)['id'];
    $app->auth()->createStaff($ownerId, $staffName, 'Receiving Test Staff', $password);
    $staffId = (int) $app->users()->findByUsername($staffName)['id'];
    $supplierId = $app->supplierService()->create($ownerId, [
        'supplier_code' => $tag, 'name' => 'Receiving Supplier', 'contact_person' => 'Supplier contact',
        'phone' => '+855 12345678', 'address' => 'Receiving address', 'payment_terms_days' => '30',
    ]);
    $categoryId = $app->productDomain()->createCategory($ownerId, $tag, null);
    $pieceId = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs'")->fetchColumn();
    $caseId = (int) $pdo->query("SELECT id FROM units WHERE code = 'case'")->fetchColumn();
    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN'")->fetchColumn();
    $otherLocationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'RETAIL'")->fetchColumn();
    foreach (['A','B'] as $code) {
        $productIds[] = $app->productDomain()->createProduct($ownerId, [
            'sku' => $tag . $code, 'name' => $tag . $code, 'category_id' => $categoryId, 'base_unit_id' => $pieceId, 'kind' => 'physical',
        ]);
    }
    $baseId = (int) $app->products()->findProductUnitByProductAndUnit($productIds[0], $pieceId)['id'];
    $otherBaseId = (int) $app->products()->findProductUnitByProductAndUnit($productIds[1], $pieceId)['id'];
    $caseProductId = $app->productDomain()->addProductUnit($ownerId, $productIds[0], $caseId, '12.000000');
    $opening = $app->inventory()->createDraftReceipt($ownerId, [
        'receipt_type' => 'opening', 'reference_no' => $tag . '-OPEN', 'location_id' => $locationId,
        'items' => [['product_unit_id' => $baseId, 'quantity' => '12', 'cost_per_base_khr' => '1000']],
    ]);
    $inventoryIds[] = $opening;
    $app->inventory()->confirmReceipt($ownerId, $opening);
    $service = $app->goodsReceiving();
    $po = po52([
        ['product_unit_id' => $caseProductId, 'ordered_qty' => '2', 'unit_purchase_price' => '12', 'discount_percent' => '25'],
        ['product_unit_id' => $baseId, 'ordered_qty' => '3', 'unit_purchase_price' => '0.5'],
    ], 'USD', '4000.000000');
    $caseLine = (int) $po['items'][0]['id'];
    $baseLine = (int) $po['items'][1]['id'];
    $request = draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1'], ['purchase_item_id' => $baseLine, 'quantity' => '1']]);
    $first = receive52($request);
    $replay = $service->createDraft($staffId, $request);
    check52(!$replay['created'] && $replay['receipt_id'] === $first, 'same delivery request replay is idempotent');
    $state = $app->inventory()->reconcileProductValuation($ownerId, $productIds[0]);
    check52($state['cost_qty'] === '12.000' && $state['cost_value'] === '12000.0000', 'quantity-only goods receipt draft does not change stock or value');
    $firstHeader = $app->goodsReceipts()->find($first);
    $firstItems = $app->goodsReceipts()->items($first);
    check52($firstItems[0]['approved_cost_per_base_khr'] === '3000.000000'
        && $firstItems[0]['qty_base'] === '12.000', 'approved unit, discount, currency, and FX snapshots determine receipt cost');

    reject52(fn () => $service->createDraft($staffId, array_replace($request, ['location_id' => $otherLocationId])), 'idempotency key cannot be reused for a different delivery payload');
    reject52(fn () => $service->createDraft($staffId, array_replace($request, ['receipt_key' => $tag . '-DUPLICATE'])), 'same supplier delivery reference cannot be recorded under a second key');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '3']])), 'over-delivery is rejected before draft creation');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '0']])), 'zero quantity is rejected');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '-1']])), 'negative quantity is rejected');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '0.5']])), 'receipt respects approved purchasing-unit precision');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1']], null, ['location_id' => 999999999])), 'invalid stock location is rejected');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1']], null, ['supplier_id' => 999999999])), 'supplier must belong to the selected PO');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1', 'product_unit_id' => $otherBaseId]])), 'receipt cannot substitute a product or purchase unit');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1', 'cost_per_base_khr' => '1']])), 'Staff cannot inject receipt costs');
    reject52(fn () => $service->confirm($staffId, $first), 'Staff cannot confirm valued goods receipts');
    reject52(fn () => $service->reconcile($staffId, $po['id']), 'Staff cannot access valuation reconciliation');
    reject52(fn () => $app->inventory()->confirmReceipt($ownerId, (int) $firstHeader['inventory_receipt_id']), 'standalone inventory endpoint cannot bypass PO confirmation');
    reject52(fn () => $app->inventory()->setReceiptCosts($ownerId, (int) $firstHeader['inventory_receipt_id'], [['receipt_item_id' => $firstItems[0]['inventory_receipt_item_id'], 'cost_per_base_khr' => '1']]), 'standalone receipt-cost setter cannot override approved PO costs');

    // Simulate later catalog drift: receipt conversion must remain exactly 12.
    $pdo->prepare('UPDATE product_units SET to_base_factor = 99 WHERE id = ?')->execute([$caseProductId]);
    $service->confirm($ownerId, $first);
    $pdo->prepare('UPDATE product_units SET to_base_factor = 12 WHERE id = ?')->execute([$caseProductId]);
    $state = $app->inventory()->reconcileProductValuation($ownerId, $productIds[0]);
    check52($app->purchaseOrders()->find($po['id'])['status'] === 'partially_received'
        && $state['cost_qty'] === '25.000' && $state['cost_value'] === '50000.0000' && $state['average_cost'] === '2000.000000',
        'partial delivery with multiple units of the same product preserves conversions and weighted-average value');
    check52(!$service->confirm($ownerId, $first)['confirmed'], 'duplicate confirmation posts no second movement or valuation entry');
    reject52(fn () => $service->close($ownerId, $po['id']), 'partially received purchase order cannot be closed');
    reject52(fn () => $app->purchasingService()->cancel($ownerId, $po['id'], 'Unsafe cancellation'), 'received quantities prevent purchase-order cancellation');
    reject52(fn () => $app->purchasingService()->reject($ownerId, $po['id'], 'Unsafe rejection'), 'received quantities prevent purchase-order rejection');
    reject52(fn () => $app->inventory()->reverseReceipt($ownerId, (int) $firstHeader['inventory_receipt_id'], 'Bypass reversal'), 'standalone inventory reversal cannot invalidate PO fulfillment');
    $second = receive52(draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1'], ['purchase_item_id' => $baseLine, 'quantity' => '2']], null, ['location_id' => $otherLocationId]));
    $service->confirm($ownerId, $second);
    $report = $service->reconcile($ownerId, $po['id']);
    check52($report['reconciled'] && $report['status'] === 'received'
        && $report['product_values'][$productIds[0]]['cost_qty'] === '39.000'
        && $report['product_values'][$productIds[0]]['cost_value'] === '90000.0000',
        'multiple partial receipts fully fulfill PO and reconcile stock and valuation across locations');
    check52($service->close($ownerId, $po['id'])['closed'] && !$service->close($ownerId, $po['id'])['closed'], 'fully received PO closes once after reconciliation');
    reject52(fn () => $service->createDraft($staffId, draft52($po['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1']])), 'closed purchase order rejects new receipts');

    $staffView = $service->forViewer($staffId, $first);
    $staffList = $service->listForViewer($staffId, $po['id']);
    check52(!array_key_exists('currency_snapshot', $staffView) && !array_key_exists('landed_unit_cost_khr', $staffView['items'][0])
        && !array_key_exists('approved_cost_per_base_khr', $staffView['items'][0]) && !array_key_exists('exchange_rate_snapshot', $staffList[0]),
        'Staff detail and list responses contain no cost, exchange-rate, or variance fields');

    $variancePo = po52([['product_unit_id' => $otherBaseId, 'ordered_qty' => '4', 'unit_purchase_price' => '100']]);
    $varianceReceipt = receive52(draft52($variancePo['id'], [['purchase_item_id' => $variancePo['items'][0]['id'], 'quantity' => '4']]));
    $varianceLine = $app->goodsReceipts()->items($varianceReceipt)[0];
    $pdo->prepare('UPDATE goods_receipt_items SET landed_unit_cost_khr = 150 WHERE id = ?')->execute([$varianceLine['id']]);
    reject52(fn () => $service->confirm($ownerId, $varianceReceipt), 'unapproved cost tampering prevents confirmation even for an Owner');
    $pdo->prepare('UPDATE goods_receipt_items SET landed_unit_cost_khr = 100 WHERE id = ?')->execute([$varianceLine['id']]);
    reject52(fn () => $service->approveCostVariance($staffId, $varianceReceipt, (int) $varianceLine['id'], '150', 'Supplier price changed'), 'Staff cannot approve a cost variance');
    reject52(fn () => $service->approveCostVariance($ownerId, $varianceReceipt, (int) $varianceLine['id'], '150', ''), 'cost variance requires an explicit reason');
    $service->approveCostVariance($ownerId, $varianceReceipt, (int) $varianceLine['id'], '150', 'Owner accepted documented supplier price difference');
    reject52(fn () => $service->approveCostVariance($ownerId, $varianceReceipt, (int) $varianceLine['id'], '160', 'Second approval'), 'cost variance approval history cannot be overwritten');
    $service->confirm($ownerId, $varianceReceipt);
    $varianceState = $app->inventory()->reconcileProductValuation($ownerId, $productIds[1]);
    check52($varianceState['cost_value'] === '600.0000' && $app->purchaseOrders()->items($variancePo['id'])[0]['unit_purchase_price'] === '100.0000'
        && $service->reconcile($ownerId, $variancePo['id'])['reconciled'], 'Owner-approved actual receipt cost posts while original PO price and baseline remain intact');

    $invalidPo = po52([['product_unit_id' => $baseId, 'ordered_qty' => '1', 'unit_purchase_price' => '10']], approve: false);
    reject52(fn () => $service->createDraft($staffId, draft52($invalidPo['id'], [['purchase_item_id' => $invalidPo['items'][0]['id'], 'quantity' => '1']])), 'unapproved PO rejects receiving');

    $racePo = po52([['product_unit_id' => $baseId, 'ordered_qty' => '5', 'unit_purchase_price' => '10']]);
    $raceLine = (int) $racePo['items'][0]['id'];
    reject52(fn () => $service->createDraft($staffId, draft52($racePo['id'], [['purchase_item_id' => $caseLine, 'quantity' => '1']])), 'foreign PO line is rejected on an otherwise receivable order');
    $raceA = receive52(draft52($racePo['id'], [['purchase_item_id' => $raceLine, 'quantity' => '4']]));
    $raceB = receive52(draft52($racePo['id'], [['purchase_item_id' => $raceLine, 'quantity' => '4']]));
    check52(race52($ownerId, $raceA, $raceB) === ['confirmed','rejected'], 'concurrent deliveries cannot over-receive the same PO');
    $winner = $app->goodsReceipts()->find($raceA)['status'] === 'confirmed' ? $raceA : $raceB;
    $loser = $winner === $raceA ? $raceB : $raceA;
    $service->cancelDraft($ownerId, $loser, 'Delivery counted on other draft');
    $staleDraft = receive52(draft52($racePo['id'], [['purchase_item_id' => $raceLine, 'quantity' => '1']]));
    $raceLast = receive52(draft52($racePo['id'], [['purchase_item_id' => $raceLine, 'quantity' => '1']]));
    check52(race52($ownerId, $raceLast, $raceLast) === ['confirmed','duplicate'], 'concurrent duplicate confirmations create exactly one posting');
    check52($service->reconcile($ownerId, $racePo['id'])['reconciled'], 'concurrent fulfillment retains exact PO, stock, and value reconciliation');
    reject52(fn () => $service->close($ownerId, $racePo['id']), 'unresolved stale receipt drafts prevent closing a fulfilled PO');
    $service->cancelDraft($ownerId, $staleDraft, 'Duplicate delivery draft resolved');
    check52($service->close($ownerId, $racePo['id'])['closed'], 'Owner can resolve stale drafts and then close the order');

    $splitPo = po52([['product_unit_id' => $baseId, 'ordered_qty' => '5', 'unit_purchase_price' => '10']]);
    $splitLine = (int) $splitPo['items'][0]['id'];
    $splitA = receive52(draft52($splitPo['id'], [['purchase_item_id' => $splitLine, 'quantity' => '3']]));
    $splitB = receive52(draft52($splitPo['id'], [['purchase_item_id' => $splitLine, 'quantity' => '2']]));
    check52(race52($ownerId, $splitA, $splitB) === ['confirmed','confirmed']
        && $service->reconcile($ownerId, $splitPo['id'])['reconciled'], 'concurrent valid partial deliveries both commit within the approved total');

    $rollbackPo = po52([
        ['product_unit_id' => $baseId, 'ordered_qty' => '2', 'unit_purchase_price' => '20'],
        ['product_unit_id' => $otherBaseId, 'ordered_qty' => '2', 'unit_purchase_price' => '30'],
    ]);
    $rollback = receive52(draft52($rollbackPo['id'], array_map(static fn (array $line): array => ['purchase_item_id' => $line['id'], 'quantity' => '2'], $rollbackPo['items'])));
    $rollbackHeader = $app->goodsReceipts()->find($rollback);
    $irId = (int) $rollbackHeader['inventory_receipt_id'];
    $irItems = $app->inventoryReceipts()->items($irId);
    $blockedItem = $irItems[1];
    $blockedKey = sprintf('inventory-receipt:%d:line:%d:confirmed', $irId, $blockedItem['id']);
    $poisonId = $app->inventoryReceipts()->createMovement((int) $blockedItem['product_id'], $locationId, '0.000', 'test_conflict', $irId, 0, $blockedKey, '1.000000', $ownerId);
    $beforeRollback = $app->inventory()->reconcileProductValuation($ownerId, $productIds[0]);
    reject52(fn () => $service->confirm($ownerId, $rollback), 'posting conflict on later line rolls back earlier stock and valuation writes');
    check52($app->inventory()->reconcileProductValuation($ownerId, $productIds[0]) === $beforeRollback
        && $app->goodsReceipts()->find($rollback)['status'] === 'draft'
        && $app->inventoryReceipts()->find($irId)['status'] === 'draft'
        && $app->purchaseOrders()->items($rollbackPo['id'])[0]['received_qty'] === '0.000', 'failed confirmation rolls back stock, value, document state, and PO fulfillment atomically');
    $pdo->prepare('DELETE FROM stock_movements WHERE id = ?')->execute([$poisonId]);
    $pdo->prepare("UPDATE products SET status = 'archived' WHERE id = ?")->execute([$productIds[0]]);
    reject52(fn () => $service->confirm($ownerId, $rollback), 'archived product cannot be confirmed');
    reject52(fn () => $service->createDraft($staffId, draft52($rollbackPo['id'], [['purchase_item_id' => $rollbackPo['items'][0]['id'], 'quantity' => '1']])), 'archived product cannot enter a new receipt draft');
    $pdo->prepare("UPDATE products SET status = 'active' WHERE id = ?")->execute([$productIds[0]]);
    $service->confirm($ownerId, $rollback);
    check52($service->reconcile($ownerId, $rollbackPo['id'])['reconciled'], 'corrected draft can confirm after rollback');

    // Reconciliation identifies tampering without repairing the stored counter.
    $pdo->prepare('UPDATE purchase_items SET received_qty = 1 WHERE id = ?')->execute([$rollbackPo['items'][0]['id']]);
    $badReport = $service->reconcile($ownerId, $rollbackPo['id']);
    check52(!$badReport['reconciled'] && $app->purchaseOrders()->items($rollbackPo['id'])[0]['received_qty'] === '1.000', 'read-only reconciliation flags inconsistent received totals without changing them');
    $pdo->prepare('UPDATE purchase_items SET received_qty = 2 WHERE id = ?')->execute([$rollbackPo['items'][0]['id']]);
    $movement = $pdo->prepare("SELECT id FROM stock_movements WHERE source_type = 'inventory_receipt' AND source_id = ? ORDER BY id LIMIT 1");
    $movement->execute([$irId]);
    $movementId = (int) $movement->fetchColumn();
    $pdo->prepare('UPDATE inventory_cost_entries SET value_delta_khr = value_delta_khr + 1 WHERE stock_movement_id = ?')->execute([$movementId]);
    check52(!$service->reconcile($ownerId, $rollbackPo['id'])['reconciled'], 'reconciliation detects receipt valuation and product-wide ledger differences');
    $pdo->prepare('UPDATE inventory_cost_entries SET value_delta_khr = value_delta_khr - 1 WHERE stock_movement_id = ?')->execute([$movementId]);

    Session::clearAuthentication();
    $app->auth()->attemptLogin($ownerName, $password);
    $router = require dirname(__DIR__) . '/routes/web.php';
    foreach (['goods-receipt-create','goods-receipt-confirm','goods-receipt-cost-variance','goods-receipt-cancel','purchase-order-close'] as $route) {
        $_POST = ['_csrf' => 'invalid-token'];
        try { $router->dispatch($route, 'POST'); check52(false, $route . ' CSRF rejection'); }
        catch (HttpException $e) { check52($e->statusCode() === 419, $route . ' rejects invalid CSRF'); }
    }
    $_POST = ['_csrf' => Session::csrfToken(), 'receipt_id' => $first];
    ob_start();
    $router->dispatch('goods-receipt-confirm', 'POST');
    $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check52($json['success'] && !$json['data']['confirmed'] && http_response_code() === 200, 'HTTP confirmation returns successful idempotent JSON');
    Session::clearAuthentication();
    $app->auth()->attemptLogin($staffName, $password);
    foreach (['goods-receipt-confirm','goods-receipt-cost-variance','goods-receipt-cancel','purchase-order-close'] as $route) {
        $_POST = ['_csrf' => Session::csrfToken()];
        try { $router->dispatch($route, 'POST'); check52(false, $route . ' Staff authorization'); }
        catch (HttpException $e) { check52($e->statusCode() === 403, $route . ' rejects Staff'); }
    }
    $_GET = ['receipt_id' => $first];
    ob_start(); $router->dispatch('goods-receipt-detail', 'GET'); $staffJson = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check52($staffJson['success'] && !isset($staffJson['data']['items'][0]['landed_unit_cost_khr']), 'Staff HTTP detail response omits costs');
    $_POST = ['_csrf' => Session::csrfToken(), 'purchase_id' => []];
    ob_start(); $router->dispatch('goods-receipt-create', 'POST'); $invalidJson = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check52(!$invalidJson['success'] && http_response_code() === 422, 'malformed input produces validation JSON and HTTP 422');
    check52((int) $pdo->query('SELECT COUNT(*) FROM payables')->fetchColumn() === 0, 'goods receiving does not create supplier payables');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id = ? AND action IN ('goods_receipt.confirmed','goods_receipt.cost_variance_approved','purchase_order.closed')");
    $audit->execute([$ownerId]);
    check52((int) $audit->fetchColumn() > 0, 'receiving, variance approval, and closing have audit records');
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
    echo 'FAIL: ' . $e . PHP_EOL;
} finally {
    $_POST = $_GET = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (session_status() === PHP_SESSION_ACTIVE) { Session::destroy(); }
    cleanup52();
}
echo $failures === [] ? 'All Phase 5.2 integration checks passed.' . PHP_EOL : count($failures) . ' Phase 5.2 checks failed.' . PHP_EOL;
ob_end_flush();
exit($failures === [] ? 0 : 1);
