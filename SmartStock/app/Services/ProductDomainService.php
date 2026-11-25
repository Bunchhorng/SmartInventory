<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

final class ProductDomainService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Category $categories,
        private Unit $units,
        private Product $products,
    ) {
    }

    public function createCategory(int $actorId, string $name, ?int $parentId): int
    {
        $this->assertCanManage($actorId);
        $name = $this->categoryName($name);

        return $this->transaction(function () use ($actorId, $name, $parentId): int {
            $this->assertValidParent($parentId);
            if ($this->categories->siblingExists($name, $parentId)) {
                throw new DomainException('A category with this name already exists at this level.');
            }
            $categoryId = $this->categories->create($name, $parentId);
            $this->auditLog->record($actorId, 'category.created', 'categories', $categoryId);
            return $categoryId;
        });
    }

    public function updateCategory(int $actorId, int $categoryId, string $name, ?int $parentId): void
    {
        $this->assertCanManage($actorId);
        $name = $this->categoryName($name);

        $this->transaction(function () use ($actorId, $categoryId, $name, $parentId): void {
            $category = $this->categories->find($categoryId, true);
            if ($category === null) {
                throw new DomainException('The category was not found.');
            }
            if ($parentId === $categoryId) {
                throw new DomainException('A category cannot be its own parent.');
            }
            $this->assertValidParent($parentId);
            if ($parentId !== null && $this->wouldCreateCategoryCycle($categoryId, $parentId)) {
                throw new DomainException('A category cannot be moved below one of its descendants.');
            }
            if ($this->categories->siblingExists($name, $parentId, $categoryId)) {
                throw new DomainException('A category with this name already exists at this level.');
            }
            $this->categories->update($categoryId, $name, $parentId);
            $this->auditLog->record($actorId, 'category.updated', 'categories', $categoryId);
        });
    }

    public function archiveCategory(int $actorId, int $categoryId): void
    {
        $this->assertCanManage($actorId);
        $this->transaction(function () use ($actorId, $categoryId): void {
            $category = $this->categories->find($categoryId, true);
            if ($category === null) {
                throw new DomainException('The category was not found.');
            }
            if (!(bool) $category['is_active']) {
                throw new DomainException('The category is already archived.');
            }
            $this->categories->archive($categoryId);
            $this->auditLog->record($actorId, 'category.archived', 'categories', $categoryId);
        });
    }

    public function createUnit(int $actorId, string $code, string $name, int $precisionDigits): int
    {
        $this->assertCanManage($actorId);
        [$code, $name, $precisionDigits] = $this->validatedUnit($code, $name, $precisionDigits);

        return $this->transaction(function () use ($actorId, $code, $name, $precisionDigits): int {
            if ($this->units->duplicateExists($code, $name)) {
                throw new DomainException('A unit with this code or name already exists.');
            }
            $unitId = $this->units->create($code, $name, $precisionDigits);
            $this->auditLog->record($actorId, 'unit.created', 'units', $unitId);
            return $unitId;
        });
    }

    public function updateUnit(int $actorId, int $unitId, string $code, string $name, int $precisionDigits): void
    {
        $this->assertCanManage($actorId);
        [$code, $name, $precisionDigits] = $this->validatedUnit($code, $name, $precisionDigits);

        $this->transaction(function () use ($actorId, $unitId, $code, $name, $precisionDigits): void {
            $unit = $this->units->find($unitId, true);
            if ($unit === null) {
                throw new DomainException('The unit was not found.');
            }
            if ($this->units->duplicateExists($code, $name, $unitId)) {
                throw new DomainException('A unit with this code or name already exists.');
            }
            $this->units->update($unitId, $code, $name, $precisionDigits);
            $this->auditLog->record($actorId, 'unit.updated', 'units', $unitId);
        });
    }

    public function archiveUnit(int $actorId, int $unitId): void
    {
        $this->assertCanManage($actorId);
        $this->transaction(function () use ($actorId, $unitId): void {
            $unit = $this->units->find($unitId, true);
            if ($unit === null) {
                throw new DomainException('The unit was not found.');
            }
            if ($unit['status'] === 'archived') {
                throw new DomainException('The unit is already archived.');
            }
            if ($this->units->isReferencedByActiveProduct($unitId)) {
                throw new DomainException('A unit used by an active product cannot be archived. Archive the product first.');
            }
            $this->units->archive($unitId);
            $this->auditLog->record($actorId, 'unit.archived', 'units', $unitId);
        });
    }

    /** @param array<string, mixed> $input */
    public function createProduct(int $actorId, array $input): int
    {
        $this->assertCanManage($actorId);
        $data = $this->validatedProductInput($input, true);

        return $this->transaction(function () use ($actorId, $data): int {
            $this->assertActiveCategory($data['category_id']);
            $this->assertActiveUnit($data['base_unit_id']);
            $brandId = $this->resolveBrand($data['brand_id'], $data['brand_name']);
            $sku = $data['sku'] === '' ? $this->generateSku() : $data['sku'];
            if ($this->products->skuExists($sku)) {
                throw new DomainException('The SKU is already in use.');
            }
            if ($data['barcode'] !== '' && $this->products->barcodeExists($data['barcode'])) {
                throw new DomainException('The barcode is already assigned to another product unit.');
            }

            $productId = $this->products->create(
                $sku,
                $data['name'],
                $data['category_id'],
                $brandId,
                $data['base_unit_id'],
                $data['kind'],
                $data['track_batches'],
                $data['track_expiry'],
            );
            $baseProductUnitId = $this->products->createProductUnit($productId, $data['base_unit_id'], '1.000000');
            if ($data['barcode'] !== '') {
                $this->products->addBarcode($baseProductUnitId, $data['barcode']);
            }
            $this->auditLog->record($actorId, 'product.created', 'products', $productId);
            return $productId;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateProduct(int $actorId, int $productId, array $input): void
    {
        $this->assertCanManage($actorId);
        $data = $this->validatedProductInput($input, false);

        $this->transaction(function () use ($actorId, $productId, $data): void {
            $product = $this->products->find($productId, true);
            if ($product === null) {
                throw new DomainException('The product was not found.');
            }
            if ($product['status'] !== 'active') {
                throw new DomainException('Archived products cannot be changed.');
            }
            if ((int) $product['base_unit_id'] !== $data['base_unit_id']) {
                throw new DomainException('Base units cannot be changed after product creation because stock is stored in that unit.');
            }
            $this->assertActiveCategory($data['category_id']);
            $brandId = $this->resolveBrand($data['brand_id'], $data['brand_name']);
            $this->products->update(
                $productId,
                $data['name'],
                $data['category_id'],
                $brandId,
                $data['kind'],
                $data['track_batches'],
                $data['track_expiry'],
            );
            $this->auditLog->record($actorId, 'product.updated', 'products', $productId);
        });
    }

    public function archiveProduct(int $actorId, int $productId): void
    {
        $this->assertCanManage($actorId);
        $this->transaction(function () use ($actorId, $productId): void {
            $product = $this->products->find($productId, true);
            if ($product === null) {
                throw new DomainException('The product was not found.');
            }
            if ($product['status'] === 'archived') {
                throw new DomainException('The product is already archived.');
            }
            $this->products->archive($productId);
            $this->auditLog->record($actorId, 'product.archived', 'products', $productId);
        });
    }

    public function deleteProduct(int $actorId, int $productId): never
    {
        $this->assertCanManage($actorId);
        if ($this->products->find($productId) === null) {
            throw new DomainException('The product was not found.');
        }
        throw new DomainException('Products are not deleted. Archive them to preserve transaction history.');
    }

    public function addProductUnit(int $actorId, int $productId, int $unitId, string $factor): int
    {
        $this->assertCanManage($actorId);
        $factor = Decimal::positive($factor, 6);

        return $this->transaction(function () use ($actorId, $productId, $unitId, $factor): int {
            $product = $this->products->find($productId, true);
            if ($product === null || $product['status'] !== 'active') {
                throw new DomainException('An active product is required.');
            }
            $this->assertActiveUnit($unitId);
            if ($this->products->findProductUnitByProductAndUnit($productId, $unitId) !== null) {
                throw new DomainException('This product already supports the selected unit.');
            }
            $productUnitId = $this->products->createProductUnit($productId, $unitId, $factor);
            $this->auditLog->record($actorId, 'product_unit.created', 'product_units', $productUnitId, 'Product ID: ' . $productId);
            return $productUnitId;
        });
    }

    public function updateProductUnitFactor(int $actorId, int $productUnitId, string $factor): void
    {
        $this->assertCanManage($actorId);
        $factor = Decimal::positive($factor, 6);

        $this->transaction(function () use ($actorId, $productUnitId, $factor): void {
            $productUnit = $this->products->findProductUnit($productUnitId, true);
            if ($productUnit === null || $productUnit['product_status'] !== 'active') {
                throw new DomainException('An active product unit is required.');
            }
            if ((int) $productUnit['unit_id'] === (int) $productUnit['base_unit_id']) {
                throw new DomainException('The base product-unit conversion is fixed at one.');
            }
            if ($this->products->productUnitHasTransactionReference($productUnitId)) {
                throw new DomainException('A conversion used by a transaction cannot be changed. Add a new product configuration instead.');
            }
            $this->products->updateProductUnitFactor($productUnitId, $factor);
            $this->auditLog->record($actorId, 'product_unit.updated', 'product_units', $productUnitId);
        });
    }

    public function addBarcode(int $actorId, int $productUnitId, string $barcode): int
    {
        $this->assertCanManage($actorId);
        $barcode = $this->barcode($barcode);
        return $this->transaction(function () use ($actorId, $productUnitId, $barcode): int {
            $productUnit = $this->products->findProductUnit($productUnitId, true);
            if ($productUnit === null || $productUnit['product_status'] !== 'active') {
                throw new DomainException('An active product unit is required.');
            }
            if ($this->products->barcodeExists($barcode)) {
                throw new DomainException('The barcode is already assigned to another product unit.');
            }
            $barcodeId = $this->products->addBarcode($productUnitId, $barcode);
            $this->auditLog->record($actorId, 'product_barcode.created', 'product_barcodes', $barcodeId);
            return $barcodeId;
        });
    }

    public function setPrice(
        int $actorId,
        int $productUnitId,
        string $priceType,
        string $currency,
        string $amount,
    ): int {
        $this->assertCanManage($actorId);
        $priceType = $this->priceType($priceType);
        $currency = $this->currency($currency);
        $amount = Decimal::nonNegative($amount, 4);

        return $this->transaction(function () use ($actorId, $productUnitId, $priceType, $currency, $amount): int {
            $productUnit = $this->products->findProductUnit($productUnitId, true);
            if ($productUnit === null || $productUnit['product_status'] !== 'active') {
                throw new DomainException('An active product unit is required.');
            }
            $priceId = $this->products->setCurrentPrice($productUnitId, $priceType, $currency, $amount);
            $this->auditLog->record($actorId, 'product_price.set', 'product_prices', $priceId, sprintf('%s %s', $priceType, $currency));
            return $priceId;
        });
    }

    /** @return array{product_unit_id: int, price_type: string, currency: string, amount: string, is_override: bool} */
    public function resolveSellingPrice(
        int $actorId,
        int $productUnitId,
        string $priceType,
        string $currency,
        ?string $overrideAmount = null,
    ): array {
        $this->assertCanView($actorId);
        $productUnit = $this->products->findProductUnit($productUnitId);
        if ($productUnit === null || $productUnit['product_status'] !== 'active') {
            throw new DomainException('An active product unit is required.');
        }
        $priceType = $this->priceType($priceType);
        $currency = $this->currency($currency);
        if ($overrideAmount !== null && trim($overrideAmount) !== '') {
            if (!$this->users->hasPermission($actorId, 'pricing.override')) {
                throw new DomainException('You do not have permission to override selling prices.');
            }
            return [
                'product_unit_id' => $productUnitId,
                'price_type' => $priceType,
                'currency' => $currency,
                'amount' => Decimal::nonNegative($overrideAmount, 4),
                'is_override' => true,
            ];
        }
        $price = $this->products->currentPrice($productUnitId, $priceType, $currency);
        if ($price === null) {
            throw new DomainException('No current selling price is configured for this unit and currency.');
        }
        return [
            'product_unit_id' => $productUnitId,
            'price_type' => $priceType,
            'currency' => $currency,
            'amount' => (string) $price['amount'],
            'is_override' => false,
        ];
    }

    public function toBaseQuantity(int $productUnitId, string $quantity): string
    {
        $productUnit = $this->products->findProductUnit($productUnitId);
        if ($productUnit === null) {
            throw new DomainException('The product unit was not found.');
        }
        $quantity = Decimal::positive($quantity, (int) $productUnit['precision_digits']);
        return Decimal::multiply($quantity, (string) $productUnit['to_base_factor'], 6);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function listProductsForViewer(int $actorId, string $search, string $status, int $page, int $perPage): array
    {
        $this->assertCanView($actorId);
        if (!$this->users->isOwner($actorId) && $status !== 'active') {
            $status = 'active';
        }
        [$page, $perPage] = $this->pagination($page, $perPage);
        $search = $this->search($search);
        return [
            'items' => $this->products->list($search, $this->status($status), $perPage, ($page - 1) * $perPage),
            'total' => $this->products->count($search, $this->status($status)),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function listCategoriesForViewer(int $actorId, string $search, string $status, int $page, int $perPage): array
    {
        $this->assertCanView($actorId);
        if (!$this->users->isOwner($actorId) && $status !== 'active') {
            $status = 'active';
        }
        [$page, $perPage] = $this->pagination($page, $perPage);
        $search = $this->search($search);
        $status = $this->status($status);
        return [
            'items' => $this->categories->list($search, $status, $perPage, ($page - 1) * $perPage),
            'total' => $this->categories->count($search, $status),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function listUnitsForViewer(int $actorId, string $search, string $status, int $page, int $perPage): array
    {
        $this->assertCanView($actorId);
        if (!$this->users->isOwner($actorId) && $status !== 'active') {
            $status = 'active';
        }
        [$page, $perPage] = $this->pagination($page, $perPage);
        $search = $this->search($search);
        $status = $this->status($status);
        return [
            'items' => $this->units->list($search, $status, $perPage, ($page - 1) * $perPage),
            'total' => $this->units->count($search, $status),
        ];
    }

    private function assertCanManage(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'products.manage')) {
            throw new DomainException('You do not have permission to manage product master data.');
        }
    }

    private function assertCanView(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'products.view')) {
            throw new DomainException('You do not have permission to view products.');
        }
    }

    private function assertValidParent(?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = $this->categories->find($parentId, true);
        if ($parent === null || !(bool) $parent['is_active']) {
            throw new DomainException('The selected parent category is not active.');
        }
    }

    private function assertActiveCategory(int $categoryId): void
    {
        $category = $this->categories->find($categoryId, true);
        if ($category === null || !(bool) $category['is_active']) {
            throw new DomainException('The selected category is not active.');
        }
    }

    private function assertActiveUnit(int $unitId): void
    {
        $unit = $this->units->find($unitId, true);
        if ($unit === null || $unit['status'] !== 'active') {
            throw new DomainException('The selected unit is not active.');
        }
    }

    private function wouldCreateCategoryCycle(int $categoryId, int $newParentId): bool
    {
        $seen = [];
        $currentId = $newParentId;
        while ($currentId !== null) {
            if ($currentId === $categoryId) {
                return true;
            }
            if (isset($seen[$currentId])) {
                throw new DomainException('The existing category hierarchy is invalid and contains a cycle.');
            }
            $seen[$currentId] = true;
            $current = $this->categories->find($currentId, true);
            if ($current === null) {
                return false;
            }
            $currentId = $current['parent_id'] === null ? null : (int) $current['parent_id'];
        }
        return false;
    }

    /** @param array<string, mixed> $input @return array{sku: string, name: string, category_id: int, brand_id: int|null, brand_name: string, base_unit_id: int, kind: string, track_batches: bool, track_expiry: bool, barcode: string} */
    private function validatedProductInput(array $input, bool $creating): array
    {
        $sku = strtoupper(trim((string) ($input['sku'] ?? '')));
        if ($sku !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,79}$/', $sku)) {
            throw new DomainException('SKU must contain 1–80 letters, numbers, dots, dashes, or underscores.');
        }
        if (!$creating && $sku !== '') {
            throw new DomainException('SKU changes are not supported because transaction references use it operationally.');
        }
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || $this->length($name) > 200) {
            throw new DomainException('Product name is required and must be 200 characters or fewer.');
        }
        $categoryId = $this->positiveId($input['category_id'] ?? null, 'A valid category is required.');
        $baseUnitId = $this->positiveId($input['base_unit_id'] ?? null, 'A valid base unit is required.');
        $brandId = $this->nullableId($input['brand_id'] ?? null, 'The brand is invalid.');
        $brandName = trim((string) ($input['brand_name'] ?? ''));
        if ($brandId !== null && $brandName !== '') {
            throw new DomainException('Choose an existing brand or provide a new brand name, not both.');
        }
        if ($brandName !== '' && $this->length($brandName) > 120) {
            throw new DomainException('Brand name must be 120 characters or fewer.');
        }
        $kind = (string) ($input['kind'] ?? 'physical');
        if (!in_array($kind, ['physical', 'fuel'], true)) {
            throw new DomainException('Product kind is invalid.');
        }
        $trackBatches = $this->boolean($input['track_batches'] ?? false);
        $trackExpiry = $this->boolean($input['track_expiry'] ?? false);
        if ($trackExpiry && !$trackBatches) {
            throw new DomainException('Expiration tracking requires batch tracking.');
        }
        $barcode = trim((string) ($input['barcode'] ?? ''));
        if (!$creating && $barcode !== '') {
            throw new DomainException('Add barcodes through the product-unit barcode action.');
        }
        if ($barcode !== '') {
            $barcode = $this->barcode($barcode);
        }

        return [
            'sku' => $sku,
            'name' => $name,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'brand_name' => $brandName,
            'base_unit_id' => $baseUnitId,
            'kind' => $kind,
            'track_batches' => $trackBatches,
            'track_expiry' => $trackExpiry,
            'barcode' => $barcode,
        ];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function validatedUnit(string $code, string $name, int $precisionDigits): array
    {
        $code = strtolower(trim($code));
        $name = trim($name);
        if (!preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $code)) {
            throw new DomainException('Unit code must use 2–30 lowercase letters, numbers, dashes, or underscores.');
        }
        if ($name === '' || $this->length($name) > 80) {
            throw new DomainException('Unit name is required and must be 80 characters or fewer.');
        }
        if ($precisionDigits < 0 || $precisionDigits > 3) {
            throw new DomainException('Unit precision must be between 0 and 3 decimal places.');
        }
        return [$code, $name, $precisionDigits];
    }

    private function resolveBrand(?int $brandId, string $brandName): ?int
    {
        if ($brandId !== null) {
            if ($this->products->findBrand($brandId) === null) {
                throw new DomainException('The selected brand was not found.');
            }
            return $brandId;
        }
        if ($brandName === '') {
            return null;
        }
        $existing = $this->products->findBrandByName($brandName);
        return $existing === null ? $this->products->createBrand($brandName) : (int) $existing['id'];
    }

    private function generateSku(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sku = 'SKU-' . (new \DateTimeImmutable())->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            if (!$this->products->skuExists($sku)) {
                return $sku;
            }
        }
        throw new DomainException('Unable to generate a unique SKU. Please try again.');
    }

    private function categoryName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || $this->length($name) > 140) {
            throw new DomainException('Category name is required and must be 140 characters or fewer.');
        }
        return $name;
    }

    private function barcode(string $barcode): string
    {
        $barcode = trim($barcode);
        if (!preg_match('/^[A-Za-z0-9._:\/+-]{1,100}$/', $barcode)) {
            throw new DomainException('Barcode must contain 1–100 supported barcode characters.');
        }
        return $barcode;
    }

    private function priceType(string $priceType): string
    {
        if (!in_array($priceType, ['retail', 'wholesale'], true)) {
            throw new DomainException('Price type must be retail or wholesale.');
        }
        return $priceType;
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('Currency must be a three-letter ISO code.');
        }
        return $currency;
    }

    private function positiveId(mixed $value, string $message): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException($message);
        }
        return (int) $value;
    }

    private function nullableId(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveId($value, $message);
    }

    private function boolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    /** @return array{0: int, 1: int} */
    private function pagination(int $page, int $perPage): array
    {
        return [max(1, $page), max(1, min(100, $perPage))];
    }

    private function search(string $search): string
    {
        $search = trim($search);
        if ($this->length($search) > 100) {
            throw new DomainException('Search text must be 100 characters or fewer.');
        }
        return $search;
    }

    private function status(string $status): string
    {
        if (!in_array($status, ['all', 'active', 'archived'], true)) {
            throw new DomainException('Status filter is invalid.');
        }
        return $status;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @template T @param callable():T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new DomainException('The record could not be saved because it conflicts with existing data.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
