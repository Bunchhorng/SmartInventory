<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryReceipt;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

/** Phase 6.1 approval-controlled, immutable internal stock transfers. */
final class StockTransferService
{
    /** @param null|\Closure():void $afterInventoryPosting Test-only transaction fault hook. */
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $audit,
        private StockTransfer $transfers,
        private InventoryReceipt $inventoryReceipts,
        private InventoryService $inventory,
        private ?\Closure $afterInventoryPosting = null,
    ) {
    }

    /** @param array<string, mixed> $input @return array{transfer_id:int,created:bool} */
    public function createDraft(int $actorId, array $input): array
    {
        $this->permission($actorId, 'request');
        $this->keys($input, ['_csrf', 'document_key', 'source_location_id', 'destination_location_id', 'items']);
        $documentKey = $this->documentKey($input['document_key'] ?? null);
        $sourceId = $this->id($input['source_location_id'] ?? null);
        $destinationId = $this->id($input['destination_location_id'] ?? null);
        if ($sourceId === $destinationId) {
            throw new DomainException('Transfer source and destination locations must be different.');
        }
        if (!is_array($input['items'] ?? null) || $input['items'] === [] || count($input['items']) > 100) {
            throw new DomainException('A transfer requires between one and 100 item lines.');
        }

        return $this->transaction(function () use ($actorId, $documentKey, $sourceId, $destinationId, $input): array {
            $this->eligibleLocations($sourceId, $destinationId, true);
            $requested = [];
            foreach ($input['items'] as $line) {
                if (!is_array($line)) {
                    throw new DomainException('Transfer item data is invalid.');
                }
                $this->keys($line, ['product_unit_id', 'quantity']);
                $unitId = $this->id($line['product_unit_id'] ?? null);
                if (isset($requested[$unitId])) {
                    throw new DomainException('A product unit may occur only once in a transfer request.');
                }
                $requested[$unitId] = $this->text($line['quantity'] ?? null);
            }
            ksort($requested, SORT_NUMERIC);

            $lines = [];
            $products = [];
            foreach ($requested as $productUnitId => $requestedQuantity) {
                $productUnit = $this->inventoryReceipts->productUnit((int) $productUnitId, true);
                if (
                    $productUnit === null
                    || $productUnit['product_status'] !== 'active'
                    || $productUnit['unit_status'] !== 'active'
                    || (bool) $productUnit['track_batches']
                ) {
                    throw new DomainException('Transfers require active, non-batch products and active units.');
                }
                $productId = (int) $productUnit['product_id'];
                if (isset($products[$productId])) {
                    throw new DomainException('A product may occur only once in a transfer, even when different units are selected.');
                }
                $quantity = $this->quantity($requestedQuantity, (int) $productUnit['precision_digits']);
                $quantityBase = Decimal::positive(Decimal::multiply($quantity, (string) $productUnit['to_base_factor'], 3), 3);
                $products[$productId] = true;
                $lines[] = [
                    'product_unit_id' => (int) $productUnit['id'],
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'qty_base' => $quantityBase,
                    'conversion_snapshot' => (string) $productUnit['to_base_factor'],
                    'unit_precision_snapshot' => (int) $productUnit['precision_digits'],
                ];
            }
            usort($lines, static fn (array $left, array $right): int => $left['product_id'] <=> $right['product_id']);
            $requestHash = $this->digest([$sourceId, $destinationId, $lines]);
            $existing = $this->transfers->byKey($documentKey);
            if ($existing !== null) {
                if ((int) $existing['created_by'] !== $actorId || $existing['request_hash'] !== $requestHash) {
                    throw new DomainException('Transfer request key belongs to another request.');
                }
                return ['transfer_id' => (int) $existing['id'], 'created' => false];
            }
            $transferId = $this->transfers->create([
                'transfer_no' => $this->transferNo(),
                'document_key' => $documentKey,
                'request_hash' => $requestHash,
                'from_location_id' => $sourceId,
                'to_location_id' => $destinationId,
                'created_by' => $actorId,
            ]);
            foreach ($lines as $line) {
                $this->transfers->createItem(['transfer_id' => $transferId] + $line);
            }
            $this->audit->record($actorId, 'stock_transfer.draft_created', 'stock_transfers', $transferId);
            return ['transfer_id' => $transferId, 'created' => true];
        });
    }

    /** @return array{transfer_id:int,submitted:bool} */
    public function submit(int $actorId, int $transferId): array
    {
        $this->permission($actorId, 'request');
        return $this->transaction(function () use ($actorId, $transferId): array {
            $document = $this->locked($transferId);
            if (!$this->users->isOwner($actorId) && (int) $document['created_by'] !== $actorId) {
                throw new DomainException('Only the requester or an Owner may submit this transfer.');
            }
            $items = $this->transfers->items($transferId, true);
            if ($document['status'] === 'submitted') {
                $this->validateDocument($document, $items);
                return ['transfer_id' => $transferId, 'submitted' => false];
            }
            $this->state($document, ['draft']);
            $this->validateDocument($document, $items);
            $this->transfers->transition($document, 'submitted', $actorId);
            $this->audit->record($actorId, 'stock_transfer.submitted', 'stock_transfers', $transferId);
            return ['transfer_id' => $transferId, 'submitted' => true];
        });
    }

    /** @return array{transfer_id:int,approved:bool} */
    public function approve(int $actorId, int $transferId): array
    {
        $this->owner($actorId, 'approve');
        return $this->transaction(function () use ($actorId, $transferId): array {
            $document = $this->locked($transferId);
            $items = $this->transfers->items($transferId, true);
            if ($document['status'] === 'approved') {
                $this->assertApproval($document, $items);
                return ['transfer_id' => $transferId, 'approved' => false];
            }
            $this->state($document, ['submitted']);
            $this->validateDocument($document, $items);
            $this->transfers->createApproval($transferId, $this->contentHash($document, $items), $actorId);
            $this->transfers->transition($document, 'approved', $actorId);
            $this->audit->record($actorId, 'stock_transfer.approved', 'stock_transfers', $transferId);
            return ['transfer_id' => $transferId, 'approved' => true];
        });
    }

    /** @return array{transfer_id:int,confirmed:bool} */
    public function confirm(int $actorId, int $transferId): array
    {
        $this->owner($actorId, 'confirm');
        return $this->transaction(function () use ($actorId, $transferId): array {
            $document = $this->locked($transferId);
            $items = $this->transfers->items($transferId, true);
            if ($document['status'] === 'confirmed') {
                $this->assertPostingEvidence($document, $items);
                return ['transfer_id' => $transferId, 'confirmed' => false];
            }
            $this->state($document, ['approved']);
            $this->assertApproval($document, $items);
            $this->validateDocument($document, $items);
            $postings = $this->inventory->postLockedStockTransfer($actorId, $document, $items);
            if ($this->afterInventoryPosting !== null) {
                ($this->afterInventoryPosting)();
            }
            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $posting = $postings[$itemId] ?? null;
                if (!is_array($posting)) {
                    throw new \LogicException('Stock-transfer posting result is incomplete.');
                }
                $this->transfers->createPosting([
                    'transfer_item_id' => $itemId,
                    'source_stock_movement_id' => $posting['source_movement_id'],
                    'destination_stock_movement_id' => $posting['destination_movement_id'],
                    'source_cost_entry_id' => $posting['source_cost_entry_id'],
                    'destination_cost_entry_id' => $posting['destination_cost_entry_id'],
                    'posting_key' => 'stock-transfer:' . $document['id'] . ':line:' . $itemId,
                    'qty_base' => $item['qty_base'],
                    'unit_cost_khr' => $posting['unit_cost_khr'],
                    'carrying_value_khr' => $posting['value_khr'],
                    'posted_by' => $actorId,
                ]);
            }
            $this->transfers->markConfirmed($transferId, $actorId);
            $this->audit->record($actorId, 'stock_transfer.confirmed', 'stock_transfers', $transferId);
            return ['transfer_id' => $transferId, 'confirmed' => true];
        });
    }

    public function reject(int $actorId, int $transferId, string $reason): void
    {
        $this->terminate($actorId, $transferId, 'rejected', $reason, ['submitted', 'approved']);
    }

    public function cancel(int $actorId, int $transferId, string $reason): void
    {
        $this->terminate($actorId, $transferId, 'cancelled', $reason, ['draft', 'submitted', 'approved']);
    }

    /** @return list<array<string, mixed>> */
    public function listForViewer(int $actorId, string $status = 'all', int $page = 1, int $perPage = 25): array
    {
        $this->permission($actorId, 'view');
        if (!in_array($status, ['all', 'draft', 'submitted', 'approved', 'confirmed', 'rejected', 'cancelled'], true)) {
            throw new DomainException('Transfer status filter is invalid.');
        }
        return $this->transfers->list($status, max(1, min(100, $perPage)), (max(1, $page) - 1) * max(1, min(100, $perPage)));
    }

    /** @return array<string, mixed> */
    public function forViewer(int $actorId, int $transferId): array
    {
        $this->permission($actorId, 'view');
        $document = $this->transfers->find($transferId);
        if ($document === null) {
            throw new DomainException('Stock transfer not found.');
        }
        $items = $this->transfers->items($transferId);
        $result = [
            'transfer' => $this->publicHeader($document),
            'items' => array_map(fn (array $item): array => $this->publicItem($item), $items),
        ];
        if ($this->users->isOwner($actorId)) {
            $result['approval'] = $this->transfers->approval($transferId);
            $result['postings'] = $this->transfers->postings($transferId);
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function reconcile(int $actorId, int $transferId): array
    {
        $this->owner($actorId, 'reconcile');
        return $this->transaction(function () use ($transferId): array {
            $document = $this->transfers->find($transferId);
            if ($document === null) {
                throw new DomainException('Stock transfer not found.');
            }
            $items = $this->transfers->items($transferId);
            $evidence = $this->transfers->evidence($transferId);
            $unmatched = 0;
            $quantityDelta = '0.000';
            $valueDelta = '0.0000';
            foreach ($evidence as $row) {
                $quantity = Decimal::positive((string) $row['qty_base'], 3);
                $value = $row['carrying_value_khr'] === null ? '0.0000' : Decimal::nonNegative((string) $row['carrying_value_khr'], 4);
                $matched = $row['posting_id'] !== null
                    && (int) $row['source_product_id'] === (int) $row['product_id']
                    && (int) $row['destination_product_id'] === (int) $row['product_id']
                    && (int) $row['source_location_id'] === (int) $document['from_location_id']
                    && (int) $row['destination_location_id'] === (int) $document['to_location_id']
                    && (string) $row['source_qty_delta'] === '-' . $quantity
                    && (string) $row['destination_qty_delta'] === $quantity
                    && $row['source_type'] === 'stock_transfer'
                    && $row['destination_type'] === 'stock_transfer'
                    && (int) $row['source_id'] === (int) $document['id']
                    && (int) $row['destination_id'] === (int) $document['id']
                    && (int) $row['source_line_id'] === (int) $row['transfer_item_id']
                    && (int) $row['destination_line_id'] === (int) $row['transfer_item_id']
                    && (string) $row['source_cost_qty'] === '-' . $quantity
                    && (string) $row['destination_cost_qty'] === $quantity
                    && (string) $row['source_value_delta'] === '-' . $value
                    && (string) $row['destination_value_delta'] === $value
                    && $row['source_costing_type'] === 'stock_transfer'
                    && $row['destination_costing_type'] === 'stock_transfer';
                if (!$matched) {
                    ++$unmatched;
                }
                if ($row['source_qty_delta'] !== null && $row['destination_qty_delta'] !== null) {
                    $quantityDelta = $this->addSigned($quantityDelta, Decimal::signedSubtract(
                        Decimal::nonNegative(ltrim((string) $row['destination_qty_delta'], '-'), 3),
                        Decimal::nonNegative(ltrim((string) $row['source_qty_delta'], '-'), 3),
                        3,
                    ), 3);
                }
                if ($row['source_value_delta'] !== null && $row['destination_value_delta'] !== null) {
                    $valueDelta = $this->addSigned($valueDelta, Decimal::signedSubtract(
                        Decimal::nonNegative(ltrim((string) $row['destination_value_delta'], '-'), 4),
                        Decimal::nonNegative(ltrim((string) $row['source_value_delta'], '-'), 4),
                        4,
                    ), 4);
                }
            }
            $approval = $this->transfers->approval($transferId);
            $audits = $this->transfers->audits($transferId);
            $confirmedAudit = array_filter($audits, static fn (array $audit): bool => $audit['action'] === 'stock_transfer.confirmed');
            $approvalOk = $document['status'] !== 'approved' && $document['status'] !== 'confirmed'
                ? true
                : $approval !== null && $approval['content_hash'] === $this->contentHash($document, $items);
            $confirmedEvidenceOk = $document['status'] !== 'confirmed'
                ? count($evidence) === 0
                : count($evidence) === count($items) && $unmatched === 0 && count($confirmedAudit) === 1;
            return [
                'read_only' => true,
                'transfer_id' => $transferId,
                'status' => $document['status'],
                'item_count' => count($items),
                'posting_count' => count(array_filter($evidence, static fn (array $row): bool => $row['posting_id'] !== null)),
                'unmatched_pairs' => $unmatched,
                'company_quantity_delta' => $quantityDelta,
                'valuation_delta_khr' => $valueDelta,
                'approval_consistent' => $approvalOk,
                'confirmation_audit_count' => count($confirmedAudit),
                'reconciled' => $approvalOk && $confirmedEvidenceOk && $quantityDelta === '0.000' && $valueDelta === '0.0000',
            ];
        }, true);
    }

    private function terminate(int $actorId, int $transferId, string $status, string $reason, array $allowed): void
    {
        $this->owner($actorId, 'approve');
        $reason = trim($reason);
        if (strlen($reason) < 3 || strlen($reason) > 500) {
            throw new DomainException('A termination reason of 3-500 bytes is required.');
        }
        $this->transaction(function () use ($actorId, $transferId, $status, $reason, $allowed): void {
            $document = $this->locked($transferId);
            $this->state($document, $allowed);
            $this->transfers->transition($document, $status, $actorId, $reason);
            $this->audit->record($actorId, 'stock_transfer.' . $status, 'stock_transfers', $transferId, $reason);
        });
    }

    /** @param array<string, mixed> $document @param list<array<string, mixed>> $items */
    private function validateDocument(array $document, array $items): void
    {
        $this->eligibleLocations((int) $document['from_location_id'], (int) $document['to_location_id'], true);
        if ($items === [] || count($items) > 100) {
            throw new DomainException('A transfer requires between one and 100 item lines.');
        }
        $products = [];
        foreach ($items as $item) {
            if (
                $item['product_status'] !== 'active'
                || $item['unit_status'] !== 'active'
                || (bool) $item['track_batches']
                || (int) $item['current_product_id'] !== (int) $item['product_id']
            ) {
                throw new DomainException('Transfers require active, non-batch products and active units.');
            }
            $productId = (int) $item['product_id'];
            if (isset($products[$productId])) {
                throw new DomainException('A product may occur only once per transfer.');
            }
            $products[$productId] = true;
            $quantity = $this->quantity((string) $item['quantity'], (int) $item['unit_precision_snapshot']);
            $expectedBase = Decimal::positive(Decimal::multiply($quantity, (string) $item['conversion_snapshot'], 3), 3);
            if ($expectedBase !== Decimal::positive((string) $item['qty_base'], 3)) {
                throw new DomainException('Transfer item conversion evidence is inconsistent.');
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function eligibleLocations(int $sourceId, int $destinationId, bool $lock): array
    {
        if ($sourceId === $destinationId) {
            throw new DomainException('Transfer source and destination locations must be different.');
        }
        $locations = $this->transfers->locations($sourceId, $destinationId, $lock);
        if (count($locations) !== 2) {
            throw new DomainException('Both transfer locations must exist.');
        }
        foreach ($locations as $location) {
            if (!(bool) $location['is_active'] || !in_array($location['type'], ['retail', 'storage'], true)) {
                throw new DomainException('Transfers require active retail or storage locations.');
            }
        }
        return $locations;
    }

    /** @return array<string, mixed> */
    private function locked(int $transferId): array
    {
        $document = $this->transfers->find($transferId, true);
        if ($document === null) {
            throw new DomainException('Stock transfer not found.');
        }
        return $document;
    }

    /** @param array<string, mixed> $document @param list<array<string, mixed>> $items */
    private function assertApproval(array $document, array $items): void
    {
        $approval = $this->transfers->approval((int) $document['id']);
        if ($approval === null || $approval['content_hash'] !== $this->contentHash($document, $items)
            || (int) $approval['approved_by'] !== (int) $document['approved_by']) {
            throw new DomainException('Transfer differs from its immutable Owner approval.');
        }
    }

    /** @param array<string, mixed> $document @param list<array<string, mixed>> $items */
    private function assertPostingEvidence(array $document, array $items): void
    {
        $evidence = $this->transfers->evidence((int) $document['id']);
        if (count($evidence) !== count($items) || count(array_filter($evidence, static fn (array $row): bool => $row['posting_id'] === null)) !== 0) {
            throw new DomainException('Confirmed transfer posting evidence is incomplete.');
        }
    }

    /** @param array<string, mixed> $document @param list<array<string, mixed>> $items */
    private function contentHash(array $document, array $items): string
    {
        $header = array_intersect_key($document, array_flip(['id', 'from_location_id', 'to_location_id', 'created_by']));
        ksort($header);
        $snapshots = [];
        foreach ($items as $item) {
            $snapshots[] = array_intersect_key($item, array_flip([
                'id', 'product_unit_id', 'product_id', 'quantity', 'qty_base', 'conversion_snapshot', 'unit_precision_snapshot',
            ]));
        }
        usort($snapshots, static fn (array $left, array $right): int => (int) $left['product_id'] <=> (int) $right['product_id']);
        return $this->digest([$header, $snapshots]);
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function publicHeader(array $document): array
    {
        return array_intersect_key($document, array_flip([
            'id', 'transfer_no', 'from_location_id', 'to_location_id', 'status', 'created_by', 'created_at',
            'submitted_at', 'approved_at', 'confirmed_at', 'terminated_at',
        ]));
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function publicItem(array $item): array
    {
        return array_intersect_key($item, array_flip([
            'id', 'transfer_id', 'product_unit_id', 'product_id', 'quantity', 'qty_base',
            'conversion_snapshot', 'unit_precision_snapshot',
        ]));
    }

    private function state(array $document, array $allowed): void
    {
        if (!in_array($document['status'], $allowed, true)) {
            throw new DomainException('Invalid stock-transfer state transition.');
        }
    }

    private function permission(int $actorId, string $action): void
    {
        if (!$this->users->findActiveById($actorId) || !$this->users->hasPermission($actorId, 'stock_transfers.' . $action)) {
            throw new DomainException('Not authorized for this stock-transfer action.');
        }
    }

    private function owner(int $actorId, string $action): void
    {
        $this->permission($actorId, $action);
        if (!$this->users->isOwner($actorId)) {
            throw new DomainException('Only an active Owner may approve, confirm or reconcile internal stock transfers.');
        }
    }

    private function id(mixed $value): int
    {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException('A positive record identifier is required.');
        }
        return (int) $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new DomainException('Text or decimal input is required.');
        }
        return (string) $value;
    }

    private function documentKey(mixed $value): string
    {
        $value = $this->text($value);
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $value)) {
            throw new DomainException('A transfer request key of 8-80 letters, digits, dashes or underscores is required.');
        }
        return $value;
    }

    private function quantity(string $value, int $precision): string
    {
        if ($precision < 0 || $precision > 3) {
            throw new DomainException('Unsupported transfer unit precision.');
        }
        $value = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        return Decimal::positive(Decimal::positive($value, $precision), 3);
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new DomainException('Unsupported input. Transfers accept only locations, product units and quantities.');
        }
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function transferNo(): string
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $number = 'ST-' . (new \DateTimeImmutable())->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
            if (!$this->transfers->transferNoExists($number)) {
                return $number;
            }
        }
        throw new DomainException('Unable to allocate a unique transfer number.');
    }

    private function addSigned(string $left, string $right, int $scale): string
    {
        $leftNegative = str_starts_with($left, '-');
        $rightNegative = str_starts_with($right, '-');
        $leftMagnitude = Decimal::nonNegative(ltrim($left, '-'), $scale);
        $rightMagnitude = Decimal::nonNegative(ltrim($right, '-'), $scale);
        if ($leftNegative === $rightNegative) {
            $sum = Decimal::add($leftMagnitude, $rightMagnitude, $scale);
            return $leftNegative && $sum !== str_repeat('0', 1) . ($scale > 0 ? '.' . str_repeat('0', $scale) : '') ? '-' . $sum : $sum;
        }
        $comparison = Decimal::compare($leftMagnitude, $rightMagnitude, $scale);
        if ($comparison === 0) {
            return $scale === 0 ? '0' : '0.' . str_repeat('0', $scale);
        }
        $difference = $comparison > 0
            ? Decimal::subtract($leftMagnitude, $rightMagnitude, $scale)
            : Decimal::subtract($rightMagnitude, $leftMagnitude, $scale);
        return ($comparison > 0 ? $leftNegative : $rightNegative) ? '-' . $difference : $difference;
    }

    /** @template T @param callable():T $operation @return T */
    private function transaction(callable $operation, bool $readOnly = false): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Stock-transfer service requires its own transaction.');
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . ($readOnly ? 'REPEATABLE READ' : 'READ COMMITTED'));
        if ($readOnly) {
            $this->pdo->exec('SET TRANSACTION READ ONLY');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \PDOException) {
                throw new DomainException('Stock transfer conflicted with existing records. No changes were committed.', 0, $exception);
            }
            throw $exception;
        }
    }
}
