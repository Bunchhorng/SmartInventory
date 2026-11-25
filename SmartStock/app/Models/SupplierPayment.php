<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class SupplierPayment
{
    public function __construct(private PDO $pdo) {}

    public function find(int $id, bool $lock = false): ?array
    {
        // Only the document is lock-scoped. Do not serialize unrelated suppliers
        // by taking an exclusive lock on a shared cash/bank method row.
        $q = $this->pdo->prepare('SELECT d.*, (SELECT m.code FROM payment_methods m WHERE m.id = d.payment_method_id) AS method_code
            FROM supplier_payments d WHERE d.id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]); return $q->fetch() ?: null;
    }

    public function byKey(string $key): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_payments WHERE payment_key = ? FOR UPDATE');
        $q->execute([$key]); return $q->fetch() ?: null;
    }

    public function create(string $key, string $hash, int $supplier, ?string $reference, ?string $notes, int $actor): int
    {
        $q = $this->pdo->prepare('INSERT INTO supplier_payments
            (payment_key, request_hash, supplier_id, reference_no, normalized_reference, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $q->execute([$key, $hash, $supplier, $reference, self::normalizeReference($reference), $notes, $actor]);
        return (int) $this->pdo->lastInsertId();
    }

    public static function normalizeReference(?string $reference): ?string
    {
        if ($reference === null) { return null; }
        $reference = preg_replace('/\s+/u', ' ', trim($reference));
        return function_exists('mb_strtoupper') ? mb_strtoupper($reference, 'UTF-8') : strtoupper($reference);
    }

    public function update(int $id, array $data): void
    {
        $allowed = ['payment_method_id','currency','amount_original','exchange_rate_to_khr','amount_khr',
            'settlement_usd_rate_to_khr','reference_no','normalized_reference','paid_at','notes'];
        $sets = []; $values = [];
        foreach ($data as $column => $value) {
            if (!in_array($column, $allowed, true)) { throw new \LogicException('Unsupported payment column.'); }
            $sets[] = $column . ' = ?'; $values[] = $value;
        }
        if ($sets === []) { return; }
        $this->pdo->prepare("UPDATE supplier_payments SET " . implode(', ', $sets) . " WHERE id = ? AND status = 'draft'")
            ->execute([...$values, $id]);
    }

    public function items(int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_payment_items WHERE supplier_payment_id = ? ORDER BY invoice_id');
        $q->execute([$id]); return $q->fetchAll();
    }

    public function replaceItems(int $id, array $items): void
    {
        $this->pdo->prepare('DELETE FROM supplier_payment_items WHERE supplier_payment_id = ?')->execute([$id]);
        $q = $this->pdo->prepare('INSERT INTO supplier_payment_items
            (supplier_payment_id, invoice_id, invoice_amount_original, payment_amount_original) VALUES (?, ?, ?, ?)');
        foreach ($items as $item) { $q->execute([$id, $item['invoice_id'], $item['invoice_amount_original'], $item['payment_amount_original']]); }
    }

    public function transition(int $id, string $from, string $to, int $actor, ?string $reason = null, ?int $paymentId = null): void
    {
        if (!in_array($to, ['submitted','approved','posted','rejected','cancelled'], true)) { throw new \LogicException('Invalid transition.'); }
        $sets = "status = ?, {$to}_by = ?, {$to}_at = NOW()"; $values = [$to, $actor];
        if ($to === 'posted') { $sets .= ', payment_id = ?'; $values[] = $paymentId; }
        if ($to === 'rejected' || $to === 'cancelled') {
            $sets .= ', ' . ($to === 'rejected' ? 'rejection_reason' : 'cancellation_reason') . ' = ?'; $values[] = $reason;
        }
        $q = $this->pdo->prepare("UPDATE supplier_payments SET {$sets} WHERE id = ? AND status = ?");
        $q->execute([...$values, $id, $from]);
        if ($q->rowCount() !== 1) { throw new \DomainException('The payment state changed. Reload and try again.'); }
    }

    public function approval(int $id): ?array { return $this->evidence('supplier_payment_approvals', $id); }
    public function verification(int $id): ?array { return $this->evidence('supplier_payment_verifications', $id); }
    private function evidence(string $table, int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE supplier_payment_id = ?');
        $q->execute([$id]); return $q->fetch() ?: null;
    }
    public function approve(int $id, string $hash, int $actor): void
    {
        $this->pdo->prepare('INSERT INTO supplier_payment_approvals (supplier_payment_id, content_hash, approved_by) VALUES (?, ?, ?)')
            ->execute([$id, $hash, $actor]);
    }
    public function verify(int $id, string $hash, string $evidence, int $actor): void
    {
        $this->pdo->prepare('INSERT INTO supplier_payment_verifications (supplier_payment_id, content_hash, evidence, verified_by) VALUES (?, ?, ?, ?)')
            ->execute([$id, $hash, $evidence, $actor]);
    }

    public function reservedOriginal(int $invoiceId, int $exceptDocument): string
    {
        $q = $this->pdo->prepare("SELECT COALESCE(SUM(i.invoice_amount_original), 0) FROM supplier_payment_items i
            JOIN supplier_payments d ON d.id = i.supplier_payment_id
            WHERE i.invoice_id = ? AND d.status = 'approved' AND d.id <> ?");
        $q->execute([$invoiceId, $exceptDocument]); return (string) $q->fetchColumn();
    }

    public function postLedger(array $doc, int $actor, ?array $verification): int
    {
        $q = $this->pdo->prepare("INSERT INTO payments (payment_key, direction, payment_method_id, customer_id, supplier_id,
            payment_purpose, amount_original, currency, exchange_rate_to_khr, amount_khr, reference_no,
            verification_status, verified_at, verified_by, paid_at, recorded_by)
            VALUES (?, 'out', ?, NULL, ?, 'supplier_payment', ?, ?, ?, ?, ?, 'verified', ?, ?, ?, ?)");
        $q->execute(['supplier-payment:' . $doc['id'], $doc['payment_method_id'], $doc['supplier_id'], $doc['amount_original'],
            $doc['currency'], $doc['exchange_rate_to_khr'], $doc['amount_khr'], $doc['reference_no'],
            $verification['verified_at'] ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $verification['verified_by'] ?? $actor, $doc['paid_at'], $actor]);
        return (int) $this->pdo->lastInsertId();
    }

    public function allocate(int $docId, int $paymentId, array $line, int $actor): void
    {
        $q = $this->pdo->prepare('INSERT INTO payable_allocations
            (payable_id, payment_id, amount_khr, supplier_payment_id, invoice_amount_original, payment_amount_original, cash_amount_khr, allocated_by, posting_version)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 2)');
        $q->execute([$line['payable_id'], $paymentId, $line['amount_khr'], $docId, $line['invoice_amount_original'],
            $line['payment_amount_original'], $line['cash_amount_khr'], $actor]);
        $allocationId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO supplier_payment_differences (allocation_id, fx_difference_khr, rounding_difference_khr) VALUES (?, ?, ?)')
            ->execute([$allocationId, $line['fx_difference_khr'], $line['rounding_difference_khr']]);
        $this->pdo->prepare('INSERT INTO supplier_payable_activities (payable_id, payment_allocation_id) VALUES (?,?)')
            ->execute([$line['payable_id'], $allocationId]);
    }

    public function allocations(int $docId): array
    {
        $q = $this->pdo->prepare('SELECT a.*, p.supplier_invoice_id AS invoice_id, v.fx_difference_khr, v.rounding_difference_khr
            FROM payable_allocations a JOIN payables p ON p.id = a.payable_id
            LEFT JOIN supplier_payment_differences v ON v.allocation_id = a.id WHERE a.supplier_payment_id = ? ORDER BY p.supplier_invoice_id');
        $q->execute([$docId]); return $q->fetchAll();
    }

    public function list(int $supplierId, ?int $creator = null, int $page = 1, ?int $limit = 50): array
    {
        $sql = 'SELECT id FROM supplier_payments WHERE supplier_id = ?'; $args = [$supplierId];
        if ($creator !== null) { $sql .= ' AND created_by = ?'; $args[] = $creator; }
        $sql .= ' ORDER BY id';
        if ($limit !== null) { $sql .= ' DESC LIMIT ? OFFSET ?'; $args[] = $limit; $args[] = ($page - 1) * $limit; }
        $q = $this->pdo->prepare($sql);
        foreach ($args as $i => $arg) { $q->bindValue($i + 1, $arg, PDO::PARAM_INT); }
        $q->execute(); return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }

    public function duplicateReferences(int $supplierId): array
    {
        $q = $this->pdo->prepare('SELECT normalized_reference, payment_method_id, COUNT(*) AS occurrences
            FROM supplier_payments WHERE supplier_id = ? AND normalized_reference IS NOT NULL
            GROUP BY normalized_reference, payment_method_id HAVING COUNT(*) > 1');
        $q->execute([$supplierId]); return $q->fetchAll();
    }
}
