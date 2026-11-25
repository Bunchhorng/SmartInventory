<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryReceipt;
use App\Models\InventoryCost;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

final class InventoryService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private InventoryReceipt $receipts,
        private InventoryCost $costs,
        private MovingAverageCostService $movingAverageCosts,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function createDraftReceipt(int $actorId, array $input): int
    {
        $this->assertCanReceive($actorId);
        $receiptType = $this->receiptType((string) ($input['receipt_type'] ?? ''));
        $referenceNo = $this->referenceNo((string) ($input['reference_no'] ?? ''));
        $locationId = $this->positiveId($input['location_id'] ?? null, 'A valid stock location is required.');
        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > 100) {
            throw new DomainException('A receipt requires between one and 100 stock items.');
        }

        return $this->transaction(function () use ($actorId, $receiptType, $referenceNo, $locationId, $items): int {
            if ($this->receipts->referenceExists($referenceNo)) {
                throw new DomainException('This receipt reference is already in use.');
            }
            if ($this->receipts->findActiveLocation($locationId, true) === null) {
                throw new DomainException('The selected stock location is not active.');
            }

            /** @var list<array{product_unit_id: int, qty_received: string, qty_base: string, cost_per_base_khr: string|null}> $lines */
            $lines = [];
            $productIds = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new DomainException('Receipt item data is invalid.');
                }
                $productUnitId = $this->positiveId($item['product_unit_id'] ?? null, 'A valid product unit is required.');
                $productUnit = $this->receipts->productUnit($productUnitId, true);
                if (
                    $productUnit === null
                    || $productUnit['product_status'] !== 'active'
                    || $productUnit['unit_status'] !== 'active'
                ) {
                    throw new DomainException('Receipt items must use active products and units.');
                }
                if ((bool) $productUnit['track_batches']) {
                    throw new DomainException('Batch-tracked products require the dedicated batch receipt workflow and cannot use this receipt type.');
                }
                $productId = (int) $productUnit['product_id'];
                if (isset($productIds[$productId])) {
                    throw new DomainException('A product may appear only once per receipt.');
                }
                $productIds[$productId] = true;

                $quantityReceived = Decimal::positive(
                    (string) ($item['quantity'] ?? ''),
                    (int) $productUnit['precision_digits'],
                );
                $quantityBase = Decimal::multiply(
                    $quantityReceived,
                    (string) $productUnit['to_base_factor'],
                    3,
                );
                $cost = $this->optionalCost($actorId, $item);
                $lines[] = [
                    'product_unit_id' => $productUnitId,
                    'qty_received' => $quantityReceived,
                    'qty_base' => $quantityBase,
                    'cost_per_base_khr' => $cost,
                ];
            }

            $receiptId = $this->receipts->create(
                $this->generateReceiptNo(),
                $receiptType,
                $referenceNo,
                $locationId,
                $actorId,
            );
            foreach ($lines as $line) {
                $this->receipts->createItem(
                    $receiptId,
                    $line['product_unit_id'],
                    $line['qty_received'],
                    $line['qty_base'],
                    $line['cost_per_base_khr'],
                );
            }
            $this->auditLog->record(
                $actorId,
                'inventory_receipt.draft_created',
                'inventory_receipts',
                $receiptId,
                sprintf('%s receipt reference %s.', $receiptType, $referenceNo),
            );
            return $receiptId;
        });
    }

    /**
     * @param list<array<string, mixed>> $costs
     */
    public function setReceiptCosts(int $actorId, int $receiptId, array $costs): void
    {
        $this->assertCanValueStock($actorId);
        if ($costs === []) {
            throw new DomainException('A cost is required for every receipt item.');
        }

        $this->transaction(function () use ($actorId, $receiptId, $costs): void {
            $receipt = $this->receipts->find($receiptId, true);
            if ($receipt === null || $receipt['status'] !== 'draft') {
                throw new DomainException('Only a draft receipt can receive valuation details.');
            }
            $this->assertStandaloneReceipt($receiptId);
            $items = $this->receipts->items($receiptId, true);
            $expectedItemIds = [];
            foreach ($items as $item) {
                $expectedItemIds[(int) $item['id']] = true;
            }
            $costsByItemId = [];
            foreach ($costs as $cost) {
                if (!is_array($cost)) {
                    throw new DomainException('Receipt cost data is invalid.');
                }
                $itemId = $this->positiveId($cost['receipt_item_id'] ?? null, 'A valid receipt item is required.');
                if (!isset($expectedItemIds[$itemId]) || isset($costsByItemId[$itemId])) {
                    throw new DomainException('Receipt costs must contain each receipt item exactly once.');
                }
                $costsByItemId[$itemId] = Decimal::positive((string) ($cost['cost_per_base_khr'] ?? ''), 6);
            }
            if (count($costsByItemId) !== count($expectedItemIds)) {
                throw new DomainException('Receipt costs must contain each receipt item exactly once.');
            }
            $this->receipts->updateCosts($receiptId, $costsByItemId);
            $this->auditLog->record($actorId, 'inventory_receipt.costs_set', 'inventory_receipts', $receiptId);
        });
    }

    /**
     * @return array{receipt_id: int, confirmed: bool}
     */
    public function confirmReceipt(int $actorId, int $receiptId): array
    {
        $this->assertCanReceive($actorId);
        // Confirming reveals and posts valuation data, so it remains an Owner
        // approval until the purchase/valuation workflow is implemented.
        $this->assertCanValueStock($actorId);

        return $this->transaction(function () use ($actorId, $receiptId): array {
            $receipt = $this->receipts->find($receiptId, true);
            if ($receipt === null) {
                throw new DomainException('The stock receipt was not found.');
            }
            $this->assertStandaloneReceipt($receiptId);
            if ($receipt['status'] === 'confirmed') {
                return ['receipt_id' => $receiptId, 'confirmed' => false];
            }
            if ($receipt['status'] !== 'draft') {
                throw new DomainException('Only a draft receipt can be confirmed.');
            }
            return $this->postLockedReceipt($actorId, $receipt, $this->receipts->items($receiptId, true));
        });
    }

    /**
     * Internal composition contract: the caller owns the transaction and holds
     * document/item locks. GoodsReceivingService validates its PO before using
     * this same posting path. No nested transaction and no independent commit.
     * @param array<string, mixed> $receipt
     * @param list<array<string, mixed>> $items
     * @return array{receipt_id: int, confirmed: bool}
     */
    public function postLockedReceipt(int $actorId, array $receipt, array $items): array
    {
        $this->assertCanReceive($actorId);
        $this->assertCanValueStock($actorId);
        if (!$this->pdo->inTransaction() || $receipt['status'] !== 'draft') {
            throw new \LogicException('Receipt posting requires a locked draft inside the caller transaction.');
        }
        $receiptId = (int) $receipt['id'];
        usort($items, static fn (array $a, array $b): int =>
            (int) $a['product_id'] <=> (int) $b['product_id'] ?: (int) $a['id'] <=> (int) $b['id']);
        if ($this->receipts->findActiveLocation((int) $receipt['location_id'], true) === null) {
            throw new DomainException('The receipt stock location is no longer active.');
        }
        if ($items === []) {
            throw new DomainException('A receipt requires at least one item before confirmation.');
        }

        /** @var array<int, array<string, string|int>> $costStates */
        $costStates = [];
        foreach ($items as $item) {
            if ($item['product_status'] !== 'active' || $item['unit_status'] !== 'active' || (bool) $item['track_batches']) {
                throw new DomainException('Every confirmed receipt item must use an active non-batch-tracked product and unit.');
            }
            if ($item['cost_per_base_khr'] === null) {
                throw new DomainException('Every confirmed receipt item requires an explicit cost per base unit.');
            }
            Decimal::positive((string) $item['cost_per_base_khr'], 6);
            $productId = (int) $item['product_id'];
            if (!isset($costStates[$productId])) {
                $costStates[$productId] = $this->movingAverageCosts->lockProductForPosting($productId);
            }
        }

        /** @var array<int, array<string, mixed>> $balances */
        $balances = [];
        foreach ($items as $item) {
            $balances[(int) $item['id']] = $this->receipts->lockBalance((int) $item['product_id'], (int) $receipt['location_id']);
        }
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $productId = (int) $item['product_id'];
            $movementKey = $this->confirmationMovementKey($receiptId, $itemId);
            if ($this->receipts->movementExists($movementKey)) {
                throw new DomainException('This receipt item has already been posted.');
            }
            $this->receipts->changeBalance((int) $balances[$itemId]['id'], (string) $item['qty_base']);
            $movementId = $this->receipts->createMovement(
                $productId,
                (int) $receipt['location_id'],
                (string) $item['qty_base'],
                'inventory_receipt',
                $receiptId,
                $itemId,
                $movementKey,
                (string) $item['cost_per_base_khr'],
                $actorId,
            );
            $costStates[$productId] = $this->movingAverageCosts->postValuedReceipt(
                $costStates[$productId],
                $movementId,
                $movementKey,
                (string) $receipt['receipt_type'],
                $receiptId,
                $itemId,
                (string) $item['qty_base'],
                (string) $item['cost_per_base_khr'],
                $actorId,
            );
        }
        $this->receipts->markConfirmed($receiptId, $actorId);
        $this->auditLog->record($actorId, 'inventory_receipt.confirmed', 'inventory_receipts', $receiptId);
        return ['receipt_id' => $receiptId, 'confirmed' => true];
    }

    /** Internal composition: caller validates/locks PO, receipt, approval and return quantities.
     * No separate transaction. Only available, non-batch retail/storage balances are eligible.
     * @return array<int,array{movement_id:int,unit_cost_khr:string,value_khr:string}>
     */
    public function postLockedPurchaseReturn(int $actorId, array $doc, array $items): array
    {
        if (!$this->pdo->inTransaction() || ($doc['status'] ?? '') !== 'approved') {
            throw new \LogicException('Purchase return posting requires a locked approved document in the caller transaction.');
        }
        if (!$this->users->findActiveById($actorId) || !$this->users->isOwner($actorId)
            || !$this->users->hasPermission($actorId,'purchase_returns.confirm')) {
            throw new DomainException('Only an authorized active Owner may confirm physical purchase returns.');
        }
        $location=$this->receipts->findActiveLocation((int)$doc['location_id'],true);
        if (!$location || !in_array($location['type'],['retail','storage'],true) || !$items) {
            throw new DomainException('Purchase returns require available stock in an active retail/storage receipt location.');
        }
        usort($items,static fn(array $a,array $b): int => (int)$a['product_id']<=>(int)$b['product_id'] ?: (int)$a['id']<=>(int)$b['id']);
        $states=$balances=$result=[];
        foreach ($items as $item) {
            $product=(int)$item['product_id'];
            if (!isset($states[$product])) { $states[$product]=$this->movingAverageCosts->lockProductForPosting($product); }
        }
        foreach ($states as $product=>$_) { $balances[$product]=$this->receipts->lockBalance($product,(int)$doc['location_id']); }
        foreach ($states as $product=>$_) {
            $value=$this->costs->reconciliation($product);
            $stock=$this->receipts->reconciliation($product,(int)$doc['location_id']);
            if (Decimal::signedSubtract($value['cost_qty'],'0',3)!==$value['ledger_qty']
                || Decimal::signedSubtract($value['cost_value'],'0',4)!==$value['ledger_value']
                || Decimal::signedSubtract($stock['balance'],'0',3)!==$stock['ledger']) {
                throw new DomainException('Stock and valuation ledgers must reconcile before a purchase return can be dispatched.');
            }
        }
        foreach ($items as $item) {
            $product=(int)$item['product_id']; $id=(int)$item['id']; $qty=Decimal::positive((string)$item['qty_base'],3);
            if (Decimal::compare((string)$balances[$product]['qty_base'],$qty,3)<0) { throw new DomainException('Insufficient available stock at the original receipt location.'); }
            $key='purchase-return:'.$doc['id'].':'.$id;
            if ($this->receipts->movementExists($key)) { throw new DomainException('Purchase return line is already posted.'); }
            $quote=$this->movingAverageCosts->quoteOutgoingCogs($states[$product],$qty);
            $this->receipts->changeBalance((int)$balances[$product]['id'],'-'.$qty);
            $movement=$this->receipts->createMovement($product,(int)$doc['location_id'],'-'.$qty,'purchase_return',(int)$doc['id'],$id,$key,$quote['unit_cost_khr'],$actorId);
            $posted=$this->movingAverageCosts->postPurchaseReturn($states[$product],$movement,$key,(int)$doc['id'],$id,$qty,$actorId);
            $states[$product]=$posted['state'];
            $balances[$product]['qty_base']=Decimal::subtract((string)$balances[$product]['qty_base'],$qty,3);
            $result[$id]=['movement_id'=>$movement,'unit_cost_khr'=>$quote['unit_cost_khr'],'value_khr'=>$quote['value_khr']];
        }
        return $result;
    }

    /**
     * Internal composition for Phase 5.7. The caller has locked the approved
     * correction and proved each source return posting; this method adds only
     * positive compensating ledger rows at the saved outgoing carrying value.
     */
    public function postLockedPurchaseReturnCorrection(int $actorId, array $doc, array $items): array
    {
        if (!$this->pdo->inTransaction() || ($doc['status'] ?? '') !== 'approved') { throw new \LogicException('Purchase return correction posting requires a locked approved document.'); }
        if (!$this->users->findActiveById($actorId) || !$this->users->isOwner($actorId) || !$this->users->hasPermission($actorId,'purchase_return_corrections.post')) { throw new DomainException('Only an authorized active Owner may post purchase return corrections.'); }
        if (!$items) { throw new DomainException('A correction requires verified items.'); }
        usort($items,static fn(array $a,array $b): int=>(int)$a['product_id']<=>(int)$b['product_id'] ?: (int)$a['location_id']<=>(int)$b['location_id'] ?: (int)$a['id']<=>(int)$b['id']);
        $states=$balances=$result=[];
        foreach ($items as $item) { $product=(int)$item['product_id']; if (!isset($states[$product])) { $states[$product]=$this->movingAverageCosts->lockProductForPosting($product); } }
        foreach ($items as $item) { $key=(int)$item['product_id'].':'.(int)$item['location_id']; if (!isset($balances[$key])) { $location=$this->receipts->findActiveLocation((int)$item['location_id'],true); if (!$location || !in_array($location['type'],['retail','storage'],true)) { throw new DomainException('Corrections may restore only to the original active retail/storage location.'); } $balances[$key]=$this->receipts->lockBalance((int)$item['product_id'],(int)$item['location_id']); } }
        foreach ($states as $product=>$_) { $value=$this->costs->reconciliation($product); if (Decimal::signedSubtract($value['cost_qty'],'0',3)!==$value['ledger_qty'] || Decimal::signedSubtract($value['cost_value'],'0',4)!==$value['ledger_value']) { throw new DomainException('Inventory valuation must reconcile before a correction can post.'); } }
        foreach ($balances as $key=>$balance) { [$product,$location]=array_map('intval',explode(':',$key)); $stock=$this->receipts->reconciliation($product,$location); if (Decimal::signedSubtract($stock['balance'],'0',3)!==$stock['ledger']) { throw new DomainException('Stock ledger must reconcile before a correction can post.'); } }
        foreach ($items as $item) {
            $id=(int)$item['id']; $product=(int)$item['product_id']; $location=(int)$item['location_id']; $balanceKey=$product.':'.$location;
            $qty=Decimal::positive((string)$item['qty_base'],3); $value=Decimal::nonNegative((string)$item['carrying_value_khr'],4); $key='purchase-return-correction:'.$doc['id'].':'.$id;
            if ($this->receipts->movementExists($key)) { throw new DomainException('Purchase return correction line is already posted.'); }
            $this->receipts->changeBalance((int)$balances[$balanceKey]['id'],$qty);
            $movement=$this->receipts->createMovement($product,$location,$qty,'purchase_return_correction',(int)$doc['id'],$id,$key,(string)$item['original_unit_cost_khr'],$actorId);
            $states[$product]=$this->movingAverageCosts->postPurchaseReturnCorrection($states[$product],$movement,$key,(int)$doc['id'],$id,$qty,$value,(string)$item['original_unit_cost_khr'],$actorId);
            $balances[$balanceKey]['qty_base']=Decimal::add((string)$balances[$balanceKey]['qty_base'],$qty,3);
            $result[$id]=['movement_id'=>$movement,'unit_cost_khr'=>(string)$item['original_unit_cost_khr'],'value_khr'=>$value];
        }
        return $result;
    }

    /**
     * Composes the immutable physical and valuation legs of an approved
     * Phase 6.1 internal transfer. The caller owns the transfer document
     * transaction; this method intentionally never starts a nested one.
     *
     * @param array<string, mixed> $doc
     * @param list<array<string, mixed>> $items
     * @return array<int,array{source_movement_id:int,destination_movement_id:int,source_cost_entry_id:int,destination_cost_entry_id:int,unit_cost_khr:string,value_khr:string}>
     */
    public function postLockedStockTransfer(int $actorId, array $doc, array $items): array
    {
        if (!$this->pdo->inTransaction() || ($doc['status'] ?? '') !== 'approved') {
            throw new \LogicException('Stock-transfer posting requires a locked approved document.');
        }
        if (!$this->users->findActiveById($actorId) || !$this->users->isOwner($actorId)
            || !$this->users->hasPermission($actorId, 'stock_transfers.confirm')) {
            throw new DomainException('Only an authorized active Owner may confirm internal stock transfers.');
        }
        if ($items === []) {
            throw new DomainException('An internal stock transfer requires at least one item.');
        }

        $sourceId = (int) $doc['from_location_id'];
        $destinationId = (int) $doc['to_location_id'];
        if ($sourceId < 1 || $destinationId < 1 || $sourceId === $destinationId) {
            throw new DomainException('Transfer source and destination must be different locations.');
        }
        $locationIds = [$sourceId, $destinationId];
        sort($locationIds, SORT_NUMERIC);
        $locations = [];
        foreach ($locationIds as $locationId) {
            $location = $this->receipts->findActiveLocation($locationId, true);
            if ($location === null || !in_array($location['type'], ['retail', 'storage'], true)) {
                throw new DomainException('Transfers require active retail or storage locations.');
            }
            $locations[$locationId] = $location;
        }

        usort($items, static fn (array $left, array $right): int =>
            (int) $left['product_id'] <=> (int) $right['product_id'] ?: (int) $left['id'] <=> (int) $right['id']
        );
        $states = [];
        foreach ($items as $item) {
            if (
                $item['product_status'] !== 'active'
                || $item['unit_status'] !== 'active'
                || (bool) $item['track_batches']
                || (int) $item['current_product_id'] !== (int) $item['product_id']
            ) {
                throw new DomainException('Transfers require active, non-batch products and active product units.');
            }
            $productId = (int) $item['product_id'];
            if (isset($states[$productId])) {
                throw new DomainException('A product may occur only once in an internal transfer.');
            }
            $states[$productId] = $this->movingAverageCosts->lockProductForPosting($productId);
        }

        /** @var array<string, array<string, mixed>> $balances */
        $balances = [];
        $balanceRequests = [];
        foreach ($items as $item) {
            foreach ($locationIds as $locationId) {
                $balanceRequests[(int) $item['product_id'] . ':' . $locationId] = [
                    'product_id' => (int) $item['product_id'],
                    'location_id' => $locationId,
                ];
            }
        }
        uasort($balanceRequests, static fn (array $left, array $right): int =>
            $left['product_id'] <=> $right['product_id'] ?: $left['location_id'] <=> $right['location_id']
        );
        foreach ($balanceRequests as $key => $request) {
            $balances[$key] = $this->receipts->lockBalance($request['product_id'], $request['location_id']);
        }

        foreach ($states as $productId => $_state) {
            $valuation = $this->costs->reconciliation($productId);
            if (
                Decimal::signedSubtract($valuation['cost_qty'], '0', 3) !== $valuation['ledger_qty']
                || Decimal::signedSubtract($valuation['cost_value'], '0', 4) !== $valuation['ledger_value']
            ) {
                throw new DomainException('Inventory valuation must reconcile before an internal transfer can post.');
            }
        }
        foreach ($balances as $key => $_balance) {
            [$productId, $locationId] = array_map('intval', explode(':', $key));
            $stock = $this->receipts->reconciliation($productId, $locationId);
            if (Decimal::signedSubtract($stock['balance'], '0', 3) !== $stock['ledger']) {
                throw new DomainException('Stock ledger must reconcile before an internal transfer can post.');
            }
        }

        $posted = [];
        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $productId = (int) $item['product_id'];
            $quantity = Decimal::positive((string) $item['qty_base'], 3);
            $sourceKey = $productId . ':' . $sourceId;
            $destinationKey = $productId . ':' . $destinationId;
            if (Decimal::compare((string) $balances[$sourceKey]['qty_base'], $quantity, 3) < 0) {
                throw new DomainException('Insufficient available stock at the transfer source location.');
            }
            $outKey = 'stock-transfer:' . $doc['id'] . ':line:' . $itemId . ':out';
            $inKey = 'stock-transfer:' . $doc['id'] . ':line:' . $itemId . ':in';
            if ($this->receipts->movementExists($outKey) || $this->receipts->movementExists($inKey)) {
                throw new DomainException('Transfer line is already posted.');
            }
            $quote = $this->movingAverageCosts->quoteOutgoingCogs($states[$productId], $quantity);

            $this->receipts->changeBalance((int) $balances[$sourceKey]['id'], '-' . $quantity);
            $sourceMovementId = $this->receipts->createMovement(
                $productId, $sourceId, '-' . $quantity, 'stock_transfer', (int) $doc['id'], $itemId,
                $outKey, $quote['unit_cost_khr'], $actorId,
            );
            $sourceCostEntryId = $this->movingAverageCosts->postInternalTransfer(
                $states[$productId], $sourceMovementId, $outKey, (int) $doc['id'], $itemId,
                '-' . $quantity, '-' . $quote['value_khr'], $actorId,
            );

            $this->receipts->changeBalance((int) $balances[$destinationKey]['id'], $quantity);
            $destinationMovementId = $this->receipts->createMovement(
                $productId, $destinationId, $quantity, 'stock_transfer', (int) $doc['id'], $itemId,
                $inKey, $quote['unit_cost_khr'], $actorId,
            );
            $destinationCostEntryId = $this->movingAverageCosts->postInternalTransfer(
                $states[$productId], $destinationMovementId, $inKey, (int) $doc['id'], $itemId,
                $quantity, $quote['value_khr'], $actorId,
            );
            $balances[$sourceKey]['qty_base'] = Decimal::subtract((string) $balances[$sourceKey]['qty_base'], $quantity, 3);
            $balances[$destinationKey]['qty_base'] = Decimal::add((string) $balances[$destinationKey]['qty_base'], $quantity, 3);
            $posted[$itemId] = [
                'source_movement_id' => $sourceMovementId,
                'destination_movement_id' => $destinationMovementId,
                'source_cost_entry_id' => $sourceCostEntryId,
                'destination_cost_entry_id' => $destinationCostEntryId,
                'unit_cost_khr' => $quote['unit_cost_khr'],
                'value_khr' => $quote['value_khr'],
            ];
        }

        return $posted;
    }

    public function reverseReceipt(int $actorId, int $receiptId, string $reason): void
    {
        $this->assertCanValueStock($actorId);
        $reason = trim($reason);
        if ($reason === '' || $this->length($reason) > 500) {
            throw new DomainException('A reversal reason of 1–500 characters is required.');
        }

        $this->transaction(function () use ($actorId, $receiptId, $reason): void {
            $receipt = $this->receipts->find($receiptId, true);
            if ($receipt === null || $receipt['status'] !== 'confirmed') {
                throw new DomainException('Only a confirmed receipt can be reversed.');
            }
            $this->assertStandaloneReceipt($receiptId);
            $items = $this->receipts->items($receiptId, true);
            /** @var array<int, array<string, string|int>> $costStates */
            $costStates = [];
            foreach ($items as $item) {
                $costStates[(int) $item['id']] = $this->movingAverageCosts->lockProductForPosting((int) $item['product_id']);
            }
            /** @var array<int, array<string, mixed>> $balances */
            $balances = [];
            foreach ($items as $item) {
                $balance = $this->receipts->lockBalance((int) $item['product_id'], (int) $receipt['location_id']);
                if (Decimal::compare((string) $balance['qty_base'], (string) $item['qty_base'], 3) < 0) {
                    throw new DomainException('This receipt cannot be reversed because later transactions consumed its stock.');
                }
                $balances[(int) $item['id']] = $balance;
            }
            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $movementKey = $this->reversalMovementKey($receiptId, $itemId);
                if ($this->receipts->movementExists($movementKey)) {
                    throw new DomainException('This receipt item has already been reversed.');
                }
                $this->receipts->changeBalance((int) $balances[$itemId]['id'], '-' . (string) $item['qty_base']);
                $movementId = $this->receipts->createMovement(
                    (int) $item['product_id'],
                    (int) $receipt['location_id'],
                    '-' . (string) $item['qty_base'],
                    'inventory_receipt_reversal',
                    $receiptId,
                    $itemId,
                    $movementKey,
                    (string) $item['cost_per_base_khr'],
                    $actorId,
                );
                $costStates[$itemId] = $this->movingAverageCosts->postReceiptReversal(
                    $costStates[$itemId],
                    $movementId,
                    $movementKey,
                    $receiptId,
                    $itemId,
                    (string) $item['qty_base'],
                    $actorId,
                );
            }
            $this->receipts->markReversed($receiptId, $actorId, $reason);
            $this->auditLog->record($actorId, 'inventory_receipt.reversed', 'inventory_receipts', $receiptId, $reason);
        });
    }

    /** @return array{balance: string, ledger: string, reconciled: bool} */
    public function reconcileProductLocation(int $actorId, int $productId, int $locationId): array
    {
        $this->assertCanViewStock($actorId);
        $result = $this->receipts->reconciliation($productId, $locationId);
        return $result + [
            'reconciled' => Decimal::compare($result['balance'], $result['ledger'], 3) === 0,
        ];
    }

    /**
     * Valuation details are restricted to an Owner. This method is deliberately
     * service-only until a separate Owner reporting screen is requested.
     *
     * @return array{balance_qty: string, cost_qty: string, cost_value: string, average_cost: string, ledger_qty: string, ledger_value: string, reconciled: bool}
     */
    public function reconcileProductValuation(int $actorId, int $productId): array
    {
        $this->assertCanValueStock($actorId);
        $result = $this->costs->reconciliation($productId);
        return $result + [
            'reconciled' => Decimal::compare($result['balance_qty'], $result['cost_qty'], 3) === 0
                && Decimal::compare($result['cost_qty'], $result['ledger_qty'], 3) === 0
                && Decimal::compare($result['cost_value'], $result['ledger_value'], 4) === 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listReceiptsForViewer(int $actorId, string $status, int $page, int $perPage): array
    {
        $this->assertCanViewStock($actorId);
        if (!in_array($status, ['all', 'draft', 'confirmed', 'reversed'], true)) {
            throw new DomainException('Receipt status filter is invalid.');
        }
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        return $this->receipts->list($status, $perPage, ($page - 1) * $perPage);
    }

    private function optionalCost(int $actorId, array $item): ?string
    {
        if (!array_key_exists('cost_per_base_khr', $item) || trim((string) $item['cost_per_base_khr']) === '') {
            return null;
        }
        $this->assertCanValueStock($actorId);
        return Decimal::positive((string) $item['cost_per_base_khr'], 6);
    }

    private function assertStandaloneReceipt(int $receiptId): void
    {
        if ($this->receipts->isPurchaseLinked($receiptId)) {
            throw new DomainException('Purchase-linked receipts require the goods receiving workflow. Direct valuation, confirmation, and reversal are prohibited.');
        }
    }

    private function assertCanReceive(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'stock.receive')) {
            throw new DomainException('You do not have permission to receive stock.');
        }
    }

    private function assertCanViewStock(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'stock.view')) {
            throw new DomainException('You do not have permission to view stock.');
        }
    }

    private function assertCanValueStock(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'stock.adjust.approve')) {
            throw new DomainException('Only an authorized Owner can access or post receipt valuation details.');
        }
    }

    private function receiptType(string $receiptType): string
    {
        if (!in_array($receiptType, ['opening', 'purchase'], true)) {
            throw new DomainException('Receipt type must be opening or purchase.');
        }
        return $receiptType;
    }

    private function referenceNo(string $referenceNo): string
    {
        $referenceNo = trim($referenceNo);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{1,79}$/', $referenceNo)) {
            throw new DomainException('Receipt reference must contain 2–80 letters, numbers, dots, slashes, dashes, or underscores.');
        }
        return $referenceNo;
    }

    private function positiveId(mixed $value, string $message): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException($message);
        }
        return (int) $value;
    }

    private function generateReceiptNo(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $receiptNo = 'IR-' . (new \DateTimeImmutable())->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $statement = $this->pdo->prepare('SELECT 1 FROM inventory_receipts WHERE receipt_no = ? LIMIT 1');
            $statement->execute([$receiptNo]);
            if (!$statement->fetchColumn()) {
                return $receiptNo;
            }
        }
        throw new DomainException('Unable to generate a unique receipt number. Please try again.');
    }

    private function confirmationMovementKey(int $receiptId, int $receiptItemId): string
    {
        return sprintf('inventory-receipt:%d:line:%d:confirmed', $receiptId, $receiptItemId);
    }

    private function reversalMovementKey(int $receiptId, int $receiptItemId): string
    {
        return sprintf('inventory-receipt:%d:line:%d:reversed', $receiptId, $receiptItemId);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @template T @param callable():T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new DomainException('The inventory transaction could not be saved because it conflicts with existing data.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
