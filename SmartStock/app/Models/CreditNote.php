<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class CreditNote
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $creditNoteId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, credit_note_no, credit_note_key, sale_id, customer_id, status, amount_khr,
                    receivable_reduction_khr, excess_credit_khr, reason, created_by, created_at,
                    approved_by, approved_at, posted_by, posted_at
             FROM credit_notes WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$creditNoteId]);
        $note = $statement->fetch();
        return is_array($note) ? $note : null;
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $creditNoteKey, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, credit_note_no, credit_note_key, sale_id, customer_id, status, amount_khr,
                    receivable_reduction_khr, excess_credit_khr, reason, created_by, created_at,
                    approved_by, approved_at, posted_by, posted_at
             FROM credit_notes WHERE credit_note_key = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$creditNoteKey]);
        $note = $statement->fetch();
        return is_array($note) ? $note : null;
    }

    public function create(
        string $number,
        string $key,
        int $saleId,
        int $customerId,
        string $amountKhr,
        string $reason,
        int $createdBy,
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO credit_notes
             (credit_note_no, credit_note_key, sale_id, customer_id, status, amount_khr, reason, created_by)
             VALUES (?, ?, ?, ?, 'draft', ?, ?, ?)"
        );
        $statement->execute([$number, $key, $saleId, $customerId, $amountKhr, $reason, $createdBy]);
        return (int) $this->pdo->lastInsertId();
    }

    public function approve(int $creditNoteId, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE credit_notes SET status = 'approved', approved_by = ?, approved_at = NOW()
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([$actorId, $creditNoteId]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> */
    public function lockPostedForSale(int $saleId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, amount_khr, receivable_reduction_khr, excess_credit_khr
             FROM credit_notes WHERE sale_id = ? AND status = 'posted' ORDER BY id FOR UPDATE"
        );
        $statement->execute([$saleId]);
        return $statement->fetchAll();
    }

    public function activeReceivableReductionTotal(int $receivableId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(cnra.amount_khr), 0)
             FROM credit_note_receivable_applications cnra
             JOIN credit_notes cn ON cn.id = cnra.credit_note_id
             WHERE cnra.receivable_id = ? AND cn.status = 'posted'"
        );
        $statement->execute([$receivableId]);
        return (string) $statement->fetchColumn();
    }

    public function createReceivableApplication(int $creditNoteId, int $receivableId, string $amountKhr, int $actorId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO credit_note_receivable_applications (credit_note_id, receivable_id, amount_khr, applied_by)
             VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$creditNoteId, $receivableId, $amountKhr, $actorId]);
    }

    public function markPosted(int $creditNoteId, int $actorId, string $receivableReductionKhr, string $excessCreditKhr): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE credit_notes
             SET status = 'posted', receivable_reduction_khr = ?, excess_credit_khr = ?, posted_by = ?, posted_at = NOW()
             WHERE id = ? AND status = 'approved'"
        );
        $statement->execute([$receivableReductionKhr, $excessCreditKhr, $actorId, $creditNoteId]);
        return $statement->rowCount() === 1;
    }

    public function hasRefundReservation(int $creditNoteId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM refunds WHERE credit_note_id = ? AND status IN ('approved', 'posted')"
        );
        $statement->execute([$creditNoteId]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function hasConfirmedPhysicalReturn(int $creditNoteId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM sale_returns WHERE credit_note_id = ? AND status = 'confirmed' LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$creditNoteId]);
        return $statement->fetch() !== false;
    }

    public function reverse(int $creditNoteId, string $amountKhr, string $reason, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE credit_notes SET status = 'reversed' WHERE id = ? AND status = 'posted'"
        );
        $statement->execute([$creditNoteId]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        $reversal = $this->pdo->prepare(
            'INSERT INTO credit_note_reversals (credit_note_id, amount_khr, reason, reversed_by) VALUES (?, ?, ?, ?)'
        );
        $reversal->execute([$creditNoteId, $amountKhr, $reason, $actorId]);
        return true;
    }

    /** @return array<string, mixed>|null */
    public function findRefund(int $refundId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.refund_no, r.refund_key, r.credit_note_id, r.customer_id, r.payment_method_id,
                    pm.code AS method_code, r.currency, r.amount_original, r.exchange_rate_to_khr, r.amount_khr,
                    r.reference_no, r.reason, r.status, r.created_by, r.created_at, r.approved_by, r.approved_at,
                    r.posted_by, r.posted_at, r.payment_id, r.rejection_reason, r.reversed_by, r.reversed_at, r.reversal_reason
             FROM refunds r JOIN payment_methods pm ON pm.id = r.payment_method_id
             WHERE r.id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$refundId]);
        $refund = $statement->fetch();
        return is_array($refund) ? $refund : null;
    }

    /** @return array<string, mixed>|null */
    public function findRefundByKey(string $refundKey, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.refund_no, r.refund_key, r.credit_note_id, r.customer_id, r.payment_method_id,
                    pm.code AS method_code, r.currency, r.amount_original, r.exchange_rate_to_khr, r.amount_khr,
                    r.reference_no, r.reason, r.status, r.created_by, r.created_at, r.approved_by, r.approved_at,
                    r.posted_by, r.posted_at, r.payment_id, r.rejection_reason, r.reversed_by, r.reversed_at, r.reversal_reason
             FROM refunds r JOIN payment_methods pm ON pm.id = r.payment_method_id
             WHERE r.refund_key = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$refundKey]);
        $refund = $statement->fetch();
        return is_array($refund) ? $refund : null;
    }

    public function createRefund(
        string $number,
        string $key,
        int $creditNoteId,
        int $customerId,
        int $paymentMethodId,
        string $currency,
        string $amountOriginal,
        string $exchangeRate,
        string $amountKhr,
        ?string $reference,
        string $reason,
        int $createdBy,
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO refunds
             (refund_no, refund_key, credit_note_id, customer_id, payment_method_id, currency, amount_original,
              exchange_rate_to_khr, amount_khr, reference_no, reason, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        $statement->execute([
            $number, $key, $creditNoteId, $customerId, $paymentMethodId, $currency, $amountOriginal,
            $exchangeRate, $amountKhr, $reference, $reason, $createdBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function reservedRefundTotal(int $creditNoteId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount_khr), 0) FROM refunds
             WHERE credit_note_id = ? AND status IN ('approved', 'posted')"
        );
        $statement->execute([$creditNoteId]);
        return (string) $statement->fetchColumn();
    }

    public function verifiedRefundTotal(int $creditNoteId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(r.amount_khr), 0)
             FROM refunds r JOIN payments p ON p.id = r.payment_id
             WHERE r.credit_note_id = ? AND r.status = 'posted'
               AND p.direction = 'out' AND p.verification_status = 'verified'"
        );
        $statement->execute([$creditNoteId]);
        return (string) $statement->fetchColumn();
    }

    public function approveRefund(int $refundId, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE refunds SET status = 'approved', approved_by = ?, approved_at = NOW()
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([$actorId, $refundId]);
        return $statement->rowCount() === 1;
    }

    public function markRefundPosted(int $refundId, int $paymentId, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE refunds SET status = 'posted', payment_id = ?, posted_by = ?, posted_at = NOW()
             WHERE id = ? AND status = 'approved'"
        );
        $statement->execute([$paymentId, $actorId, $refundId]);
        return $statement->rowCount() === 1;
    }

    public function rejectRefund(int $refundId, int $actorId, string $reason): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE refunds SET status = 'rejected', rejection_reason = ?
             WHERE id = ? AND status = 'posted'"
        );
        $statement->execute([$reason, $refundId]);
        return $statement->rowCount() === 1;
    }

    public function reversePendingRefund(int $refundId, int $actorId, string $reason): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE refunds SET status = 'reversed', reversed_by = ?, reversed_at = NOW(), reversal_reason = ?
             WHERE id = ? AND status = 'posted'"
        );
        $statement->execute([$actorId, $reason, $refundId]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string, mixed>> */
    public function listCreditNotes(): array
    {
        return $this->pdo->query(
            "SELECT cn.id, cn.credit_note_no, cn.status, cn.amount_khr, cn.receivable_reduction_khr, cn.excess_credit_khr,
                    cn.reason, cn.created_at, cn.approved_at, cn.posted_at, s.invoice_no, c.name AS customer_name,
                    creator.full_name AS created_by_name
             FROM credit_notes cn JOIN sales s ON s.id = cn.sale_id JOIN customers c ON c.id = cn.customer_id
             JOIN users creator ON creator.id = cn.created_by
             ORDER BY cn.created_at DESC, cn.id DESC LIMIT 100"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listRefunds(): array
    {
        return $this->pdo->query(
            "SELECT r.id, r.refund_no, r.status, r.currency, r.amount_original, r.exchange_rate_to_khr, r.amount_khr,
                    r.reference_no, r.reason, cn.credit_note_no, c.name AS customer_name, pm.name AS method_name,
                    p.verification_status
             FROM refunds r JOIN credit_notes cn ON cn.id = r.credit_note_id
             JOIN customers c ON c.id = r.customer_id JOIN payment_methods pm ON pm.id = r.payment_method_id
             LEFT JOIN payments p ON p.id = r.payment_id
             ORDER BY r.created_at DESC, r.id DESC LIMIT 100"
        )->fetchAll();
    }
}
