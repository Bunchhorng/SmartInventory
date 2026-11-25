<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Persistence for the materialized product-wide weighted-average state and
 * its immutable valuation ledger. Call lockProductCost only inside a posting
 * transaction; the returned row remains locked until that transaction ends.
 */
final class InventoryCost
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function lockProductCost(int $productId): array
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO product_costs (product_id, qty_valued, inventory_value_khr, avg_unit_cost_khr)
             VALUES (?, 0, 0, 0)
             ON DUPLICATE KEY UPDATE product_id = product_id'
        );
        $insert->execute([$productId]);

        $statement = $this->pdo->prepare(
            'SELECT id, product_id, qty_valued, inventory_value_khr, avg_unit_cost_khr
             FROM product_costs WHERE product_id = ? LIMIT 1 FOR UPDATE'
        );
        $statement->execute([$productId]);
        $cost = $statement->fetch();
        if (!is_array($cost)) {
            throw new \RuntimeException('Unable to lock the product costing state.');
        }

        return $cost;
    }

    public function totalNonBatchBalance(int $productId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(qty_base), 0) FROM stock_balances
             WHERE product_id = ? AND batch_id IS NULL'
        );
        $statement->execute([$productId]);
        return (string) ($statement->fetchColumn() ?: '0.000');
    }

    public function updateProductCost(
        int $costId,
        string $quantityValued,
        string $inventoryValueKhr,
        string $averageUnitCostKhr,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE product_costs
             SET qty_valued = ?, inventory_value_khr = ?, avg_unit_cost_khr = ?
             WHERE id = ?'
        );
        $statement->execute([$quantityValued, $inventoryValueKhr, $averageUnitCostKhr, $costId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Product costing state was not updated.');
        }
    }

    public function createEntry(
        int $productId,
        int $stockMovementId,
        string $sourceType,
        int $sourceId,
        int $sourceLineId,
        string $entryKey,
        string $quantityDelta,
        string $valueDeltaKhr,
        string $unitCostSnapshotKhr,
        string $averageBeforeKhr,
        string $averageAfterKhr,
        string $costingType,
        int $actorId,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO inventory_cost_entries
             (product_id, stock_movement_id, source_type, source_id, source_line_id, entry_key,
              qty_delta, value_delta_khr, unit_cost_snapshot_khr, avg_unit_cost_before_khr,
              avg_unit_cost_after_khr, costing_type, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $productId,
            $stockMovementId,
            $sourceType,
            $sourceId,
            $sourceLineId,
            $entryKey,
            $quantityDelta,
            $valueDeltaKhr,
            $unitCostSnapshotKhr,
            $averageBeforeKhr,
            $averageAfterKhr,
            $costingType,
            $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed> */
    public function reconciliation(int $productId): array
    {
        $state = $this->pdo->prepare(
            'SELECT qty_valued, inventory_value_khr, avg_unit_cost_khr
             FROM product_costs WHERE product_id = ? LIMIT 1'
        );
        $state->execute([$productId]);
        $cost = $state->fetch();

        $balances = $this->totalNonBatchBalance($productId);
        $ledger = $this->pdo->prepare(
            'SELECT COALESCE(SUM(qty_delta), 0) AS quantity_total,
                    COALESCE(SUM(value_delta_khr), 0) AS value_total
             FROM inventory_cost_entries WHERE product_id = ?'
        );
        $ledger->execute([$productId]);
        $entryTotals = $ledger->fetch();

        return [
            'balance_qty' => $balances,
            'cost_qty' => is_array($cost) ? (string) $cost['qty_valued'] : '0.000',
            'cost_value' => is_array($cost) ? (string) $cost['inventory_value_khr'] : '0.0000',
            'average_cost' => is_array($cost) ? (string) $cost['avg_unit_cost_khr'] : '0.000000',
            'ledger_qty' => is_array($entryTotals) ? (string) $entryTotals['quantity_total'] : '0.000',
            'ledger_value' => is_array($entryTotals) ? (string) $entryTotals['value_total'] : '0.0000',
        ];
    }
}
