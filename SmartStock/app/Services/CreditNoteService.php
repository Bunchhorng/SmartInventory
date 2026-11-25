<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\User;
use App\Support\Decimal;
use DomainException;
use PDO;

final class CreditNoteService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Sale $sales,
        private Payment $payments,
        private CreditNote $creditNotes,
    ) {
    }

    /** @param array<string, mixed> $input @return array{credit_note_id: int, created: bool} */
    public function createDraft(int $actorId, array $input): array
    {
        $this->assertOwnerFinancialControl($actorId);
        $key = $this->idempotencyKey((string) ($input['credit_note_key'] ?? $input['idempotency_key'] ?? ''), 'credit note');
        $saleId = $this->positiveId($input['sale_id'] ?? null, 'A valid confirmed sale is required.');
        $amount = Decimal::positive((string) ($input['amount_khr'] ?? ''), 4);
        $reason = $this->reason($input['reason'] ?? null, 'A credit-note reason');
        $existing = $this->creditNotes->findByKey($key);
        if ($existing !== null) {
            return ['credit_note_id' => (int) $existing['id'], 'created' => false];
        }

        return $this->transaction(function () use ($actorId, $key, $saleId, $amount, $reason): array {
            $existing = $this->creditNotes->findByKey($key, true);
            if ($existing !== null) {
                return ['credit_note_id' => (int) $existing['id'], 'created' => false];
            }
            $sale = $this->sales->find($saleId, true);
            $this->assertEligibleSale($sale);
            $creditNoteId = $this->creditNotes->create(
                $this->generateNumber('CN'),
                $key,
                $saleId,
                (int) $sale['customer_id'],
                $amount,
                $reason,
                $actorId,
            );
            $this->auditLog->record($actorId, 'credit_note.draft_created', 'credit_notes', $creditNoteId, 'sale:' . $saleId);
            return ['credit_note_id' => $creditNoteId, 'created' => true];
        });
    }

    /** @return array{credit_note_id: int, approved: bool} */
    public function approve(int $actorId, int $creditNoteId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        return $this->transaction(function () use ($actorId, $creditNoteId): array {
            $note = $this->creditNotes->find($creditNoteId, true);
            if ($note === null) {
                throw new DomainException('The credit note was not found.');
            }
            if ($note['status'] === 'approved') {
                return ['credit_note_id' => $creditNoteId, 'approved' => false];
            }
            if ($note['status'] !== 'draft') {
                throw new DomainException('Only a draft credit note can be approved.');
            }
            $approved = $this->creditNotes->approve($creditNoteId, $actorId);
            $this->auditLog->record($actorId, 'credit_note.approved', 'credit_notes', $creditNoteId);
            return ['credit_note_id' => $creditNoteId, 'approved' => $approved];
        });
    }

    /** @return array{credit_note_id: int, posted: bool, receivable_reduction_khr: string, excess_credit_khr: string} */
    public function post(int $actorId, int $creditNoteId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        return $this->transaction(function () use ($actorId, $creditNoteId): array {
            $note = $this->creditNotes->find($creditNoteId, true);
            if ($note === null) {
                throw new DomainException('The credit note was not found.');
            }
            if ($note['status'] === 'posted') {
                return [
                    'credit_note_id' => $creditNoteId,
                    'posted' => false,
                    'receivable_reduction_khr' => (string) $note['receivable_reduction_khr'],
                    'excess_credit_khr' => (string) $note['excess_credit_khr'],
                ];
            }
            if ($note['status'] !== 'approved') {
                throw new DomainException('Only an approved credit note can be posted.');
            }
            $sale = $this->sales->find((int) $note['sale_id'], true);
            $this->assertEligibleSale($sale);
            if ((int) $sale['customer_id'] !== (int) $note['customer_id']) {
                throw new DomainException('The credit note customer does not match the invoice customer.');
            }
            $receivable = $this->payments->receivableForSale((int) $note['sale_id'], true);
            if ($receivable === null || $receivable['voided_at'] !== null) {
                throw new DomainException('An active receivable is required before posting this credit note.');
            }
            $postedNotes = $this->creditNotes->lockPostedForSale((int) $note['sale_id']);
            $postedCredit = '0.0000';
            foreach ($postedNotes as $postedNote) {
                $postedCredit = Decimal::add($postedCredit, (string) $postedNote['amount_khr'], 4);
            }
            $creditable = Decimal::subtract((string) $sale['total_khr'], $postedCredit, 4);
            if (Decimal::compare((string) $note['amount_khr'], $creditable, 4) > 0) {
                throw new DomainException('This credit note exceeds the remaining creditable invoice amount.');
            }
            $reserved = Decimal::nonNegative($this->payments->activeAllocationTotal((int) $receivable['id']), 4);
            $paid = Decimal::nonNegative($this->payments->verifiedInboundAllocationTotal((int) $receivable['id']), 4);
            if (Decimal::compare($reserved, $paid, 4) !== 0) {
                throw new DomainException('Verify or reject pending invoice payments before posting a credit note.');
            }
            $previousReduction = Decimal::nonNegative($this->creditNotes->activeReceivableReductionTotal((int) $receivable['id']), 4);
            $outstanding = Decimal::subtract(
                Decimal::subtract((string) $receivable['original_khr'], $paid, 4),
                $previousReduction,
                4,
            );
            $amount = Decimal::positive((string) $note['amount_khr'], 4);
            $reduction = Decimal::compare($amount, $outstanding, 4) > 0 ? $outstanding : $amount;
            $excess = Decimal::subtract($amount, $reduction, 4);
            if (!Decimal::isZeroValue($reduction)) {
                $this->creditNotes->createReceivableApplication($creditNoteId, (int) $receivable['id'], $reduction, $actorId);
            }
            if (!$this->creditNotes->markPosted($creditNoteId, $actorId, $reduction, $excess)) {
                throw new DomainException('The credit note status changed before it could be posted.');
            }
            $this->auditLog->record($actorId, 'credit_note.posted', 'credit_notes', $creditNoteId, 'receivable:' . $reduction . ';excess:' . $excess);
            return [
                'credit_note_id' => $creditNoteId,
                'posted' => true,
                'receivable_reduction_khr' => $reduction,
                'excess_credit_khr' => $excess,
            ];
        });
    }

    /** @return array{credit_note_id: int, reversed: bool} */
    public function reverse(int $actorId, int $creditNoteId, string $reason): array
    {
        $this->assertOwnerFinancialControl($actorId);
        $reason = $this->reason($reason, 'A credit-note reversal reason');
        return $this->transaction(function () use ($actorId, $creditNoteId, $reason): array {
            $note = $this->creditNotes->find($creditNoteId, true);
            if ($note === null) {
                throw new DomainException('The credit note was not found.');
            }
            if ($note['status'] === 'reversed') {
                return ['credit_note_id' => $creditNoteId, 'reversed' => false];
            }
            if ($note['status'] !== 'posted') {
                throw new DomainException('Only a posted credit note can be reversed.');
            }
            if ($this->creditNotes->hasRefundReservation($creditNoteId)) {
                throw new DomainException('A posted credit note with an approved or released refund cannot be reversed.');
            }
            if ($this->creditNotes->hasConfirmedPhysicalReturn($creditNoteId)) {
                throw new DomainException('A posted credit note linked to a confirmed physical return cannot be reversed.');
            }
            $reversed = $this->creditNotes->reverse($creditNoteId, (string) $note['amount_khr'], $reason, $actorId);
            $this->auditLog->record($actorId, 'credit_note.reversed', 'credit_notes', $creditNoteId, $reason);
            return ['credit_note_id' => $creditNoteId, 'reversed' => $reversed];
        });
    }

    /** @param array<string, mixed> $input @return array{refund_id: int, created: bool} */
    public function createRefundDraft(int $actorId, array $input): array
    {
        $this->assertOwnerFinancialControl($actorId);
        $key = $this->idempotencyKey((string) ($input['refund_key'] ?? $input['idempotency_key'] ?? ''), 'refund');
        $creditNoteId = $this->positiveId($input['credit_note_id'] ?? null, 'A valid posted credit note is required.');
        $methodCode = strtolower(trim((string) ($input['payment_method'] ?? '')));
        if (!in_array($methodCode, ['cash', 'bank', 'khqr'], true)) {
            throw new DomainException('Refund method must be cash, bank, or KHQR.');
        }
        $method = $this->payments->activeMethod($methodCode);
        if ($method === null) {
            throw new DomainException('The selected refund method is not active.');
        }
        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        if (!in_array($currency, ['KHR', 'USD'], true)) {
            throw new DomainException('Refund currency must be KHR or USD.');
        }
        $amountOriginal = Decimal::positive((string) ($input['amount_original'] ?? ''), 4);
        $exchangeRate = Decimal::positive((string) ($input['exchange_rate_to_khr'] ?? ''), 6);
        if ($currency === 'KHR' && Decimal::compare($exchangeRate, '1.000000', 6) !== 0) {
            throw new DomainException('KHR refunds must use an exchange rate of exactly 1.000000.');
        }
        $amountKhr = Decimal::multiplyRounded($amountOriginal, $exchangeRate, 4);
        $reference = trim((string) ($input['reference_no'] ?? ''));
        if ($this->length($reference) > 120) {
            throw new DomainException('Refund reference may contain at most 120 characters.');
        }
        if (($methodCode === 'bank' || $methodCode === 'khqr') && $reference === '') {
            throw new DomainException('Bank transfer and KHQR refunds require a manual reference.');
        }
        $reason = $this->reason($input['reason'] ?? null, 'A refund reason');
        $existing = $this->creditNotes->findRefundByKey($key);
        if ($existing !== null) {
            return ['refund_id' => (int) $existing['id'], 'created' => false];
        }

        return $this->transaction(function () use ($actorId, $key, $creditNoteId, $method, $currency, $amountOriginal, $exchangeRate, $amountKhr, $reference, $reason): array {
            $existing = $this->creditNotes->findRefundByKey($key, true);
            if ($existing !== null) {
                return ['refund_id' => (int) $existing['id'], 'created' => false];
            }
            $note = $this->creditNotes->find($creditNoteId, true);
            if ($note === null || $note['status'] !== 'posted' || Decimal::isZeroValue((string) $note['excess_credit_khr'])) {
                throw new DomainException('A posted credit note with refundable excess is required.');
            }
            if (Decimal::compare($amountKhr, (string) $note['excess_credit_khr'], 4) > 0) {
                throw new DomainException('The refund exceeds the originating credit note excess.');
            }
            $refundId = $this->creditNotes->createRefund(
                $this->generateNumber('RF'),
                $key,
                $creditNoteId,
                (int) $note['customer_id'],
                (int) $method['id'],
                $currency,
                $amountOriginal,
                $exchangeRate,
                $amountKhr,
                $reference === '' ? null : $reference,
                $reason,
                $actorId,
            );
            $this->auditLog->record($actorId, 'refund.draft_created', 'refunds', $refundId, 'credit_note:' . $creditNoteId);
            return ['refund_id' => $refundId, 'created' => true];
        });
    }

    /** @return array{refund_id: int, approved: bool} */
    public function approveRefund(int $actorId, int $refundId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        return $this->transaction(function () use ($actorId, $refundId): array {
            $refund = $this->creditNotes->findRefund($refundId, true);
            if ($refund === null) {
                throw new DomainException('The refund was not found.');
            }
            if ($refund['status'] === 'approved') {
                return ['refund_id' => $refundId, 'approved' => false];
            }
            if ($refund['status'] !== 'draft') {
                throw new DomainException('Only a draft refund can be approved.');
            }
            $note = $this->creditNotes->find((int) $refund['credit_note_id'], true);
            if ($note === null || $note['status'] !== 'posted') {
                throw new DomainException('The originating credit note is no longer refundable.');
            }
            $reserved = Decimal::nonNegative($this->creditNotes->reservedRefundTotal((int) $note['id']), 4);
            $available = Decimal::subtract((string) $note['excess_credit_khr'], $reserved, 4);
            if (Decimal::compare((string) $refund['amount_khr'], $available, 4) > 0) {
                throw new DomainException('The refund exceeds currently available refundable customer credit.');
            }
            $approved = $this->creditNotes->approveRefund($refundId, $actorId);
            $this->auditLog->record($actorId, 'refund.approved', 'refunds', $refundId);
            return ['refund_id' => $refundId, 'approved' => $approved];
        });
    }

    /** @return array{refund_id: int, posted: bool, verification_status: string} */
    public function postRefund(int $actorId, int $refundId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        return $this->transaction(function () use ($actorId, $refundId): array {
            $refund = $this->creditNotes->findRefund($refundId, true);
            if ($refund === null) {
                throw new DomainException('The refund was not found.');
            }
            if ($refund['status'] === 'posted') {
                $payment = $refund['payment_id'] === null ? null : $this->payments->find((int) $refund['payment_id']);
                return [
                    'refund_id' => $refundId,
                    'posted' => false,
                    'verification_status' => $payment === null ? 'pending' : (string) $payment['verification_status'],
                ];
            }
            if ($refund['status'] !== 'approved') {
                throw new DomainException('Only an approved refund can be released.');
            }
            $note = $this->creditNotes->find((int) $refund['credit_note_id'], true);
            if ($note === null || $note['status'] !== 'posted') {
                throw new DomainException('The originating credit note is no longer refundable.');
            }
            $existingPayment = $this->payments->findByKey((string) $refund['refund_key'], true);
            if ($existingPayment !== null) {
                throw new DomainException('This refund key is already linked to another payment.');
            }
            $verificationStatus = $refund['method_code'] === 'cash' ? 'verified' : 'pending';
            $paymentId = $this->payments->createOutgoingPayment(
                (string) $refund['refund_key'],
                (int) $refund['payment_method_id'],
                (int) $refund['customer_id'],
                'customer_credit_refund',
                (string) $refund['amount_original'],
                (string) $refund['currency'],
                (string) $refund['exchange_rate_to_khr'],
                (string) $refund['amount_khr'],
                $refund['reference_no'] === null ? null : (string) $refund['reference_no'],
                $verificationStatus,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $actorId,
                $refund['method_code'] === 'cash' ? $actorId : null,
            );
            if (!$this->creditNotes->markRefundPosted($refundId, $paymentId, $actorId)) {
                throw new DomainException('The refund status changed before it could be released.');
            }
            $this->auditLog->record($actorId, 'refund.posted', 'refunds', $refundId, $verificationStatus);
            return ['refund_id' => $refundId, 'posted' => true, 'verification_status' => $verificationStatus];
        });
    }

    /** @return array{refund_id: int, changed: bool, verification_status: string} */
    public function verifyRefund(int $actorId, int $refundId, bool $approved, string $reason = ''): array
    {
        $this->assertOwnerFinancialControl($actorId);
        $reason = trim($reason);
        if (!$approved) {
            $reason = $this->reason($reason, 'A refund rejection reason');
        }
        return $this->transaction(function () use ($actorId, $refundId, $approved, $reason): array {
            $refund = $this->creditNotes->findRefund($refundId, true);
            if ($refund === null || $refund['payment_id'] === null) {
                throw new DomainException('A released refund was not found.');
            }
            $payment = $this->payments->find((int) $refund['payment_id'], true);
            if ($payment === null || $payment['direction'] !== 'out') {
                throw new DomainException('The linked outgoing refund payment was not found.');
            }
            if ($payment['verification_status'] !== 'pending') {
                return ['refund_id' => $refundId, 'changed' => false, 'verification_status' => (string) $payment['verification_status']];
            }
            if ($approved) {
                $changed = $this->payments->markVerified((int) $payment['id'], $actorId);
                $this->auditLog->record($actorId, 'refund.verified', 'refunds', $refundId);
                return ['refund_id' => $refundId, 'changed' => $changed, 'verification_status' => 'verified'];
            }
            $changed = $this->payments->markRejected((int) $payment['id'], $actorId);
            if ($changed && !$this->creditNotes->rejectRefund($refundId, $actorId, $reason)) {
                throw new DomainException('The refund could not be marked rejected.');
            }
            $this->auditLog->record($actorId, 'refund.rejected', 'refunds', $refundId, $reason);
            return ['refund_id' => $refundId, 'changed' => $changed, 'verification_status' => 'rejected'];
        });
    }

    /** @return array{refund_id: int, reversed: bool} */
    public function reversePendingRefund(int $actorId, int $refundId, string $reason): array
    {
        $this->assertOwnerFinancialControl($actorId);
        $reason = $this->reason($reason, 'A pending-refund reversal reason');
        return $this->transaction(function () use ($actorId, $refundId, $reason): array {
            $refund = $this->creditNotes->findRefund($refundId, true);
            if ($refund === null || $refund['payment_id'] === null) {
                throw new DomainException('A released pending refund was not found.');
            }
            $payment = $this->payments->find((int) $refund['payment_id'], true);
            if ($payment === null || $payment['verification_status'] !== 'pending') {
                throw new DomainException('Only an unverified bank or KHQR refund can be reversed in this workflow.');
            }
            $rejected = $this->payments->markRejected((int) $payment['id'], $actorId);
            $reversed = $rejected && $this->creditNotes->reversePendingRefund($refundId, $actorId, $reason);
            if (!$reversed) {
                throw new DomainException('The pending refund could not be reversed.');
            }
            $this->auditLog->record($actorId, 'refund.reversed', 'refunds', $refundId, $reason);
            return ['refund_id' => $refundId, 'reversed' => true];
        });
    }

    /** @return list<array<string, mixed>> */
    public function creditNotesForOwner(int $actorId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        return $this->creditNotes->listCreditNotes();
    }

    /** @return list<array<string, mixed>> */
    public function refundsForOwner(int $actorId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        return $this->creditNotes->listRefunds();
    }

    /** @return array{gross_sales_revenue: string, credit_notes_khr: string, net_sales_revenue: string, refundable_customer_credit: string, verified_refunds_khr: string} */
    public function financialReconciliation(int $actorId): array
    {
        $this->assertOwnerFinancialControl($actorId);
        $totals = $this->pdo->query(
            "SELECT
                COALESCE((SELECT SUM(s.total_khr) FROM sales s WHERE s.status = 'confirmed'), 0) AS gross_sales_revenue,
                COALESCE((SELECT SUM(cn.amount_khr) FROM credit_notes cn WHERE cn.status = 'posted'), 0) AS credit_notes_khr,
                COALESCE((SELECT SUM(cn.excess_credit_khr) FROM credit_notes cn WHERE cn.status = 'posted'), 0) AS credit_excess_khr,
                COALESCE((SELECT SUM(r.amount_khr) FROM refunds r JOIN payments p ON p.id = r.payment_id
                          WHERE r.status = 'posted' AND p.direction = 'out' AND p.verification_status = 'verified'), 0) AS verified_refunds_khr"
        )->fetch();
        $gross = is_array($totals) ? (string) $totals['gross_sales_revenue'] : '0.0000';
        $credits = is_array($totals) ? (string) $totals['credit_notes_khr'] : '0.0000';
        $excess = is_array($totals) ? (string) $totals['credit_excess_khr'] : '0.0000';
        $refunds = is_array($totals) ? (string) $totals['verified_refunds_khr'] : '0.0000';
        return [
            'gross_sales_revenue' => $gross,
            'credit_notes_khr' => $credits,
            'net_sales_revenue' => Decimal::signedSubtract($gross, $credits, 4),
            'refundable_customer_credit' => Decimal::subtract($excess, $refunds, 4),
            'verified_refunds_khr' => $refunds,
        ];
    }

    /** @param array<string, mixed>|null $sale */
    private function assertEligibleSale(?array $sale): void
    {
        if ($sale === null || $sale['status'] !== 'confirmed' || $sale['customer_id'] === null) {
            throw new DomainException('Only a confirmed, non-voided customer sale is eligible for a credit note.');
        }
    }

    private function assertOwnerFinancialControl(int $actorId): void
    {
        if (!$this->users->isOwner($actorId)) {
            throw new DomainException('Only an Owner may approve credit notes or release refunds.');
        }
    }

    private function idempotencyKey(string $value, string $label): string
    {
        $value = trim($value);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/', $value)) {
            throw new DomainException(sprintf('A 16-80 character %s idempotency key is required.', $label));
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

    private function generateNumber(string $prefix): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = $prefix . '-' . (new \DateTimeImmutable())->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $table = $prefix === 'CN' ? 'credit_notes' : 'refunds';
            $column = $prefix === 'CN' ? 'credit_note_no' : 'refund_no';
            $statement = $this->pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $column . ' = ? LIMIT 1');
            $statement->execute([$number]);
            if (!$statement->fetchColumn()) {
                return $number;
            }
        }
        throw new DomainException('Unable to generate a unique financial document number. Please try again.');
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
            throw new DomainException('The financial transaction could not be saved because it conflicts with existing data.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
