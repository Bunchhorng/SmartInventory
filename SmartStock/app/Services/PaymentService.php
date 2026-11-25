<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

final class PaymentService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Customer $customers,
        private Payment $payments,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function createCustomer(int $actorId, array $input): int
    {
        $this->assertCanManageCredit($actorId);
        $name = trim((string) ($input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $creditLimit = Decimal::nonNegative((string) ($input['credit_limit_khr'] ?? '0'), 4);
        $creditEnabled = ($input['credit_enabled'] ?? '') === '1' || ($input['credit_enabled'] ?? false) === true;
        $termsDays = $this->termsDays($input['payment_terms_days'] ?? 0);
        if ($name === '' || $this->length($name) > 180) {
            throw new DomainException('A customer name of at most 180 characters is required.');
        }
        if ($this->length($phone) > 50) {
            throw new DomainException('Customer phone may contain at most 50 characters.');
        }
        if (!$creditEnabled && !Decimal::isZeroValue($creditLimit)) {
            throw new DomainException('Enable the credit profile before assigning a credit limit.');
        }

        return $this->transaction(function () use ($actorId, $name, $phone, $creditLimit, $creditEnabled, $termsDays): int {
            $customerId = $this->customers->create($name, $phone === '' ? null : $phone, $creditLimit, $creditEnabled, $termsDays);
            $this->auditLog->record($actorId, 'customer.created', 'customers', $customerId, 'credit profile created');
            return $customerId;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateCustomerCredit(int $actorId, int $customerId, array $input): void
    {
        $this->assertCanManageCredit($actorId);
        $creditLimit = Decimal::nonNegative((string) ($input['credit_limit_khr'] ?? ''), 4);
        $creditEnabled = ($input['credit_enabled'] ?? '') === '1' || ($input['credit_enabled'] ?? false) === true;
        $termsDays = $this->termsDays($input['payment_terms_days'] ?? 0);
        if (!$creditEnabled && !Decimal::isZeroValue($creditLimit)) {
            throw new DomainException('Enable the credit profile before assigning a credit limit.');
        }

        $this->transaction(function () use ($actorId, $customerId, $creditLimit, $creditEnabled, $termsDays): void {
            if ($this->customers->active($customerId, true) === null) {
                throw new DomainException('The selected active customer was not found.');
            }
            $this->customers->updateCreditProfile($customerId, $creditLimit, $creditEnabled, $termsDays);
            $this->auditLog->record($actorId, 'customer.credit_profile_updated', 'customers', $customerId, 'credit profile updated');
        });
    }

    /** @param array<string, mixed> $input @return array{payment_id: int, recorded: bool, verification_status: string} */
    public function recordPayment(int $actorId, array $input): array
    {
        $this->assertCanRecord($actorId);
        $paymentKey = trim((string) ($input['payment_key'] ?? $input['idempotency_key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/', $paymentKey)) {
            throw new DomainException('A 16-80 character payment idempotency key is required.');
        }
        $methodCode = strtolower(trim((string) ($input['payment_method'] ?? '')));
        if (!in_array($methodCode, ['cash', 'bank', 'khqr'], true)) {
            throw new DomainException('Payment method must be cash, bank, or KHQR.');
        }
        $method = $this->payments->activeMethod($methodCode);
        if ($method === null) {
            throw new DomainException('The selected payment method is not active.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if (!in_array($currency, ['KHR', 'USD'], true)) {
            throw new DomainException('Payment currency must be KHR or USD.');
        }
        $amountOriginal = Decimal::positive((string) ($input['amount_original'] ?? ''), 4);
        $exchangeRate = Decimal::positive((string) ($input['exchange_rate_to_khr'] ?? ''), 6);
        if ($currency === 'KHR' && Decimal::compare($exchangeRate, '1.000000', 6) !== 0) {
            throw new DomainException('KHR payments must use an exchange rate of exactly 1.000000.');
        }
        $amountKhr = Decimal::multiplyRounded($amountOriginal, $exchangeRate, 4);
        $referenceNo = trim((string) ($input['reference_no'] ?? ''));
        if ($this->length($referenceNo) > 120) {
            throw new DomainException('Payment reference may contain at most 120 characters.');
        }
        if (($methodCode === 'bank' || $methodCode === 'khqr') && $referenceNo === '') {
            throw new DomainException('Bank transfer and KHQR payments require a manual reference.');
        }
        $purpose = strtolower(trim((string) ($input['payment_purpose'] ?? 'sale_settlement')));
        if (!in_array($purpose, ['sale_settlement', 'customer_repayment'], true)) {
            throw new DomainException('The payment purpose is invalid.');
        }
        $paidAt = $this->paidAt($input['paid_at'] ?? null);
        $allocationInput = $input['allocations'] ?? null;
        if (!is_array($allocationInput) || $allocationInput === [] || count($allocationInput) > 100) {
            throw new DomainException('A payment requires one to 100 explicit invoice allocations.');
        }
        /** @var array<int, string> $allocations */
        $allocations = [];
        $allocatedTotal = '0.0000';
        foreach ($allocationInput as $allocation) {
            if (!is_array($allocation)) {
                throw new DomainException('Payment allocation data is invalid.');
            }
            $saleId = $this->positiveId($allocation['sale_id'] ?? null, 'Every allocation requires a valid sale.');
            if (isset($allocations[$saleId])) {
                throw new DomainException('A payment may allocate to an invoice only once.');
            }
            $amount = Decimal::positive((string) ($allocation['amount_khr'] ?? ''), 4);
            $allocations[$saleId] = $amount;
            $allocatedTotal = Decimal::add($allocatedTotal, $amount, 4);
        }
        if (Decimal::compare($allocatedTotal, $amountKhr, 4) !== 0) {
            throw new DomainException('Payment allocations must exactly equal the converted KHR payment amount.');
        }
        $declaredCustomerId = $this->nullablePositiveId($input['customer_id'] ?? null, 'Customer identifier is invalid.');

        $existing = $this->payments->findByKey($paymentKey);
        if ($existing !== null) {
            if ($existing['direction'] !== 'in') { throw new DomainException('This payment key belongs to a different payment workflow.'); }
            return [
                'payment_id' => (int) $existing['id'],
                'recorded' => false,
                'verification_status' => (string) $existing['verification_status'],
            ];
        }

        return $this->transaction(function () use (
            $actorId,
            $paymentKey,
            $method,
            $methodCode,
            $currency,
            $amountOriginal,
            $exchangeRate,
            $amountKhr,
            $referenceNo,
            $purpose,
            $paidAt,
            $allocations,
            $declaredCustomerId,
        ): array {
            $existing = $this->payments->findByKey($paymentKey, true);
            if ($existing !== null) {
                if ($existing['direction'] !== 'in') { throw new DomainException('This payment key belongs to a different payment workflow.'); }
                return [
                    'payment_id' => (int) $existing['id'],
                    'recorded' => false,
                    'verification_status' => (string) $existing['verification_status'],
                ];
            }
            $saleIds = array_keys($allocations);
            sort($saleIds, SORT_NUMERIC);
            $receivables = $this->payments->lockReceivablesBySaleIds($saleIds);
            if (count($receivables) !== count($saleIds)) {
                throw new DomainException('Every payment allocation must target an existing confirmed invoice.');
            }

            $customerId = $receivables[0]['customer_id'] === null ? null : (int) $receivables[0]['customer_id'];
            foreach ($receivables as $receivable) {
                $saleId = (int) $receivable['sale_id'];
                if ($receivable['sale_status'] !== 'confirmed' || $receivable['voided_at'] !== null) {
                    throw new DomainException('Payments may only settle active confirmed invoices.');
                }
                $receivableCustomerId = $receivable['customer_id'] === null ? null : (int) $receivable['customer_id'];
                if ($customerId !== $receivableCustomerId) {
                    throw new DomainException('One payment can settle invoices for one identifiable customer only.');
                }
                if ($receivableCustomerId === null && count($saleIds) > 1) {
                    throw new DomainException('Anonymous cash invoices must be settled one invoice per payment.');
                }
                $reserved = $this->payments->activeAllocationTotal((int) $receivable['id']);
                $credited = $this->payments->activeCreditNoteReductionTotal((int) $receivable['id']);
                $available = Decimal::subtract(
                    Decimal::subtract((string) $receivable['original_khr'], $reserved, 4),
                    $credited,
                    4,
                );
                if (Decimal::compare($allocations[$saleId], $available, 4) > 0) {
                    throw new DomainException('A payment allocation exceeds the unallocated invoice balance.');
                }
            }
            if ($declaredCustomerId !== null && $declaredCustomerId !== $customerId) {
                throw new DomainException('The payment customer does not match the selected invoice customer.');
            }
            if ($purpose === 'customer_repayment') {
                if ($customerId === null) {
                    throw new DomainException('Customer debt repayment requires an identifiable customer.');
                }
                foreach ($receivables as $receivable) {
                    if ($receivable['settlement_type'] !== 'credit') {
                        throw new DomainException('Customer debt repayment may settle credit invoices only.');
                    }
                }
            }

            $verificationStatus = $methodCode === 'cash' ? 'verified' : 'pending';
            $paymentId = $this->payments->createPayment(
                $paymentKey,
                (int) $method['id'],
                $customerId,
                $purpose,
                $amountOriginal,
                $currency,
                $exchangeRate,
                $amountKhr,
                $referenceNo === '' ? null : $referenceNo,
                $verificationStatus,
                $paidAt,
                $actorId,
                $methodCode === 'cash' ? $actorId : null,
            );
            foreach ($receivables as $receivable) {
                $this->payments->createAllocation(
                    (int) $receivable['id'],
                    $paymentId,
                    $allocations[(int) $receivable['sale_id']],
                    $actorId,
                );
            }
            $this->auditLog->record($actorId, 'payment.recorded', 'payments', $paymentId, $verificationStatus . ':' . $purpose);
            return ['payment_id' => $paymentId, 'recorded' => true, 'verification_status' => $verificationStatus];
        });
    }

    /** @return array{payment_id: int, changed: bool, verification_status: string} */
    public function verifyPayment(int $actorId, int $paymentId, bool $approved, string $reason = ''): array
    {
        $this->assertCanManageCredit($actorId);
        $reason = trim($reason);
        if (!$approved && ($reason === '' || $this->length($reason) > 500)) {
            throw new DomainException('A rejection reason of at most 500 characters is required.');
        }
        return $this->transaction(function () use ($actorId, $paymentId, $approved, $reason): array {
            $payment = $this->payments->find($paymentId, true);
            if ($payment === null) {
                throw new DomainException('The payment was not found.');
            }
            if ($payment['direction'] !== 'in') {
                throw new DomainException('Outgoing payments must be verified through their own supplier-payment or refund workflow.');
            }
            if ($payment['verification_status'] !== 'pending') {
                return ['payment_id' => $paymentId, 'changed' => false, 'verification_status' => (string) $payment['verification_status']];
            }
            if ($approved) {
                $changed = $this->payments->markVerified($paymentId, $actorId);
                $this->auditLog->record($actorId, 'payment.verified', 'payments', $paymentId);
                return ['payment_id' => $paymentId, 'changed' => $changed, 'verification_status' => 'verified'];
            }
            $changed = $this->payments->rejectAndReverseAllocations($paymentId, $actorId, $reason);
            $this->auditLog->record($actorId, 'payment.rejected', 'payments', $paymentId, $reason);
            return ['payment_id' => $paymentId, 'changed' => $changed, 'verification_status' => 'rejected'];
        });
    }

    /** @return array{status: string, outstanding_khr: string, due_date: ?string} */
    public function receivableStatus(int $actorId, int $saleId, ?string $asOfDate = null): array
    {
        $this->assertCanRecord($actorId);
        $receivable = $this->payments->receivableForSale($saleId);
        if ($receivable === null) {
            throw new DomainException('No receivable exists for this sale.');
        }
        $paid = $this->payments->verifiedInboundAllocationTotal((int) $receivable['id']);
        $credited = $this->payments->activeCreditNoteReductionTotal((int) $receivable['id']);
        $outstanding = Decimal::subtract(Decimal::subtract((string) $receivable['original_khr'], $paid, 4), $credited, 4);
        $asOf = $asOfDate === null ? new \DateTimeImmutable('today') : $this->dateOnly($asOfDate);
        $dueDate = $receivable['due_date'] === null ? null : (string) $receivable['due_date'];
        $status = 'unpaid';
        if ($receivable['voided_at'] !== null) {
            $status = 'voided';
        } elseif (Decimal::isZeroValue($outstanding)) {
            $status = 'paid';
        } elseif (Decimal::compare(Decimal::add($paid, $credited, 4), '0.0000', 4) > 0) {
            $status = 'partial';
        } elseif ($dueDate !== null && $this->dateOnly($dueDate) < $asOf) {
            $status = 'overdue';
        }
        return ['status' => $status, 'outstanding_khr' => $outstanding, 'due_date' => $dueDate];
    }

    /** @return list<array<string, mixed>> */
    public function listReceivablesForViewer(int $actorId): array
    {
        $this->assertCanRecord($actorId);
        return $this->payments->listReceivables($this->users->hasPermission($actorId, 'credit.manage'));
    }

    /** @return list<array<string, mixed>> */
    public function listPaymentsForViewer(int $actorId): array
    {
        $this->assertCanRecord($actorId);
        return $this->payments->listPayments();
    }

    /** @return list<array<string, mixed>> */
    public function paymentMethods(int $actorId): array
    {
        $this->assertCanRecord($actorId);
        return $this->payments->activeMethods();
    }

    /** @return array{sales_revenue: string, cash_received: string, bank_received: string, pending_bank: string, customer_credit_outstanding: string} */
    public function financialReconciliation(int $actorId): array
    {
        $this->assertCanManageCredit($actorId);
        $paymentTotals = $this->payments->reconciliationTotals();
        $statement = $this->pdo->query(
            "SELECT COALESCE((SELECT SUM(total_khr) FROM sales WHERE status = 'confirmed'), 0)
                    - COALESCE((SELECT SUM(amount_khr) FROM credit_notes WHERE status = 'posted'), 0)"
        );
        return ['sales_revenue' => (string) $statement->fetchColumn()] + $paymentTotals;
    }

    /** @return list<array<string, mixed>> */
    public function customersForOwner(int $actorId): array
    {
        $this->assertCanManageCredit($actorId);
        return $this->customers->listWithCreditExposure();
    }

    private function assertCanRecord(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'payments.record')) {
            throw new DomainException('You do not have permission to record payments.');
        }
    }

    private function assertCanManageCredit(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'credit.manage')) {
            throw new DomainException('Only an authorized Owner may manage customer credit or verify payments.');
        }
    }

    private function termsDays(mixed $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3650]]) === false) {
            throw new DomainException('Payment terms must be between zero and 3650 days.');
        }
        return (int) $value;
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

    private function paidAt(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw);
        if ($date === false || $date->format('Y-m-d H:i:s') !== $raw) {
            throw new DomainException('Payment time must use YYYY-MM-DD HH:MM:SS.');
        }
        return $raw;
    }

    private function dateOnly(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('A valid YYYY-MM-DD date is required.');
        }
        return $date;
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
            throw new DomainException('The payment transaction could not be saved because it conflicts with existing data.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
