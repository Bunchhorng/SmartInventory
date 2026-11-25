<?php
declare(strict_types=1);
namespace App\Models;

use PDO;

/** Supplier-only financial documents; never accesses customer-credit ledgers. */
final class SupplierAdjustment
{
    public function __construct(private PDO $pdo) {}
    public function table(string $kind): string
    {
        return match ($kind) { 'credit' => 'supplier_credit_notes', 'correction' => 'supplier_payment_corrections', default => throw new \LogicException('Invalid adjustment kind.') };
    }
    private function column(string $kind): string { $this->table($kind); return $kind . '_id'; }
    public function find(string $kind, int $id, bool $lock = false): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . $this->table($kind) . ' WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]); return $q->fetch() ?: null;
    }
    public function byKey(string $kind, string $key): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . $this->table($kind) . ' WHERE document_key = ? FOR UPDATE');
        $q->execute([$key]); return $q->fetch() ?: null;
    }
    public function create(string $kind, array $data): int
    {
        $columns = $kind === 'credit' ? ['document_key','request_hash','supplier_id','invoice_id','credit_no','normalized_credit_no','currency','exchange_rate_to_khr','amount_original','reason','created_by']
            : ['document_key','request_hash','supplier_id','supplier_payment_id','source_hash','reason','resolution','evidence_reference','evidence','created_by'];
        $q = $this->pdo->prepare('INSERT INTO ' . $this->table($kind) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $q->execute(array_map(fn ($c) => $data[$c], $columns)); return (int) $this->pdo->lastInsertId();
    }
    public function update(string $kind, int $id, array $data): void
    {
        $allowed = $kind === 'credit' ? ['credit_no','normalized_credit_no','amount_original','reason'] : ['reason','resolution','evidence_reference','evidence'];
        if (array_diff(array_keys($data), $allowed)) { throw new \LogicException('Invalid draft columns.'); }
        if (!$data) { return; }
        $q = $this->pdo->prepare('UPDATE ' . $this->table($kind) . ' SET ' . implode(',', array_map(fn ($c) => $c . '=?', array_keys($data))) . " WHERE id=? AND status='draft'");
        $q->execute([...array_values($data), $id]);
    }
    public function transition(string $kind, array $doc, string $to, int $actor, ?string $reason = null): void
    {
        if (!in_array($to, ['submitted','approved','posted','rejected','cancelled'], true)) { throw new \LogicException('Invalid transition.'); }
        $sets = "status=?, {$to}_by=?, {$to}_at=NOW()"; $args = [$to, $actor];
        if (in_array($to, ['rejected','cancelled'], true)) { $sets .= ',termination_reason=?'; $args[] = $reason; }
        $q = $this->pdo->prepare('UPDATE ' . $this->table($kind) . " SET {$sets} WHERE id=? AND status=?");
        $q->execute([...$args, $doc['id'], $doc['status']]);
        if ($q->rowCount() !== 1) { throw new \DomainException('Adjustment state changed. Reload and retry.'); }
    }
    public function approval(string $kind, int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_financial_approvals WHERE ' . $this->column($kind) . '=?');
        $q->execute([$id]); return $q->fetch() ?: null;
    }
    public function approve(string $kind, int $id, string $hash, int $actor): void
    {
        $this->pdo->prepare('INSERT INTO supplier_financial_approvals (' . $this->column($kind) . ',content_hash,approved_by) VALUES (?,?,?)')->execute([$id,$hash,$actor]);
    }
    public function list(string $kind, int $supplierId, ?int $page = 1): array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . $this->table($kind) . ' WHERE supplier_id=? ORDER BY id' . ($page === null ? '' : ' DESC LIMIT 50 OFFSET ?'));
        $q->bindValue(1, $supplierId, PDO::PARAM_INT);
        if ($page !== null) { $q->bindValue(2, ($page - 1) * 50, PDO::PARAM_INT); }
        $q->execute(); return $q->fetchAll();
    }
    public function activeCorrection(int $paymentId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_payment_corrections WHERE active_payment_id=?');
        $q->execute([$paymentId]); return $q->fetch() ?: null;
    }
    public function creditAllocation(int $id): ?array { return $this->one('supplier_credit_allocations', 'id', $id); }
    public function correctionAllocation(int $id): ?array { return $this->one('supplier_payment_correction_allocations', 'id', $id); }
    public function reapplication(int $id): ?array { return $this->one('supplier_credit_reapplications', 'id', $id); }
    public function creditPosting(int $id): ?array { return $this->one('supplier_credit_allocations', 'credit_id', $id); }
    private function one(string $table, string $column, int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $column . '=?'); $q->execute([$id]); return $q->fetch() ?: null;
    }
    public function corrections(int $id): array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_payment_correction_allocations WHERE correction_id=? ORDER BY payable_id');
        $q->execute([$id]); return $q->fetchAll();
    }
    public function activities(int $payableId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_payable_activities WHERE payable_id=? ORDER BY id'); $q->execute([$payableId]); return $q->fetchAll();
    }
    public function activity(int $payable, string $kind, int $source): void
    {
        $column = match ($kind) { 'payment' => 'payment_allocation_id', 'credit' => 'credit_allocation_id', 'correction' => 'correction_allocation_id', 'reapplication' => 'reapplication_id', 'credit_return_correction' => 'credit_return_correction_posting_id', default => throw new \LogicException('Invalid activity.') };
        $this->pdo->prepare('INSERT INTO supplier_payable_activities (payable_id,' . $column . ') VALUES (?,?)')->execute([$payable,$source]);
    }
    public function postCredit(array $data): int { return $this->insert('supplier_credit_allocations', $data); }
    public function postCorrection(array $data): int { return $this->insert('supplier_payment_correction_allocations', $data); }
    public function reapply(array $data): int { return $this->insert('supplier_credit_reapplications', $data); }
    private function insert(string $table, array $data): int
    {
        // Column names are internal service constants, never accepted from request data.
        $q = $this->pdo->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0,count($data),'?')) . ')');
        $q->execute(array_values($data)); return (int) $this->pdo->lastInsertId();
    }
    public function postingCounts(int $payable): array
    {
        $counts = [];
        foreach (['credit' => 'supplier_credit_allocations','correction' => 'supplier_payment_correction_allocations','reapplication' => 'supplier_credit_reapplications','credit_return_correction' => 'supplier_credit_return_correction_postings'] as $kind => $table) {
            $q = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE payable_id=?'); $q->execute([$payable]); $counts[$kind] = (int) $q->fetchColumn();
        }
        return $counts;
    }
    public function reapplicationsForSupplier(int $supplier): array
    {
        $q = $this->pdo->prepare('SELECT r.*, a.credit_id, c.correction_id FROM supplier_credit_reapplications r
            JOIN supplier_credit_allocations a ON a.id=r.credit_allocation_id
            JOIN supplier_payment_correction_allocations c ON c.id=r.correction_allocation_id
            JOIN payables p ON p.id=r.payable_id WHERE p.supplier_id=? ORDER BY r.id');
        $q->execute([$supplier]); return $q->fetchAll();
    }
}
