<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

/**
 * Phase 5.1 deliberately manages commercial intent only. It never writes a
 * stock movement, inventory valuation, goods receipt, payable, or payment.
 */
final class PurchasingService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Supplier $suppliers,
        private Product $products,
        private PurchaseOrder $purchaseOrders,
    ) {
    }

    /** @param array<string, mixed> $input @return array{purchase_id: int, created: bool} */
    public function createDraft(int $actorId, array $input): array
    {
        $this->assertCanRequest($actorId);
        $isOwner = $this->users->isOwner($actorId);
        $data = $this->validatedDraftInput($input, $isOwner);

        return $this->transaction(function () use ($actorId, $data, $isOwner): array {
            $existing = $this->purchaseOrders->findByKey($data['purchase_key'], true);
            if ($existing !== null) {
                if ((int) $existing['created_by'] !== $actorId) {
                    throw new DomainException('This purchase-request key is already in use.');
                }
                return ['purchase_id' => (int) $existing['id'], 'created' => false];
            }
            $supplier = $this->suppliers->find($data['supplier_id'], true);
            if ($supplier === null || $supplier['status'] !== 'active') {
                throw new DomainException('An active supplier is required for a new purchase order.');
            }
            $items = $this->materializeDraftItems($data['items'], $isOwner);
            $estimatedTotal = $this->estimatedTotal($items);
            $purchaseId = $this->purchaseOrders->create(
                $this->nextPurchaseNumber(),
                $data['purchase_key'],
                $data['supplier_id'],
                $data['currency'],
                $data['exchange_rate_to_khr'],
                $data['expected_delivery_date'],
                $data['notes'],
                $estimatedTotal,
                $actorId,
            );
            $this->storeItems($purchaseId, $items);
            $this->auditLog->record($actorId, 'purchase_order.draft_created', 'purchases', $purchaseId);
            return ['purchase_id' => $purchaseId, 'created' => true];
        });
    }

    /** @param array<string, mixed> $input */
    public function updateDraft(int $actorId, int $purchaseId, array $input): void
    {
        $this->assertCanRequest($actorId);
        $isOwner = $this->users->isOwner($actorId);
        $data = $this->validatedDraftInput($input, $isOwner, false);

        $this->transaction(function () use ($actorId, $purchaseId, $data, $isOwner): void {
            $purchase = $this->purchaseOrders->find($purchaseId, true);
            if ($purchase === null) {
                throw new DomainException('The purchase order was not found.');
            }
            if ($purchase['status'] !== 'draft') {
                throw new DomainException('Only a draft purchase order can be changed.');
            }
            if (!$isOwner && (int) $purchase['created_by'] !== $actorId) {
                throw new DomainException('You may only edit a purchase request that you created.');
            }
            $supplier = $this->suppliers->find($data['supplier_id'], true);
            if ($supplier === null || $supplier['status'] !== 'active') {
                throw new DomainException('An active supplier is required for a draft purchase order.');
            }
            $items = $this->materializeDraftItems($data['items'], $isOwner);
            $this->purchaseOrders->updateDraft(
                $purchaseId,
                $data['supplier_id'],
                $data['currency'],
                $data['exchange_rate_to_khr'],
                $data['expected_delivery_date'],
                $data['notes'],
                $this->estimatedTotal($items),
            );
            $this->purchaseOrders->deleteItems($purchaseId);
            $this->storeItems($purchaseId, $items);
            $this->auditLog->record($actorId, 'purchase_order.draft_updated', 'purchases', $purchaseId);
        });
    }

    /** @return array{purchase_id: int, submitted: bool} */
    public function submit(int $actorId, int $purchaseId): array
    {
        $this->assertCanRequest($actorId);

        return $this->transaction(function () use ($actorId, $purchaseId): array {
            $purchase = $this->purchaseOrders->find($purchaseId, true);
            if ($purchase === null) {
                throw new DomainException('The purchase order was not found.');
            }
            if ($purchase['status'] === 'submitted') {
                return ['purchase_id' => $purchaseId, 'submitted' => false];
            }
            if ($purchase['status'] !== 'draft') {
                throw new DomainException('Only a draft purchase order can be submitted.');
            }
            if (!$this->users->isOwner($actorId) && (int) $purchase['created_by'] !== $actorId) {
                throw new DomainException('You may only submit a purchase request that you created.');
            }
            $supplier = $this->suppliers->find((int) $purchase['supplier_id'], true);
            if ($supplier === null || $supplier['status'] !== 'active') {
                throw new DomainException('The supplier must be active before this purchase order can be submitted.');
            }
            $total = $this->assertReadyForSubmission($purchaseId);
            $this->assertEstimatedTotal($purchase['estimated_total'], $total);
            $this->purchaseOrders->markSubmitted($purchaseId, $actorId);
            $this->auditLog->record($actorId, 'purchase_order.submitted', 'purchases', $purchaseId);
            return ['purchase_id' => $purchaseId, 'submitted' => true];
        });
    }

    /** @return array{purchase_id: int, approved: bool} */
    public function approve(int $actorId, int $purchaseId): array
    {
        $this->assertOwnerPermission($actorId, 'purchases.approve', 'approve purchase orders');

        return $this->transaction(function () use ($actorId, $purchaseId): array {
            $purchase = $this->purchaseOrders->find($purchaseId, true);
            if ($purchase === null) {
                throw new DomainException('The purchase order was not found.');
            }
            if ($purchase['status'] === 'approved') {
                return ['purchase_id' => $purchaseId, 'approved' => false];
            }
            if ($purchase['status'] !== 'submitted') {
                throw new DomainException('Only a submitted purchase order can be approved.');
            }
            $supplier = $this->suppliers->find((int) $purchase['supplier_id'], true);
            if ($supplier === null) {
                throw new DomainException('The supplier reference is no longer valid.');
            }
            $total = $this->assertReadyForSubmission($purchaseId);
            $this->assertEstimatedTotal($purchase['estimated_total'], $total);
            $this->purchaseOrders->markApproved($purchaseId, $actorId);
            $this->purchaseOrders->createApprovalSnapshot(
                $purchaseId,
                (string) $purchase['purchase_no'],
                (string) $supplier['supplier_code'],
                (string) $supplier['name'],
                (string) $purchase['currency'],
                (string) $purchase['exchange_rate_to_khr'],
                $total,
                $actorId,
            );
            $this->auditLog->record($actorId, 'purchase_order.approved', 'purchases', $purchaseId);
            return ['purchase_id' => $purchaseId, 'approved' => true];
        });
    }

    public function reject(int $actorId, int $purchaseId, string $reason): void
    {
        $this->assertOwnerPermission($actorId, 'purchases.approve', 'reject purchase orders');
        $reason = $this->reason($reason, 'A rejection reason');

        $this->transaction(function () use ($actorId, $purchaseId, $reason): void {
            $purchase = $this->purchaseOrders->find($purchaseId, true);
            if ($purchase === null) {
                throw new DomainException('The purchase order was not found.');
            }
            if ($purchase['status'] !== 'submitted') {
                throw new DomainException('Only a submitted purchase order can be rejected.');
            }
            $this->purchaseOrders->markRejected($purchaseId, $actorId, $reason);
            $this->auditLog->record($actorId, 'purchase_order.rejected', 'purchases', $purchaseId, $reason);
        });
    }

    public function cancel(int $actorId, int $purchaseId, string $reason): void
    {
        $this->assertOwnerPermission($actorId, 'purchases.cancel', 'cancel purchase orders');
        $reason = $this->reason($reason, 'A cancellation reason');

        $this->transaction(function () use ($actorId, $purchaseId, $reason): void {
            $purchase = $this->purchaseOrders->find($purchaseId, true);
            if ($purchase === null) {
                throw new DomainException('The purchase order was not found.');
            }
            if (!in_array($purchase['status'], ['draft', 'submitted', 'rejected', 'approved'], true)) {
                throw new DomainException('This purchase order cannot be cancelled from its current status.');
            }
            if ($this->purchaseOrders->hasReceivingOrPayableReference($purchaseId)) {
                throw new DomainException('A purchase order with a goods receipt or payable cannot be cancelled. Use the later receiving correction workflow.');
            }
            $this->purchaseOrders->markCancelled($purchaseId, $actorId, $reason);
            $this->auditLog->record($actorId, 'purchase_order.cancelled', 'purchases', $purchaseId, $reason);
        });
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function listForViewer(int $actorId, string $search, string $status, int $page, int $perPage): array
    {
        $this->assertCanView($actorId);
        $search = $this->search($search);
        $status = in_array($status, self::STATUSES, true) ? $status : 'all';
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $items = $this->purchaseOrders->list($search, $status, $perPage, ($page - 1) * $perPage);
        if (!$this->users->isOwner($actorId)) {
            $items = array_map([$this, 'withoutCosts'], $items);
        }
        return ['items' => $items, 'total' => $this->purchaseOrders->count($search, $status)];
    }

    /** @return array<string, mixed> */
    public function forViewer(int $actorId, int $purchaseId): array
    {
        $this->assertCanView($actorId);
        $purchase = $this->purchaseOrders->find($purchaseId);
        if ($purchase === null) {
            throw new DomainException('The purchase order was not found.');
        }
        $purchase['items'] = $this->purchaseOrders->items($purchaseId);
        if (!$this->users->isOwner($actorId)) {
            $purchase = $this->withoutCosts($purchase);
            $purchase['items'] = array_map([$this, 'withoutCosts'], $purchase['items']);
        }
        return $purchase;
    }

    /** @return list<array{id: int, sku: string, product_name: string, unit_name: string, unit_code: string}> */
    public function activeProductUnitsForRequest(int $actorId): array
    {
        $this->assertCanRequest($actorId);
        return $this->pdo->query(
            "SELECT pu.id, p.sku, p.name AS product_name, u.name AS unit_name, u.code AS unit_code
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id AND p.status = 'active'
             JOIN units u ON u.id = pu.unit_id AND u.status = 'active'
             ORDER BY p.name, u.name, pu.id"
        )->fetchAll();
    }

    /** @param list<array<string, mixed>> $items */
    private function storeItems(int $purchaseId, array $items): void
    {
        foreach ($items as $item) {
            $this->purchaseOrders->createItem(
                $purchaseId,
                $item['product_unit_id'],
                $item['ordered_qty'],
                $item['unit_purchase_price'],
                $item['discount_percent'],
                $item['product_sku_snapshot'],
                $item['product_name_snapshot'],
                $item['unit_code_snapshot'],
                $item['unit_name_snapshot'],
                $item['unit_to_base_factor_snapshot'],
                $item['qty_base_snapshot'],
                $item['line_subtotal'],
                $item['line_discount_amount'],
                $item['line_total'],
                $item['unit_precision_snapshot'],
            );
        }
    }

    /** @param list<array{product_unit_id: int, ordered_qty: string, unit_purchase_price: ?string, discount_percent: string}> $items @return list<array<string, mixed>> */
    private function materializeDraftItems(array $items, bool $isOwner): array
    {
        $materialized = [];
        foreach ($items as $item) {
            $productUnit = $this->products->findProductUnit($item['product_unit_id'], true);
            if ($productUnit === null || $productUnit['product_status'] !== 'active' || $productUnit['unit_status'] !== 'active') {
                throw new DomainException('Each purchase-order item must use an active product and product-specific active unit.');
            }
            $quantity = Decimal::positive($item['ordered_qty'], (int) $productUnit['precision_digits']);
            $factor = Decimal::positive((string) $productUnit['to_base_factor'], 6);
            $quantityBase = Decimal::multiply($quantity, $factor, 6);
            $price = $item['unit_purchase_price'];
            if (!$isOwner && $price !== null) {
                throw new DomainException('Staff purchase requests cannot include purchasing prices.');
            }
            $lineSubtotal = null;
            $lineDiscount = null;
            $lineTotal = null;
            if ($price !== null) {
                $lineSubtotal = Decimal::multiplyRounded($quantity, $price, 4);
                $discountFraction = Decimal::divide($item['discount_percent'], '100.0000', 6);
                $lineDiscount = Decimal::multiplyRounded($lineSubtotal, $discountFraction, 4);
                $lineTotal = Decimal::subtract($lineSubtotal, $lineDiscount, 4);
            }
            $materialized[] = [
                'product_unit_id' => $item['product_unit_id'],
                'ordered_qty' => $quantity,
                'unit_purchase_price' => $price,
                'unit_precision_snapshot' => (int) $productUnit['precision_digits'],
                'discount_percent' => $item['discount_percent'],
                'product_sku_snapshot' => (string) $productUnit['product_sku'],
                'product_name_snapshot' => (string) $productUnit['product_name'],
                'unit_code_snapshot' => (string) $productUnit['unit_code'],
                'unit_name_snapshot' => (string) $productUnit['unit_name'],
                'unit_to_base_factor_snapshot' => $factor,
                'qty_base_snapshot' => $quantityBase,
                'line_subtotal' => $lineSubtotal,
                'line_discount_amount' => $lineDiscount,
                'line_total' => $lineTotal,
            ];
        }
        return $materialized;
    }

    /** @param list<array<string, mixed>> $items */
    private function estimatedTotal(array $items): ?string
    {
        $total = '0.0000';
        foreach ($items as $item) {
            if ($item['line_total'] === null) {
                return null;
            }
            $total = Decimal::add($total, (string) $item['line_total'], 4);
        }
        return $total;
    }

    private function assertReadyForSubmission(int $purchaseId): string
    {
        $items = $this->purchaseOrders->items($purchaseId, true);
        if ($items === []) {
            throw new DomainException('A purchase order needs at least one item before submission.');
        }
        $total = '0.0000';
        foreach ($items as $item) {
            if (
                $item['unit_purchase_price'] === null || $item['line_subtotal'] === null
                || $item['line_discount_amount'] === null || $item['line_total'] === null
                || $item['unit_to_base_factor_snapshot'] === null || $item['qty_base_snapshot'] === null
            ) {
                throw new DomainException('An Owner must set a valid purchasing price for every line before submission.');
            }
            $price = Decimal::positive((string) $item['unit_purchase_price'], 4);
            $quantity = Decimal::positive((string) $item['ordered_qty'], 3);
            $discount = Decimal::nonNegative((string) $item['discount_percent'], 4, 7);
            if (Decimal::compare($discount, '100.0000', 4) > 0) {
                throw new DomainException('A line discount cannot exceed 100 percent.');
            }
            $subtotal = Decimal::multiplyRounded($quantity, $price, 4);
            $discountAmount = Decimal::multiplyRounded($subtotal, Decimal::divide($discount, '100.0000', 6), 4);
            $lineTotal = Decimal::subtract($subtotal, $discountAmount, 4);
            if (
                Decimal::compare((string) $item['line_subtotal'], $subtotal, 4) !== 0
                || Decimal::compare((string) $item['line_discount_amount'], $discountAmount, 4) !== 0
                || Decimal::compare((string) $item['line_total'], $lineTotal, 4) !== 0
            ) {
                throw new DomainException('The saved purchase-order totals no longer match its line snapshots.');
            }
            $total = Decimal::add($total, $lineTotal, 4);
        }
        return $total;
    }

    private function assertEstimatedTotal(mixed $storedTotal, string $calculatedTotal): void
    {
        if ($storedTotal === null || Decimal::compare((string) $storedTotal, $calculatedTotal, 4) !== 0) {
            throw new DomainException('The saved purchase-order estimated total no longer matches its line snapshots.');
        }
    }

    /** @param array<string, mixed> $input @return array{purchase_key: string, supplier_id: int, currency: string, exchange_rate_to_khr: string, expected_delivery_date: ?string, notes: ?string, items: list<array{product_unit_id: int, ordered_qty: string, unit_purchase_price: ?string, discount_percent: string}>} */
    private function validatedDraftInput(array $input, bool $isOwner, bool $requirePurchaseKey = true): array
    {
        $key = trim((string) ($input['purchase_key'] ?? ''));
        if ($requirePurchaseKey && !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) {
            throw new DomainException('Purchase-request key must contain 8-80 letters, numbers, underscores, or hyphens.');
        }
        if (!$requirePurchaseKey && $key !== '' && !preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) {
            throw new DomainException('Purchase-request key must contain 8-80 letters, numbers, underscores, or hyphens.');
        }
        $supplierId = filter_var($input['supplier_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($supplierId === false || $supplierId === null) {
            throw new DomainException('An active supplier is required.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? 'KHR')));
        if (!in_array($currency, ['KHR', 'USD'], true)) {
            throw new DomainException('Purchase orders currently support KHR or USD only.');
        }
        $exchangeRate = Decimal::positive((string) ($input['exchange_rate_to_khr'] ?? ''), 6);
        if ($currency === 'KHR' && Decimal::compare($exchangeRate, '1.000000', 6) !== 0) {
            throw new DomainException('KHR purchase orders must use an exchange rate of 1.000000.');
        }
        $expectedDeliveryDate = $this->date($input['expected_delivery_date'] ?? null);
        $notes = $this->optionalText($input['notes'] ?? null, 1000, 'Purchase-order notes');
        $rawItems = $input['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === [] || count($rawItems) > 100) {
            throw new DomainException('A purchase order needs 1-100 line items.');
        }
        $items = [];
        $seenProductUnits = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                throw new DomainException('Each purchase-order item is invalid.');
            }
            $productUnitId = filter_var($rawItem['product_unit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productUnitId === false || $productUnitId === null) {
                throw new DomainException('A valid product purchasing unit is required for every line.');
            }
            if (isset($seenProductUnits[$productUnitId])) {
                throw new DomainException('A product purchasing unit may appear only once on a purchase order.');
            }
            $seenProductUnits[$productUnitId] = true;
            $priceInput = trim((string) ($rawItem['unit_purchase_price'] ?? ''));
            if (!$isOwner && $priceInput !== '') {
                throw new DomainException('Staff purchase requests cannot submit purchasing prices.');
            }
            $discountInput = trim((string) ($rawItem['discount_percent'] ?? '0'));
            if ($discountInput === '') {
                $discountInput = '0';
            }
            $discount = Decimal::nonNegative($discountInput, 4, 7);
            if (Decimal::compare($discount, '100.0000', 4) > 0) {
                throw new DomainException('A line discount cannot exceed 100 percent.');
            }
            if (!$isOwner && !Decimal::isZeroValue($discount)) {
                throw new DomainException('Staff purchase requests cannot submit purchasing discounts.');
            }
            $items[] = [
                'product_unit_id' => (int) $productUnitId,
                'ordered_qty' => trim((string) ($rawItem['ordered_qty'] ?? '')),
                'unit_purchase_price' => $priceInput === '' ? null : Decimal::positive($priceInput, 4),
                'discount_percent' => $discount,
            ];
        }

        return [
            'purchase_key' => $key,
            'supplier_id' => (int) $supplierId,
            'currency' => $currency,
            'exchange_rate_to_khr' => $exchangeRate,
            'expected_delivery_date' => $expectedDeliveryDate,
            'notes' => $notes,
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function withoutCosts(array $record): array
    {
        foreach ([
            'currency', 'exchange_rate_to_khr', 'estimated_total', 'unit_purchase_price', 'discount_percent',
            'line_subtotal', 'line_discount_amount', 'line_total', 'rejection_reason', 'cancellation_reason',
        ] as $field) {
            unset($record[$field]);
        }
        return $record;
    }

    private function assertCanView(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'purchases.view')) {
            throw new DomainException('You do not have permission to view purchase orders.');
        }
    }

    private function assertCanRequest(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'purchases.request')) {
            throw new DomainException('You do not have permission to create purchase requests.');
        }
    }

    private function assertOwnerPermission(int $actorId, string $permission, string $action): void
    {
        if (!$this->users->isOwner($actorId) || !$this->users->hasPermission($actorId, $permission)) {
            throw new DomainException(sprintf('Only an Owner may %s.', $action));
        }
    }

    private function nextPurchaseNumber(): string
    {
        do {
            $number = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        } while ($this->purchaseOrders->purchaseNoExists($number));
        return $number;
    }

    private function date(mixed $value): ?string
    {
        $date = trim((string) $value);
        if ($date === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new DomainException('Expected delivery date must use YYYY-MM-DD.');
        }
        return $date;
    }

    private function reason(string $reason, string $label): string
    {
        $reason = trim($reason);
        if ($this->length($reason) < 3 || $this->length($reason) > 500) {
            throw new DomainException($label . ' of 3-500 characters is required.');
        }
        return $reason;
    }

    private function optionalText(mixed $value, int $maximum, string $label): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if ($this->length($text) > $maximum) {
            throw new DomainException(sprintf('%s may not exceed %d characters.', $label, $maximum));
        }
        return $text;
    }

    private function search(string $search): string
    {
        $search = trim($search);
        return $this->length($search) > 100 ? substr($search, 0, 100) : $search;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @var list<string> */
    private const STATUSES = [
        'draft', 'submitted', 'approved', 'partially_received', 'received', 'closed', 'rejected', 'cancelled',
    ];

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
            throw new DomainException('The purchase order could not be saved because it conflicts with an existing record.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
