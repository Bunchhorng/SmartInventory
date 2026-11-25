<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Product
{
    public function __construct(private PDO $pdo)
    {
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE sku = ?';
        $params = [$sku];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sku, name, category_id, brand_id, base_unit_id, kind, track_batches, track_expiry, status
             FROM products WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id]);
        $product = $statement->fetch();
        return is_array($product) ? $product : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $search, string $status, int $limit, int $offset): array
    {
        [$where, $params] = $this->productListWhere($search, $status);
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.sku, p.name, p.category_id, p.brand_id, p.base_unit_id, p.kind,
                    p.track_batches, p.track_expiry, p.status, p.created_at, p.updated_at,
                    c.name AS category_name, b.name AS brand_name, u.code AS base_unit_code, u.name AS base_unit_name,
                    GROUP_CONCAT(DISTINCT CONCAT(pp.price_type, \' \', pp.amount, \' \', pp.currency)
                        ORDER BY pp.price_type, pp.currency SEPARATOR \', \') AS current_prices
             FROM products p
             JOIN categories c ON c.id = p.category_id
             JOIN units u ON u.id = p.base_unit_id
             LEFT JOIN brands b ON b.id = p.brand_id
             LEFT JOIN product_units pu ON pu.product_id = p.id
             LEFT JOIN product_prices pp ON pp.product_unit_id = pu.id AND pp.is_current = 1'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' GROUP BY p.id
                ORDER BY p.name, p.id LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function count(string $search, string $status): int
    {
        [$where, $params] = $this->productListWhere($search, $status, 'p');
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM products p' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function findBrandByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM brands WHERE name = ? LIMIT 1');
        $statement->execute([$name]);
        $brand = $statement->fetch();
        return is_array($brand) ? $brand : null;
    }

    /** @return array<string, mixed>|null */
    public function findBrand(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, name FROM brands WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $brand = $statement->fetch();
        return is_array($brand) ? $brand : null;
    }

    /** @return list<array{id: int, name: string}> */
    public function brands(): array
    {
        return $this->pdo->query('SELECT id, name FROM brands ORDER BY name, id')->fetchAll();
    }

    public function createBrand(string $name): int
    {
        $statement = $this->pdo->prepare('INSERT INTO brands (name) VALUES (?)');
        $statement->execute([$name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function create(
        string $sku,
        string $name,
        int $categoryId,
        ?int $brandId,
        int $baseUnitId,
        string $kind,
        bool $trackBatches,
        bool $trackExpiry,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO products
             (sku, name, category_id, brand_id, base_unit_id, kind, track_batches, track_expiry, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\')'
        );
        $statement->execute([
            $sku,
            $name,
            $categoryId,
            $brandId,
            $baseUnitId,
            $kind,
            (int) $trackBatches,
            (int) $trackExpiry,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        int $categoryId,
        ?int $brandId,
        string $kind,
        bool $trackBatches,
        bool $trackExpiry,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE products
             SET name = ?, category_id = ?, brand_id = ?, kind = ?, track_batches = ?, track_expiry = ?
             WHERE id = ?'
        );
        $statement->execute([
            $name,
            $categoryId,
            $brandId,
            $kind,
            (int) $trackBatches,
            (int) $trackExpiry,
            $id,
        ]);
    }

    public function archive(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE products SET status = 'archived' WHERE id = ?");
        $statement->execute([$id]);
    }

    public function createProductUnit(int $productId, int $unitId, string $factor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO product_units (product_id, unit_id, to_base_factor) VALUES (?, ?, ?)'
        );
        $statement->execute([$productId, $unitId, $factor]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findProductUnit(int $productUnitId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pu.id, pu.product_id, pu.unit_id, pu.to_base_factor, p.status AS product_status,
                    p.sku AS product_sku, p.name AS product_name, p.base_unit_id,
                    u.code AS unit_code, u.name AS unit_name, u.precision_digits, u.status AS unit_status
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id
             JOIN units u ON u.id = pu.unit_id
             WHERE pu.id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$productUnitId]);
        $productUnit = $statement->fetch();
        return is_array($productUnit) ? $productUnit : null;
    }

    /** @return array<string, mixed>|null */
    public function findProductUnitByProductAndUnit(int $productId, int $unitId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, product_id, unit_id, to_base_factor FROM product_units WHERE product_id = ? AND unit_id = ? LIMIT 1'
        );
        $statement->execute([$productId, $unitId]);
        $productUnit = $statement->fetch();
        return is_array($productUnit) ? $productUnit : null;
    }

    /** @return list<array<string, mixed>> */
    public function productUnits(int $productId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pu.id, pu.product_id, pu.unit_id, pu.to_base_factor, u.code, u.name, u.precision_digits
             FROM product_units pu JOIN units u ON u.id = pu.unit_id
             WHERE pu.product_id = ? ORDER BY pu.unit_id'
        );
        $statement->execute([$productId]);
        return $statement->fetchAll();
    }

    public function updateProductUnitFactor(int $productUnitId, string $factor): void
    {
        $statement = $this->pdo->prepare('UPDATE product_units SET to_base_factor = ? WHERE id = ?');
        $statement->execute([$factor, $productUnitId]);
    }

    public function productUnitHasTransactionReference(int $productUnitId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM purchase_items WHERE product_unit_id = ?
             UNION ALL SELECT 1 FROM sale_items WHERE product_unit_id = ?
             LIMIT 1'
        );
        $statement->execute([$productUnitId, $productUnitId]);
        return (bool) $statement->fetchColumn();
    }

    public function barcodeExists(string $barcode): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM product_barcodes WHERE barcode = ? LIMIT 1');
        $statement->execute([$barcode]);
        return (bool) $statement->fetchColumn();
    }

    public function addBarcode(int $productUnitId, string $barcode): int
    {
        $statement = $this->pdo->prepare('INSERT INTO product_barcodes (product_unit_id, barcode) VALUES (?, ?)');
        $statement->execute([$productUnitId, $barcode]);
        return (int) $this->pdo->lastInsertId();
    }

    public function setCurrentPrice(
        int $productUnitId,
        string $priceType,
        string $currency,
        string $amount,
    ): int {
        $lock = $this->pdo->prepare(
            'SELECT id FROM product_prices
             WHERE product_unit_id = ? AND price_type = ? AND currency = ? AND is_current = 1 FOR UPDATE'
        );
        $lock->execute([$productUnitId, $priceType, $currency]);

        $clear = $this->pdo->prepare(
            'UPDATE product_prices SET is_current = 0
             WHERE product_unit_id = ? AND price_type = ? AND currency = ? AND is_current = 1'
        );
        $clear->execute([$productUnitId, $priceType, $currency]);

        $insert = $this->pdo->prepare(
            'INSERT INTO product_prices (product_unit_id, price_type, currency, amount, is_current)
             VALUES (?, ?, ?, ?, 1)'
        );
        $insert->execute([$productUnitId, $priceType, $currency, $amount]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function currentPrice(int $productUnitId, string $priceType, string $currency): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, product_unit_id, price_type, currency, amount, effective_at
             FROM product_prices
             WHERE product_unit_id = ? AND price_type = ? AND currency = ? AND is_current = 1
             LIMIT 1'
        );
        $statement->execute([$productUnitId, $priceType, $currency]);
        $price = $statement->fetch();
        return is_array($price) ? $price : null;
    }

    /** @return list<array<string, mixed>> */
    public function priceHistory(int $productUnitId, string $priceType, string $currency): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, product_unit_id, price_type, currency, amount, effective_at, is_current
             FROM product_prices WHERE product_unit_id = ? AND price_type = ? AND currency = ?
             ORDER BY effective_at DESC, id DESC'
        );
        $statement->execute([$productUnitId, $priceType, $currency]);
        return $statement->fetchAll();
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function productListWhere(string $search, string $status, string $prefix = 'p'): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = sprintf('(%1$s.sku LIKE ? OR %1$s.name LIKE ?)', $prefix);
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (in_array($status, ['active', 'archived'], true)) {
            $where[] = $prefix . '.status = ?';
            $params[] = $status;
        }
        return [$where, $params];
    }
}
