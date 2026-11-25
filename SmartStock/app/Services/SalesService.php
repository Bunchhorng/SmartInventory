<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryReceipt;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Models\ValuationPreflightReport;
use App\Support\Decimal;
use DomainException;
use PDO;

final class SalesService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Customer $customers,
        private Payment $payments,
        private Sale $sales,
        private InventoryReceipt $stockLedger,
        private MovingAverageCostService $movingAverageCosts,
        private ValuationPreflightReport $valuationPreflight,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function createDraftSale(int $actorId, array $input): int
    {
        $this->assertCanCreate($actorId);
        $channel = $this->saleChannel((string) ($input['sale_channel'] ?? ''));
        $settlementType = $this->settlementType((string) ($input['settlement_type'] ?? 'cash'));
        $customerId = $this->nullablePositiveId($input['customer_id'] ?? null, 'Customer identifier is invalid.');
        $creditOverride = ($input['credit_limit_override'] ?? '') === '1' || ($input['credit_limit_override'] ?? false) === true;
        $creditOverrideReason = trim((string) ($input['credit_override_reason'] ?? ''));
        if ($settlementType === 'credit' && $customerId === null) {
            throw new DomainException('Credit sales require an identifiable active customer.');
        }
        if ($settlementType !== 'credit' && $creditOverride) {
            throw new DomainException('A credit-limit override is valid only for a credit sale.');
        }
        if ($creditOverride) {
            if (!$this->users->hasPermission($actorId, 'credit.manage')) {
                throw new DomainException('Only an authorized Owner may approve a credit-limit override.');
            }
            if ($creditOverrideReason === '' || $this->length($creditOverrideReason) > 500) {
                throw new DomainException('A credit-limit override reason of 1-500 characters is required.');
            }
        }
        $items = $input['items'] ?? null;
        if (!is_array($items) || $items === [] || count($items) > 100) {
            throw new DomainException('A sale requires between one and 100 items.');
        }

        return $this->transaction(function () use ($actorId, $channel, $settlementType, $customerId, $creditOverride, $creditOverrideReason, $items): int {
            if ($customerId !== null && $this->customers->active($customerId, true) === null) {
                throw new DomainException('The selected customer is not active.');
            }
            /** @var list<array<string, string|int|bool>> $lines */
            $lines = [];
            $productIds = [];
            $subtotal = '0.0000';
            $discountTotal = '0.0000';
            $total = '0.0000';
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new DomainException('Sale item data is invalid.');
                }
                $productUnitId = $this->positiveId($item['product_unit_id'] ?? null, 'A valid product unit is required.');
                $locationId = $this->positiveId($item['location_id'] ?? null, 'A valid stock location is required.');
                if ($this->sales->activeLocation($locationId, true) === null) {
                    throw new DomainException('The selected sale stock location is not active.');
                }
                $productUnit = $this->sales->productUnit($productUnitId, true);
                if (
                    $productUnit === null
                    || $productUnit['product_status'] !== 'active'
                    || $productUnit['unit_status'] !== 'active'
                    || (bool) $productUnit['track_batches']
                ) {
                    throw new DomainException('Sale items must use active, non-batch-tracked products and units.');
                }
                $productId = (int) $productUnit['product_id'];
                if (isset($productIds[$productId])) {
                    throw new DomainException('A product may appear only once per sale.');
                }
                $productIds[$productId] = true;
                $quantitySold = Decimal::positive((string) ($item['quantity'] ?? ''), (int) $productUnit['precision_digits']);
                $quantityBase = Decimal::multiply($quantitySold, (string) $productUnit['to_base_factor'], 3);
                $price = $this->resolvePrice($actorId, $productUnitId, $channel, $item);
                $discount = Decimal::nonNegative((string) ($item['discount_khr'] ?? '0'), 4);
                $lineSubtotal = Decimal::multiplyRounded($quantitySold, $price['amount'], 4);
                if (Decimal::compare($discount, $lineSubtotal, 4) > 0) {
                    throw new DomainException('A line discount cannot exceed its selling amount.');
                }
                $lineRevenue = Decimal::subtract($lineSubtotal, $discount, 4);
                $subtotal = Decimal::add($subtotal, $lineSubtotal, 4);
                $discountTotal = Decimal::add($discountTotal, $discount, 4);
                $total = Decimal::add($total, $lineRevenue, 4);
                $lines[] = [
                    'product_unit_id' => $productUnitId,
                    'location_id' => $locationId,
                    'quantity_sold' => $quantitySold,
                    'quantity_base' => $quantityBase,
                    'unit_to_base_factor' => (string) $productUnit['to_base_factor'],
                    'unit_price_khr' => $price['amount'],
                    'is_price_override' => $price['is_override'],
                    'discount_khr' => $discount,
                    'line_revenue_khr' => $lineRevenue,
                ];
            }

            $saleId = $this->sales->create(
                $this->generateInvoiceNo(),
                $customerId,
                $channel,
                $settlementType,
                $creditOverride,
                $creditOverride ? $creditOverrideReason : null,
                $creditOverride ? $actorId : null,
                $subtotal,
                $discountTotal,
                $total,
                $actorId,
            );
            foreach ($lines as $line) {
                $this->sales->createItem(
                    $saleId,
                    (int) $line['product_unit_id'],
                    $channel,
                    (int) $line['location_id'],
                    (string) $line['quantity_sold'],
                    (string) $line['quantity_base'],
                    (string) $line['unit_to_base_factor'],
                    (string) $line['unit_price_khr'],
                    (bool) $line['is_price_override'],
                    (string) $line['discount_khr'],
                    (string) $line['line_revenue_khr'],
                );
            }
            $this->auditLog->record($actorId, 'sale.draft_created', 'sales', $saleId, $channel . ':' . $settlementType);
            return $saleId;
        });
    }

    /** @return array{sale_id: int, confirmed: bool} */
    public function confirmSale(int $actorId, int $saleId): array
    {
        $this->assertCanConfirm($actorId);

        return $this->transaction(function () use ($actorId, $saleId): array {
            $sale = $this->sales->find($saleId, true);
            if ($sale === null) {
                throw new DomainException('The sale was not found.');
            }
            if ($sale['status'] === 'confirmed') {
                return ['sale_id' => $saleId, 'confirmed' => false];
            }
            if ($sale['status'] !== 'draft') {
                throw new DomainException('Only a draft sale can be confirmed.');
            }
            $items = $this->sales->items($saleId, true);
            if ($items === []) {
                throw new DomainException('A sale requires at least one item before confirmation.');
            }
            $this->validateDraftSnapshots($sale, $items);

            /** @var array<int, array<string, string|int>> $costStates */
            $costStates = [];
            foreach ($items as $item) {
                $costStates[(int) $item['id']] = $this->movingAverageCosts->lockProductForPosting((int) $item['product_id']);
                $quote = $this->movingAverageCosts->quoteOutgoingCogs(
                    $costStates[(int) $item['id']],
                    (string) $item['qty_base'],
                );
                if (Decimal::compare((string) $item['line_revenue_khr'], $quote['value_khr'], 4) < 0
                    && !$this->users->hasPermission($actorId, 'pricing.below_cost.approve')) {
                    throw new DomainException('This sale price or discount is below the locked inventory cost and requires Owner approval.');
                }
            }

            /** @var array<int, array<string, mixed>> $balances */
            $balances = [];
            foreach ($items as $item) {
                $balance = $this->stockLedger->lockBalance((int) $item['product_id'], (int) $item['location_id']);
                if (Decimal::compare((string) $balance['qty_base'], (string) $item['qty_base'], 3) < 0) {
                    throw new DomainException('Insufficient stock is available at the selected location.');
                }
                $balances[(int) $item['id']] = $balance;
            }
            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $movementKey = $this->saleMovementKey($saleId, $itemId);
                if ($this->stockLedger->movementExists($movementKey)) {
                    throw new DomainException('This sale item has already been posted.');
                }
                $this->stockLedger->changeBalance((int) $balances[$itemId]['id'], '-' . (string) $item['qty_base']);
                $movementId = $this->stockLedger->createMovement(
                    (int) $item['product_id'],
                    (int) $item['location_id'],
                    '-' . (string) $item['qty_base'],
                    'sale',
                    $saleId,
                    $itemId,
                    $movementKey,
                    (string) $costStates[$itemId]['avg_unit_cost_khr'],
                    $actorId,
                );
                $posting = $this->movingAverageCosts->postSaleCogs(
                    $costStates[$itemId],
                    $movementId,
                    $movementKey,
                    $saleId,
                    $itemId,
                    (string) $item['qty_base'],
                    $actorId,
                );
                $costStates[$itemId] = $posting['state'];
                $this->sales->updateItemCogs($itemId, $posting['cogs']['value_khr'], $posting['cogs']['unit_cost_khr']);
            }
            $this->createReceivableForConfirmedSale($actorId, $sale);
            $this->sales->markConfirmed($saleId, $actorId);
            $this->auditLog->record($actorId, 'sale.confirmed', 'sales', $saleId);
            return ['sale_id' => $saleId, 'confirmed' => true];
        });
    }

    /** @return array{sale_id: int, voided: bool} */
    public function voidSale(int $actorId, int $saleId, string $reason, bool $restockConfirmed): array
    {
        $this->assertCanVoid($actorId);
        $reason = trim($reason);
        if ($reason === '' || $this->length($reason) > 500) {
            throw new DomainException('A void reason of 1â€“500 characters is required.');
        }
        if (!$restockConfirmed) {
            throw new DomainException('An Owner must explicitly confirm that every voided item is physically resellable before stock can be restored.');
        }

        return $this->transaction(function () use ($actorId, $saleId, $reason): array {
            $sale = $this->sales->find($saleId, true);
            if ($sale === null) {
                throw new DomainException('The sale was not found.');
            }
            if ($sale['status'] === 'voided') {
                return ['sale_id' => $saleId, 'voided' => false];
            }
            if ($sale['status'] !== 'confirmed') {
                throw new DomainException('Only a confirmed sale can be voided.');
            }
            $items = $this->sales->items($saleId, true);
            if ($items === []) {
                throw new DomainException('The confirmed sale has no items to reverse.');
            }
            $this->payments->assertSaleCanBeVoided($saleId);

            /** @var array<int, array<string, string|int>> $costStates */
            $costStates = [];
            foreach ($items as $item) {
                $costStates[(int) $item['id']] = $this->movingAverageCosts->lockProductForPosting((int) $item['product_id']);
            }
            /** @var array<int, array<string, mixed>> $balances */
            $balances = [];
            foreach ($items as $item) {
                $balances[(int) $item['id']] = $this->stockLedger->lockBalance((int) $item['product_id'], (int) $item['location_id']);
            }
            foreach ($items as $item) {
                $itemId = (int) $item['id'];
                $movementKey = $this->saleVoidMovementKey($saleId, $itemId);
                if ($this->stockLedger->movementExists($movementKey)) {
                    throw new DomainException('This sale item has already been voided.');
                }
                $this->stockLedger->changeBalance((int) $balances[$itemId]['id'], (string) $item['qty_base']);
                $movementId = $this->stockLedger->createMovement(
                    (int) $item['product_id'],
                    (int) $item['location_id'],
                    (string) $item['qty_base'],
                    'sale_void',
                    $saleId,
                    $itemId,
                    $movementKey,
                    (string) $item['cogs_unit_cost_khr'],
                    $actorId,
                );
                $costStates[$itemId] = $this->movingAverageCosts->postSaleVoid(
                    $costStates[$itemId],
                    $movementId,
                    $movementKey,
                    $saleId,
                    $itemId,
                    (string) $item['qty_base'],
                    (string) $item['cogs_khr'],
                    (string) $item['cogs_unit_cost_khr'],
                    $actorId,
                );
            }
            $this->payments->markReceivableVoided($saleId, $actorId, $reason);
            $this->sales->markVoided($saleId, $actorId, $reason);
            $this->auditLog->record($actorId, 'sale.voided', 'sales', $saleId, $reason);
            return ['sale_id' => $saleId, 'voided' => true];
        });
    }

    /** @return list<array<string, mixed>> */
    public function listSalesForViewer(int $actorId, int $page, int $perPage): array
    {
        $this->assertCanCreate($actorId);
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        return $this->sales->list($this->users->isOwner($actorId), $perPage, ($page - 1) * $perPage);
    }

    /** @return array{revenue: string, cogs: string, gross_profit: string} */
    public function financialReconciliation(int $actorId): array
    {
        if (!$this->users->hasPermission($actorId, 'reports.profit')) {
            throw new DomainException('Only an authorized Owner can access sales profit data.');
        }
        $totals = $this->sales->confirmedTotals();
        return $totals + ['gross_profit' => Decimal::signedSubtract($totals['revenue'], $totals['cogs'], 4)];
    }

    /** @return array<string, int> */
    public function historicalValuationPreflight(int $actorId): array
    {
        if (!$this->users->hasPermission($actorId, 'reports.profit')) {
            throw new DomainException('Only an authorized Owner can access valuation preflight data.');
        }
        return $this->valuationPreflight->report();
    }

    /** @param array<string, mixed> $sale @param list<array<string, mixed>> $items */
    private function validateDraftSnapshots(array $sale, array $items): void
    {
        $subtotal = '0.0000';
        $discountTotal = '0.0000';
        $total = '0.0000';
        foreach ($items as $item) {
            if (
                $item['product_status'] !== 'active'
                || $item['unit_status'] !== 'active'
                || !(bool) $item['location_active']
                || (bool) $item['track_batches']
            ) {
                throw new DomainException('Every confirmed sale item must use an active, non-batch-tracked product, unit, and stock location.');
            }
            if ((string) $item['price_type'] !== (string) $sale['sale_channel']) {
                throw new DomainException('Sale pricing does not match its retail or wholesale channel.');
            }
            // Database DECIMAL(18,3) values retain trailing zeroes even for a
            // whole-unit sale. Draft creation already enforced the unit's
            // input precision, so revalidate the persisted snapshot at its
            // storage scale here.
            $quantitySold = Decimal::positive((string) $item['qty_sold'], 3);
            $factor = Decimal::positive((string) $item['unit_to_base_factor'], 6);
            $quantityBase = Decimal::multiply($quantitySold, $factor, 3);
            if (Decimal::compare($quantityBase, (string) $item['qty_base'], 3) !== 0) {
                throw new DomainException('The sale base-unit snapshot is invalid.');
            }
            $unitPrice = Decimal::nonNegative((string) $item['unit_price_khr'], 4);
            $discount = Decimal::nonNegative((string) $item['discount_khr'], 4);
            $lineSubtotal = Decimal::multiplyRounded($quantitySold, $unitPrice, 4);
            if (Decimal::compare($discount, $lineSubtotal, 4) > 0) {
                throw new DomainException('A sale discount exceeds its selling amount.');
            }
            $lineRevenue = Decimal::subtract($lineSubtotal, $discount, 4);
            if (Decimal::compare($lineRevenue, (string) $item['line_revenue_khr'], 4) !== 0) {
                throw new DomainException('The sale revenue snapshot is invalid.');
            }
            $subtotal = Decimal::add($subtotal, $lineSubtotal, 4);
            $discountTotal = Decimal::add($discountTotal, $discount, 4);
            $total = Decimal::add($total, $lineRevenue, 4);
        }
        if (
            Decimal::compare($subtotal, (string) $sale['subtotal_khr'], 4) !== 0
            || Decimal::compare($discountTotal, (string) $sale['discount_khr'], 4) !== 0
            || Decimal::compare($total, (string) $sale['total_khr'], 4) !== 0
        ) {
            throw new DomainException('The sale financial totals are invalid.');
        }
    }

    /** @param array<string, mixed> $sale */
    private function createReceivableForConfirmedSale(int $actorId, array $sale): void
    {
        $settlementType = (string) $sale['settlement_type'];
        $customerId = $sale['customer_id'] === null ? null : (int) $sale['customer_id'];
        $termsDays = 0;
        $creditLimit = '0.0000';
        $dueDate = (new \DateTimeImmutable('today'))->format('Y-m-d');

        if ($settlementType === 'credit') {
            if ($customerId === null) {
                throw new DomainException('Credit sales require an identifiable active customer.');
            }
            $customer = $this->customers->active($customerId, true);
            if ($customer === null || !(bool) $customer['credit_enabled']) {
                throw new DomainException('The selected customer does not have an active credit profile.');
            }
            $termsDays = (int) $customer['payment_terms_days'];
            $creditLimit = Decimal::nonNegative((string) $customer['credit_limit'], 4);
            $exposure = Decimal::nonNegative($this->payments->customerExposure($customerId), 4);
            $projectedExposure = Decimal::add($exposure, (string) $sale['total_khr'], 4);
            if (Decimal::compare($projectedExposure, $creditLimit, 4) > 0 && !(bool) $sale['credit_limit_override']) {
                throw new DomainException('This credit sale exceeds the approved customer credit limit.');
            }
            if (Decimal::compare($projectedExposure, $creditLimit, 4) > 0 && $sale['credit_override_approved_by'] === null) {
                throw new DomainException('A credit-limit override requires Owner approval.');
            }
            $dueDate = (new \DateTimeImmutable('today'))->modify('+' . $termsDays . ' days')->format('Y-m-d');
        } elseif ($customerId !== null && $this->customers->active($customerId, true) === null) {
            throw new DomainException('The selected customer is not active.');
        }

        $this->payments->createReceivable(
            (int) $sale['id'],
            $customerId,
            $dueDate,
            $termsDays,
            $creditLimit,
            Decimal::positive((string) $sale['total_khr'], 4),
        );
    }

    /** @param array<string, mixed> $item @return array{amount: string, is_override: bool} */
    private function resolvePrice(int $actorId, int $productUnitId, string $channel, array $item): array
    {
        $override = array_key_exists('override_price_khr', $item) ? trim((string) $item['override_price_khr']) : '';
        if ($override !== '') {
            if (!$this->users->hasPermission($actorId, 'pricing.override')) {
                throw new DomainException('You do not have permission to override selling prices.');
            }
            return ['amount' => Decimal::nonNegative($override, 4), 'is_override' => true];
        }
        $price = $this->sales->currentPrice($productUnitId, $channel, true);
        if ($price === null) {
            throw new DomainException('No current selling price is configured for this sale unit and channel.');
        }
        return ['amount' => Decimal::nonNegative((string) $price['amount'], 4), 'is_override' => false];
    }

    private function assertCanCreate(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'sales.create')) {
            throw new DomainException('You do not have permission to create sales.');
        }
    }

    private function assertCanConfirm(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'sales.confirm')) {
            throw new DomainException('You do not have permission to confirm sales.');
        }
    }

    private function assertCanVoid(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'sales.void')) {
            throw new DomainException('Only an authorized Owner can void confirmed sales.');
        }
    }

    private function saleChannel(string $channel): string
    {
        if (!in_array($channel, ['retail', 'wholesale'], true)) {
            throw new DomainException('Sale channel must be retail or wholesale.');
        }
        return $channel;
    }

    private function settlementType(string $settlementType): string
    {
        if (!in_array($settlementType, ['cash', 'credit'], true)) {
            throw new DomainException('Sale settlement type must be cash or credit.');
        }
        return $settlementType;
    }

    private function positiveId(mixed $value, string $message): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException($message);
        }
        return (int) $value;
    }

    private function nullablePositiveId(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveId($value, $message);
    }

    private function generateInvoiceNo(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $invoiceNo = 'SA-' . (new \DateTimeImmutable())->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $statement = $this->pdo->prepare('SELECT 1 FROM sales WHERE invoice_no = ? LIMIT 1');
            $statement->execute([$invoiceNo]);
            if (!$statement->fetchColumn()) {
                return $invoiceNo;
            }
        }
        throw new DomainException('Unable to generate a unique sale invoice number. Please try again.');
    }

    private function saleMovementKey(int $saleId, int $saleItemId): string
    {
        return sprintf('sale:%d:line:%d:confirmed', $saleId, $saleItemId);
    }

    private function saleVoidMovementKey(int $saleId, int $saleItemId): string
    {
        return sprintf('sale:%d:line:%d:voided', $saleId, $saleItemId);
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
            throw new DomainException('The sale transaction could not be saved because it conflicts with existing data.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
