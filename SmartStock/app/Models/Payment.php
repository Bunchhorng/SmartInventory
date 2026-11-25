<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Payment
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $paymentKey, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, payment_key, direction, payment_method_id, customer_id, payment_purpose, amount_original,
                    currency, exchange_rate_to_khr, amount_khr, reference_no, verification_status, paid_at,
                    recorded_by, verified_at, verified_by, supplier_id
             FROM payments WHERE payment_key = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$paymentKey]);
        $payment = $statement->fetch();
        return is_array($payment) ? $payment : null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $paymentId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, payment_key, direction, payment_method_id, customer_id, payment_purpose, amount_original,
                    currency, exchange_rate_to_khr, amount_khr, reference_no, verification_status, paid_at,
                    recorded_by, verified_at, verified_by, supplier_id
             FROM payments WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$paymentId]);
        $payment = $statement->fetch();
        return is_array($payment) ? $payment : null;
    }

    /** @return array<string, mixed>|null */
    public function activeMethod(string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name FROM payment_methods WHERE code = ? AND is_active = 1 LIMIT 1'
        );
        $statement->execute([$code]);
        $method = $statement->fetch();
        return is_array($method) ? $method : null;
    }

    /** @return list<array<string, mixed>> */
    public function activeMethods(): array
    {
        return $this->pdo->query(
            'SELECT id, code, name FROM payment_methods WHERE is_active = 1 AND code IN (\'cash\', \'bank\', \'khqr\') ORDER BY id'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function lockReceivablesBySaleIds(array $saleIds): array
    {
        if ($saleIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($saleIds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.sale_id, r.customer_id, r.due_date, r.original_khr, r.voided_at,
                    s.invoice_no, s.status AS sale_status, s.settlement_type
             FROM receivables r
             JOIN sales s ON s.id = r.sale_id
             WHERE r.sale_id IN (' . $placeholders . ')
             ORDER BY r.id FOR UPDATE'
        );
        $statement->execute($saleIds);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function receivableForSale(int $saleId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, sale_id, customer_id, due_date, credit_terms_days, credit_limit_khr, original_khr,
                    voided_at, voided_by, void_reason
             FROM receivables WHERE sale_id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$saleId]);
        $receivable = $statement->fetch();
        return is_array($receivable) ? $receivable : null;
    }

    public function createReceivable(
        int $saleId,
        ?int $customerId,
        ?string $dueDate,
        int $creditTermsDays,
        string $creditLimitKhr,
        string $originalKhr,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO receivables
             (sale_id, customer_id, due_date, credit_terms_days, credit_limit_khr, original_khr)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$saleId, $customerId, $dueDate, $creditTermsDays, $creditLimitKhr, $originalKhr]);
        return (int) $this->pdo->lastInsertId();
    }

    public function activeAllocationTotal(int $receivableId): string
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_khr), 0) FROM receivable_allocations
             WHERE receivable_id = ? AND reversed_at IS NULL'
        );
        $statement->execute([$receivableId]);
        return (string) $statement->fetchColumn();
    }

    public function verifiedInboundAllocationTotal(int $receivableId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(ra.amount_khr), 0)
             FROM receivable_allocations ra
             JOIN payments p ON p.id = ra.payment_id
             WHERE ra.receivable_id = ? AND ra.reversed_at IS NULL
               AND p.direction = 'in' AND p.verification_status = 'verified'"
        );
        $statement->execute([$receivableId]);
        return (string) $statement->fetchColumn();
    }

    /** Posted credit notes reduce a receivable independently of cash collection. */
    public function activeCreditNoteReductionTotal(int $receivableId): string
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

    public function customerExposure(int $customerId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(r.original_khr - COALESCE((
                    SELECT SUM(ra.amount_khr)
                    FROM receivable_allocations ra
                    JOIN payments p ON p.id = ra.payment_id
                    WHERE ra.receivable_id = r.id AND ra.reversed_at IS NULL
                      AND p.direction = 'in' AND p.verification_status = 'verified'
                ), 0) - COALESCE((
                    SELECT SUM(cnra.amount_khr)
                    FROM credit_note_receivable_applications cnra
                    JOIN credit_notes cn ON cn.id = cnra.credit_note_id
                    WHERE cnra.receivable_id = r.id AND cn.status = 'posted'
                ), 0)), 0)
             FROM receivables r
             WHERE r.customer_id = ? AND r.voided_at IS NULL"
        );
        $statement->execute([$customerId]);
        return (string) $statement->fetchColumn();
    }

    public function createPayment(
        string $paymentKey,
        int $paymentMethodId,
        ?int $customerId,
        string $purpose,
        string $amountOriginal,
        string $currency,
        string $exchangeRate,
        string $amountKhr,
        ?string $referenceNo,
        string $verificationStatus,
        string $paidAt,
        int $recordedBy,
        ?int $verifiedBy,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO payments
             (payment_key, direction, payment_method_id, customer_id, payment_purpose, amount_original,
              currency, exchange_rate_to_khr, amount_khr, reference_no, verification_status, paid_at,
              recorded_by, verified_at, verified_by)
             VALUES (?, \'in\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $paymentKey,
            $paymentMethodId,
            $customerId,
            $purpose,
            $amountOriginal,
            $currency,
            $exchangeRate,
            $amountKhr,
            $referenceNo,
            $verificationStatus,
            $paidAt,
            $recordedBy,
            $verifiedBy === null ? null : (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $verifiedBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createOutgoingPayment(
        string $paymentKey,
        int $paymentMethodId,
        int $customerId,
        string $purpose,
        string $amountOriginal,
        string $currency,
        string $exchangeRate,
        string $amountKhr,
        ?string $referenceNo,
        string $verificationStatus,
        string $paidAt,
        int $recordedBy,
        ?int $verifiedBy,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO payments
             (payment_key, direction, payment_method_id, customer_id, payment_purpose, amount_original,
              currency, exchange_rate_to_khr, amount_khr, reference_no, verification_status, paid_at,
              recorded_by, verified_at, verified_by)
             VALUES (?, \'out\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $paymentKey,
            $paymentMethodId,
            $customerId,
            $purpose,
            $amountOriginal,
            $currency,
            $exchangeRate,
            $amountKhr,
            $referenceNo,
            $verificationStatus,
            $paidAt,
            $recordedBy,
            $verifiedBy === null ? null : (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            $verifiedBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createAllocation(int $receivableId, int $paymentId, string $amountKhr, int $allocatedBy): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO receivable_allocations (receivable_id, payment_id, amount_khr, allocated_by)
             VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$receivableId, $paymentId, $amountKhr, $allocatedBy]);
    }

    public function markVerified(int $paymentId, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments SET verification_status = 'verified', verified_at = NOW(), verified_by = ?
             WHERE id = ? AND verification_status = 'pending'"
        );
        $statement->execute([$actorId, $paymentId]);
        return $statement->rowCount() === 1;
    }

    public function markRejected(int $paymentId, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE payments SET verification_status = 'rejected', verified_at = NOW(), verified_by = ?
             WHERE id = ? AND verification_status = 'pending'"
        );
        $statement->execute([$actorId, $paymentId]);
        return $statement->rowCount() === 1;
    }

    public function rejectAndReverseAllocations(int $paymentId, int $actorId, string $reason): bool
    {
        $payment = $this->find($paymentId, true);
        if ($payment === null || $payment['verification_status'] !== 'pending') {
            return false;
        }
        $statement = $this->pdo->prepare(
            "UPDATE payments SET verification_status = 'rejected', verified_at = NOW(), verified_by = ?
             WHERE id = ? AND verification_status = 'pending'"
        );
        $statement->execute([$actorId, $paymentId]);
        if ($statement->rowCount() !== 1) {
            return false;
        }
        $reverse = $this->pdo->prepare(
            'UPDATE receivable_allocations
             SET reversed_at = NOW(), reversed_by = ?, reversal_reason = ?
             WHERE payment_id = ? AND reversed_at IS NULL'
        );
        $reverse->execute([$actorId, $reason, $paymentId]);
        return true;
    }

    public function assertSaleCanBeVoided(int $saleId): void
    {
        $receivable = $this->receivableForSale($saleId, true);
        if ($receivable === null) {
            return;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM receivable_allocations WHERE receivable_id = ? AND reversed_at IS NULL'
        );
        $statement->execute([(int) $receivable['id']]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new \DomainException('This sale has payment allocations. Void it only after an Owner completes a compensating refund or credit-adjustment workflow.');
        }
        $creditNotes = $this->pdo->prepare(
            "SELECT id FROM credit_notes WHERE sale_id = ? AND status = 'posted' FOR UPDATE"
        );
        $creditNotes->execute([$saleId]);
        if ($creditNotes->fetch() !== false) {
            throw new \DomainException('This sale has a posted credit note. Reverse the credit note before voiding the sale.');
        }
        $returns = $this->pdo->prepare(
            "SELECT id FROM sale_returns WHERE sale_id = ? AND status = 'confirmed' LIMIT 1 FOR UPDATE"
        );
        $returns->execute([$saleId]);
        if ($returns->fetch() !== false) {
            throw new \DomainException('This sale has a confirmed physical return and cannot be fully voided.');
        }
    }

    public function markReceivableVoided(int $saleId, int $actorId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE receivables SET voided_at = NOW(), voided_by = ?, void_reason = ?
             WHERE sale_id = ? AND voided_at IS NULL'
        );
        $statement->execute([$actorId, $reason, $saleId]);
    }

    /** @return list<array<string, mixed>> */
    public function listReceivables(bool $includeCustomerCredit, int $limit = 100): array
    {
        $customerColumns = $includeCustomerCredit
            ? ', c.credit_limit, c.credit_enabled, c.payment_terms_days'
            : '';
        $statement = $this->pdo->prepare(
            "SELECT r.id, r.sale_id, r.customer_id, r.due_date, r.original_khr, r.voided_at,
                    s.invoice_no, s.status AS sale_status, s.settlement_type,
                    c.name AS customer_name" . $customerColumns . ",
                    COALESCE((SELECT SUM(ra.amount_khr)
                              FROM receivable_allocations ra JOIN payments p ON p.id = ra.payment_id
                              WHERE ra.receivable_id = r.id AND ra.reversed_at IS NULL
                                AND p.direction = 'in' AND p.verification_status = 'verified'), 0) AS paid_khr,
                    COALESCE((SELECT SUM(cnra.amount_khr)
                              FROM credit_note_receivable_applications cnra JOIN credit_notes cn ON cn.id = cnra.credit_note_id
                              WHERE cnra.receivable_id = r.id AND cn.status = 'posted'), 0) AS credited_khr,
                    r.original_khr - COALESCE((SELECT SUM(ra2.amount_khr)
                                                FROM receivable_allocations ra2 JOIN payments p2 ON p2.id = ra2.payment_id
                                                WHERE ra2.receivable_id = r.id AND ra2.reversed_at IS NULL
                                                  AND p2.direction = 'in' AND p2.verification_status = 'verified'), 0)
                                   - COALESCE((SELECT SUM(cnra2.amount_khr)
                                               FROM credit_note_receivable_applications cnra2 JOIN credit_notes cn2 ON cn2.id = cnra2.credit_note_id
                                               WHERE cnra2.receivable_id = r.id AND cn2.status = 'posted'), 0) AS outstanding_khr,
                    CASE
                        WHEN r.voided_at IS NOT NULL THEN 'voided'
                        WHEN COALESCE((SELECT SUM(ra3.amount_khr)
                                       FROM receivable_allocations ra3 JOIN payments p3 ON p3.id = ra3.payment_id
                                       WHERE ra3.receivable_id = r.id AND ra3.reversed_at IS NULL
                                         AND p3.direction = 'in' AND p3.verification_status = 'verified'), 0)
                             + COALESCE((SELECT SUM(cnra3.amount_khr)
                                         FROM credit_note_receivable_applications cnra3 JOIN credit_notes cn3 ON cn3.id = cnra3.credit_note_id
                                         WHERE cnra3.receivable_id = r.id AND cn3.status = 'posted'), 0) >= r.original_khr THEN 'paid'
                        WHEN COALESCE((SELECT SUM(ra4.amount_khr)
                                       FROM receivable_allocations ra4 JOIN payments p4 ON p4.id = ra4.payment_id
                                       WHERE ra4.receivable_id = r.id AND ra4.reversed_at IS NULL
                                         AND p4.direction = 'in' AND p4.verification_status = 'verified'), 0)
                             + COALESCE((SELECT SUM(cnra4.amount_khr)
                                         FROM credit_note_receivable_applications cnra4 JOIN credit_notes cn4 ON cn4.id = cnra4.credit_note_id
                                         WHERE cnra4.receivable_id = r.id AND cn4.status = 'posted'), 0) > 0 THEN 'partial'
                        WHEN r.due_date IS NOT NULL AND r.due_date < CURDATE() THEN 'overdue'
                        ELSE 'unpaid'
                    END AS payment_status
             FROM receivables r
             JOIN sales s ON s.id = r.sale_id
             LEFT JOIN customers c ON c.id = r.customer_id
             ORDER BY r.voided_at IS NULL DESC, r.due_date IS NULL, r.due_date, r.id DESC
             LIMIT ?"
        );
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listPayments(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.payment_key, p.direction, p.payment_purpose, p.amount_original, p.currency,
                    p.exchange_rate_to_khr, p.amount_khr, p.reference_no, p.verification_status, p.paid_at,
                    pm.code AS method_code, pm.name AS method_name, c.name AS customer_name,
                    recorder.full_name AS recorded_by_name, verifier.full_name AS verified_by_name
             FROM payments p
             JOIN payment_methods pm ON pm.id = p.payment_method_id
             LEFT JOIN customers c ON c.id = p.customer_id
             JOIN users recorder ON recorder.id = p.recorded_by
             LEFT JOIN users verifier ON verifier.id = p.verified_by
             WHERE p.direction = \'in\'
             ORDER BY p.paid_at DESC, p.id DESC LIMIT ?'
        );
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return array{cash_received: string, bank_received: string, pending_bank: string, customer_credit_outstanding: string} */
    public function reconciliationTotals(): array
    {
        $statement = $this->pdo->query(
            "SELECT
                COALESCE(SUM(CASE WHEN p.verification_status = 'verified' AND pm.code = 'cash' THEN p.amount_khr ELSE 0 END), 0) AS cash_received,
                COALESCE(SUM(CASE WHEN p.verification_status = 'verified' AND pm.code IN ('bank', 'khqr') THEN p.amount_khr ELSE 0 END), 0) AS bank_received,
                COALESCE(SUM(CASE WHEN p.verification_status = 'pending' AND pm.code IN ('bank', 'khqr') THEN p.amount_khr ELSE 0 END), 0) AS pending_bank,
                COALESCE((SELECT SUM(r.original_khr - COALESCE((
                    SELECT SUM(ra.amount_khr) FROM receivable_allocations ra
                    JOIN payments inbound ON inbound.id = ra.payment_id
                    WHERE ra.receivable_id = r.id AND ra.reversed_at IS NULL
                      AND inbound.direction = 'in' AND inbound.verification_status = 'verified'
                ), 0) - COALESCE((
                    SELECT SUM(cnra.amount_khr)
                    FROM credit_note_receivable_applications cnra
                    JOIN credit_notes cn ON cn.id = cnra.credit_note_id
                    WHERE cnra.receivable_id = r.id AND cn.status = 'posted'
                ), 0)) FROM receivables r WHERE r.customer_id IS NOT NULL AND r.voided_at IS NULL), 0) AS customer_credit_outstanding
             FROM payments p JOIN payment_methods pm ON pm.id = p.payment_method_id
             WHERE p.direction = 'in'"
        );
        $totals = $statement->fetch();
        return [
            'cash_received' => is_array($totals) ? (string) $totals['cash_received'] : '0.0000',
            'bank_received' => is_array($totals) ? (string) $totals['bank_received'] : '0.0000',
            'pending_bank' => is_array($totals) ? (string) $totals['pending_bank'] : '0.0000',
            'customer_credit_outstanding' => is_array($totals) ? (string) $totals['customer_credit_outstanding'] : '0.0000',
        ];
    }
}
