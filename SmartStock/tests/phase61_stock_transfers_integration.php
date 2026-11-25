<?php
declare(strict_types=1);

use App\Core\{HttpException, Session};
use App\Services\StockTransferService;

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/TestDatabase.php';
$testDatabase = requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $app->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase || $app->users()->countOwners() !== 0) {
    fwrite(STDERR, 'Use an empty disposable _test database with no Owner.'); exit(2);
}
if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('stock_transfers','stock_transfer_items','stock_transfer_approvals','stock_transfer_postings')")->fetchColumn() !== 4) {
    fwrite(STDERR, 'Apply Phase 6.1 to the disposable database first.'); exit(2);
}
$sessions = dirname(__DIR__) . '/storage/phase61-test-sessions';
if (!is_dir($sessions)) { mkdir($sessions, 0700, true); }
session_save_path($sessions); ob_start(); Session::start();

$tag = 'P61-' . strtoupper(bin2hex(random_bytes(5)));
$checks = 0; $failures = [];
$owner = $staff = $category = $productOne = $productTwo = $unitOne = $unitTwo = $caseUnit = null;
$source = $destinationA = $destinationB = $destinationC = null;

function ok61(bool $pass, string $message): void { global $checks, $failures; ++$checks; if (!$pass) { $failures[] = $message; } echo ($pass ? 'PASS: ' : 'FAIL: ') . $message . PHP_EOL; }
function reject61(callable $operation, string $message): void { try { $operation(); ok61(false, $message); } catch (DomainException) { ok61(true, $message); } }
function key61(): string { global $tag; return $tag . '-' . bin2hex(random_bytes(8)); }
function balance61(int $productId, int $locationId): string { global $pdo; $q = $pdo->prepare('SELECT COALESCE(qty_base,0) FROM stock_balances WHERE product_id=? AND location_id=? AND batch_id IS NULL'); $q->execute([$productId, $locationId]); return (string) ($q->fetchColumn() ?: '0.000'); }
function productId61(int $productUnitId): int { global $pdo; $q = $pdo->prepare('SELECT product_id FROM product_units WHERE id=?'); $q->execute([$productUnitId]); return (int) $q->fetchColumn(); }
function seed61(int $unitId, int $locationId, string $quantity, string $cost): void { global $app, $owner; $receipt = $app->inventory()->createDraftReceipt($owner, ['receipt_type' => 'opening', 'reference_no' => key61(), 'location_id' => $locationId, 'items' => [['product_unit_id' => $unitId, 'quantity' => $quantity, 'cost_per_base_khr' => $cost]]]); $app->inventory()->confirmReceipt($owner, $receipt); }
function draft61(int $actor, int $source, int $destination, array $items): int { global $app; return $app->stockTransferService()->createDraft($actor, ['document_key' => key61(), 'source_location_id' => $source, 'destination_location_id' => $destination, 'items' => $items])['transfer_id']; }
function approved61(int $transferId, int $requester): void { global $app, $owner; $app->stockTransferService()->submit($requester, $transferId); $app->stockTransferService()->approve($owner, $transferId); }
function count61(string $sql, array $params = []): int { global $pdo; $q = $pdo->prepare($sql); $q->execute($params); return (int) $q->fetchColumn(); }
function concurrent61(int $first, int $second): array {
    global $owner;
    $barrier = tempnam(sys_get_temp_dir(), 'p61-transfer-');
    if ($barrier === false || !unlink($barrier)) { throw new RuntimeException('Cannot create concurrency barrier.'); }
    $workers = [];
    try {
        foreach ([$first, $second] as $transferId) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/phase61_transfer_worker.php', 'confirm', (string) $owner, (string) $transferId, $barrier], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
            if (!is_resource($process)) { throw new RuntimeException('Cannot start transfer worker.'); }
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        $result = [];
        foreach ($workers as [$process, $pipes]) {
            $result[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0 || $error !== '') { throw new RuntimeException('Transfer worker failed: ' . $error); }
        }
        sort($result); return $result;
    } finally { foreach (glob($barrier . '.*.ready') ?: [] as $file) { unlink($file); } }
}
function unauth61(string $route): int {
    $process = proc_open([PHP_BINARY, __DIR__ . '/phase61_transfer_worker.php', 'unauthenticated', $route], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) { throw new RuntimeException('Cannot start unauthenticated worker.'); }
    fclose($pipes[0]); $output = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '' || !preg_match('/^\d+$/', $output)) { throw new RuntimeException('Unauthenticated worker failed.'); }
    return (int) $output;
}

try {
    $password = 'Phase61!' . $tag;
    $ownerName = strtolower($tag) . '_owner'; $staffName = strtolower($tag) . '_staff';
    $app->auth()->createInitialOwner($ownerName, 'Phase 6.1 Owner', $password); $owner = (int) $app->users()->findByUsername($ownerName)['id'];
    $app->auth()->createStaff($owner, $staffName, 'Phase 6.1 Staff', $password); $staff = (int) $app->users()->findByUsername($staffName)['id'];
    $category = $app->productDomain()->createCategory($owner, $tag, null);
    $pcs = (int) $pdo->query("SELECT id FROM units WHERE code='pcs'")->fetchColumn();
    $productOne = $app->productDomain()->createProduct($owner, ['sku' => $tag . '-1', 'name' => $tag . ' One', 'category_id' => $category, 'base_unit_id' => $pcs, 'kind' => 'physical']);
    $productTwo = $app->productDomain()->createProduct($owner, ['sku' => $tag . '-2', 'name' => $tag . ' Two', 'category_id' => $category, 'base_unit_id' => $pcs, 'kind' => 'physical']);
    $unitOne = (int) $app->products()->findProductUnitByProductAndUnit($productOne, $pcs)['id'];
    $unitTwo = (int) $app->products()->findProductUnitByProductAndUnit($productTwo, $pcs)['id'];
    $case = $app->productDomain()->createUnit($owner, strtolower(str_replace('-', '', $tag)) . 'c', $tag . ' Case', 0);
    $caseUnit = $app->productDomain()->addProductUnit($owner, $productOne, $case, '12.000000');
    $source = (int) $pdo->query("SELECT id FROM stock_locations WHERE code='MAIN'")->fetchColumn();
    $locations = $pdo->prepare('INSERT INTO stock_locations (code,name,type,is_active) VALUES (?,?,?,?)');
    foreach ([['A', 'storage', 1], ['B', 'retail', 1], ['C', 'storage', 1], ['I', 'storage', 0]] as [$suffix, $type, $active]) { $locations->execute([$tag . $suffix, $tag . ' ' . $suffix, $type, $active]); }
    $q = $pdo->prepare('SELECT id FROM stock_locations WHERE code=?');
    foreach (['A', 'B', 'C'] as $suffix) { $q->execute([$tag . $suffix]); ${'destination' . $suffix} = (int) $q->fetchColumn(); }
    $q->execute([$tag . 'I']); $inactive = (int) $q->fetchColumn();
    seed61($unitOne, $source, '80', '7'); seed61($unitTwo, $source, '30', '11');
    $productOneCostBefore = $pdo->prepare('SELECT qty_valued,inventory_value_khr,avg_unit_cost_khr FROM product_costs WHERE product_id=?'); $productOneCostBefore->execute([$productOne]); $costBefore = $productOneCostBefore->fetch();
    $financialBefore = [count61('SELECT COUNT(*) FROM payments'), count61('SELECT COUNT(*) FROM payables'), count61('SELECT COUNT(*) FROM sales')];

    $single = draft61($staff, $source, $destinationA, [['product_unit_id' => $unitOne, 'quantity' => '5']]); approved61($single, $staff);
    ok61($app->stockTransferService()->confirm($owner, $single)['confirmed'], 'approved single-item transfer confirms once');
    ok61(balance61($productOne, $source) === '75.000' && balance61($productOne, $destinationA) === '5.000', 'confirmation moves base stock from source to destination only');
    ok61(count61("SELECT COUNT(*) FROM stock_movements WHERE source_type='stock_transfer' AND source_id=?", [$single]) === 2 && count61("SELECT COUNT(*) FROM inventory_cost_entries WHERE source_type='stock_transfer' AND source_id=?", [$single]) === 2, 'single transfer writes exactly one immutable outbound/inbound movement and valuation pair');
    $productOneCostAfter = $pdo->prepare('SELECT qty_valued,inventory_value_khr,avg_unit_cost_khr FROM product_costs WHERE product_id=?'); $productOneCostAfter->execute([$productOne]);
    ok61($costBefore === $productOneCostAfter->fetch(), 'internal transfer leaves product-wide moving-average quantity, value and unit cost unchanged');
    ok61(!$app->stockTransferService()->confirm($owner, $single)['confirmed'], 'duplicate confirmation returns the idempotent result without another posting');
    ok61($financialBefore === [count61('SELECT COUNT(*) FROM payments'), count61('SELECT COUNT(*) FROM payables'), count61('SELECT COUNT(*) FROM sales')], 'transfer creates no cash, payable, sales, revenue or liability records');

    $multi = draft61($staff, $source, $destinationB, [['product_unit_id' => $unitOne, 'quantity' => '3'], ['product_unit_id' => $unitTwo, 'quantity' => '4']]); approved61($multi, $staff); $app->stockTransferService()->confirm($owner, $multi);
    ok61(balance61($productOne, $destinationB) === '3.000' && balance61($productTwo, $destinationB) === '4.000', 'multi-item transfer posts paired lines across a second eligible location');
    $converted = draft61($staff, $source, $destinationC, [['product_unit_id' => $caseUnit, 'quantity' => '2']]); approved61($converted, $staff); $app->stockTransferService()->confirm($owner, $converted);
    ok61(balance61($productOne, $destinationC) === '24.000', 'product-unit conversion is snapshotted and posts the exact base quantity');

    reject61(fn () => draft61($staff, $source, $source, [['product_unit_id' => $unitOne, 'quantity' => '1']]), 'same source and destination are rejected');
    reject61(fn () => draft61($staff, $source, $inactive, [['product_unit_id' => $unitOne, 'quantity' => '1']]), 'inactive locations are rejected before draft creation');
    $insufficient = draft61($staff, $source, $destinationA, [['product_unit_id' => $unitTwo, 'quantity' => '1000']]); approved61($insufficient, $staff);
    $beforeInsufficient = balance61($productTwo, $source); reject61(fn () => $app->stockTransferService()->confirm($owner, $insufficient), 'insufficient source stock rejects confirmation');
    ok61($app->stockTransfers()->find($insufficient)['status'] === 'approved' && $beforeInsufficient === balance61($productTwo, $source) && count61("SELECT COUNT(*) FROM stock_movements WHERE source_id=? AND source_type='stock_transfer'", [$insufficient]) === 0, 'insufficient confirmation leaves no partial stock, movement or status advance');

    $raceProduct = $app->productDomain()->createProduct($owner, ['sku' => $tag . '-R', 'name' => $tag . ' Race', 'category_id' => $category, 'base_unit_id' => $pcs, 'kind' => 'physical']);
    $raceUnit = (int) $app->products()->findProductUnitByProductAndUnit($raceProduct, $pcs)['id']; seed61($raceUnit, $source, '5', '9');
    $raceA = draft61($staff, $source, $destinationA, [['product_unit_id' => $raceUnit, 'quantity' => '4']]); $raceB = draft61($staff, $source, $destinationB, [['product_unit_id' => $raceUnit, 'quantity' => '4']]); approved61($raceA, $staff); approved61($raceB, $staff);
    $race = concurrent61($raceA, $raceB); $raceTransfers = $pdo->prepare('SELECT status FROM stock_transfers WHERE id IN (?,?) ORDER BY id'); $raceTransfers->execute([$raceA, $raceB]); $statuses = array_column($raceTransfers->fetchAll(), 'status'); sort($statuses);
    ok61($race === ['confirmed', 'rejected'] && $statuses === ['approved', 'confirmed'] && balance61($raceProduct, $source) === '1.000' && count61("SELECT COUNT(*) FROM stock_movements WHERE source_type='stock_transfer' AND source_id IN (?,?)", [$raceA, $raceB]) === 2, 'independent MySQL processes serialize concurrent transfers and prevent negative source stock');

    $rollback = draft61($staff, $source, $destinationA, [['product_unit_id' => $unitTwo, 'quantity' => '2']]); approved61($rollback, $staff);
    $rollbackBalances = $pdo->query('SELECT product_id,location_id,qty_base FROM stock_balances ORDER BY id')->fetchAll(); $rollbackCost = $pdo->prepare('SELECT qty_valued,inventory_value_khr,avg_unit_cost_khr FROM product_costs WHERE product_id=?'); $rollbackCost->execute([$productTwo]); $rollbackCost = $rollbackCost->fetch();
    $faulty = new StockTransferService($pdo, $app->users(), $app->auditLog(), $app->stockTransfers(), $app->inventoryReceipts(), $app->inventory(), static function (): void { throw new DomainException('Injected Phase 6.1 post-write failure'); });
    reject61(fn () => $faulty->confirm($owner, $rollback), 'deterministic post-write fault injection aborts the transfer transaction');
    $costCheck = $pdo->prepare('SELECT qty_valued,inventory_value_khr,avg_unit_cost_khr FROM product_costs WHERE product_id=?'); $costCheck->execute([$productTwo]);
    ok61($app->stockTransfers()->find($rollback)['status'] === 'approved' && $rollbackBalances === $pdo->query('SELECT product_id,location_id,qty_base FROM stock_balances ORDER BY id')->fetchAll() && $rollbackCost === $costCheck->fetch() && count61('SELECT COUNT(*) FROM stock_transfer_postings stp JOIN stock_transfer_items sti ON sti.id=stp.transfer_item_id WHERE sti.transfer_id=?', [$rollback]) === 0 && count61("SELECT COUNT(*) FROM audit_logs WHERE action='stock_transfer.confirmed' AND entity_id=?", [$rollback]) === 0, 'rollback leaves no balance, valuation, posting, status or confirmation-audit residue');
    ok61($app->stockTransferService()->confirm($owner, $rollback)['confirmed'] && count61('SELECT COUNT(*) FROM stock_transfer_postings stp JOIN stock_transfer_items sti ON sti.id=stp.transfer_item_id WHERE sti.transfer_id=?', [$rollback]) === 1, 'retry after injected rollback posts exactly once');

    $report = $app->stockTransferService()->reconcile($owner, $single);
    ok61($report['read_only'] && $report['reconciled'] && $report['company_quantity_delta'] === '0.000' && $report['valuation_delta_khr'] === '0.0000' && $report['unmatched_pairs'] === 0 && $report['confirmation_audit_count'] === 1, 'read-only reconciliation proves paired movement, valuation, approval and confirmation evidence');
    reject61(fn () => $app->stockTransferService()->approve($staff, $single), 'Staff cannot approve transfers');
    reject61(fn () => $app->stockTransferService()->confirm($staff, $single), 'Staff cannot confirm transfers');
    reject61(fn () => $app->stockTransferService()->reconcile($staff, $single), 'Staff cannot access sensitive transfer reconciliation');
    $staffDetail = json_encode($app->stockTransferService()->forViewer($staff, $single), JSON_THROW_ON_ERROR);
    ok61(!str_contains($staffDetail, 'unit_cost_khr') && !str_contains($staffDetail, 'carrying_value_khr') && !str_contains($staffDetail, 'inventory_value_khr'), 'Staff transfer responses contain quantities only and no cost or valuation fields');

    $router = require dirname(__DIR__) . '/routes/web.php'; Session::clearAuthentication();
    ok61(unauth61('stock-transfer-create') === 302 && unauth61('stock-transfer-reconciliation') === 302, 'unauthenticated transfer HTTP routes use the existing 302 authentication contract');
    $httpInput = ['document_key' => key61(), 'source_location_id' => $source, 'destination_location_id' => $destinationA, 'items' => [['product_unit_id' => $unitTwo, 'quantity' => '1']]];
    $app->auth()->attemptLogin($staffName, $password);
    foreach ([null, 'invalid-token', []] as $token) { $_POST = $token === null ? [] : ['_csrf' => $token] + $httpInput; try { $router->dispatch('stock-transfer-create', 'POST'); ok61(false, 'missing or invalid transfer CSRF is rejected'); } catch (HttpException $exception) { ok61($exception->statusCode() === 419, 'missing or invalid transfer CSRF is rejected'); } }
    $_POST = ['_csrf' => Session::csrfToken()] + $httpInput; ob_start(); $router->dispatch('stock-transfer-create', 'POST'); $created = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR); $httpTransfer = (int) ($created['data']['transfer_id'] ?? 0);
    ok61($created['success'] && $httpTransfer > 0 && http_response_code() === 200, 'authorized Staff creates a quantity-only transfer through the CSRF-protected route');
    $_POST = ['_csrf' => Session::csrfToken(), 'transfer_id' => $httpTransfer]; ob_start(); $router->dispatch('stock-transfer-submit', 'POST'); $submitted = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    ok61($submitted['success'] && http_response_code() === 200, 'authorized Staff submits the transfer through the HTTP route');
    foreach (['stock-transfer-approve' => 'POST', 'stock-transfer-confirm' => 'POST', 'stock-transfer-reconciliation' => 'GET'] as $route => $method) { $_POST = ['_csrf' => Session::csrfToken(), 'transfer_id' => $httpTransfer]; $_GET = ['transfer_id' => $httpTransfer]; try { $router->dispatch($route, $method); ok61(false, 'Staff restricted HTTP action'); } catch (HttpException $exception) { ok61($exception->statusCode() === 403, 'Staff is forbidden from Owner-only transfer HTTP actions'); } }
    $_GET = ['transfer_id' => $single]; ob_start(); $router->dispatch('stock-transfer-detail', 'GET'); $staffHttpDetail = ob_get_clean(); ok61(!str_contains($staffHttpDetail, 'unit_cost_khr') && !str_contains($staffHttpDetail, 'carrying_value_khr'), 'Staff HTTP detail never exposes valuation information');
    $statusBeforeGet = $app->stockTransfers()->find($httpTransfer)['status']; try { $router->dispatch('stock-transfer-confirm', 'GET'); ok61(false, 'GET cannot confirm transfer'); } catch (HttpException $exception) { ok61($exception->statusCode() === 405 && $statusBeforeGet === 'submitted', 'GET cannot perform transfer state changes'); }
    Session::clearAuthentication(); $app->auth()->attemptLogin($ownerName, $password);
    foreach (['approve', 'confirm'] as $action) { $_POST = ['_csrf' => Session::csrfToken(), 'transfer_id' => $httpTransfer]; ob_start(); $router->dispatch('stock-transfer-' . $action, 'POST'); $json = json_decode(ob_get_clean(), true, flags: JSON_THROW_ON_ERROR); ok61($json['success'] && http_response_code() === 200, 'authorized Owner ' . $action . 's transfer through the HTTP route'); }
} catch (Throwable $exception) { $failures[] = $exception->getMessage(); echo 'FAIL: ' . $exception . PHP_EOL; }

echo $failures === [] ? 'All Phase 6.1 integration checks passed (' . $checks . ' checks).' . PHP_EOL : count($failures) . ' Phase 6.1 checks failed.' . PHP_EOL;
ob_end_flush(); exit($failures === [] ? 0 : 1);
