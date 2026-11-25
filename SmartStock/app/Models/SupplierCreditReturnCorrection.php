<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Persistence for Phase 5.8's compensating supplier-credit correction only. */
final class SupplierCreditReturnCorrection
{
    public function __construct(private PDO $pdo) {}

    public function find(int $id, bool $lock = false): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_credit_return_corrections WHERE id=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]); return $q->fetch() ?: null;
    }

    public function byKey(string $key, bool $lock = false): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_credit_return_corrections WHERE document_key=?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$key]); return $q->fetch() ?: null;
    }

    public function create(array $data): int { return $this->insert('supplier_credit_return_corrections', $data); }
    public function post(array $data): int { return $this->insert('supplier_credit_return_correction_postings', $data); }

    public function transition(array $doc, string $to, int $actor, ?string $reason = null): void
    {
        if (!in_array($to, ['submitted','approved','posted','rejected','cancelled'], true)) { throw new \LogicException('Invalid correction transition.'); }
        $field = in_array($to, ['rejected','cancelled'], true) ? 'terminated' : $to;
        $sql = 'UPDATE supplier_credit_return_corrections SET status=?,'.$field.'_by=?,'.$field.'_at=NOW()';
        $args = [$to,$actor];
        if ($reason !== null) { $sql .= ',termination_reason=?'; $args[] = $reason; }
        $q = $this->pdo->prepare($sql.' WHERE id=? AND status=?');
        $q->execute([...$args,$doc['id'],$doc['status']]);
        if ($q->rowCount() !== 1) { throw new \DomainException('Correction state changed. Reload and retry.'); }
    }

    public function approve(int $id, string $hash, int $actor): void
    {
        $this->insert('supplier_credit_return_correction_approvals', ['correction_id'=>$id,'content_hash'=>$hash,'approved_by'=>$actor]);
    }

    public function approval(int $id): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM supplier_credit_return_correction_approvals WHERE correction_id=?');
        $q->execute([$id]); return $q->fetch() ?: null;
    }

    public function posting(int $correctionId): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM supplier_credit_return_correction_postings WHERE correction_id=?');
        $q->execute([$correctionId]); return $q->fetch() ?: null;
    }

    public function postingById(int $id): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM supplier_credit_return_correction_postings WHERE id=?');
        $q->execute([$id]); return $q->fetch() ?: null;
    }

    public function list(int $supplierId): array
    {
        $q=$this->pdo->prepare('SELECT * FROM supplier_credit_return_corrections WHERE supplier_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([$supplierId]); return $q->fetchAll();
    }

    /** Locks all business records required to prove that a P5.6 attribution is the exact source. */
    public function sourceLink(int $linkId, bool $lock = false): ?array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $q=$this->pdo->prepare("SELECT l.*, ri.return_id,ri.goods_receipt_item_id,ri.product_id,ri.quantity AS return_quantity,ri.qty_base AS return_qty_base,
            ri.conversion_snapshot,ri.unit_precision_snapshot,r.supplier_id AS return_supplier_id,r.purchase_id,r.status AS return_status,
            rp.id AS return_posting_id,rp.quantity AS return_posted_quantity,rp.qty_base AS return_posted_qty_base,rp.carrying_value_khr AS return_carrying_value_khr,
            c.status AS credit_status,c.supplier_id AS credit_supplier_id,c.invoice_id AS credit_invoice_id,c.currency AS credit_currency,
            c.exchange_rate_to_khr AS credit_exchange_rate,c.amount_original AS credit_amount_original,c.posted_by AS credit_posted_by,c.posted_at AS credit_posted_at,
            a.id AS credit_allocation_id,a.payable_id,a.amount_original AS allocation_original,a.amount_khr AS allocation_khr,
            a.applied_original,a.applied_khr,a.recoverable_original,a.recoverable_khr,a.rounding_difference_khr,
            i.status AS invoice_status,i.supplier_id AS invoice_supplier_id,i.purchase_id AS invoice_purchase_id,i.currency AS invoice_currency,i.exchange_rate_to_khr AS invoice_exchange_rate,
            m.invoice_id AS match_invoice_id,m.payable_id AS match_payable_id,m.goods_receipt_item_id AS match_goods_receipt_item_id,m.quantity AS match_quantity,m.qty_base AS match_qty_base,
            p.supplier_id AS payable_supplier_id,p.supplier_invoice_id,p.currency AS payable_currency,p.exchange_rate_to_khr AS payable_exchange_rate,p.amount_original AS payable_original,p.original_khr AS payable_khr
            FROM purchase_return_credit_links l
            JOIN purchase_return_items ri ON ri.id=l.return_item_id JOIN purchase_returns r ON r.id=ri.return_id
            JOIN purchase_return_postings rp ON rp.return_item_id=ri.id
            JOIN supplier_credit_notes c ON c.id=l.credit_id JOIN supplier_credit_allocations a ON a.credit_id=c.id
            JOIN supplier_invoices i ON i.id=c.invoice_id JOIN supplier_invoice_matches m ON m.id=l.invoice_match_id
            JOIN payables p ON p.id=a.payable_id WHERE l.id=?".$suffix);
        $q->execute([$linkId]); return $q->fetch() ?: null;
    }

    /** Posted-only totals are authoritative capacities; draft requests never reserve a financial or physical amount. */
    public function totalsForLink(int $linkId): array
    {
        $q=$this->pdo->prepare("SELECT COALESCE(SUM(p.amount_original),0) amount_original,COALESCE(SUM(p.amount_khr),0) amount_khr,
            COALESCE(SUM(p.restored_ap_original),0) restored_ap_original,COALESCE(SUM(p.restored_ap_khr),0) restored_ap_khr,
            COALESCE(SUM(p.reduced_recoverable_original),0) reduced_recoverable_original,COALESCE(SUM(p.reduced_recoverable_khr),0) reduced_recoverable_khr,
            COALESCE(SUM(p.quantity),0) quantity,COALESCE(SUM(p.qty_base),0) qty_base
            FROM supplier_credit_return_correction_postings p JOIN supplier_credit_return_corrections c ON c.id=p.correction_id
            WHERE p.purchase_return_credit_link_id=? AND c.status='posted'");
        $q->execute([$linkId]); return $q->fetch();
    }

    public function totalsForCredit(int $creditId): array
    {
        $q=$this->pdo->prepare("SELECT COALESCE(SUM(p.amount_original),0) amount_original,COALESCE(SUM(p.amount_khr),0) amount_khr,
            COALESCE(SUM(p.restored_ap_original),0) restored_ap_original,COALESCE(SUM(p.restored_ap_khr),0) restored_ap_khr,
            COALESCE(SUM(p.reduced_recoverable_original),0) reduced_recoverable_original,COALESCE(SUM(p.reduced_recoverable_khr),0) reduced_recoverable_khr,
            COALESCE(SUM(p.quantity),0) quantity,COALESCE(SUM(p.qty_base),0) qty_base
            FROM supplier_credit_return_correction_postings p JOIN supplier_credit_return_corrections c ON c.id=p.correction_id WHERE p.credit_id=? AND c.status='posted'");
        $q->execute([$creditId]); return $q->fetch();
    }

    /** Amounts released from this exact financial attribution, grouped by physical return source. */
    public function physicalCapacity(int $returnPostingId): array
    {
        $q=$this->pdo->prepare("SELECT COALESCE(SUM(p.quantity),0) quantity,COALESCE(SUM(p.qty_base),0) qty_base
            FROM supplier_credit_return_correction_postings p JOIN supplier_credit_return_corrections c ON c.id=p.correction_id
            JOIN purchase_return_credit_links l ON l.id=p.purchase_return_credit_link_id
            JOIN purchase_return_postings rp ON rp.return_item_id=l.return_item_id
            WHERE rp.id=? AND c.status='posted'");
        $q->execute([$returnPostingId]); return $q->fetch();
    }

    public function allForPurchase(int $purchaseId): array
    {
        $q=$this->pdo->prepare("SELECT c.*,p.id AS posting_id,p.amount_khr,p.restored_ap_original,p.restored_ap_khr,p.reduced_recoverable_original,p.reduced_recoverable_khr,p.quantity,p.qty_base,
            l.amount_original AS linked_amount_original,l.quantity AS linked_quantity,l.qty_base AS linked_qty_base,l.credit_value_khr,
            rp.id AS return_posting_id,rp.quantity AS return_quantity,rp.qty_base AS return_qty_base
            FROM supplier_credit_return_corrections c LEFT JOIN supplier_credit_return_correction_postings p ON p.correction_id=c.id
            JOIN purchase_returns r ON r.id=c.purchase_return_id JOIN purchase_return_credit_links l ON l.id=c.purchase_return_credit_link_id
            JOIN purchase_return_postings rp ON rp.return_item_id=l.return_item_id WHERE r.purchase_id=? ORDER BY c.id");
        $q->execute([$purchaseId]); return $q->fetchAll();
    }

    private function insert(string $table, array $data): int
    {
        $q=$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');
        $q->execute(array_values($data)); return (int)$this->pdo->lastInsertId();
    }
}
