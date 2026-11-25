<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\InventoryReceipt;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

final class SalesReturnService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Sale $sales,
        private CreditNote $creditNotes,
        private SaleReturn $returns,
        private InventoryReceipt $stockLedger,
        private MovingAverageCostService $movingAverageCosts,
    ) {
    }

    /** @param array<string, mixed> $input @return array{return_id: int, created: bool} */
    public function createDraft(int $actorId, array $input): array
    {
        $this->assertCanCreate($actorId);
        $key = $this->idempotencyKey((string) ($input['return_key'] ?? $input['idempotency_key'] ?? ''));
        $saleId = $this->positiveId($input['sale_id'] ?? null, 'A valid confirmed sale is required.');
        $creditNoteId = $this->positiveId($input['credit_note_id'] ?? null, 'A valid posted credit note is required.');
        $reason = $this->reason($input['reason'] ?? null, 'A return reason');
        $requestedItems = $input['items'] ?? null;
        if (!is_array($requestedItems) || $requestedItems === [] || count($requestedItems) > 100) {
            throw new DomainException('A return requires one to 100 requested sale lines.');
        }
        $existing = $this->returns->findByKey($key);
        if ($existing !== null) {
            return ['return_id' => (int) $existing['id'], 'created' => false];
        }

        return $this->transaction(function () use ($actorId, $key, $saleId, $creditNoteId, $reason, $requestedItems): array {
            $existing = $this->returns->findByKey($key, true);
            if ($existing !== null) {
                return ['return_id' => (int) $existing['id'], 'created' => false];
            }
            $sale = $this->sales->find($saleId, true);
            $this->assertReturnableSale($sale);
            $creditNote = $this->creditNotes->find($creditNoteId, true);
            $this->assertCreditNoteForSale($creditNote, $saleId);
            $saleItems = $this->sales->items($saleId, true);
            $saleItemsById = [];
            foreach ($saleItems as $saleItem) {
                $saleItemsById[(int) $saleItem['id']] = $saleItem;
            }

            /** @var array<int, array{sale_item_id: int, qty_base: string, condition: string}> $lines */
            $lines = [];
            $requestedFinancial = '0.0000';
            foreach ($requestedItems as $requestedItem) {
                if (!is_array($requestedItem)) {
                    throw new DomainException('Return item data is invalid.');
                }
                $saleItemId = $this->positiveId($requestedItem['sale_item_id'] ?? null, 'Every return item requires a valid original sale line.');
                if (isset($lines[$saleItemId]) || !isset($saleItemsById[$saleItemId])) {
                    throw new DomainException('Each requested return line must belong to the selected sale and appear only once.');
                }
                $quantity = Decimal::positive((string) ($requestedItem['requested_qty_base'] ?? $requestedItem['qty_base'] ?? ''), 3);
                $condition = $this->condition((string) ($requestedItem['condition_code'] ?? ''));
                $prior = $this->returns->confirmedTotalsForSaleItem($saleItemId);
                $remaining = Decimal::subtract((string) $saleItemsById[$saleItemId]['qty_base'], $prior['qty_base'], 3);
                if (Decimal::compare($quantity, $remaining, 3) > 0) {
                    throw new DomainException('A return quantity cannot exceed the original sale quantity remaining after confirmed returns.');
                }
                $financial = $this->proportionalAmount(
                    $quantity,
                    (string) $saleItemsById[$saleItemId]['qty_base'],
                    (string) $saleItemsById[$saleItemId]['line_revenue_khr'],
                    $prior['qty_base'],
                    $prior['financial_amount_khr'],
                );
                $requestedFinancial = Decimal::add($requestedFinancial, $financial, 4);
                $lines[$saleItemId] = ['sale_item_id' => $saleItemId, 'qty_base' => $quantity, 'condition' => $condition];
            }
            $alreadyCredited = $this->returns->confirmedFinancialTotalForCreditNote($creditNoteId);
            $creditAvailable = Decimal::subtract((string) $creditNote['amount_khr'], $alreadyCredited, 4);
            if (Decimal::compare($requestedFinancial, $creditAvailable, 4) > 0) {
                throw new DomainException('The requested physical return exceeds the unused financial credit on this credit note.');
            }
            $returnId = $this->returns->create($this->generateNumber(), $key, $saleId, $creditNoteId, $reason, $actorId);
            foreach ($lines as $line) {
                $this->returns->createItem($returnId, $line['sale_item_id'], $line['qty_base'], $line['condition']);
            }
            $this->auditLog->record($actorId, 'sale_return.draft_created', 'sale_returns', $returnId, 'sale:' . $saleId . ';credit_note:' . $creditNoteId);
            return ['return_id' => $returnId, 'created' => true];
        });
    }

    /** @param array<string, mixed> $input @return array{return_id: int, approved: bool} */
    public function approve(int $actorId, int $returnId, array $input): array
    {
        $this->assertOwner($actorId);
        $approvals = $input['approved_items'] ?? $input['items'] ?? null;
        if (!is_array($approvals) || $approvals === [] || count($approvals) > 100) {
            throw new DomainException('An approved quantity is required for every requested return item.');
        }

        return $this->transaction(function () use ($actorId, $returnId, $approvals): array {
            $return = $this->returns->find($returnId, true);
            if ($return === null) {
                throw new DomainException('The sales return was not found.');
            }
            if ($return['status'] === 'approved') {
                return ['return_id' => $returnId, 'approved' => false];
            }
            if ($return['status'] !== 'draft') {
                throw new DomainException('Only a draft sales return can be approved.');
            }
            $sale = $this->sales->find((int) $return['sale_id'], true);
            $this->assertReturnableSale($sale);
            $creditNote = $this->creditNotes->find((int) $return['credit_note_id'], true);
            $this->assertCreditNoteForSale($creditNote, (int) $return['sale_id']);
            $saleItems = $this->sales->items((int) $return['sale_id'], true);
            $saleItemsById = [];
            foreach ($saleItems as $saleItem) {
                $saleItemsById[(int) $saleItem['id']] = $saleItem;
            }
            $returnItems = $this->returns->items($returnId, true);
            $approvalsByReturnItem = [];
            foreach ($approvals as $approval) {
                if (!is_array($approval)) {
                    throw new DomainException('Return approval data is invalid.');
                }
                $returnItemId = $this->positiveId($approval['return_item_id'] ?? null, 'Every approved line requires a return item identifier.');
                if (isset($approvalsByReturnItem[$returnItemId])) {
                    throw new DomainException('Each return item may be approved only once.');
                }
                $approvalsByReturnItem[$returnItemId] = Decimal::positive((string) ($approval['approved_qty_base'] ?? ''), 3);
            }
            if (count($approvalsByReturnItem) !== count($returnItems)) {
                throw new DomainException('Every requested return item requires an approved quantity.');
            }

            $approvedFinancial = '0.0000';
            foreach ($returnItems as $returnItem) {
                $returnItemId = (int) $returnItem['id'];
                if (!isset($approvalsByReturnItem[$returnItemId])) {
                    throw new DomainException('Every requested return item requires an approved quantity.');
                }
                $quantity = $approvalsByReturnItem[$returnItemId];
                if (Decimal::compare($quantity, (string) $returnItem['requested_qty_base'], 3) > 0) {
                    throw new DomainException('An approved return quantity cannot exceed the quantity requested.');
                }
                $saleItem = $saleItemsById[(int) $returnItem['sale_item_id']] ?? null;
                if ($saleItem === null) {
                    throw new DomainException('A return item no longer belongs to the original sale.');
                }
                $prior = $this->returns->confirmedTotalsForSaleItem((int) $returnItem['sale_item_id']);
                $remaining = Decimal::subtract((string) $saleItem['qty_base'], $prior['qty_base'], 3);
                if (Decimal::compare($quantity, $remaining, 3) > 0) {
                    throw new DomainException('An approved return quantity exceeds the original quantity remaining after confirmed returns.');
                }
                $approvedFinancial = Decimal::add($approvedFinancial, $this->proportionalAmount(
                    $quantity,
                    (string) $saleItem['qty_base'],
                    (string) $saleItem['line_revenue_khr'],
                    $prior['qty_base'],
                    $prior['financial_amount_khr'],
                ), 4);
            }
            $alreadyCredited = $this->returns->confirmedFinancialTotalForCreditNote((int) $return['credit_note_id']);
            if (Decimal::compare($approvedFinancial, Decimal::subtract((string) $creditNote['amount_khr'], $alreadyCredited, 4), 4) > 0) {
                throw new DomainException('The approved physical return exceeds the unused financial credit on this credit note.');
            }
            foreach ($approvalsByReturnItem as $returnItemId => $quantity) {
                $this->returns->updateApprovedQuantity($returnItemId, $quantity);
            }
            $approved = $this->returns->markApproved($returnId, $actorId);
            $this->auditLog->record($actorId, 'sale_return.approved', 'sale_returns', $returnId);
            return ['return_id' => $returnId, 'approved' => $approved];
        });
    }

    /** @return array{return_id: int, confirmed: bool, financial_amount_khr: string} */
    public function confirm(int $actorId, int $returnId): array
    {
        $this->assertOwner($actorId);
        return $this->transaction(function () use ($actorId, $returnId): array {
            $return = $this->returns->find($returnId, true);
            if ($return === null) {
                throw new DomainException('The sales return was not found.');
            }
            if ($return['status'] === 'confirmed') {
                return ['return_id' => $returnId, 'confirmed' => false, 'financial_amount_khr' => (string) $return['financial_amount_khr']];
            }
            if ($return['status'] !== 'approved') {
                throw new DomainException('Only an approved sales return can be confirmed.');
            }
            $sale = $this->sales->find((int) $return['sale_id'], true);
            $this->assertReturnableSale($sale);
            $creditNote = $this->creditNotes->find((int) $return['credit_note_id'], true);
            $this->assertCreditNoteForSale($creditNote, (int) $return['sale_id']);
            $saleItems = $this->sales->items((int) $return['sale_id'], true);
            $saleItemsById = [];
            foreach ($saleItems as $saleItem) {
                $saleItemsById[(int) $saleItem['id']] = $saleItem;
            }
            $returnItems = $this->returns->items($returnId, true);
            if ($returnItems === []) {
                throw new DomainException('An approved sales return requires at least one item.');
            }

            /** @var list<array{item: array<string,mixed>, sale_item: array<string,mixed>, financial: string, cost: string}> $postings */
            $postings = [];
            $financialTotal = '0.0000';
            foreach ($returnItems as $returnItem) {
                $quantity = Decimal::positive((string) $returnItem['approved_qty_base'], 3);
                $saleItem = $saleItemsById[(int) $returnItem['sale_item_id']] ?? null;
                if ($saleItem === null) {
                    throw new DomainException('A return item no longer belongs to the original sale.');
                }
                $prior = $this->returns->confirmedTotalsForSaleItem((int) $returnItem['sale_item_id']);
                $remainingQty = Decimal::subtract((string) $saleItem['qty_base'], $prior['qty_base'], 3);
                if (Decimal::compare($quantity, $remainingQty, 3) > 0) {
                    throw new DomainException('A return quantity exceeds the original quantity remaining after confirmed returns.');
                }
                $financial = $this->proportionalAmount($quantity, (string) $saleItem['qty_base'], (string) $saleItem['line_revenue_khr'], $prior['qty_base'], $prior['financial_amount_khr']);
                $cost = $this->proportionalAmount($quantity, (string) $saleItem['qty_base'], (string) $saleItem['cogs_khr'], $prior['qty_base'], $prior['cost_basis_khr']);
                $financialTotal = Decimal::add($financialTotal, $financial, 4);
                $postings[] = ['item' => $returnItem, 'sale_item' => $saleItem, 'financial' => $financial, 'cost' => $cost];
            }
            $alreadyCredited = $this->returns->confirmedFinancialTotalForCreditNote((int) $return['credit_note_id']);
            if (Decimal::compare($financialTotal, Decimal::subtract((string) $creditNote['amount_khr'], $alreadyCredited, 4), 4) > 0) {
                throw new DomainException('This physical return exceeds the unused financial credit on the linked credit note.');
            }

            /** @var array<int, array<string, string|int>> $costStates */
            $costStates = [];
            foreach ($postings as $posting) {
                if ($posting['item']['condition_code'] !== 'resellable') {
                    continue;
                }
                $productId = (int) $posting['sale_item']['product_id'];
                if (!isset($costStates[$productId])) {
                    $costStates[$productId] = $this->movingAverageCosts->lockProductForPosting($productId);
                }
            }
            /** @var array<int, array<string, mixed>> $balances */
            $balances = [];
            foreach ($postings as $posting) {
                if ($posting['item']['condition_code'] !== 'resellable') {
                    continue;
                }
                if (!(bool) $posting['sale_item']['location_active']) {
                    throw new DomainException('A resellable return cannot be restored to an inactive original stock location.');
                }
                $returnItemId = (int) $posting['item']['id'];
                $balances[$returnItemId] = $this->stockLedger->lockBalance(
                    (int) $posting['sale_item']['product_id'],
                    (int) $posting['sale_item']['location_id'],
                );
            }

            foreach ($postings as $posting) {
                $returnItem = $posting['item'];
                $saleItem = $posting['sale_item'];
                $returnItemId = (int) $returnItem['id'];
                $quantity = (string) $returnItem['approved_qty_base'];
                $this->returns->postItemAmounts($returnItemId, $posting['financial'], $posting['cost']);
                if ($returnItem['condition_code'] === 'resellable') {
                    $movementKey = $this->restockMovementKey($returnId, $returnItemId);
                    if ($this->stockLedger->movementExists($movementKey)) {
                        throw new DomainException('This return item has already restored stock.');
                    }
                    $this->stockLedger->changeBalance((int) $balances[$returnItemId]['id'], $quantity);
                    $movementId = $this->stockLedger->createMovement(
                        (int) $saleItem['product_id'],
                        (int) $saleItem['location_id'],
                        $quantity,
                        'sale_return',
                        $returnId,
                        $returnItemId,
                        $movementKey,
                        (string) $saleItem['cogs_unit_cost_khr'],
                        $actorId,
                    );
                    $productId = (int) $saleItem['product_id'];
                    $costStates[$productId] = $this->movingAverageCosts->postSaleReturn(
                        $costStates[$productId],
                        $movementId,
                        $movementKey,
                        $returnId,
                        $returnItemId,
                        $quantity,
                        $posting['cost'],
                        (string) $saleItem['cogs_unit_cost_khr'],
                        $actorId,
                    );
                    continue;
                }
                if (in_array($returnItem['condition_code'], ['damaged', 'expired'], true)) {
                    $this->returns->createWriteoff($returnItemId, (string) $returnItem['condition_code'], $posting['cost'], (string) $return['reason'], $actorId);
                    continue;
                }
                $this->returns->createQuarantineHold(
                    $returnItemId,
                    (int) $saleItem['product_id'],
                    (int) $saleItem['location_id'],
                    $quantity,
                    $posting['cost'],
                    $actorId,
                );
            }
            if (!$this->returns->markConfirmed($returnId, $actorId, $financialTotal)) {
                throw new DomainException('The sales return status changed before it could be confirmed.');
            }
            $this->auditLog->record($actorId, 'sale_return.confirmed', 'sale_returns', $returnId, 'financial:' . $financialTotal);
            return ['return_id' => $returnId, 'confirmed' => true, 'financial_amount_khr' => $financialTotal];
        });
    }

    /** @return list<array<string, mixed>> */
    public function returnsForViewer(int $actorId): array
    {
        $this->assertCanCreate($actorId);
        return $this->returns->listReturns($this->users->isOwner($actorId));
    }

    private function assertCanCreate(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'sales.create')) {
            throw new DomainException('You do not have permission to create sales return requests.');
        }
    }

    private function assertOwner(int $actorId): void
    {
        if (!$this->users->isOwner($actorId)) {
            throw new DomainException('Only an Owner can approve or confirm physical sales returns.');
        }
    }

    /** @param array<string, mixed>|null $sale */
    private function assertReturnableSale(?array $sale): void
    {
        if ($sale === null || $sale['status'] !== 'confirmed') {
            throw new DomainException('Only a confirmed, non-voided sale is eligible for a physical return.');
        }
    }

    /** @param array<string, mixed>|null $creditNote */
    private function assertCreditNoteForSale(?array $creditNote, int $saleId): void
    {
        if ($creditNote === null || $creditNote['status'] !== 'posted' || (int) $creditNote['sale_id'] !== $saleId) {
            throw new DomainException('A posted credit note for the original sale is required before a physical return can proceed.');
        }
    }

    private function proportionalAmount(string $quantity, string $saleQuantity, string $lineAmount, string $priorQuantity, string $priorAmount): string
    {
        $remainingQuantity = Decimal::subtract($saleQuantity, $priorQuantity, 3);
        $remainingAmount = Decimal::subtract($lineAmount, $priorAmount, 4);
        if (Decimal::compare($quantity, $remainingQuantity, 3) === 0) {
            return $remainingAmount;
        }
        $perBase = Decimal::divide($lineAmount, $saleQuantity, 8);
        $amount = Decimal::multiplyRounded($quantity, $perBase, 4);
        if (Decimal::compare($amount, $remainingAmount, 4) > 0) {
            throw new DomainException('Return rounding would exceed the immutable original sale amount.');
        }
        return $amount;
    }

    private function condition(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, ['resellable', 'damaged', 'expired', 'quarantine'], true)) {
            throw new DomainException('Return condition must be resellable, damaged, expired, or quarantine.');
        }
        return $value;
    }

    private function idempotencyKey(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/', $value)) {
            throw new DomainException('A 16-80 character return idempotency key is required.');
        }
        return $value;
    }

    private function positiveId(mixed $value, string $message): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException($message);
        }
        return (int) $value;
    }

    private function reason(mixed $value, string $label): string
    {
        $reason = trim((string) $value);
        if ($reason === '' || $this->length($reason) > 500) {
            throw new DomainException($label . ' of 1-500 characters is required.');
        }
        return $reason;
    }

    private function generateNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'SR-' . (new \DateTimeImmutable())->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $statement = $this->pdo->prepare('SELECT 1 FROM sale_returns WHERE return_no = ? LIMIT 1');
            $statement->execute([$number]);
            if (!$statement->fetchColumn()) {
                return $number;
            }
        }
        throw new DomainException('Unable to generate a unique return number. Please try again.');
    }

    private function restockMovementKey(int $returnId, int $returnItemId): string
    {
        return 'sale-return:' . $returnId . ':item:' . $returnItemId . ':restock';
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
            throw new DomainException('The return transaction could not be saved because it conflicts with existing data.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
