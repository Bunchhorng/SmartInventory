<?php
declare(strict_types=1);

use App\Controllers\ProductController;
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
$ownerUsername = 'phase2_owner_' . $suffix;
$staffUsername = 'phase2_staff_' . $suffix;
$ownerPassword = 'Phase2Owner!' . $suffix;
$staffPassword = 'Phase2Staff!' . $suffix;
$prefix = 'P2 ' . $suffix;
$failures = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase2-test-sessions';
$ownerId = null;
$staffId = null;
$productId = null;
$categoryIds = [];
$unitIds = [];

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, "This integration test requires an empty disposable test database with no Owner account.\n");
    exit(2);
}
if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

function phase2Check(bool $condition, string $message): void
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
function phase2ExpectDomain(callable $operation, string $message): void
{
    try {
        $operation();
        phase2Check(false, $message);
    } catch (DomainException) {
        phase2Check(true, $message);
    }
}

/** @param list<int> $categoryIds @param list<int> $unitIds */
function phase2Cleanup(PDO $pdo, ?int $ownerId, ?int $staffId, ?int $productId, array $categoryIds, array $unitIds, string $prefix): void
{
    if ($productId !== null) {
        $deleteBarcodes = $pdo->prepare(
            'DELETE pb FROM product_barcodes pb JOIN product_units pu ON pu.id = pb.product_unit_id WHERE pu.product_id = ?'
        );
        $deleteBarcodes->execute([$productId]);
        $deletePrices = $pdo->prepare(
            'DELETE pp FROM product_prices pp JOIN product_units pu ON pu.id = pp.product_unit_id WHERE pu.product_id = ?'
        );
        $deletePrices->execute([$productId]);
        $deleteUnits = $pdo->prepare('DELETE FROM product_units WHERE product_id = ?');
        $deleteUnits->execute([$productId]);
        $deleteProduct = $pdo->prepare('DELETE FROM products WHERE id = ?');
        $deleteProduct->execute([$productId]);
    }

    rsort($categoryIds);
    foreach ($categoryIds as $categoryId) {
        $statement = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $statement->execute([$categoryId]);
    }
    foreach ($unitIds as $unitId) {
        $statement = $pdo->prepare('DELETE FROM units WHERE id = ?');
        $statement->execute([$unitId]);
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
    Session::start();
    phase2Check(
        $app->auth()->createInitialOwner($ownerUsername, 'Phase 2 Test Owner', $ownerPassword) === null,
        'initial Owner can be created in the disposable test database',
    );
    $owner = $app->users()->findByUsername($ownerUsername);
    $ownerId = $owner === null ? null : (int) $owner['id'];
    phase2Check($ownerId !== null, 'Owner record is available for authorization checks');
    if ($ownerId === null) {
        throw new RuntimeException('Owner creation failed.');
    }
    phase2Check(
        $app->auth()->createStaff($ownerId, $staffUsername, 'Phase 2 Test Staff', $staffPassword) === null,
        'Owner can create a Staff account for authorization checks',
    );
    $staff = $app->users()->findByUsername($staffUsername);
    $staffId = $staff === null ? null : (int) $staff['id'];
    phase2Check($staffId !== null, 'Staff record is available for authorization checks');
    if ($staffId === null) {
        throw new RuntimeException('Staff creation failed.');
    }

    $domain = $app->productDomain();
    $rootCategoryId = $domain->createCategory($ownerId, $prefix . ' Food', null);
    $categoryIds[] = $rootCategoryId;
    $childCategoryId = $domain->createCategory($ownerId, $prefix . ' Drinks', $rootCategoryId);
    $categoryIds[] = $childCategoryId;
    phase2Check($rootCategoryId > 0 && $childCategoryId > 0, 'Owner can create parent and child categories');
    $domain->updateCategory($ownerId, $childCategoryId, $prefix . ' Beverages', $rootCategoryId);
    phase2Check($app->categories()->find($childCategoryId)['name'] === $prefix . ' Beverages', 'Owner can update a category');
    phase2ExpectDomain(
        fn () => $domain->createCategory($ownerId, $prefix . ' Food', null),
        'duplicate sibling categories are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->updateCategory($ownerId, $rootCategoryId, $prefix . ' Food', $childCategoryId),
        'circular category relationships are rejected',
    );
    $domain->archiveCategory($ownerId, $childCategoryId);
    $archivedCategories = $domain->listCategoriesForViewer($ownerId, $prefix, 'archived', 1, 25);
    phase2Check(count($archivedCategories['items']) === 1, 'archived categories are filterable and preserved');

    $baseUnitId = $domain->createUnit($ownerId, 'each' . substr($suffix, 0, 4), $prefix . ' Each', 3);
    $unitIds[] = $baseUnitId;
    $conversionUnitId = $domain->createUnit($ownerId, 'bundle' . substr($suffix, 0, 4), $prefix . ' Bundle', 3);
    $unitIds[] = $conversionUnitId;
    $unusedUnitId = $domain->createUnit($ownerId, 'unused' . substr($suffix, 0, 4), $prefix . ' Unused', 0);
    $unitIds[] = $unusedUnitId;
    phase2Check($baseUnitId > 0 && $conversionUnitId > 0, 'Owner can create configurable measurement units');
    phase2ExpectDomain(
        fn () => $domain->createUnit($ownerId, 'each' . substr($suffix, 0, 4), $prefix . ' Each', 3),
        'duplicate unit codes and names are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->createUnit($ownerId, 'badprecision' . substr($suffix, 0, 4), $prefix . ' Bad', 4),
        'unsupported unit precision is rejected',
    );
    $domain->updateUnit($ownerId, $unusedUnitId, 'unused' . substr($suffix, 0, 4), $prefix . ' Unused revised', 0);
    phase2Check($app->units()->find($unusedUnitId)['name'] === $prefix . ' Unused revised', 'Owner can update a unit');
    $domain->archiveUnit($ownerId, $unusedUnitId);
    phase2Check($app->units()->find($unusedUnitId)['status'] === 'archived', 'unused units can be archived');

    $barcode = 'P2-BAR-' . strtoupper($suffix);
    $productId = $domain->createProduct($ownerId, [
        'sku' => '',
        'name' => $prefix . ' Rice',
        'category_id' => $rootCategoryId,
        'brand_name' => $prefix . ' Brand',
        'base_unit_id' => $baseUnitId,
        'kind' => 'physical',
        'track_batches' => '1',
        'track_expiry' => '1',
        'barcode' => $barcode,
    ]);
    $product = $app->products()->find($productId);
    phase2Check($product !== null && str_starts_with((string) $product['sku'], 'SKU-'), 'products receive generated unique SKUs');
    phase2Check($product !== null && (bool) $product['track_batches'] && (bool) $product['track_expiry'], 'product tracking flags are persisted');
    $domain->updateProduct($ownerId, $productId, [
        'name' => $prefix . ' Rice revised',
        'category_id' => $rootCategoryId,
        'base_unit_id' => $baseUnitId,
        'brand_name' => $prefix . ' Brand',
        'kind' => 'physical',
        'track_batches' => '1',
        'track_expiry' => '1',
    ]);
    phase2Check($app->products()->find($productId)['name'] === $prefix . ' Rice revised', 'Owner can update product master data without changing its base unit');
    phase2ExpectDomain(
        fn () => $domain->createProduct($ownerId, [
            'sku' => (string) $product['sku'], 'name' => $prefix . ' Duplicate SKU', 'category_id' => $rootCategoryId,
            'base_unit_id' => $baseUnitId, 'kind' => 'physical',
        ]),
        'duplicate product SKUs are rejected',
    );
    $rolledBackSku = 'ROLLBACK-' . strtoupper($suffix);
    phase2ExpectDomain(
        fn () => $domain->createProduct($ownerId, [
            'sku' => $rolledBackSku, 'name' => $prefix . ' Rollback', 'category_id' => $rootCategoryId,
            'base_unit_id' => $baseUnitId, 'kind' => 'physical', 'barcode' => $barcode,
        ]),
        'duplicate barcode rejects product creation',
    );
    phase2Check(!$app->products()->skuExists($rolledBackSku), 'failed multi-table product creation is rolled back');

    $baseProductUnit = $app->products()->findProductUnitByProductAndUnit($productId, $baseUnitId);
    phase2Check($baseProductUnit !== null && $baseProductUnit['to_base_factor'] === '1.000000', 'every product receives an immutable base-unit conversion');
    $conversionProductUnitId = $domain->addProductUnit($ownerId, $productId, $conversionUnitId, '12.500000');
    phase2Check(
        $domain->toBaseQuantity($conversionProductUnitId, '2.500') === '31.250000',
        'product-unit conversion uses fixed-point decimal arithmetic',
    );
    phase2ExpectDomain(
        fn () => $domain->toBaseQuantity($conversionProductUnitId, '0'),
        'zero product quantities are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->toBaseQuantity($conversionProductUnitId, '-1'),
        'negative product quantities are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->addProductUnit($ownerId, $productId, $conversionUnitId, '1'),
        'duplicate product units are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->updateProductUnitFactor($ownerId, $conversionProductUnitId, '0'),
        'zero conversion factors are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->updateProductUnitFactor($ownerId, $conversionProductUnitId, '-1'),
        'negative conversion factors are rejected',
    );
    phase2ExpectDomain(
        fn () => $domain->archiveUnit($ownerId, $baseUnitId),
        'a unit referenced by an active product cannot be archived',
    );

    if ($baseProductUnit === null) {
        throw new RuntimeException('Base product unit missing.');
    }
    $baseProductUnitId = (int) $baseProductUnit['id'];
    $domain->setPrice($ownerId, $baseProductUnitId, 'retail', 'KHR', '1000');
    $domain->setPrice($ownerId, $baseProductUnitId, 'retail', 'KHR', '1100.5000');
    $priceHistory = $app->products()->priceHistory($baseProductUnitId, 'retail', 'KHR');
    $currentCount = count(array_filter($priceHistory, static fn (array $price): bool => (bool) $price['is_current']));
    phase2Check(count($priceHistory) === 2 && $currentCount === 1 && $priceHistory[0]['amount'] === '1100.5000', 'price changes preserve history with one current price');
    phase2ExpectDomain(
        fn () => $domain->setPrice($ownerId, $baseProductUnitId, 'retail', 'KHR', '-0.01'),
        'negative selling prices are rejected',
    );

    $staffProducts = $domain->listProductsForViewer($staffId, $prefix, 'all', 1, 25);
    phase2Check(
        count($staffProducts['items']) === 1 && !array_key_exists('avg_unit_cost_khr', $staffProducts['items'][0]),
        'Staff product listings contain active public product data without purchase cost',
    );
    phase2ExpectDomain(
        fn () => $domain->createCategory($staffId, $prefix . ' Unauthorized', null),
        'Staff cannot manage product master data',
    );
    phase2ExpectDomain(
        fn () => $domain->resolveSellingPrice($staffId, $baseProductUnitId, 'retail', 'KHR', '900'),
        'Staff cannot make flexible selling-price overrides by submitting an override amount',
    );
    $ownerOverride = $domain->resolveSellingPrice($ownerId, $baseProductUnitId, 'retail', 'KHR', '900');
    phase2Check($ownerOverride['is_override'] && $ownerOverride['amount'] === '900.0000', 'authorized Owner price overrides are validated without changing default price history');

    Session::clearAuthentication();
    phase2Check($app->auth()->attemptLogin($ownerUsername, $ownerPassword) !== null, 'Owner can create an authenticated session for CSRF testing');
    $_POST = ['name' => $prefix . ' CSRF', '_csrf' => 'invalid-token'];
    try {
        (new ProductController($app))->createCategory();
        phase2Check(false, 'product mutation rejects invalid CSRF tokens');
    } catch (HttpException $exception) {
        phase2Check($exception->statusCode() === 419, 'product mutation rejects invalid CSRF tokens');
    }
    $_POST = [];

    $domain->archiveProduct($ownerId, $productId);
    phase2Check($app->products()->find($productId)['status'] === 'archived', 'products are archived rather than destructively deleted');
    phase2ExpectDomain(
        fn () => $domain->deleteProduct($ownerId, $productId),
        'destructive product deletion is unavailable, preserving future transaction references',
    );
} catch (Throwable $exception) {
    $failures[] = 'unexpected exception: ' . $exception->getMessage();
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception . PHP_EOL);
} finally {
    $_POST = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        Session::destroy();
    }
    phase2Cleanup($pdo, $ownerId, $staffId, $productId, $categoryIds, $unitIds, $prefix);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d Phase 2 integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 2 product-domain integration checks passed.' . PHP_EOL);
