<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\InventoryCost;
use App\Support\Decimal;
use DomainException;

/**
 * Product-wide moving weighted-average cost posting.
 *
 * Each public mutating method is deliberately transactionless: its caller
 * must own one database transaction that also writes the matching immutable
 * stock movement and materialized stock balance. Product-cost rows are locked
 * before the matching location balances, in product-id order, by callers.
 */
final class MovingAverageCostService
{
    public function __construct(private InventoryCost $costs)
    {
    }

    /** @return array<string, string|int> */
    public function lockProductForPosting(int $productId): array
    {
        $state = $this->normalizeState($this->costs->lockProductCost($productId));
        $physicalQuantity = Decimal::nonNegative($this->costs->totalNonBatchBalance($productId), 3);
        if (Decimal::compare($state['qty_valued'], $physicalQuantity, 3) !== 0) {
            throw new DomainException(
                'Product stock is not reconciled with its valuation state. Complete the historical valuation migration before posting another receipt.'
            );
        }

        return $state;
    }

    /**
     * Posts an incoming valuation entry after its matching stock movement and
     * physical balance have been written in the same transaction.
     *
     * @param array<string, string|int> $lockedState
     * @return array<string, string|int>
     */
    public function postValuedReceipt(
        array $lockedState,
        int $stockMovementId,
        string $movementKey,
        string $receiptType,
        int $receiptId,
        int $receiptItemId,
        string $quantityBase,
        string $costPerBaseKhr,
        int $actorId,
    ): array {
        if (!in_array($receiptType, ['opening', 'purchase'], true)) {
            throw new DomainException('Unsupported receipt costing type.');
        }
        $quantityBase = Decimal::positive($quantityBase, 3);
        $costPerBaseKhr = Decimal::positive($costPerBaseKhr, 6);
        $valueDelta = Decimal::multiplyRounded($quantityBase, $costPerBaseKhr, 4);
        if (Decimal::compare($valueDelta, '0.0000', 4) === 0) {
            throw new DomainException('The received inventory value is too small for the supported KHR precision.');
        }

        $nextQuantity = Decimal::add($lockedState['qty_valued'], $quantityBase, 3);
        $nextValue = Decimal::add($lockedState['inventory_value_khr'], $valueDelta, 4);
        $nextAverage = Decimal::divide($nextValue, $nextQuantity, 6);
        $this->costs->updateProductCost(
            (int) $lockedState['id'],
            $nextQuantity,
            $nextValue,
            $nextAverage,
        );
        $this->costs->createEntry(
            (int) $lockedState['product_id'],
            $stockMovementId,
            'inventory_receipt',
            $receiptId,
            $receiptItemId,
            $this->entryKey($movementKey),
            $quantityBase,
            $valueDelta,
            $costPerBaseKhr,
            $lockedState['avg_unit_cost_khr'],
            $nextAverage,
            'receipt_' . $receiptType,
            $actorId,
        );

        return [
            'id' => (int) $lockedState['id'],
            'product_id' => (int) $lockedState['product_id'],
            'qty_valued' => $nextQuantity,
            'inventory_value_khr' => $nextValue,
            'avg_unit_cost_khr' => $nextAverage,
        ];
    }

    /**
     * Posts the compensating valuation side of a receipt reversal. If later
     * receipts exist, stock is removed at the current average; this preserves
     * the newer valuation instead of restoring an obsolete historical average.
     *
     * @param array<string, string|int> $lockedState
     * @return array<string, string|int>
     */
    public function postReceiptReversal(
        array $lockedState,
        int $stockMovementId,
        string $movementKey,
        int $receiptId,
        int $receiptItemId,
        string $quantityBase,
        int $actorId,
    ): array {
        $quantityBase = Decimal::positive($quantityBase, 3);
        if (Decimal::compare($lockedState['qty_valued'], $quantityBase, 3) < 0) {
            throw new DomainException('This receipt cannot be reversed because the valued inventory was later consumed.');
        }

        $nextQuantity = Decimal::subtract($lockedState['qty_valued'], $quantityBase, 3);
        if (Decimal::compare($nextQuantity, '0.000', 3) === 0) {
            // Full depletion clears any accumulated four-decimal rounding residual.
            $valueRemoved = $lockedState['inventory_value_khr'];
            $nextValue = '0.0000';
            $nextAverage = '0.000000';
        } else {
            $valueRemoved = Decimal::multiplyRounded($quantityBase, $lockedState['avg_unit_cost_khr'], 4);
            if (Decimal::compare($valueRemoved, $lockedState['inventory_value_khr'], 4) > 0) {
                throw new DomainException('This receipt reversal would make inventory value negative.');
            }
            $nextValue = Decimal::subtract($lockedState['inventory_value_khr'], $valueRemoved, 4);
            $nextAverage = Decimal::divide($nextValue, $nextQuantity, 6);
        }

        $this->costs->updateProductCost(
            (int) $lockedState['id'],
            $nextQuantity,
            $nextValue,
            $nextAverage,
        );
        $this->costs->createEntry(
            (int) $lockedState['product_id'],
            $stockMovementId,
            'inventory_receipt_reversal',
            $receiptId,
            $receiptItemId,
            $this->entryKey($movementKey),
            '-' . $quantityBase,
            '-' . $valueRemoved,
            $lockedState['avg_unit_cost_khr'],
            $lockedState['avg_unit_cost_khr'],
            $nextAverage,
            'receipt_reversal',
            $actorId,
        );

        return [
            'id' => (int) $lockedState['id'],
            'product_id' => (int) $lockedState['product_id'],
            'qty_valued' => $nextQuantity,
            'inventory_value_khr' => $nextValue,
            'avg_unit_cost_khr' => $nextAverage,
        ];
    }

    /**
     * Future confirmed sales, fuel, transfer-out, and adjustment services can
     * use this immutable quote while holding the product-cost lock. This phase
     * intentionally does not create sales or outgoing stock movements.
     *
     * @param array<string, string|int> $lockedState
     * @return array{qty_base: string, unit_cost_khr: string, value_khr: string}
     */
    public function quoteOutgoingCogs(array $lockedState, string $quantityBase): array
    {
        $quantityBase = Decimal::positive($quantityBase, 3);
        if (Decimal::compare($lockedState['qty_valued'], $quantityBase, 3) < 0) {
            throw new DomainException('Insufficient valued inventory for an outgoing cost snapshot.');
        }
        $value = Decimal::compare($lockedState['qty_valued'], $quantityBase, 3) === 0
            ? $lockedState['inventory_value_khr']
            : Decimal::multiplyRounded($quantityBase, $lockedState['avg_unit_cost_khr'], 4);
        if (Decimal::compare($value, $lockedState['inventory_value_khr'], 4) > 0) {
            throw new DomainException('The outgoing cost snapshot would make inventory value negative.');
        }

        return [
            'qty_base' => $quantityBase,
            'unit_cost_khr' => $lockedState['avg_unit_cost_khr'],
            'value_khr' => $value,
        ];
    }

    /**
     * Posts the inventory-cost side of one confirmed sale. The returned COGS
     * snapshot belongs on the immutable sale line in the same transaction.
     *
     * @param array<string, string|int> $lockedState
     * @return array{state: array<string, string|int>, cogs: array{qty_base: string, unit_cost_khr: string, value_khr: string}}
     */
    public function postSaleCogs(
        array $lockedState,
        int $stockMovementId,
        string $movementKey,
        int $saleId,
        int $saleItemId,
        string $quantityBase,
        int $actorId,
    ): array {
        return $this->postOutgoing($lockedState,$stockMovementId,$movementKey,'sale',$saleId,$saleItemId,$quantityBase,$actorId,'sale_cogs');
    }

    /** Same locked outgoing engine, with separate physical purchase-return provenance. */
    public function postPurchaseReturn(array $state, int $movementId, string $key, int $returnId, int $itemId, string $qty, int $actor): array
    {
        return $this->postOutgoing($state,$movementId,$key,'purchase_return',$returnId,$itemId,$qty,$actor,'purchase_return');
    }

    /**
     * Restores physically verified goods at the exact immutable carrying-value
     * snapshot removed by the original purchase return. It never edits that
     * return or reuses today's average as its historical value basis.
     */
    public function postPurchaseReturnCorrection(array $state, int $movementId, string $key, int $correctionId,
        int $itemId, string $qty, string $carryingValue, string $originalUnitCost, int $actor): array
    {
        $qty=Decimal::positive($qty,3); $carryingValue=Decimal::nonNegative($carryingValue,4);
        $originalUnitCost=Decimal::nonNegative($originalUnitCost,6);
        $nextQuantity=Decimal::add($state['qty_valued'],$qty,3);
        $nextValue=Decimal::add($state['inventory_value_khr'],$carryingValue,4);
        $nextAverage=Decimal::divide($nextValue,$nextQuantity,6);
        $this->costs->updateProductCost((int)$state['id'],$nextQuantity,$nextValue,$nextAverage);
        $this->costs->createEntry((int)$state['product_id'],$movementId,'purchase_return_correction',$correctionId,$itemId,
            $this->entryKey($key),$qty,$carryingValue,$originalUnitCost,$state['avg_unit_cost_khr'],$nextAverage,
            'purchase_return_correction',$actor);
        return ['id'=>(int)$state['id'],'product_id'=>(int)$state['product_id'],'qty_valued'=>$nextQuantity,
            'inventory_value_khr'=>$nextValue,'avg_unit_cost_khr'=>$nextAverage];
    }

    /**
     * Writes one immutable side of an internal location transfer. Product-wide
     * quantity, carrying value and weighted average deliberately remain locked
     * but unchanged: the equal and opposite side belongs to the same transfer
     * transaction and uses this exact carrying-value snapshot.
     *
     * @param array<string, string|int> $lockedState
     */
    public function postInternalTransfer(
        array $lockedState,
        int $stockMovementId,
        string $movementKey,
        int $transferId,
        int $transferItemId,
        string $quantityDelta,
        string $valueDeltaKhr,
        int $actorId,
    ): int {
        $quantityDelta = Decimal::signed($quantityDelta, 3);
        $valueDeltaKhr = Decimal::signed($valueDeltaKhr, 4);
        $quantityNegative = str_starts_with($quantityDelta, '-');
        $valueNegative = str_starts_with($valueDeltaKhr, '-');
        $quantityMagnitude = ltrim($quantityDelta, '-');
        $valueMagnitude = ltrim($valueDeltaKhr, '-');
        if (Decimal::compare($quantityMagnitude, '0.000', 3) === 0) {
            throw new DomainException('An internal transfer valuation entry requires a non-zero quantity.');
        }
        if (Decimal::compare($valueMagnitude, '0.0000', 4) !== 0 && $quantityNegative !== $valueNegative) {
            throw new DomainException('Internal transfer quantity and carrying-value signs must agree.');
        }

        return $this->costs->createEntry(
            (int) $lockedState['product_id'],
            $stockMovementId,
            'stock_transfer',
            $transferId,
            $transferItemId,
            $this->entryKey($movementKey),
            $quantityDelta,
            $valueDeltaKhr,
            (string) $lockedState['avg_unit_cost_khr'],
            (string) $lockedState['avg_unit_cost_khr'],
            (string) $lockedState['avg_unit_cost_khr'],
            'stock_transfer',
            $actorId,
        );
    }

    private function postOutgoing(array $lockedState, int $stockMovementId, string $movementKey, string $sourceType,
        int $sourceId, int $sourceLineId, string $quantityBase, int $actorId, string $costingType): array
    {
        $cogs = $this->quoteOutgoingCogs($lockedState, $quantityBase);
        $nextQuantity = Decimal::subtract($lockedState['qty_valued'], $cogs['qty_base'], 3);
        $nextValue = Decimal::subtract($lockedState['inventory_value_khr'], $cogs['value_khr'], 4);
        $nextAverage = Decimal::compare($nextQuantity, '0.000', 3) === 0
            ? '0.000000'
            : Decimal::divide($nextValue, $nextQuantity, 6);

        $this->costs->updateProductCost(
            (int) $lockedState['id'],
            $nextQuantity,
            $nextValue,
            $nextAverage,
        );
        $this->costs->createEntry(
            (int) $lockedState['product_id'],
            $stockMovementId,
            $sourceType,
            $sourceId,
            $sourceLineId,
            $this->entryKey($movementKey),
            '-' . $cogs['qty_base'],
            '-' . $cogs['value_khr'],
            $cogs['unit_cost_khr'],
            $lockedState['avg_unit_cost_khr'],
            $nextAverage,
            $costingType,
            $actorId,
        );

        return [
            'state' => [
                'id' => (int) $lockedState['id'],
                'product_id' => (int) $lockedState['product_id'],
                'qty_valued' => $nextQuantity,
                'inventory_value_khr' => $nextValue,
                'avg_unit_cost_khr' => $nextAverage,
            ],
            'cogs' => $cogs,
        ];
    }

    /**
     * Compensates a voided confirmed sale only when an Owner explicitly marks
     * every item resellable. The exact original COGS value is restored rather
     * than repricing the historical sale at today's average.
     *
     * @param array<string, string|int> $lockedState
     * @return array<string, string|int>
     */
    public function postSaleVoid(
        array $lockedState,
        int $stockMovementId,
        string $movementKey,
        int $saleId,
        int $saleItemId,
        string $quantityBase,
        string $originalCogsKhr,
        string $originalUnitCostKhr,
        int $actorId,
    ): array {
        $quantityBase = Decimal::positive($quantityBase, 3);
        $originalCogsKhr = Decimal::nonNegative($originalCogsKhr, 4);
        $originalUnitCostKhr = Decimal::nonNegative($originalUnitCostKhr, 6);
        $nextQuantity = Decimal::add($lockedState['qty_valued'], $quantityBase, 3);
        $nextValue = Decimal::add($lockedState['inventory_value_khr'], $originalCogsKhr, 4);
        $nextAverage = Decimal::divide($nextValue, $nextQuantity, 6);

        $this->costs->updateProductCost(
            (int) $lockedState['id'],
            $nextQuantity,
            $nextValue,
            $nextAverage,
        );
        $this->costs->createEntry(
            (int) $lockedState['product_id'],
            $stockMovementId,
            'sale_void',
            $saleId,
            $saleItemId,
            $this->entryKey($movementKey),
            $quantityBase,
            $originalCogsKhr,
            $originalUnitCostKhr,
            $lockedState['avg_unit_cost_khr'],
            $nextAverage,
            'sale_void',
            $actorId,
        );

        return [
            'id' => (int) $lockedState['id'],
            'product_id' => (int) $lockedState['product_id'],
            'qty_valued' => $nextQuantity,
            'inventory_value_khr' => $nextValue,
            'avg_unit_cost_khr' => $nextAverage,
        ];
    }

    /**
     * Restores sellable stock from a confirmed physical return at the
     * immutable original sale-line cost, then recalculates the current moving
     * average from the actual current valuation state. Non-resellable returns
     * deliberately do not call this method.
     *
     * @param array<string, string|int> $lockedState
     * @return array<string, string|int>
     */
    public function postSaleReturn(
        array $lockedState,
        int $stockMovementId,
        string $movementKey,
        int $returnId,
        int $returnItemId,
        string $quantityBase,
        string $costBasisKhr,
        string $originalUnitCostKhr,
        int $actorId,
    ): array {
        $quantityBase = Decimal::positive($quantityBase, 3);
        $costBasisKhr = Decimal::nonNegative($costBasisKhr, 4);
        $originalUnitCostKhr = Decimal::nonNegative($originalUnitCostKhr, 6);
        $nextQuantity = Decimal::add($lockedState['qty_valued'], $quantityBase, 3);
        $nextValue = Decimal::add($lockedState['inventory_value_khr'], $costBasisKhr, 4);
        $nextAverage = Decimal::divide($nextValue, $nextQuantity, 6);
        $this->costs->updateProductCost(
            (int) $lockedState['id'],
            $nextQuantity,
            $nextValue,
            $nextAverage,
        );
        $this->costs->createEntry(
            (int) $lockedState['product_id'],
            $stockMovementId,
            'sale_return',
            $returnId,
            $returnItemId,
            $this->entryKey($movementKey),
            $quantityBase,
            $costBasisKhr,
            $originalUnitCostKhr,
            $lockedState['avg_unit_cost_khr'],
            $nextAverage,
            'sale_return_restock',
            $actorId,
        );

        return [
            'id' => (int) $lockedState['id'],
            'product_id' => (int) $lockedState['product_id'],
            'qty_valued' => $nextQuantity,
            'inventory_value_khr' => $nextValue,
            'avg_unit_cost_khr' => $nextAverage,
        ];
    }

    /** @return array<string, string|int> */
    private function normalizeState(array $state): array
    {
        $quantity = Decimal::nonNegative((string) $state['qty_valued'], 3);
        $value = Decimal::nonNegative((string) $state['inventory_value_khr'], 4);
        $average = Decimal::nonNegative((string) $state['avg_unit_cost_khr'], 6);
        if (Decimal::compare($quantity, '0.000', 3) === 0) {
            if (Decimal::compare($value, '0.0000', 4) !== 0 || Decimal::compare($average, '0.000000', 6) !== 0) {
                throw new DomainException('Product costing state is invalid for zero stock.');
            }
        } else {
            $expectedAverage = Decimal::divide($value, $quantity, 6);
            if (Decimal::compare($average, $expectedAverage, 6) !== 0) {
                throw new DomainException('Product costing state does not match its weighted-average value.');
            }
        }

        return [
            'id' => (int) $state['id'],
            'product_id' => (int) $state['product_id'],
            'qty_valued' => $quantity,
            'inventory_value_khr' => $value,
            'avg_unit_cost_khr' => $average,
        ];
    }

    private function entryKey(string $movementKey): string
    {
        return 'inventory-cost:' . hash('sha256', $movementKey);
    }
}
