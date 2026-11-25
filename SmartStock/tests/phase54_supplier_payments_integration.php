<?php
declare(strict_types=1);

use App\Core\{HttpException, Session};

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/TestDatabase.php'; $testName = requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php'; $pdo = $app->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testName || $app->users()->countOwners() !== 0) { fwrite(STDERR, 'Use an empty disposable database with no Owner.'); exit(2); }
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'supplier_payment_differences'")->fetchColumn()) { fwrite(STDERR, 'Apply Phase 5.4 to the disposable database first.'); exit(2); }
$directory = dirname(__DIR__) . '/storage/phase54-test-sessions';
if (!is_dir($directory)) { mkdir($directory, 0700, true); } session_save_path($directory);
ob_start(); Session::start();
$tag = 'P54-' . strtoupper(bin2hex(random_bytes(5)));
$ownerId = $staffId = $categoryId = $productId = null;
$supplierIds = $purchaseIds = $inventoryIds = $failures = []; $checks = 0;
$triggerName = 'p54_fail_' . strtolower(str_replace('-', '_', $tag));

function check54(bool $pass, string $message): void
{
    global $failures, $checks; ++$checks;
    if (!$pass) { $failures[] = $message; } echo ($pass ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL;
}
function reject54(callable $operation, string $message): void
{
    try { $operation(); check54(false, $message); } catch (DomainException) { check54(true, $message); }
}
function invoice54(string $price, string $currency = 'KHR', string $rate = '1.000000', int $supplierIndex = 0, bool $post = true): int
{
    global $app, $ownerId, $supplierIds, $purchaseIds, $inventoryIds, $unitId, $locationId, $tag;
    $supplier = $supplierIds[$supplierIndex];
    $po = $app->purchasingService()->createDraft($ownerId, ['purchase_key' => $tag . '-' . bin2hex(random_bytes(8)),
        'supplier_id' => $supplier, 'currency' => $currency, 'exchange_rate_to_khr' => $rate,
        'items' => [['product_unit_id' => $unitId, 'ordered_qty' => '1', 'unit_purchase_price' => $price]]]);
    $poId = $po['purchase_id']; $purchaseIds[] = $poId;
    $app->purchasingService()->submit($ownerId, $poId); $app->purchasingService()->approve($ownerId, $poId);
    $gr = $app->goodsReceiving()->createDraft($ownerId, ['purchase_id' => $poId, 'receipt_key' => $tag . '-' . bin2hex(random_bytes(8)),
        'reference_no' => $tag . '-' . bin2hex(random_bytes(5)), 'location_id' => $locationId,
        'items' => [['purchase_item_id' => $app->purchaseOrders()->items($poId)[0]['id'], 'quantity' => '1']]]);
    $inventoryIds[] = (int) $app->goodsReceipts()->find($gr['receipt_id'])['inventory_receipt_id'];
    $app->goodsReceiving()->confirm($ownerId, $gr['receipt_id']);
    $invoice = $app->supplierInvoiceService()->createDraft($ownerId, ['invoice_key' => $tag . '-' . bin2hex(random_bytes(8)),
        'supplier_invoice_no' => $tag . '-' . bin2hex(random_bytes(8)), 'supplier_id' => $supplier, 'purchase_id' => $poId,
        'invoice_date' => '2026-11-24', 'due_date' => '2026-12-24',
        'items' => [['goods_receipt_item_id' => $app->goodsReceipts()->items($gr['receipt_id'])[0]['id'], 'quantity' => '1']]]);
    $id = $invoice['invoice_id'];
    if ($post) { $app->supplierInvoiceService()->submit($ownerId, $id); $app->supplierInvoiceService()->approve($ownerId, $id); $app->supplierInvoiceService()->post($ownerId, $id); }
    return $id;
}
function input54(array $allocations, string $amount, string $currency = 'KHR', string $rate = '1.000000', string $method = 'cash', array $overrides = []): array
{
    global $tag, $supplierIds;
    return array_replace(['payment_key' => $tag . '-' . bin2hex(random_bytes(8)), 'supplier_id' => $supplierIds[0],
        'payment_method' => $method, 'amount_original' => $amount, 'currency' => $currency, 'exchange_rate_to_khr' => $rate,
        'reference_no' => $tag . '-' . bin2hex(random_bytes(8)), 'paid_at' => '2026-11-24 12:30:00', 'allocations' => $allocations], $overrides);
}
function allocation54(int $invoice, string $original, ?string $cash = null): array
{
    return ['invoice_id' => $invoice, 'invoice_amount_original' => $original, 'payment_amount_original' => $cash ?? $original];
}
function request54(array $input, bool $approve = true): int
{
    global $app, $ownerId;
    $s = $app->supplierPaymentService(); $id = $s->createDraft($ownerId, $input)['supplier_payment_id'];
    $s->submit($ownerId, $id); if ($approve) { $s->approve($ownerId, $id); } return $id;
}
function outstanding54(int $invoice): array
{
    global $app, $ownerId, $supplierIds;
    foreach ($app->accountsPayable()->forSupplier($ownerId, $supplierIds[0])['payables'] as $row) { if ((int) $row['supplier_invoice_id'] === $invoice) { return $row; } }
    throw new RuntimeException('Fixture payable missing.');
}
function race54(int $a, int $b, string $method = 'post'): array
{
    global $ownerId;
    $workers = [];
    foreach ([$a, $b] as $id) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/phase54_payment_worker.php', (string) $ownerId, (string) $id, $method],
            [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, dirname(__DIR__));
        if (!is_resource($process)) { throw new RuntimeException('Cannot launch settlement worker.'); }
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException('Settlement worker failed: ' . $error); }
    }
    sort($results); return $results;
}
function cleanup54(): void
{
    global $pdo, $ownerId, $staffId, $categoryId, $productId, $supplierIds, $purchaseIds, $inventoryIds, $triggerName;
    $pdo->exec('DROP TRIGGER IF EXISTS ' . $triggerName);
    foreach (array_filter([$ownerId, $staffId]) as $actor) {
        $q = $pdo->prepare('SELECT id, payment_id FROM supplier_payments WHERE created_by = ?'); $q->execute([$actor]);
        foreach ($q->fetchAll() as $doc) {
            $pdo->prepare('DELETE e FROM supplier_payable_activities e JOIN payable_allocations a ON a.id=e.payment_allocation_id WHERE a.supplier_payment_id=?')->execute([$doc['id']]);
            $pdo->prepare('DELETE v FROM supplier_payment_differences v JOIN payable_allocations a ON a.id = v.allocation_id WHERE a.supplier_payment_id = ?')->execute([$doc['id']]);
            foreach (['payable_allocations','supplier_payment_verifications','supplier_payment_approvals','supplier_payment_items'] as $table) { $pdo->prepare('DELETE FROM ' . $table . ' WHERE supplier_payment_id = ?')->execute([$doc['id']]); }
            $pdo->prepare('DELETE FROM supplier_payments WHERE id = ?')->execute([$doc['id']]);
            if ($doc['payment_id']) { $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$doc['payment_id']]); }
        }
    }
    foreach (array_filter([$ownerId, $staffId]) as $actor) {
        $q = $pdo->prepare('SELECT id FROM supplier_invoices WHERE created_by = ?'); $q->execute([$actor]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            foreach (['DELETE v FROM supplier_invoice_price_variances v JOIN supplier_invoice_matches m ON m.id = v.match_id WHERE m.invoice_id = ?',
                'DELETE FROM supplier_invoice_matches WHERE invoice_id = ?', 'DELETE FROM payables WHERE supplier_invoice_id = ?',
                'DELETE FROM supplier_invoice_approvals WHERE invoice_id = ?', 'DELETE FROM supplier_invoice_items WHERE invoice_id = ?', 'DELETE FROM supplier_invoices WHERE id = ?'] as $sql) { $pdo->prepare($sql)->execute([$id]); }
        }
    }
    foreach ($purchaseIds as $id) {
        foreach (['DELETE v FROM goods_receipt_cost_variances v JOIN goods_receipt_items gi ON gi.id = v.goods_receipt_item_id JOIN goods_receipts gr ON gr.id = gi.receipt_id WHERE gr.purchase_id = ?',
            'DELETE gi FROM goods_receipt_items gi JOIN goods_receipts gr ON gr.id = gi.receipt_id WHERE gr.purchase_id = ?',
            'DELETE FROM goods_receipts WHERE purchase_id = ?', 'DELETE FROM purchase_order_approval_snapshots WHERE purchase_id = ?',
            'DELETE FROM purchase_items WHERE purchase_id = ?', 'DELETE FROM purchases WHERE id = ?'] as $sql) { $pdo->prepare($sql)->execute([$id]); }
    }
    foreach ($inventoryIds as $id) {
        $pdo->prepare("DELETE FROM inventory_cost_entries WHERE source_type = 'inventory_receipt' AND source_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM stock_movements WHERE source_type = 'inventory_receipt' AND source_id = ?")->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM inventory_receipts WHERE id = ?')->execute([$id]);
    }
    if ($productId) {
        foreach (['inventory_cost_entries','stock_movements','stock_balances','product_costs','product_units'] as $table) { $pdo->prepare('DELETE FROM ' . $table . ' WHERE product_id = ?')->execute([$productId]); }
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    }
    if ($categoryId) { $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$categoryId]); }
    foreach ($supplierIds as $id) { $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]); }
    foreach (array_filter([$ownerId, $staffId]) as $id) {
        foreach (['audit_logs' => 'actor_id','auth_sessions' => 'user_id','user_roles' => 'user_id'] as $table => $column) { $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' = ?')->execute([$id]); }
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
}

try {
    $ownerName = strtolower($tag) . '_owner'; $staffName = strtolower($tag) . '_staff'; $password = 'PaymentsTest!' . $tag;
    $app->auth()->createInitialOwner($ownerName, 'Supplier Payment Owner', $password); $ownerId = (int) $app->users()->findByUsername($ownerName)['id'];
    $app->auth()->createStaff($ownerId, $staffName, 'Supplier Payment Staff', $password); $staffId = (int) $app->users()->findByUsername($staffName)['id'];
    foreach (['A','B'] as $code) { $supplierIds[] = $app->supplierService()->create($ownerId, ['supplier_code' => $tag . $code, 'name' => $tag . $code,
        'contact_person' => 'Contact', 'phone' => '12345678', 'address' => 'Test address', 'payment_terms_days' => '30']); }
    $categoryId = $app->productDomain()->createCategory($ownerId, $tag, null);
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code = 'pcs'")->fetchColumn();
    $locationId = (int) $pdo->query("SELECT id FROM stock_locations WHERE code = 'MAIN'")->fetchColumn();
    $productId = $app->productDomain()->createProduct($ownerId, ['sku' => $tag, 'name' => $tag, 'category_id' => $categoryId, 'base_unit_id' => $pcs, 'kind' => 'physical']);
    $unitId = (int) $app->products()->findProductUnitByProductAndUnit($productId, $pcs)['id'];
    $s = $app->supplierPaymentService();
    $fullInvoice = invoice54('100'); $partialInvoice = invoice54('100'); $multiInvoice = invoice54('80');
    $usdInvoice = invoice54('10', 'USD', '4000.000000'); $gainInvoice = invoice54('10', 'USD', '4000.000000');
    $crossUsd = invoice54('5', 'USD', '4000.000000'); $crossKhr = invoice54('1000');
    $roundInvoice = invoice54('0.3702', 'USD', '1.234567');
    $raceInvoice = invoice54('90'); $rollbackA = invoice54('11'); $rollbackB = invoice54('12');
    $foreignInvoice = invoice54('10', supplierIndex: 1); $draftInvoice = invoice54('20', post: false);
    $rejectedInvoice = invoice54('20', post: false);
    $app->supplierInvoiceService()->submit($ownerId, $rejectedInvoice);
    $app->supplierInvoiceService()->reject($ownerId, $rejectedInvoice, 'Supplier must correct invoice');
    $unpostedInvoice = invoice54('20', post: false);
    $app->supplierInvoiceService()->submit($ownerId, $unpostedInvoice);
    $app->supplierInvoiceService()->approve($ownerId, $unpostedInvoice);
    $invariants = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM stock_balances ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM inventory_cost_entries ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM payables ORDER BY id')->fetchAll(), $app->paymentService()->financialReconciliation($ownerId),
        $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn(), $pdo->query('SELECT COUNT(*) FROM receivable_allocations')->fetchColumn()];

    $metadata = ['payment_key' => $tag . '-STAFF-REQUEST', 'supplier_id' => $supplierIds[0], 'reference_no' => $tag . '-STAFF-REF', 'notes' => 'Please settle supplier invoice'];
    $staffDoc = $s->createDraft($staffId, $metadata)['supplier_payment_id'];
    check54(!$s->createDraft($staffId, $metadata)['created'], 'payment request replay is idempotent');
    reject54(fn () => $s->createDraft($staffId, array_replace($metadata, ['notes' => 'Different'])), 'request key cannot change original content');
    reject54(fn () => $s->submit($staffId, $staffDoc), 'unpriced Staff request cannot be submitted');
    reject54(fn () => $s->updateDraft($staffId, $staffDoc, ['amount_original' => '100']), 'Staff cannot supply financial fields');
    $priced = input54([allocation54($fullInvoice, '100')], '100'); unset($priced['payment_key'], $priced['supplier_id']);
    $s->updateDraft($ownerId, $staffDoc, $priced);
    reject54(fn () => $s->updateDraft($staffId, $staffDoc, ['notes' => 'Change priced request']), 'Staff cannot edit priced request');
    $s->submit($staffId, $staffDoc);
    reject54(fn () => $s->post($ownerId, $staffDoc), 'submitted payment cannot bypass approval');
    reject54(fn () => $s->approve($staffId, $staffDoc), 'Staff cannot approve supplier payments');
    reject54(fn () => $s->post($staffId, $staffDoc), 'Staff cannot post supplier payments');
    check54($s->approve($ownerId, $staffDoc)['approved'] && !$s->approve($ownerId, $staffDoc)['approved'], 'approval creates one immutable approval');
    check54(outstanding54($fullInvoice)['outstanding_khr'] === '100.0000', 'approval reserves but does not reduce Accounts Payable');
    reject54(fn () => $s->updateDraft($ownerId, $staffDoc, ['notes' => 'Alter approval']), 'approved payment cannot be edited');
    $posted = $s->post($ownerId, $staffDoc);
    check54($posted['posted'] && !$s->post($ownerId, $staffDoc)['posted'] && outstanding54($fullInvoice)['outstanding_khr'] === '0.0000', 'full cash KHR payment settles once');
    reject54(fn () => $s->cancel($ownerId, $staffDoc, 'Erase settlement'), 'posted payment cannot be cancelled');
    reject54(fn () => $s->reject($ownerId, $staffDoc, 'Erase settlement'), 'posted payment cannot be rejected');
    reject54(fn () => $app->paymentService()->verifyPayment($ownerId, $posted['payment_id'], false, 'Wrong workflow'), 'customer verification cannot alter a supplier payment');
    $p = request54(input54([allocation54($partialInvoice, '30')], '30')); $s->post($ownerId, $p);
    check54(outstanding54($partialInvoice)['outstanding_original'] === '70.0000', 'partial payment preserves remaining invoice-currency debt');
    $p = request54(input54([allocation54($partialInvoice, '20')], '20')); $s->post($ownerId, $p);
    check54(outstanding54($partialInvoice)['paid_original'] === '50.0000', 'multiple payments settle one invoice without overwriting allocation history');
    $p = request54(input54([allocation54($multiInvoice, '80'), allocation54($partialInvoice, '50')], '130'));
    $s->post($ownerId, $p);
    check54(count($app->supplierPayments()->allocations($p)) === 2 && outstanding54($partialInvoice)['outstanding_khr'] === '0.0000'
        && outstanding54($multiInvoice)['outstanding_khr'] === '0.0000', 'one payment allocates across multiple same-supplier invoices in deterministic order');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($foreignInvoice, '10')], '10')), 'cross-supplier allocation is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($draftInvoice, '20')], '20')), 'draft/unposted invoice allocation is rejected');
    $app->supplierInvoiceService()->cancel($ownerId, $draftInvoice, 'Invalid invoice');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($draftInvoice, '20')], '20')), 'cancelled invoice allocation is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($rejectedInvoice, '20')], '20')), 'rejected invoice allocation is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($unpostedInvoice, '20')], '20')), 'approved but unposted invoice allocation is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($fullInvoice, '1')], '1')), 'settled invoice cannot be overpaid');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($usdInvoice, '10')], '11', 'USD', '4000')), 'unallocated cash overpayment is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($usdInvoice, '11')], '11', 'USD', '4000')), 'invoice original-currency over-allocation is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($usdInvoice, '5'), allocation54($usdInvoice, '5')], '10', 'USD', '4000')), 'duplicate invoice within one payment is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($usdInvoice, '10')], '10', 'USD', '0')), 'zero exchange rate is rejected');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($crossKhr, '1000')], '1000', 'KHR', '4000')), 'KHR exchange rate must be exactly one');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($usdInvoice, '10')], '10', 'USD', '4000', overrides: ['amount_original' => '1e1'])), 'scientific notation cannot enter fixed-point amounts');
    $p = request54(input54([allocation54($usdInvoice, '10')], '10', 'USD', '4100')); $s->post($ownerId, $p);
    $a = $app->supplierPayments()->allocations($p)[0];
    check54($a['amount_khr'] === '40000.0000' && $a['cash_amount_khr'] === '41000.0000' && $a['fx_difference_khr'] === '1000.0000'
        && outstanding54($usdInvoice)['outstanding_khr'] === '0.0000', 'cash USD settles historical carrying value and records FX loss separately');
    $p = request54(input54([allocation54($gainInvoice, '10')], '10', 'USD', '3900')); $s->post($ownerId, $p);
    check54($app->supplierPayments()->allocations($p)[0]['fx_difference_khr'] === '-1000.0000', 'lower USD settlement rate records signed FX gain');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($crossUsd, '5', '20500')], '20500')), 'paying USD debt in KHR requires explicit settlement USD rate');
    $p = request54(input54([allocation54($crossUsd, '5', '20500')], '20500', overrides: ['settlement_usd_rate_to_khr' => '4100'])); $s->post($ownerId, $p);
    check54($app->supplierPayments()->allocations($p)[0]['fx_difference_khr'] === '500.0000' && outstanding54($crossUsd)['outstanding_original'] === '0.0000', 'KHR cash can settle USD invoice at documented settlement rate without changing invoice FX');
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($crossKhr, '1000', '1')], '1', 'USD', '4100')), 'cross-currency share cannot conceal excess cash settlement');
    $p = request54(input54([allocation54($crossKhr, '1000', '0.2439')], '0.2439', 'USD', '4100')); $s->post($ownerId, $p);
    $a = $app->supplierPayments()->allocations($p)[0];
    check54($a['fx_difference_khr'] === '0.0000' && $a['rounding_difference_khr'] === '-0.0100' && outstanding54($crossKhr)['outstanding_khr'] === '0.0000', 'USD cash settlement of KHR debt reports conversion rounding separately from FX');
    for ($i = 0; $i < 3; ++$i) { $p = request54(input54([allocation54($roundInvoice, '0.1234')], '0.1234', 'USD', '1.234567')); $s->post($ownerId, $p); }
    check54(outstanding54($roundInvoice)['verified_paid_khr'] === '0.4570' && outstanding54($roundInvoice)['outstanding_khr'] === '0.0000', 'cumulative partial settlements release exact historical payable with no stranded rounding dust');
    $cashGuard = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM stock_balances ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM inventory_cost_entries ORDER BY id')->fetchAll()];
    check54($cashGuard === array_slice($invariants, 0, 4) && $pdo->query('SELECT * FROM payables ORDER BY id')->fetchAll() === $invariants[4], 'all full, partial and cross-currency cash payments leave inventory and original liability records unchanged');

    $bankInvoice = invoice54('25'); $khqrInvoice = invoice54('35');
    $releaseInvoice = invoice54('7');
    $manualGuard = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM stock_balances ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM inventory_cost_entries ORDER BY id')->fetchAll()];
    $releaseA = request54(input54([allocation54($releaseInvoice, '7')], '7'));
    reject54(fn () => $s->createDraft($ownerId, input54([allocation54($releaseInvoice, '7')], '7')), 'approved request reserves debt against competing payment requests');
    $s->reject($ownerId, $releaseA, 'Incorrect payment request');
    $releaseB = request54(input54([allocation54($releaseInvoice, '7')], '7'));
    $s->cancel($ownerId, $releaseB, 'Unverified request cancelled');
    $releaseC = request54(input54([allocation54($releaseInvoice, '7')], '7'));
    $s->post($ownerId, $releaseC);
    check54(outstanding54($releaseInvoice)['outstanding_khr'] === '0.0000', 'rejection and cancellation release only unposted/unverified reservations');
    foreach ([[$bankInvoice,'25','bank'], [$khqrInvoice,'35','khqr']] as [$inv, $amount, $method]) {
        reject54(fn () => $s->createDraft($ownerId, input54([allocation54($inv, $amount)], $amount, method: $method, overrides: ['reference_no' => ''])), $method . ' requires reference');
        $input = input54([allocation54($inv, $amount)], $amount, method: $method); $p = request54($input);
        check54(outstanding54($inv)['outstanding_khr'] === $amount . '.0000', $method . ' approval has no settlement effect');
        reject54(fn () => $s->post($ownerId, $p), $method . ' cannot post without documented verification');
        reject54(fn () => $s->verify($staffId, $p, 'Fabricated evidence'), $method . ' Staff verification is rejected');
        reject54(fn () => $s->verify($ownerId, $p, ''), $method . ' requires verification evidence');
        check54($s->verify($ownerId, $p, 'Statement checked: ' . $input['reference_no'])['verified'] && !$s->verify($ownerId, $p, 'Statement checked: ' . $input['reference_no'])['verified'], $method . ' verification is immutable and idempotent');
        reject54(fn () => $s->verify($ownerId, $p, 'Different evidence'), $method . ' verification evidence cannot change');
        reject54(fn () => $s->cancel($ownerId, $p, 'Abandon executed transfer'), $method . ' verified transfer cannot release reservation through cancellation');
        check54(outstanding54($inv)['outstanding_khr'] === $amount . '.0000', $method . ' verification alone does not reduce payable');
        $s->post($ownerId, $p);
        check54(outstanding54($inv)['outstanding_khr'] === '0.0000', $method . ' posted verified payment settles payable');
        reject54(fn () => $s->createDraft($ownerId, input54([allocation54($raceInvoice, '1')], '1', method: $method, overrides: ['reference_no' => '  ' . strtolower($input['reference_no']) . '  '])), $method . ' duplicate normalized reference is rejected');
    }
    $a = request54(input54([allocation54($raceInvoice, '90')], '90'), false);
    $b = request54(input54([allocation54($raceInvoice, '90')], '90'), false);
    check54(race54($a, $b, 'approve') === ['approved','rejected'], 'concurrent competing settlements reserve invoice exactly once');
    $winner = $app->supplierPayments()->find($a)['status'] === 'approved' ? $a : $b;
    $loser = $winner === $a ? $b : $a;
    check54(race54($winner, $winner) === ['duplicate','posted'] && outstanding54($raceInvoice)['outstanding_khr'] === '0.0000', 'concurrent duplicate posting creates exactly one payment and allocation');
    reject54(fn () => $s->approve($ownerId, $loser), 'stale submitted request cannot settle already-paid balance');
    $s->reject($ownerId, $loser, 'Another request already settled this invoice');
    reject54(fn () => $s->approve($ownerId, $loser), 'rejected payment cannot be approved');

    $p = request54(input54([allocation54($rollbackA, '11'), allocation54($rollbackB, '12')], '23'));
    $badPayable = (int) $app->payables()->forInvoice($rollbackB)['id'];
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn();
    $pdo->exec('CREATE TRIGGER ' . $triggerName . " BEFORE INSERT ON payable_allocations FOR EACH ROW BEGIN IF NEW.payable_id = " . $badPayable . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Phase 5.4 rollback test'; END IF; END");
    reject54(fn () => $s->post($ownerId, $p), 'late allocation failure aborts complete posting');
    check54((int) $pdo->query('SELECT COUNT(*) FROM payments')->fetchColumn() === $beforeCount && $app->supplierPayments()->allocations($p) === []
        && $app->supplierPayments()->find($p)['status'] === 'approved' && outstanding54($rollbackA)['outstanding_khr'] === '11.0000', 'rollback preserves approval/reservation but removes cash, earlier allocation and FX records');
    $pdo->exec('DROP TRIGGER ' . $triggerName);
    // Approval hashes defend against direct data drift as well as application edits.
    $pdo->prepare("UPDATE supplier_payments SET notes = 'tampered' WHERE id = ?")->execute([$p]);
    reject54(fn () => $s->post($ownerId, $p), 'changed approval content cannot post');
    check54(!$s->reconcile($ownerId, $supplierIds[0])['reconciled'], 'read-only reconciliation detects approval content drift');
    $pdo->prepare('UPDATE supplier_payments SET notes = NULL WHERE id = ?')->execute([$p]);
    $s->post($ownerId, $p);
    $report = $s->reconcile($ownerId, $supplierIds[0]);
    if (!$report['reconciled']) { echo json_encode($report['issues'], JSON_THROW_ON_ERROR) . PHP_EOL; }
    check54($report['reconciled'] && $report['payables']['outstanding_khr'] === '0.0000' && $report['fx_difference_khr'] === '500.0000', 'posted invoices, cash, allocations, remaining debt and signed FX reconcile');
    check54($report['rounding_difference_khr'] === '-0.0101', 'cumulative carrying-value rounding is reported as rounding, not FX gain/loss');
    $allocationId = (int) $app->supplierPayments()->allocations($p)[0]['id'];
    $pdo->prepare('UPDATE supplier_payment_differences SET rounding_difference_khr = rounding_difference_khr + 1 WHERE allocation_id = ?')->execute([$allocationId]);
    $bad = $s->reconcile($ownerId, $supplierIds[0]);
    check54(!$bad['reconciled'], 'reconciliation reports allocation rounding discrepancy without repairing it');
    $pdo->prepare('UPDATE supplier_payment_differences SET rounding_difference_khr = rounding_difference_khr - 1 WHERE allocation_id = ?')->execute([$allocationId]);
    $ledgerId = (int) $app->supplierPayments()->find($p)['payment_id'];
    $pdo->prepare("UPDATE payments SET verification_status = 'pending' WHERE id = ?")->execute([$ledgerId]);
    check54(!$s->reconcile($ownerId, $supplierIds[0])['reconciled'] && outstanding54($rollbackA)['outstanding_khr'] === '11.0000', 'only posted AND verified supplier allocations reduce reported payable');
    $pdo->prepare("UPDATE payments SET verification_status = 'verified' WHERE id = ?")->execute([$ledgerId]);
    $duplicateReference = $tag . '-DUPLICATE-METADATA';
    $metadataA = $s->createDraft($ownerId, ['payment_key' => $tag . '-DUPLICATE-REF-A', 'supplier_id' => $supplierIds[0], 'reference_no' => $duplicateReference])['supplier_payment_id'];
    $metadataB = $s->createDraft($ownerId, ['payment_key' => $tag . '-DUPLICATE-REF-B', 'supplier_id' => $supplierIds[0], 'reference_no' => $duplicateReference])['supplier_payment_id'];
    check54(!$s->reconcile($ownerId, $supplierIds[0])['reconciled'], 'reconciliation detects duplicate references even in unpriced requests');
    $s->updateDraft($ownerId, $metadataB, ['reference_no' => $duplicateReference . '-CORRECTED']);
    $s->cancel($ownerId, $metadataA, 'Unneeded metadata request'); $s->cancel($ownerId, $metadataB, 'Unneeded metadata request');

    $draft = $s->createDraft($staffId, ['payment_key' => $tag . '-CANCEL-REQUEST', 'supplier_id' => $supplierIds[0]])['supplier_payment_id'];
    $s->cancel($ownerId, $draft, 'Unneeded request');
    reject54(fn () => $s->submit($staffId, $draft), 'cancelled request cannot submit');
    reject54(fn () => $s->forViewer($staffId, $p), 'Staff cannot inspect another user payment');
    $safe = $s->forViewer($staffId, $staffDoc);
    check54(array_intersect(['amount_original','amount_khr','currency','items','allocations','approval','verification','approved_by','notes'], array_keys($safe)) === [], 'Staff own payment response excludes financial amounts, Owner notes and approval data');
    check54(count($s->listForViewer($staffId, $supplierIds[0])) === 2, 'Staff list contains only own metadata requests');
    reject54(fn () => $s->reconcile($staffId, $supplierIds[0]), 'Staff cannot access payment reconciliation');
    check54($app->paymentService()->financialReconciliation($ownerId) === $invariants[5]
        && $app->paymentService()->listPaymentsForViewer($staffId) === [], 'supplier cash flow stays out of customer payment register and revenue reconciliation');
    check54($app->supplierInvoices()->find($usdInvoice)['exchange_rate_to_khr'] === '4000.000000', 'settlement never modifies historical invoice exchange rate');

    Session::clearAuthentication(); $app->auth()->attemptLogin($ownerName, $password);
    $router = require dirname(__DIR__) . '/routes/web.php';
    foreach (['create','update','submit','approve','verify','post','reject','cancel'] as $action) {
        foreach (['invalid', []] as $token) {
            $_POST = ['_csrf' => $token];
            try { $router->dispatch('supplier-payment-' . $action, 'POST'); check54(false, $action . ' CSRF'); }
            catch (HttpException $e) { check54($e->statusCode() === 419, $action . ' rejects invalid/array CSRF token'); }
        }
    }
    $_POST = ['_csrf' => Session::csrfToken(), 'supplier_payment_id' => $staffDoc];
    ob_start(); $router->dispatch('supplier-payment-post', 'POST'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check54($json['success'] && !$json['data']['posted'] && http_response_code() === 200, 'HTTP duplicate payment posting returns idempotent success JSON');
    $_POST = ['_csrf' => Session::csrfToken(), 'supplier_id' => []];
    ob_start(); $router->dispatch('supplier-payment-create', 'POST'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check54(!$json['success'] && http_response_code() === 422, 'malformed request returns HTTP 422 JSON');
    Session::clearAuthentication(); $app->auth()->attemptLogin($staffName, $password);
    foreach (['approve','verify','post','reject','cancel'] as $action) {
        $_POST = ['_csrf' => Session::csrfToken()];
        try { $router->dispatch('supplier-payment-' . $action, 'POST'); check54(false, $action . ' authorization'); }
        catch (HttpException $e) { check54($e->statusCode() === 403, $action . ' rejects Staff through backend RBAC'); }
    }
    $_GET = ['supplier_payment_id' => $staffDoc];
    ob_start(); $router->dispatch('supplier-payment-detail', 'GET'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    check54($json['success'] && !isset($json['data']['amount_khr']) && !isset($json['data']['items']), 'Staff HTTP detail exposes metadata only');
    $_GET = ['supplier_id' => $supplierIds[0]];
    try { $router->dispatch('supplier-payment-reconciliation', 'GET'); check54(false, 'Staff reconciliation authorization'); }
    catch (HttpException $e) { check54($e->statusCode() === 403, 'Staff HTTP reconciliation is forbidden'); }
    foreach (['approved','verified','posted','rejected','cancelled'] as $action) {
        $q = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND actor_id = ?'); $q->execute(['supplier_payment.' . $action, $ownerId]);
        check54((int) $q->fetchColumn() > 0, 'supplier payment ' . $action . ' is audited');
    }
    // All supplier payment activity is stock/cost/expense neutral. Exclude the two later fixture receipts.
    $guard = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM stock_balances ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM inventory_cost_entries ORDER BY id')->fetchAll()];
    $s->reconcile($ownerId, $supplierIds[0]); $s->post($ownerId, $staffDoc);
    $after = [$pdo->query('SELECT * FROM product_costs ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM stock_balances ORDER BY id')->fetchAll(),
        $pdo->query('SELECT * FROM stock_movements ORDER BY id')->fetchAll(), $pdo->query('SELECT * FROM inventory_cost_entries ORDER BY id')->fetchAll()];
    check54($guard === $after && $manualGuard === $after && $pdo->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === $invariants[6]
        && $pdo->query('SELECT COUNT(*) FROM receivable_allocations')->fetchColumn() === $invariants[7], 'payment posting/reconciliation changes no stock, moving average, expenses or customer allocation');
} catch (Throwable $e) { $failures[] = $e->getMessage(); echo 'FAIL: ' . $e . PHP_EOL; }
finally {
    $_GET = $_POST = []; if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if (session_status() === PHP_SESSION_ACTIVE) { Session::destroy(); } cleanup54();
}
echo $failures === [] ? 'All Phase 5.4 integration checks passed (' . $checks . ' checks).' . PHP_EOL : count($failures) . ' Phase 5.4 checks failed.' . PHP_EOL;
ob_end_flush(); exit($failures === [] ? 0 : 1);
