<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class InventoryReceipt
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $receiptId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, receipt_no, receipt_type, reference_no, location_id, status, created_by,
                    confirmed_by, confirmed_at, reversed_by, reversed_at, reversal_reason, created_at
             FROM inventory_receipts WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$receiptId]);
        $receipt = $statement->fetch();
        return is_array($receipt) ? $receipt : null;
    }

    public function referenceExists(string $referenceNo): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM inventory_receipts WHERE reference_no = ? LIMIT 1');
        $statement->execute([$referenceNo]);
        return (bool) $statement->fetchColumn();
    }

    public function isPurchaseLinked(int $receiptId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM goods_receipts WHERE inventory_receipt_id = ? LIMIT 1');
        $statement->execute([$receiptId]);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function list(string $status, int $limit, int $offset): array
    {
        $params = [];
        $where = '';
        if (in_array($status, ['draft', 'confirmed', 'reversed'], true)) {
            $where = ' WHERE ir.status = ?';
            $params[] = $status;
        }
        $statement = $this->pdo->prepare(
            'SELECT ir.id, ir.receipt_no, ir.receipt_type, ir.reference_no, ir.status, ir.created_at,
                    ir.confirmed_at, sl.code AS location_code, sl.name AS location_name,
                    creator.full_name AS created_by_name, confirmer.full_name AS confirmed_by_name
             FROM inventory_receipts ir
             JOIN stock_locations sl ON sl.id = ir.location_id
             JOIN users creator ON creator.id = ir.created_by
             LEFT JOIN users confirmer ON confirmer.id = ir.confirmed_by'
             . $where .
            ' ORDER BY ir.created_at DESC, ir.id DESC LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return list<array{id: int, code: string, name: string, type: string}> */
    public function activeLocations(): array
    {
        return $this->pdo->query(
            'SELECT id, code, name, type FROM stock_locations WHERE is_active = 1 ORDER BY name, id'
        )->fetchAll();
    }

    /** @return list<array{id: int, product_name: string, sku: string, unit_name: string, unit_code: string}> */
    public function activeProductUnits(): array
    {
        return $this->pdo->query(
            "SELECT pu.id, p.name AS product_name, p.sku, u.name AS unit_name, u.code AS unit_code
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id AND p.status = 'active' AND p.track_batches = 0
             JOIN units u ON u.id = pu.unit_id AND u.status = 'active'
             ORDER BY p.name, u.name, pu.id"
        )->fetchAll();
    }

    public function create(
        string $receiptNo,
        string $receiptType,
        string $referenceNo,
        int $locationId,
        int $createdBy,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO inventory_receipts (receipt_no, receipt_type, reference_no, location_id, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$receiptNo, $receiptType, $referenceNo, $locationId, $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createItem(
        int $receiptId,
        int $productUnitId,
        string $quantityReceived,
        string $quantityBase,
        ?string $costPerBaseKhr,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO inventory_receipt_items
             (receipt_id, product_unit_id, qty_received, qty_base, cost_per_base_khr)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$receiptId, $productUnitId, $quantityReceived, $quantityBase, $costPerBaseKhr]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function productUnit(int $productUnitId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pu.id, pu.product_id, pu.unit_id, pu.to_base_factor, p.status AS product_status, p.track_batches,
                    u.precision_digits, u.status AS unit_status
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id
             JOIN units u ON u.id = pu.unit_id
             WHERE pu.id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$productUnitId]);
        $productUnit = $statement->fetch();
        return is_array($productUnit) ? $productUnit : null;
    }

    /** @return list<array<string, mixed>> */
    public function items(int $receiptId, bool $forUpdate = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT iri.id, iri.receipt_id, iri.product_unit_id, iri.qty_received, iri.qty_base, iri.cost_per_base_khr,
                    pu.product_id, pu.unit_id, pu.to_base_factor, p.status AS product_status, p.track_batches,
                    u.precision_digits, u.status AS unit_status
             FROM inventory_receipt_items iri
             JOIN product_units pu ON pu.id = iri.product_unit_id
             JOIN products p ON p.id = pu.product_id
             JOIN units u ON u.id = pu.unit_id
             WHERE iri.receipt_id = ?
             ORDER BY pu.product_id, iri.id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$receiptId]);
        return $statement->fetchAll();
    }

    /** @param array<int, string> $costsByItemId */
    public function updateCosts(int $receiptId, array $costsByItemId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE inventory_receipt_items SET cost_per_base_khr = ? WHERE id = ? AND receipt_id = ?'
        );
        foreach ($costsByItemId as $itemId => $cost) {
            $statement->execute([$cost, $itemId, $receiptId]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Receipt item was not found.');
            }
        }
    }

    public function markConfirmed(int $receiptId, int $actorId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE inventory_receipts
             SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([$actorId, $receiptId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Receipt status changed before it could be confirmed.');
        }
    }

    public function markReversed(int $receiptId, int $actorId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE inventory_receipts
             SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ?
             WHERE id = ? AND status = 'confirmed'"
        );
        $statement->execute([$actorId, $reason, $receiptId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Receipt status changed before it could be reversed.');
        }
    }

    /** @return array<string, mixed>|null */
    public function findActiveLocation(int $locationId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name, type, is_active FROM stock_locations
             WHERE id = ? AND is_active = 1 LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$locationId]);
        $location = $statement->fetch();
        return is_array($location) ? $location : null;
    }

    /**
     * Materializes and locks the non-batch balance row. The unique generated
     * batch key makes this safe even when two first receipts race to create it.
     *
     * @return array<string, mixed>
     */
    public function lockBalance(int $productId, int $locationId): array
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO stock_balances (product_id, location_id, batch_id, qty_base)
             VALUES (?, ?, NULL, 0)
             ON DUPLICATE KEY UPDATE qty_base = qty_base'
        );
        $insert->execute([$productId, $locationId]);

        $statement = $this->pdo->prepare(
            'SELECT id, product_id, location_id, qty_base FROM stock_balances
             WHERE product_id = ? AND location_id = ? AND batch_id IS NULL
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$productId, $locationId]);
        $balance = $statement->fetch();
        if (!is_array($balance)) {
            throw new \RuntimeException('Unable to lock stock balance.');
        }
        return $balance;
    }

    public function changeBalance(int $balanceId, string $quantityDelta): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE stock_balances SET qty_base = qty_base + ? WHERE id = ?'
        );
        $statement->execute([$quantityDelta, $balanceId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Stock balance was not updated.');
        }
    }

    public function createMovement(
        int $productId,
        int $locationId,
        string $quantityDelta,
        string $sourceType,
        int $sourceId,
        int $sourceLineId,
        string $movementKey,
        string $costPerBaseKhr,
        int $actorId,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO stock_movements
             (product_id, location_id, batch_id, qty_delta, source_type, source_id, source_line_id,
              movement_key, unit_cost_khr, created_by)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $productId,
            $locationId,
            $quantityDelta,
            $sourceType,
            $sourceId,
            $sourceLineId,
            $movementKey,
            $costPerBaseKhr,
            $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function movementExists(string $movementKey): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM stock_movements WHERE movement_key = ? LIMIT 1');
        $statement->execute([$movementKey]);
        return (bool) $statement->fetchColumn();
    }

    /** @return array{balance: string, ledger: string} */
    public function reconciliation(int $productId, int $locationId): array
    {
        $balance = $this->pdo->prepare(
            'SELECT COALESCE(qty_base, 0) FROM stock_balances
             WHERE product_id = ? AND location_id = ? AND batch_id IS NULL LIMIT 1'
        );
        $balance->execute([$productId, $locationId]);
        $balanceValue = (string) ($balance->fetchColumn() ?: '0.000');

        $ledger = $this->pdo->prepare(
            'SELECT COALESCE(SUM(qty_delta), 0) FROM stock_movements
             WHERE product_id = ? AND location_id = ? AND batch_id IS NULL'
        );
        $ledger->execute([$productId, $locationId]);
        $ledgerValue = (string) ($ledger->fetchColumn() ?: '0.000');

        return ['balance' => $balanceValue, 'ledger' => $ledgerValue];
    }
}
