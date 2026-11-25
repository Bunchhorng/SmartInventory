<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{AuditLog, GoodsReceipt, InventoryReceipt, PurchaseOrder, User};
use App\Support\Decimal;
use DomainException;
use PDO;

final class GoodsReceivingService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $audit,
        private GoodsReceipt $goods,
        private PurchaseOrder $orders,
        private InventoryReceipt $receipts,
        private InventoryService $inventory,
    ) {}

    /** @return array{receipt_id:int, created:bool} */
    public function createDraft(int $actorId, array $input): array
    {
        $this->permission($actorId, 'purchases.receive');
        $this->permission($actorId, 'stock.receive');
        $this->permission($actorId, 'purchases.view');
        $this->onlyKeys($input, ['_csrf','purchase_id','supplier_id','location_id','receipt_key','reference_no','delivery_notes','received_at','items']);
        $purchaseId = $this->id($input['purchase_id'] ?? null);
        $locationId = $this->id($input['location_id'] ?? null);
        $key = trim($this->string($input['receipt_key'] ?? ''));
        $reference = strtoupper(trim($this->string($input['reference_no'] ?? '')));
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)
            || !preg_match('/^[A-Z0-9][A-Z0-9._\/-]{1,79}$/', $reference)) {
            throw new DomainException('A receipt key of 8-80 characters and delivery reference of 2-80 characters are required.');
        }
        $notes = trim($this->string($input['delivery_notes'] ?? ''));
        if (strlen($notes) > 1000) { throw new DomainException('Delivery notes may contain at most 1000 bytes.'); }
        $receivedAtInput = trim($this->string($input['received_at'] ?? ''));
        if ($receivedAtInput !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $receivedAtInput);
            if (!$date || $date->format('Y-m-d H:i:s') !== $receivedAtInput) {
                throw new DomainException('Received time must use YYYY-MM-DD HH:MM:SS.');
            }
        }
        $rawItems = $input['items'] ?? null;
        if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 100) {
            throw new DomainException('A goods receipt needs 1-100 PO lines.');
        }
        $requested = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) { throw new DomainException('Invalid receipt line.'); }
            $this->onlyKeys($item, ['purchase_item_id','quantity']);
            $lineId = $this->id($item['purchase_item_id'] ?? null);
            if (isset($requested[$lineId])) { throw new DomainException('Each PO line may appear only once.'); }
            $requested[$lineId] = Decimal::positive($this->string($item['quantity'] ?? ''), 3);
        }
        ksort($requested, SORT_NUMERIC);
        // A replay with omitted received_at hashes the omission, not the clock.
        $hash = hash('sha256', json_encode([$purchaseId, $locationId, $reference, $notes, $receivedAtInput, $requested], JSON_THROW_ON_ERROR));

        return $this->transaction(function () use ($actorId, $input, $purchaseId, $locationId, $key, $reference, $notes, $receivedAtInput, $hash, $requested): array {
            $po = $this->orders->find($purchaseId, true);
            if (!$po) { throw new DomainException('Purchase order was not found.'); }
            if (isset($input['supplier_id']) && $this->id($input['supplier_id']) !== (int) $po['supplier_id']) {
                throw new DomainException('Supplier does not belong to this purchase order.');
            }
            $existing = $this->goods->byKey($key);
            if ($existing) {
                if ((int) $existing['received_by'] !== $actorId || $existing['request_hash'] !== $hash) {
                    throw new DomainException('Receipt key is already used by a different request.');
                }
                return ['receipt_id' => (int) $existing['id'], 'created' => false];
            }
            $this->receivableStatus($po);
            $approval = $this->approvedHeader($po);
            if ($this->goods->hasLegacyReceipts($purchaseId)) {
                throw new DomainException('Historical receiving records need review before this PO can receive more stock.');
            }
            $lines = $this->lockedLines($purchaseId);
            $this->assertCounters($purchaseId, $lines);
            if (!$this->receipts->findActiveLocation($locationId, true)) {
                throw new DomainException('An active destination stock location is required.');
            }
            $prepared = [];
            foreach ($requested as $lineId => $quantity) {
                $line = $lines[$lineId] ?? null;
                if (!$line) { throw new DomainException('Receipt lines must belong to the selected purchase order.'); }
                $this->activeLine($line);
                $quantity = $this->quantityAtPrecision($quantity, (int) $line['unit_precision_snapshot']);
                $remaining = Decimal::subtract((string) $line['ordered_qty'], (string) $line['received_qty'], 3);
                if (Decimal::compare($quantity, $remaining, 3) > 0) { throw new DomainException('Receipt quantity exceeds the remaining approved quantity.'); }
                $base = Decimal::positive(Decimal::multiply($quantity, (string) $line['unit_to_base_factor_snapshot'], 3), 3);
                $prepared[] = [$line, $quantity, $base, $this->approvedCost($line, $approval)];
            }
            $number = 'GR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
            $inventoryId = $this->receipts->create($number, 'purchase',
                'POGR-' . hash('sha256', $po['supplier_id'] . ':' . $reference), $locationId, $actorId);
            $goodsId = $this->goods->create([
                $number, $purchaseId, $locationId, $key, $hash, (int) $po['supplier_id'], $reference,
                $notes === '' ? null : $notes, $actorId, $receivedAtInput ?: date('Y-m-d H:i:s'),
                $inventoryId, $approval['currency_snapshot'], $approval['exchange_rate_to_khr_snapshot'],
            ]);
            foreach ($prepared as [$line, $quantity, $base, $cost]) {
                $inventoryItemId = $this->receipts->createItem($inventoryId, (int) $line['product_unit_id'], $quantity, $base, $cost);
                $this->goods->createItem($goodsId, (int) $line['id'], $inventoryItemId, $quantity, $base,
                    (string) $line['unit_to_base_factor_snapshot'], $cost);
            }
            $this->audit->record($actorId, 'goods_receipt.draft_created', 'goods_receipts', $goodsId);
            return ['receipt_id' => $goodsId, 'created' => true];
        });
    }

    /** @return array{receipt_id:int, confirmed:bool} */
    public function confirm(int $actorId, int $receiptId): array
    {
        $this->owner($actorId, 'purchases.receive.confirm');
        $purchaseId = $this->purchaseIdForReceipt($receiptId);
        return $this->transaction(function () use ($actorId, $receiptId, $purchaseId): array {
            $po = $this->orders->find($purchaseId, true);
            $receipt = $this->goods->find($receiptId, true);
            if (!$po || !$receipt || (int) $receipt['purchase_id'] !== $purchaseId) { throw new DomainException('Goods receipt was not found.'); }
            if ($receipt['status'] === 'confirmed') { return ['receipt_id' => $receiptId, 'confirmed' => false]; }
            $this->draft($receipt);
            $this->receivableStatus($po);
            $approval = $this->approvedHeader($po);
            $lines = $this->lockedLines($purchaseId);
            $this->assertCounters($purchaseId, $lines);
            $inventoryReceipt = $this->receipts->find((int) $receipt['inventory_receipt_id'], true);
            if (!$inventoryReceipt || $inventoryReceipt['status'] !== 'draft'
                || (int) $inventoryReceipt['location_id'] !== (int) $receipt['location_id']
                || (int) $receipt['supplier_id'] !== (int) $po['supplier_id']
                || $receipt['currency_snapshot'] !== $approval['currency_snapshot']
                || $receipt['exchange_rate_snapshot'] !== $approval['exchange_rate_to_khr_snapshot']) {
                throw new DomainException('Goods receipt header does not match its approved PO and inventory document.');
            }
            $inventoryItems = $this->receipts->items((int) $inventoryReceipt['id'], true);
            $byId = array_column($inventoryItems, null, 'id');
            $goodsItems = $this->goods->items($receiptId, true);
            if (!$goodsItems || count($inventoryItems) !== count($goodsItems)) { throw new DomainException('Receipt line linkage is incomplete.'); }
            foreach ($goodsItems as $item) {
                $lineId = (int) $item['purchase_item_id'];
                $line = $lines[$lineId] ?? null;
                $inventoryItem = $byId[(int) $item['inventory_receipt_item_id']] ?? null;
                if (!$line || !$inventoryItem || (int) $inventoryItem['product_unit_id'] !== (int) $line['product_unit_id']) {
                    throw new DomainException('Receipt product does not match its PO line.');
                }
                $this->activeLine($line);
                $quantity = $this->quantityAtPrecision((string) $item['received_qty'], (int) $line['unit_precision_snapshot']);
                $base = Decimal::multiply($quantity, (string) $line['unit_to_base_factor_snapshot'], 3);
                $baseline = $this->approvedCost($line, $approval);
                $actual = $item['variance_id'] === null ? $baseline : (string) $item['variance_cost'];
                if (($item['variance_id'] !== null && $item['variance_baseline'] !== $baseline)
                    || $item['approved_cost_per_base_khr'] !== $baseline || $item['landed_unit_cost_khr'] !== $actual
                    || $inventoryItem['cost_per_base_khr'] !== $actual
                    || Decimal::compare((string) $item['conversion_snapshot'], (string) $line['unit_to_base_factor_snapshot'], 6) !== 0
                    || Decimal::compare((string) $item['qty_base'], $base, 3) !== 0
                    || Decimal::compare((string) $inventoryItem['qty_base'], $base, 3) !== 0
                    || Decimal::compare((string) $inventoryItem['qty_received'], $quantity, 3) !== 0) {
                    throw new DomainException('Receipt snapshots differ from the approved quantities or costs.');
                }
                $next = Decimal::add((string) $line['received_qty'], $quantity, 3);
                if (Decimal::compare($next, (string) $line['ordered_qty'], 3) > 0) { throw new DomainException('Another confirmed delivery consumed the remaining PO quantity.'); }
                $lines[$lineId]['received_qty'] = $next;
            }
            // InventoryService locks distinct product costs in product-id order,
            // then balances, and reuses each updated state for multiple units.
            $this->inventory->postLockedReceipt($actorId, $inventoryReceipt, $inventoryItems);
            $this->goods->markConfirmed($receiptId, $actorId);
            foreach ($lines as $line) { $this->goods->setReceived((int) $line['id'], (string) $line['received_qty']); }
            $this->goods->setPurchaseStatus($purchaseId, $this->fulfillmentStatus($lines));
            $this->audit->record($actorId, 'goods_receipt.confirmed', 'goods_receipts', $receiptId);
            return ['receipt_id' => $receiptId, 'confirmed' => true];
        });
    }

    public function approveCostVariance(int $actorId, int $receiptId, int $itemId, string $actualCost, string $reason): void
    {
        $this->owner($actorId, 'purchases.cost_variance.approve');
        $actualCost = Decimal::positive($actualCost, 6);
        $reason = $this->reason($reason);
        $purchaseId = $this->purchaseIdForReceipt($receiptId);
        $this->transaction(function () use ($actorId, $receiptId, $itemId, $actualCost, $reason, $purchaseId): void {
            $po = $this->orders->find($purchaseId, true);
            $this->receivableStatus($po ?? []);
            $receipt = $this->goods->find($receiptId, true);
            $this->draft($receipt ?? []);
            $this->approvedHeader($po);
            $inventory = $this->receipts->find((int) $receipt['inventory_receipt_id'], true);
            if (!$inventory || $inventory['status'] !== 'draft') { throw new DomainException('Inventory document must still be a draft.'); }
            foreach ($this->goods->items($receiptId, true) as $item) {
                if ((int) $item['id'] !== $itemId) { continue; }
                if ($item['variance_id'] !== null) { throw new DomainException('A variance approval is immutable. Cancel the draft and record a corrected delivery if necessary.'); }
                if (Decimal::compare((string) $item['approved_cost_per_base_khr'], $actualCost, 6) === 0) { throw new DomainException('No cost variance was supplied.'); }
                $this->goods->approveVariance($itemId, (string) $item['approved_cost_per_base_khr'], $actualCost, $actorId, $reason);
                $this->receipts->updateCosts((int) $receipt['inventory_receipt_id'], [(int) $item['inventory_receipt_item_id'] => $actualCost]);
                $this->audit->record($actorId, 'goods_receipt.cost_variance_approved', 'goods_receipt_items', $itemId, $reason);
                return;
            }
            throw new DomainException('Receipt item does not belong to this goods receipt.');
        });
    }

    public function cancelDraft(int $actorId, int $receiptId, string $reason): void
    {
        $this->owner($actorId, 'purchases.receive.confirm');
        $reason = $this->reason($reason);
        $purchaseId = $this->purchaseIdForReceipt($receiptId);
        $this->transaction(function () use ($actorId, $receiptId, $reason, $purchaseId): void {
            $this->orders->find($purchaseId, true);
            $receipt = $this->goods->find($receiptId, true);
            $this->draft($receipt ?? []);
            $this->goods->markCancelled($receiptId, (int) $receipt['inventory_receipt_id'], $actorId, $reason);
            $this->audit->record($actorId, 'goods_receipt.draft_cancelled', 'goods_receipts', $receiptId, $reason);
        });
    }

    public function close(int $actorId, int $purchaseId): array
    {
        $this->owner($actorId, 'purchases.close');
        return $this->transaction(function () use ($actorId, $purchaseId): array {
            $po = $this->orders->find($purchaseId, true);
            if (!$po || !in_array($po['status'], ['received','closed'], true)) { throw new DomainException('Only a fully received PO can be closed.'); }
            $this->approvedHeader($po);
            $lines = $this->lockedLines($purchaseId);
            $this->assertCounters($purchaseId, $lines);
            if ($this->fulfillmentStatus($lines) !== 'received' || $this->goods->hasUnresolvedReceipts($purchaseId)) {
                throw new DomainException('All PO quantities must be received and all draft receipts resolved before closing.');
            }
            $report = $this->reconcile($actorId, $purchaseId);
            if (!$report['reconciled']) { throw new DomainException('Receiving reconciliation must pass before closing.'); }
            if ($po['status'] === 'closed') { return ['purchase_id' => $purchaseId, 'closed' => false]; }
            $this->goods->close($purchaseId, $actorId);
            $this->audit->record($actorId, 'purchase_order.closed', 'purchases', $purchaseId);
            return ['purchase_id' => $purchaseId, 'closed' => true];
        });
    }

    public function forViewer(int $actorId, int $receiptId): array
    {
        $this->permission($actorId, 'purchases.view');
        $receipt = $this->goods->find($receiptId);
        if (!$receipt) { throw new DomainException('Goods receipt was not found.'); }
        $items = $this->goods->items($receiptId);
        if (!$this->users->isOwner($actorId)) {
            $receipt = array_intersect_key($receipt, array_flip(self::PUBLIC_HEADER));
            $items = array_map(static fn (array $item): array => array_intersect_key($item, array_flip(self::PUBLIC_LINE)), $items);
        }
        $receipt['items'] = $items;
        return $receipt;
    }

    public function listForViewer(int $actorId, int $purchaseId, int $page = 1, int $perPage = 25): array
    {
        $this->permission($actorId, 'purchases.view');
        $rows = $this->goods->listForPurchase($purchaseId, min(100, max(1, $perPage)), (min(100000, max(1, $page)) - 1) * min(100, max(1, $perPage)));
        return $this->users->isOwner($actorId) ? $rows : array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(self::PUBLIC_HEADER)), $rows);
    }

    /** Read-only. Reports discrepancies and never materializes a costing row. */
    public function reconcile(int $actorId, int $purchaseId): array
    {
        $this->owner($actorId, 'purchases.receive.confirm');
        $read = function () use ($actorId, $purchaseId): array {
            $po = $this->orders->find($purchaseId);
            if (!$po) { throw new DomainException('Purchase order was not found.'); }
            $issues = [];
            try { $approval = $this->approvedHeader($po); } catch (DomainException $e) { $issues[] = $e->getMessage(); $approval = null; }
            $lines = array_column($this->orders->items($purchaseId), null, 'id');
            $totals = $this->goods->confirmedTotals($purchaseId);
            $lineReport = [];
            $products = [];
            foreach ($lines as $id => $line) {
                $received = (string) ($totals[$id]['received_qty'] ?? '0.000');
                $ordered = (string) $line['ordered_qty'];
                $remaining = Decimal::signedSubtract($ordered, $received, 3);
                $lineReport[] = ['purchase_item_id' => $id, 'ordered_qty' => $ordered,
                    'received_qty' => $received, 'stored_received_qty' => $line['received_qty'], 'remaining_qty' => $remaining];
                if (str_starts_with($remaining, '-') || Decimal::compare($received, (string) $line['received_qty'], 3) !== 0) {
                    $issues[] = 'PO line ' . $id . ' received quantity is inconsistent.';
                }
                if ($line['unit_to_base_factor_snapshot'] === null
                    || Decimal::compare(Decimal::multiply($received, (string) $line['unit_to_base_factor_snapshot'], 6), (string) ($totals[$id]['qty_base'] ?? '0.000000'), 6) !== 0) {
                    $issues[] = 'PO line ' . $id . ' base quantities are inconsistent.';
                }
                $productId = (int) $line['product_id'];
                if (!isset($products[$productId])) {
                    $products[$productId] = $this->inventory->reconcileProductValuation($actorId, $productId);
                    $productValue = $products[$productId];
                    $expectedAverage = Decimal::isZeroValue($productValue['cost_qty']) ? '0.000000'
                        : Decimal::divide($productValue['cost_value'], $productValue['cost_qty'], 6);
                    if (!$productValue['reconciled'] || $productValue['average_cost'] !== $expectedAverage) {
                        $issues[] = 'Product ' . $productId . ' valuation is inconsistent.';
                    }
                }
            }
            foreach ($totals as $id => $_) { if (!isset($lines[$id])) { $issues[] = 'A receipt references a line outside this PO.'; } }
            $seen = [];
            $evidence = $this->goods->evidence($purchaseId);
            foreach ($evidence as $row) {
                $itemId = (int) $row['goods_item_id'];
                $line = $lines[(int) $row['purchase_item_id']] ?? null;
                $expectedStatus = $row['goods_status'];
                $bad = !$line || !$row['inventory_id'] || $row['inventory_status'] !== $expectedStatus
                    || $row['inventory_type'] !== 'purchase'
                    || (int) $row['supplier_id'] !== (int) $po['supplier_id']
                    || (int) $row['location_id'] !== (int) $row['inventory_location']
                    || !$row['inventory_item_id'];
                if ($line && $approval && !$bad) {
                    try {
                        $cost = $this->approvedCost($line, $approval);
                        $actual = $row['variance_cost'] ?? $cost;
                        $base = Decimal::multiply((string) $row['received_qty'], (string) $line['unit_to_base_factor_snapshot'], 3);
                        $bad = $row['approved_cost_per_base_khr'] !== $cost || $row['landed_unit_cost_khr'] !== $actual
                            || $row['cost_per_base_khr'] !== $actual || $row['conversion_snapshot'] !== $line['unit_to_base_factor_snapshot']
                            || (int) $row['product_unit_id'] !== (int) $line['product_unit_id']
                            || $row['qty_base'] !== $base || $row['inventory_base'] !== $base || $row['inventory_qty'] !== $row['received_qty']
                            || $row['currency_snapshot'] !== $approval['currency_snapshot'] || $row['exchange_rate_snapshot'] !== $approval['exchange_rate_to_khr_snapshot'];
                    } catch (DomainException) { $bad = true; }
                }
                if ($expectedStatus === 'confirmed') {
                    $bad = $bad || isset($seen[$itemId]) || !$row['movement_id'] || !$row['cost_entry_id']
                        || !$row['confirmed_by'] || !$row['confirmed_at'] || !$row['inventory_confirmed_at']
                        || (int) $row['confirmed_by'] !== (int) $row['inventory_confirmed_by']
                        || $row['cost_source_type'] !== 'inventory_receipt'
                        || (int) $row['cost_source_id'] !== (int) $row['inventory_id']
                        || (int) $row['cost_source_line_id'] !== (int) $row['inventory_item_id']
                        || $row['costing_type'] !== 'receipt_purchase'
                        || $row['movement_qty'] !== $row['qty_base'] || $row['cost_qty'] !== $row['qty_base']
                        || (int) $row['movement_product'] !== (int) ($line['product_id'] ?? 0)
                        || (int) $row['cost_product'] !== (int) ($line['product_id'] ?? 0)
                        || (int) $row['movement_location'] !== (int) $row['location_id']
                        || $row['movement_cost'] !== $row['landed_unit_cost_khr'] || $row['cost_unit'] !== $row['landed_unit_cost_khr']
                        || $row['cost_value'] !== Decimal::multiplyRounded((string) $row['qty_base'], (string) $row['landed_unit_cost_khr'], 4);
                    $seen[$itemId] = true;
                    if ($line) {
                        $locationReport = $this->inventory->reconcileProductLocation($actorId, (int) $line['product_id'], (int) $row['location_id']);
                        if (!$locationReport['reconciled']) { $issues[] = 'Destination stock balance differs from its ledger.'; }
                    }
                } elseif ($row['movement_id'] !== null || $row['cost_entry_id'] !== null) { $bad = true; }
                if ($bad) { $issues[] = 'Goods receipt ' . $row['goods_receipt_id'] . ', line ' . $itemId . ' linkage/posting is inconsistent.'; }
            }
            if ($this->goods->hasUnlinkedInventoryRows($purchaseId)) { $issues[] = 'The PO has unlinked inventory rows or unsupported receipt reversals.'; }
            $expected = $this->fulfillmentStatus($lines);
            if (in_array($po['status'], ['approved','partially_received','received','closed'], true)
                && $po['status'] !== $expected && !($po['status'] === 'closed' && $expected === 'received')) {
                $issues[] = 'PO status does not match confirmed fulfillment.';
            }
            return ['purchase_id' => $purchaseId, 'status' => $po['status'], 'lines' => $lineReport,
                'receipts' => $evidence, 'product_values' => $products, 'issues' => array_values(array_unique($issues)), 'reconciled' => $issues === []];
        };
        if ($this->pdo->inTransaction()) { return $read(); }
        return $this->transaction($read, true);
    }

    private function approvedHeader(array $po): array
    {
        $a = $this->goods->approval((int) $po['id']);
        if (!$a || (int) $a['supplier_id_snapshot'] !== (int) $po['supplier_id']
            || $a['currency_snapshot'] !== $po['currency'] || $a['exchange_rate_to_khr_snapshot'] !== $po['exchange_rate_to_khr']
            || $a['purchase_no_snapshot'] !== $po['purchase_no'] || $a['estimated_total_snapshot'] !== $po['estimated_total']
            || !in_array($a['currency_snapshot'], ['KHR','USD'], true)
            || ($a['currency_snapshot'] === 'KHR' && $a['exchange_rate_to_khr_snapshot'] !== '1.000000')) {
            throw new DomainException('A complete, matching PO approval snapshot is required.');
        }
        Decimal::positive((string) $a['exchange_rate_to_khr_snapshot'], 6);
        return $a;
    }

    private function approvedCost(array $line, array $approval): string
    {
        $factor = Decimal::positive((string) $line['unit_to_base_factor_snapshot'], 6);
        if (Decimal::compare(Decimal::multiply((string) $line['ordered_qty'], $factor, 6), (string) $line['qty_base_snapshot'], 6) !== 0) {
            throw new DomainException('Approved PO conversion snapshot is inconsistent.');
        }
        // Net purchasing unit price / snapshotted base conversion, translated
        // with approved FX. Cost rounds half-up to six KHR decimals; posting
        // value rounds quantity * that cost to four decimals, as in Phase 3.2.
        $discount = Decimal::nonNegative((string) $line['discount_percent'], 4);
        $fraction = Decimal::divide(Decimal::subtract('100.0000', $discount, 4), '100', 6);
        $netPrice = Decimal::multiply((string) $line['unit_purchase_price'], $fraction, 10);
        $khr = Decimal::multiply($netPrice, (string) $approval['exchange_rate_to_khr_snapshot'], 16);
        return Decimal::positive(Decimal::divide($khr, $factor, 6), 6);
    }

    private function lockedLines(int $purchaseId): array
    {
        $lines = array_column($this->orders->items($purchaseId, true), null, 'id');
        if (!$lines) { throw new DomainException('Purchase order has no approved lines.'); }
        return $lines;
    }

    private function assertCounters(int $purchaseId, array $lines): void
    {
        if ($this->goods->hasLegacyReceipts($purchaseId)) {
            throw new DomainException('Historical receiving records need review before further posting.');
        }
        $totals = $this->goods->confirmedTotals($purchaseId);
        foreach ($lines as $id => $line) {
            if (Decimal::compare((string) $line['received_qty'], (string) ($totals[$id]['received_qty'] ?? '0.000'), 3) !== 0
                || $line['unit_to_base_factor_snapshot'] === null
                || Decimal::compare(Decimal::multiply((string) $line['received_qty'], (string) $line['unit_to_base_factor_snapshot'], 6),
                    (string) ($totals[$id]['qty_base'] ?? '0.000000'), 6) !== 0) {
                throw new DomainException('PO received quantities need reconciliation before receiving.');
            }
        }
        if (array_diff_key($totals, $lines)) { throw new DomainException('A confirmed receipt references a PO line outside this order.'); }
    }

    private function activeLine(array $line): void
    {
        if ($line['product_status'] !== 'active' || $line['unit_status'] !== 'active' || (bool) $line['track_batches']
            || $line['unit_precision_snapshot'] === null || (int) $line['unit_precision_snapshot'] > 3) {
            throw new DomainException('Receiving requires an active non-batch product with a valid snapshotted purchasing unit.');
        }
    }

    private function fulfillmentStatus(array $lines): string
    {
        $any = false;
        $all = $lines !== [];
        foreach ($lines as $line) {
            $any = $any || !Decimal::isZeroValue((string) $line['received_qty']);
            $all = $all && Decimal::compare((string) $line['received_qty'], (string) $line['ordered_qty'], 3) === 0;
        }
        return $all ? 'received' : ($any ? 'partially_received' : 'approved');
    }

    private function receivableStatus(array $po): void
    {
        if (!in_array($po['status'] ?? '', ['approved','partially_received'], true)) {
            throw new DomainException('Only approved or partially received purchase orders can receive stock.');
        }
    }

    private function draft(array $receipt): void
    {
        if (($receipt['status'] ?? '') !== 'draft' || empty($receipt['inventory_receipt_id'])) {
            throw new DomainException('A linked draft goods receipt is required.');
        }
    }

    private function purchaseIdForReceipt(int $receiptId): int
    {
        // Resolve before the transaction so an old consistent-read snapshot is
        // never established before acquiring the PO lock in a confirmation race.
        $receipt = $this->goods->find($receiptId);
        if (!$receipt) { throw new DomainException('Goods receipt was not found.'); }
        return (int) $receipt['purchase_id'];
    }

    private function quantityAtPrecision(string $qty, int $precision): string
    {
        $trimmed = rtrim(rtrim($qty, '0'), '.');
        // Only strip fractional zeros, never zeros in an integer quantity.
        $trimmed = str_contains($qty, '.') ? $trimmed : $qty;
        return Decimal::positive(Decimal::positive($trimmed, $precision), 3);
    }

    private function permission(int $actorId, string $permission): void
    {
        if (!$this->users->findActiveById($actorId) || !$this->users->hasPermission($actorId, $permission)) {
            throw new DomainException('You do not have permission for this receiving action.');
        }
    }

    private function owner(int $actorId, string $permission): void
    {
        $this->permission($actorId, $permission);
        if (!$this->users->isOwner($actorId)) { throw new DomainException('Only an Owner may approve receipt costs, confirm receipts, close orders, or view valuation reconciliation.'); }
    }

    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException('A valid positive record identifier is required.');
        }
        return (int) $id;
    }

    private function string(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) { throw new DomainException('A text or decimal value is required.'); }
        return (string) $value;
    }

    private function onlyKeys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) { throw new DomainException('Receipt input contains unsupported fields. Quantities and PO line IDs are the only permitted line inputs.'); }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (strlen($reason) < 3 || strlen($reason) > 500) { throw new DomainException('A reason of 3-500 bytes is required.'); }
        return $reason;
    }

    private const PUBLIC_HEADER = ['id','receipt_no','purchase_id','location_id','reference_no','status','delivery_notes','received_by','received_at','confirmed_by','confirmed_at','created_at','inventory_receipt_id'];
    private const PUBLIC_LINE = ['id','receipt_id','purchase_item_id','inventory_receipt_item_id','received_qty','qty_base','conversion_snapshot'];

    private function transaction(callable $operation, bool $readOnly = false): mixed
    {
        // Posting holds explicit document/cost/balance locks. Current reads
        // prevent a pre-cost-lock snapshot from seeing an obsolete balance
        // after waiting on another PO posting the same product.
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . ($readOnly ? 'REPEATABLE READ' : 'READ COMMITTED'));
        if ($readOnly) { $this->pdo->exec('SET TRANSACTION READ ONLY'); }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException) { throw new DomainException('Receiving conflicted with an existing delivery or posting. No changes were committed.', 0, $e); }
            throw $e;
        }
    }
}
