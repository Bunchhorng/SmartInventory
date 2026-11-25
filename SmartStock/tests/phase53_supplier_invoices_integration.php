<?php
declare(strict_types=1);

use App\Core\{HttpException, Session};

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/TestDatabase.php';
requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $app->pdo();
if ($app->users()->countOwners() !== 0) { fwrite(STDERR, 'Use an empty disposable database with no Owner.'); exit(2); }
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'supplier_invoice_matches'")->fetchColumn()) {
    fwrite(STDERR, 'Apply Phase 5.3 to the disposable test database first.'); exit(2);
}
$sessionDirectory = dirname(__DIR__) . '/storage/phase53-test-sessions';
if (!is_dir($sessionDirectory)) { mkdir($sessionDirectory, 0700, true); }
session_save_path($sessionDirectory);
ob_start(); Session::start();
$tag = 'P53-' . strtoupper(bin2hex(random_bytes(5)));
$ownerId = $staffId = $categoryId = null;
$supplierIds = $productIds = $purchaseIds = $inventoryIds = [];
$failures = [];
$triggerName = 'p53_fail_' . strtolower(str_replace('-', '_', $tag));

function check53(bool $pass, string $message): void
{
    global $failures;
    if (!$pass) { $failures[] = $message; }
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
}
function reject53(callable $operation, string $message): void
{
    try { $operation(); check53(false, $message); } catch (DomainException) { check53(true, $message); }
}
function po53(array $items, string $currency = 'KHR', string $fx = '1.000000'): array
{
    global $app, $ownerId, $supplierIds, $purchaseIds, $tag;
    $po = $app->purchasingService()->createDraft($ownerId, ['purchase_key' => $tag . '-' . bin2hex(random_bytes(6)),
        'supplier_id' => $supplierIds[0], 'currency' => $currency, 'exchange_rate_to_khr' => $fx, 'items' => $items]);
    $purchaseIds[] = $po['purchase_id'];
    $app->purchasingService()->submit($ownerId, $po['purchase_id']);
    $app->purchasingService()->approve($ownerId, $po['purchase_id']);
    return ['id' => $po['purchase_id'], 'items' => $app->purchaseOrders()->items($po['purchase_id'])];
}
function receipt53(array $po, array $quantities, bool $confirm = true): array
{
    global $app, $ownerId, $staffId, $inventoryIds, $locationId, $tag;
    $items = [];
    foreach ($quantities as $index => $quantity) { $items[] = ['purchase_item_id' => $po['items'][$index]['id'], 'quantity' => $quantity]; }
    $r = $app->goodsReceiving()->createDraft($staffId, ['purchase_id' => $po['id'], 'receipt_key' => $tag . '-' . bin2hex(random_bytes(6)),
        'reference_no' => $tag . '-' . bin2hex(random_bytes(5)), 'location_id' => $locationId, 'items' => $items]);
    $inventoryIds[] = (int) $app->goodsReceipts()->find($r['receipt_id'])['inventory_receipt_id'];
    if ($confirm) { $app->goodsReceiving()->confirm($ownerId, $r['receipt_id']); }
    return ['id' => $r['receipt_id'], 'items' => $app->goodsReceipts()->items($r['receipt_id'])];
}
function invoiceInput53(int $poId, ?array $items = null, array $overrides = []): array
{
    global $supplierIds, $tag;
    $input = ['invoice_key' => $tag . '-' . bin2hex(random_bytes(8)), 'supplier_invoice_no' => 'Inv/' . $tag . '/' . bin2hex(random_bytes(5)),
        'supplier_id' => $supplierIds[0], 'purchase_id' => $poId, 'invoice_date' => '2026-11-24', 'due_date' => '2026-12-24'];
    if ($items !== null) { $input['items'] = $items; }
    return array_replace($input, $overrides);
}
function approved53(int $poId, array $items, string $reason = ''): int
{
    global $app, $ownerId;
    $service = $app->supplierInvoiceService();
    $id = $service->createDraft($ownerId, invoiceInput53($poId, $items))['invoice_id'];
    $service->submit($ownerId, $id); $service->approve($ownerId, $id, $reason);
    return $id;
}
function race53(int $firstId, int $secondId): array
{
    global $ownerId;
    $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $workers = [];
    foreach ([$firstId, $secondId] as $id) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/phase53_invoice_post_worker.php', (string) $ownerId, (string) $id], $descriptors, $pipes, dirname(__DIR__));
        if (!is_resource($process)) { throw new RuntimeException('Cannot launch invoice posting worker.'); }
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException('Invoice worker error: ' . $error); }
    }
    sort($results); return $results;
}
function cleanup53(): void
{
    global $pdo, $ownerId, $staffId, $supplierIds, $productIds, $purchaseIds, $inventoryIds, $categoryId, $triggerName;
    $pdo->exec('DROP TRIGGER IF EXISTS ' . $triggerName);
    foreach (array_filter([$ownerId, $staffId]) as $actor) {
        $q = $pdo->prepare('SELECT id FROM supplier_invoices WHERE created_by = ?'); $q->execute([$actor]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            foreach ([
                'DELETE v FROM supplier_invoice_price_variances v JOIN supplier_invoice_matches m ON m.id = v.match_id WHERE m.invoice_id = ?',
                'DELETE FROM supplier_invoice_matches WHERE invoice_id = ?',
                'DELETE a FROM payable_allocations a JOIN payables p ON p.id = a.payable_id WHERE p.supplier_invoice_id = ?',
                'DELETE FROM payables WHERE supplier_invoice_id = ?',
                'DELETE FROM supplier_invoice_approvals WHERE invoice_id = ?',
                'DELETE FROM supplier_invoice_items WHERE invoice_id = ?',
                'DELETE FROM supplier_invoices WHERE id = ?',
            ] as $sql) { $pdo->prepare($sql)->execute([$id]); }
        }
    }
    foreach ($purchaseIds as $id) {
        $q = $pdo->prepare('SELECT inventory_receipt_id FROM goods_receipts WHERE purchase_id = ? AND inventory_receipt_id IS NOT NULL');
        $q->execute([$id]); $inventoryIds = array_merge($inventoryIds, array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)));
        foreach ([
            'DELETE v FROM goods_receipt_cost_variances v JOIN goods_receipt_items gi ON gi.id = v.goods_receipt_item_id JOIN goods_receipts gr ON gr.id = gi.receipt_id WHERE gr.purchase_id = ?',
            'DELETE gi FROM goods_receipt_items gi JOIN goods_receipts gr ON gr.id = gi.receipt_id WHERE gr.purchase_id = ?',
            'DELETE FROM goods_receipts WHERE purchase_id = ?', 'DELETE FROM payables WHERE purchase_id = ?',
            'DELETE FROM purchase_order_approval_snapshots WHERE purchase_id = ?', 'DELETE FROM purchase_items WHERE purchase_id = ?', 'DELETE FROM purchases WHERE id = ?',
        ] as $sql) { $pdo->prepare($sql)->execute([$id]); }
    }
    foreach (array_unique($inventoryIds) as $id) {
        $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_type = 'inventory_receipt' AND source_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM stock_movements WHERE source_type = 'inventory_receipt' AND source_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_receipts WHERE id = ?')->execute([$id]);
    }
    foreach ($productIds as $id) {
        foreach (['inventory_cost_entries','stock_movements','stock_balances','product_costs','product_units'] as $table) { $pdo->prepare('DELETE FROM ' . $table . ' WHERE product_id = ?')->execute([$id]); }
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
    }
    foreach ($supplierIds as $id) { $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]); }
    if ($categoryId) { $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]); }
    foreach (array_filter([$ownerId, $staffId]) as $id) {
        foreach (['audit_logs' => 'actor_id','auth_sessions' => 'user_id','user_roles' => 'user_id'] as $table => $column) { $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' = ?')->execute([$id]); }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}

try {
    $ownerName = strtolower($tag) . '_owner'; $staffName = strtolower($tag) . '_staff'; $password = 'InvoiceTest!' . $tag;
    $app->auth()->createInitialOwner($ownerName, 'Invoice Test Owner', $password);
    $ownerId = (int) $app->users()->findByUsername($ownerName)['id'];
    $app->auth()->createStaff($ownerId, $staffName, 'Invoice Test Staff', $password);
    $staffId = (int) $app->users()->findByUsername($staffName)['id'];
    foreach (['A','B'] as $code) { $supplierIds[] = $app->supplierService()->create($ownerId, ['supplier_code' => $tag . $code,
        'name' => 'Invoice Supplier ' . $code, 'contact_person' => 'Supplier contact', 'phone' => '+855 12345678', 'address' => 'Supplier address', 'payment_terms_days' => '30']); }
    $categoryId = $app->productDomain()->createCategory($ownerId, $tag, null);
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs'")->fetchColumn();
    $case = (int) $pdo->query("SELECT id FROM units WHERE code = 'case'")->fetchColumn();
    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN'")->fetchColumn();
    foreach (['A','B'] as $code) { $productIds[] = $app->productDomain()->createProduct($ownerId, ['sku' => $tag . $code,
        'name' => $tag . $code, 'category_id' => $categoryId, 'base_unit_id' => $pcs, 'kind' => 'physical']); }
    $base = (int) $app->products()->findProductUnitByProductAndUnit($productIds[0], $pcs)['id'];
    $other = (int) $app->products()->findProductUnitByProductAndUnit($productIds[1], $pcs)['id'];
    $caseUnit = $app->productDomain()->addProductUnit($ownerId, $productIds[0], $case, '12.000000');
    $service = $app->supplierInvoiceService();
    $po = po53([['product_unit_id' => $caseUnit, 'ordered_qty' => '3', 'unit_purchase_price' => '24', 'discount_percent' => '25'],
        ['product_unit_id' => $other, 'ordered_qty' => '4', 'unit_purchase_price' => '2']], 'USD', '4000.000000');
    $grA = receipt53($po, ['1','2']); $grB = receipt53($po, ['2','2']);
    $inventoryBefore = $pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll();
    $movementCount = (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn();
    $valuationCount = (int) $pdo->query('SELECT COUNT(*) FROM inventory_cost_entries')->fetchColumn();
    $paymentCount = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    $receivableCount = (int) $pdo->query('SELECT COUNT(*) FROM receivables')->fetchColumn();
    $metadata = invoiceInput53($po['id'], overrides: ['supplier_invoice_no' => 'Supplier-Original/AbC-' . $tag]);
    $invoiceA = $service->createDraft($staffId, $metadata)['invoice_id'];
    check53(!$service->createDraft($staffId, $metadata)['created'], 'invoice metadata request is idempotent');
    check53($app->supplierInvoices()->find($invoiceA)['supplier_invoice_no'] === $metadata['supplier_invoice_no'], 'original supplier invoice number is preserved');
    reject53(fn () => $service->createDraft($staffId, array_replace($metadata, ['invoice_key' => $tag . '-NUMBER-DUP', 'supplier_invoice_no' => strtolower($metadata['supplier_invoice_no'])])), 'duplicate supplier invoice number is rejected despite case changes');
    reject53(fn () => $service->createDraft($staffId, array_replace($metadata, ['invoice_date' => '2026-11-25'])), 'same request key cannot change the original payload');
    reject53(fn () => $service->submit($staffId, $invoiceA), 'unpriced metadata cannot be submitted for payable approval');
    $itemsA = [
        ['goods_receipt_item_id' => $grA['items'][0]['id'], 'quantity' => '1'],
        ['goods_receipt_item_id' => $grA['items'][1]['id'], 'quantity' => '1'],
        ['goods_receipt_item_id' => $grB['items'][0]['id'], 'quantity' => '1'],
        ['goods_receipt_item_id' => $grB['items'][1]['id'], 'quantity' => '1'],
    ];
    reject53(fn () => $service->updateDraft($staffId, $invoiceA, ['items' => $itemsA]), 'Staff cannot supply invoice lines or purchasing prices');
    $service->updateDraft($ownerId, $invoiceA, ['items' => $itemsA]);
    $service->submit($staffId, $invoiceA);
    reject53(fn () => $service->approve($staffId, $invoiceA), 'Staff cannot approve supplier invoices');
    reject53(fn () => $service->post($ownerId, $invoiceA), 'submitted invoice cannot be posted before approval');
    check53($service->approve($ownerId, $invoiceA)['approved'] && !$service->approve($ownerId, $invoiceA)['approved'], 'Owner approval is captured exactly once');
    $postedA = $service->post($ownerId, $invoiceA);
    $payableA = $app->payables()->forInvoice($invoiceA);
    check53($postedA['posted'] && $payableA['amount_original'] === '40.0000' && $payableA['original_khr'] === '160000.0000'
        && $payableA['currency'] === 'USD' && $payableA['exchange_rate_to_khr'] === '4000.000000', 'partial multi-line invoice matches multiple receipts and posts original-currency/FX payable snapshots');
    check53(!$service->post($ownerId, $invoiceA)['posted'], 'duplicate invoice posting creates no second payable');
    $report = $service->reconcile($ownerId, $po['id']);
    check53($report['reconciled'] && $report['supplier']['outstanding_khr'] === '160000.0000', 'partial invoice and supplier outstanding balances reconcile');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($po['id'], [['goods_receipt_item_id' => $grA['items'][0]['id'], 'quantity' => '1']])), 'already-invoiced received quantity cannot be matched twice');
    $invoiceB = approved53($po['id'], [
        ['goods_receipt_item_id' => $grA['items'][1]['id'], 'quantity' => '1'],
        ['goods_receipt_item_id' => $grB['items'][0]['id'], 'quantity' => '1'],
        ['goods_receipt_item_id' => $grB['items'][1]['id'], 'quantity' => '1'],
    ]);
    $service->post($ownerId, $invoiceB);
    $report = $service->reconcile($ownerId, $po['id']);
    check53($report['reconciled'] && $report['posted_invoice_total_khr'] === '248000.0000'
        && count($report['supplier']['payables']) === 2 && array_unique(array_column($report['quantities'], 'remaining_qty')) === ['0.000'],
        'multiple partial invoices fully match one PO with separate immutable payables');
    check53($pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll() === $inventoryBefore
        && (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === $movementCount
        && (int) $pdo->query('SELECT COUNT(*) FROM inventory_cost_entries')->fetchColumn() === $valuationCount,
        'full and partial invoice recognition leave all receipt inventory and valuation records unchanged');
    reject53(fn () => $service->cancel($ownerId, $invoiceA, 'Attempt to erase liability'), 'posted invoices cannot be cancelled or reversed through an unposted workflow');
    reject53(fn () => $service->updateDraft($ownerId, $invoiceA, ['notes' => 'Changed after approval']), 'posted invoice snapshots cannot be edited');

    $smallPo = po53([['product_unit_id' => $base, 'ordered_qty' => '10', 'unit_purchase_price' => '10']]);
    $smallReceipt = receipt53($smallPo, ['5']);
    $draftReceipt = receipt53($smallPo, ['5'], false);
    $grId = (int) $smallReceipt['items'][0]['id'];
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [['goods_receipt_item_id' => $draftReceipt['items'][0]['id'], 'quantity' => '1']])), 'unconfirmed receipt quantities cannot be invoiced');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '6']])), 'ordered but unreceived quantities cannot be invoiced');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '0']])), 'zero invoice quantity is rejected');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '-1']])), 'negative invoice quantity is rejected');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '1', 'product_unit_id' => $other]])), 'invoice purchasing unit must match PO and receipt');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], overrides: ['supplier_id' => $supplierIds[1]])), 'wrong supplier is rejected');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], overrides: ['currency' => 'USD'])), 'invoice currency mismatch is rejected');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], overrides: ['exchange_rate_to_khr' => '4000'])), 'invoice FX mismatch is rejected');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], overrides: ['invoice_date' => '2026-02-30'])), 'invalid invoice date is rejected');
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], overrides: ['due_date' => '2026-11-20'])), 'due date cannot precede invoice date');
    $badKey = $tag . '-INVALID-LINES';
    reject53(fn () => $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [
        ['goods_receipt_item_id' => $grId, 'quantity' => '1'], ['goods_receipt_item_id' => 999999999, 'quantity' => '1'],
    ], ['invoice_key' => $badKey])), 'invalid later invoice line rejects the entire draft');
    $lookup = $pdo->prepare('SELECT COUNT(*) FROM supplier_invoices WHERE invoice_key = ?'); $lookup->execute([$badKey]);
    check53((int) $lookup->fetchColumn() === 0, 'multi-line draft failure rolls back invoice and line inserts');

    $varianceId = $service->createDraft($ownerId, invoiceInput53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '1', 'unit_purchase_price' => '12']]))['invoice_id'];
    $service->submit($ownerId, $varianceId);
    reject53(fn () => $service->approve($ownerId, $varianceId), 'price variance cannot be approved without an explicit Owner reason');
    $service->approve($ownerId, $varianceId, 'Supplier price discrepancy accepted by Owner'); $service->post($ownerId, $varianceId);
    $varianceMatch = $app->supplierInvoices()->matches($varianceId)[0];
    check53($varianceMatch['receipt_value_khr'] === '10.0000' && $varianceMatch['invoice_value_khr'] === '12.0000'
        && $varianceMatch['variance_khr'] === '2.0000' && $varianceMatch['variance_type'] === 'price', 'approved invoice price difference is recorded separately from receipt valuation');
    $lowId = approved53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '1', 'unit_purchase_price' => '9']], 'Owner accepted lower supplier price');
    $service->post($ownerId, $lowId);
    check53($app->supplierInvoices()->matches($lowId)[0]['variance_khr'] === '-1.0000', 'favorable price variance preserves a negative signed amount');

    $raceA = approved53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '3']]);
    $raceB = approved53($smallPo['id'], [['goods_receipt_item_id' => $grId, 'quantity' => '3']]);
    check53(race53($raceA, $raceB) === ['posted','rejected'], 'concurrent invoice posting cannot consume the same received quantity twice');
    $loser = $app->supplierInvoices()->find($raceA)['status'] === 'posted' ? $raceB : $raceA;
    $service->cancel($ownerId, $loser, 'Another invoice already matched this received quantity');
    check53($service->reconcile($ownerId, $smallPo['id'])['reconciled'], 'concurrent match ledger and invoice payables reconcile');

    $fullPo = po53([['product_unit_id' => $other, 'ordered_qty' => '2', 'unit_purchase_price' => '10']]);
    $fullReceipt = receipt53($fullPo, ['2']);
    $fullId = approved53($fullPo['id'], [['goods_receipt_item_id' => $fullReceipt['items'][0]['id'], 'quantity' => '2']]);
    $pdo->prepare("UPDATE supplier_invoices SET due_date = '2026-12-25' WHERE id = ?")->execute([$fullId]);
    reject53(fn () => $service->post($ownerId, $fullId), 'changed invoice metadata cannot bypass its immutable approval snapshot');
    $pdo->prepare("UPDATE supplier_invoices SET due_date = '2026-12-24' WHERE id = ?")->execute([$fullId]);
    check53(race53($fullId, $fullId) === ['duplicate','posted'], 'concurrent duplicate posting recognizes exactly one full supplier invoice');
    check53($service->reconcile($ownerId, $fullPo['id'])['reconciled'], 'full supplier invoice reconciles to its received quantities and payable');

    $rollbackPo = po53([['product_unit_id' => $base, 'ordered_qty' => '1', 'unit_purchase_price' => '10'],
        ['product_unit_id' => $other, 'ordered_qty' => '1', 'unit_purchase_price' => '20']]);
    $rollbackReceipt = receipt53($rollbackPo, ['1','1']);
    $rollbackId = approved53($rollbackPo['id'], array_map(static fn (array $row): array => ['goods_receipt_item_id' => $row['id'], 'quantity' => '1'], $rollbackReceipt['items']));
    $blockedId = (int) $rollbackReceipt['items'][1]['id'];
    $pdo->exec('CREATE TRIGGER ' . $triggerName . " BEFORE INSERT ON supplier_invoice_matches FOR EACH ROW BEGIN IF NEW.goods_receipt_item_id = " . $blockedId . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5.3 rollback test'; END IF; END");
    reject53(fn () => $service->post($ownerId, $rollbackId), 'failure on a later matched line aborts posting');
    check53($app->payables()->forInvoice($rollbackId) === null && $app->supplierInvoices()->matches($rollbackId) === []
        && $app->supplierInvoices()->find($rollbackId)['status'] === 'approved', 'failed posting rolls back payable, earlier matches, and invoice status atomically');
    $pdo->exec('DROP TRIGGER ' . $triggerName);
    $service->post($ownerId, $rollbackId);
    check53($service->reconcile($ownerId, $rollbackPo['id'])['reconciled'], 'approved invoice can post safely after a rolled-back attempt');

    $roundPo = po53([['product_unit_id' => $base, 'ordered_qty' => '3', 'unit_purchase_price' => '0.1234']], 'USD', '1.234567');
    $roundGr = receipt53($roundPo, ['3']);
    $roundIds = [];
    for ($i = 0; $i < 3; $i++) {
        $roundId = approved53($roundPo['id'], [['goods_receipt_item_id' => $roundGr['items'][0]['id'], 'quantity' => '1']]);
        $roundIds[] = $roundId; $service->post($ownerId, $roundId);
    }
    $roundReport = $service->reconcile($ownerId, $roundPo['id']);
    check53($roundReport['reconciled'] && $roundReport['quantities'][0]['matched_receipt_value_khr'] === '0.4570'
        && $roundReport['posted_invoice_total_khr'] === '0.4569', 'partial invoice matching allocates exact receipt value and records rounding difference separately');
    $roundTypes = [];
    foreach ($roundIds as $id) { foreach ($app->supplierInvoices()->matches($id) as $m) { if ($m['variance_type']) { $roundTypes[] = $m['variance_type']; } } }
    check53($roundTypes === ['rounding'], 'rounding-only difference does not masquerade as a purchase-price change');

    $rejectId = $service->createDraft($ownerId, invoiceInput53($rollbackPo['id']))['invoice_id'];
    $service->cancel($ownerId, $rejectId, 'Draft invoice is not needed');
    reject53(fn () => $service->submit($ownerId, $rejectId), 'cancelled invoice cannot be submitted');
    $rejectPo = po53([['product_unit_id' => $base, 'ordered_qty' => '2', 'unit_purchase_price' => '10']]);
    $rejectGr = receipt53($rejectPo, ['2']);
    $rejected = $service->createDraft($ownerId, invoiceInput53($rejectPo['id'], [['goods_receipt_item_id' => $rejectGr['items'][0]['id'], 'quantity' => '1']]))['invoice_id'];
    $service->submit($ownerId, $rejected); $service->reject($ownerId, $rejected, 'Supplier needs to correct the invoice');
    reject53(fn () => $service->approve($ownerId, $rejected), 'rejected invoice cannot be approved');
    $legacy = $pdo->prepare('INSERT INTO payables (purchase_id, supplier_id, original_khr) VALUES (?, ?, ?)'); $legacy->execute([$rejectPo['id'], $supplierIds[0], '1.0000']);
    $legacyInvoice = approved53($rejectPo['id'], [['goods_receipt_item_id' => $rejectGr['items'][0]['id'], 'quantity' => '1']]);
    reject53(fn () => $service->post($ownerId, $legacyInvoice), 'existing legacy PO payable blocks duplicate liability recognition');
    $pdo->prepare('DELETE FROM payables WHERE purchase_id = ? AND supplier_invoice_id IS NULL')->execute([$rejectPo['id']]);

    $payable = $app->payables()->forInvoice($fullId);
    $pdo->prepare('UPDATE payables SET original_khr = original_khr + 1 WHERE id = ?')->execute([$payable['id']]);
    $corruptReport = $service->reconcile($ownerId, $fullPo['id']);
    check53(!$corruptReport['reconciled'] && $app->payables()->forInvoice($fullId)['original_khr'] === '21.0000', 'read-only payable reconciliation detects discrepancies without repairing them');
    check53(!$app->accountsPayable()->forSupplier($ownerId, $supplierIds[0])['reconciled'], 'supplier-wide payable reconciliation also identifies mismatched invoice balances');
    $pdo->prepare('UPDATE payables SET original_khr = original_khr - 1 WHERE id = ?')->execute([$payable['id']]);

    // Invoicing creates no inventory/cash/receivable activity. Subsequent
    // fixture receipts are excluded by comparing immediately around a post.
    $guardBefore = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(),
        (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM inventory_cost_entries')->fetchColumn()];
    $service->post($ownerId, $legacyInvoice);
    $guardAfter = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(),
        (int) $pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn(), (int) $pdo->query('SELECT COUNT(*) FROM inventory_cost_entries')->fetchColumn()];
    check53($guardBefore === $guardAfter && (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() === $paymentCount
        && (int) $pdo->query('SELECT COUNT(*) FROM receivables')->fetchColumn() === $receivableCount, 'ordinary invoice posting preserves inventory/value and creates no payments or customer receivables');
    $staffView = $service->forViewer($staffId, $invoiceA);
    $staffList = $service->listForViewer($staffId, $supplierIds[0]);
    check53(array_intersect(['items','total_khr','payable','currency','notes'], array_keys($staffView)) === []
        && !array_key_exists('total_original', $staffList[0]), 'Staff responses expose metadata only and omit purchasing prices and payable amounts');
    reject53(fn () => $app->accountsPayable()->forSupplier($staffId, $supplierIds[0]), 'Staff cannot access supplier outstanding balances');

    Session::clearAuthentication(); $app->auth()->attemptLogin($ownerName, $password);
    $router = require dirname(__DIR__) . '/routes/web.php';
    foreach (['create','update','submit','approve','post','reject','cancel'] as $action) {
        $_POST = ['_csrf' => 'invalid-token'];
        try { $router->dispatch('supplier-invoice-' . $action, 'POST'); check53(false, 'CSRF rejection for ' . $action); }
        catch (HttpException $e) { check53($e->statusCode() === 419, 'supplier-invoice-' . $action . ' rejects invalid CSRF'); }
    }
    $_POST = ['_csrf' => Session::csrfToken(), 'invoice_id' => $invoiceA];
    ob_start(); $router->dispatch('supplier-invoice-post', 'POST'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check53($json['success'] && !$json['data']['posted'] && http_response_code() === 200, 'HTTP invoice posting returns idempotent success JSON');
    Session::clearAuthentication(); $app->auth()->attemptLogin($staffName, $password);
    foreach (['approve','post','reject','cancel'] as $action) {
        $_POST = ['_csrf' => Session::csrfToken()];
        try { $router->dispatch('supplier-invoice-' . $action, 'POST'); check53(false, 'Staff authorization for ' . $action); }
        catch (HttpException $e) { check53($e->statusCode() === 403, 'supplier-invoice-' . $action . ' rejects Staff'); }
    }
    $_GET = ['invoice_id' => $invoiceA];
    ob_start(); $router->dispatch('supplier-invoice-detail', 'GET'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check53($json['success'] && !array_key_exists('total_khr', $json['data']), 'Staff HTTP invoice detail contains no financial fields');
    $_POST = ['_csrf' => Session::csrfToken(), 'purchase_id' => []];
    ob_start(); $router->dispatch('supplier-invoice-create', 'POST'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check53(!$json['success'] && http_response_code() === 422, 'malformed invoice input returns HTTP 422 JSON');
    $q = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE actor_id = ? AND action IN ('supplier_invoice.approved','supplier_invoice.posted','supplier_invoice.rejected','supplier_invoice.cancelled')"); $q->execute([$ownerId]);
    check53((int) $q->fetchColumn() > 0, 'invoice approvals, postings, rejections, and cancellations are audited');
} catch (Throwable $e) {
    $failures[] = $e->getMessage(); echo 'FAIL: ' . $e . PHP_EOL;
} finally {
    $_POST = $_GET = [];
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (session_status() === PHP_SESSION_ACTIVE) { Session::destroy(); }
    cleanup53();
}
echo $failures === [] ? 'All Phase 5.3 integration checks passed.' . PHP_EOL : count($failures) . ' Phase 5.3 checks failed.' . PHP_EOL;
ob_end_flush(); exit($failures === [] ? 0 : 1);
