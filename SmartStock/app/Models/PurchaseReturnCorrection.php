<?php
declare(strict_types=1);
namespace App\Models;

use PDO;

/** Persistence only. A posted correction is immutable and has no update/delete API. */
final class PurchaseReturnCorrection
{
    public function __construct(private PDO $pdo) {}
    public function find(int $id, bool $lock=false): ?array { $q=$this->pdo->prepare('SELECT * FROM purchase_return_corrections WHERE id=?'.($lock?' FOR UPDATE':'')); $q->execute([$id]); return $q->fetch() ?: null; }
    public function byKey(string $key): ?array { $q=$this->pdo->prepare('SELECT * FROM purchase_return_corrections WHERE document_key=?'); $q->execute([$key]); return $q->fetch() ?: null; }
    public function items(int $id, bool $lock=false): array { $q=$this->pdo->prepare('SELECT * FROM purchase_return_correction_items WHERE correction_id=? ORDER BY product_id,id'.($lock?' FOR UPDATE':'')); $q->execute([$id]); return $q->fetchAll(); }
    public function postings(int $id): array { $q=$this->pdo->prepare('SELECT p.* FROM purchase_return_correction_postings p JOIN purchase_return_correction_items i ON i.id=p.correction_item_id WHERE i.correction_id=? ORDER BY p.id'); $q->execute([$id]); return $q->fetchAll(); }
    public function list(int $returnId): array { $q=$this->pdo->prepare('SELECT * FROM purchase_return_corrections WHERE purchase_return_id=? ORDER BY id DESC'); $q->execute([$returnId]); return $q->fetchAll(); }
    public function create(array $data): int { return $this->insert('purchase_return_corrections',$data); }
    public function createItem(array $data): int { return $this->insert('purchase_return_correction_items',$data); }
    public function post(array $data): int { return $this->insert('purchase_return_correction_postings',$data); }
    public function approve(int $id,string $hash,int $actor): void { $this->insert('purchase_return_correction_approvals',['correction_id'=>$id,'content_hash'=>$hash,'approved_by'=>$actor]); }
    public function approval(int $id): ?array { $q=$this->pdo->prepare('SELECT * FROM purchase_return_correction_approvals WHERE correction_id=?'); $q->execute([$id]); return $q->fetch() ?: null; }
    public function transition(array $doc,string $to,int $actor,?string $reason=null): void {
        if (!in_array($to,['submitted','approved','rejected','cancelled'],true)) { throw new \LogicException('Invalid correction transition.'); }
        $field=in_array($to,['rejected','cancelled'],true)?'terminated':$to;
        $sql='UPDATE purchase_return_corrections SET status=?,'.$field.'_by=?,'.$field.'_at=NOW()'; $args=[$to,$actor];
        if ($reason!==null) { $sql.=',termination_reason=?'; $args[]=$reason; }
        $q=$this->pdo->prepare($sql.' WHERE id=? AND status=?'); $q->execute([...$args,$doc['id'],$doc['status']]); if ($q->rowCount()!==1) { throw new \DomainException('Correction state changed. Reload and retry.'); }
    }
    public function markPosted(int $id,int $actor): void { $q=$this->pdo->prepare("UPDATE purchase_return_corrections SET status='posted',posted_by=?,posted_at=NOW() WHERE id=? AND status='approved'"); $q->execute([$actor,$id]); if($q->rowCount()!==1){throw new \DomainException('Correction is not approved.');} }
    /** Immutable original return evidence, including the negative stock/cost entry. */
    public function source(int $postingId, bool $lock=false): ?array {
        $q=$this->pdo->prepare("SELECT p.*,i.return_id,i.product_id,i.quantity AS return_quantity,i.qty_base AS return_base,i.conversion_snapshot,i.unit_precision_snapshot,
            r.location_id,r.status AS return_status,r.confirmed_at,r.confirmed_by,sm.qty_delta AS movement_qty,sm.unit_cost_khr AS movement_cost,sm.source_type,sm.source_id,sm.source_line_id,
            ce.qty_delta AS cost_qty,ce.value_delta_khr AS cost_value,ce.costing_type,ce.source_type AS cost_source_type,ce.source_id AS cost_source_id,ce.source_line_id AS cost_source_line
            FROM purchase_return_postings p JOIN purchase_return_items i ON i.id=p.return_item_id JOIN purchase_returns r ON r.id=i.return_id
            LEFT JOIN stock_movements sm ON sm.id=p.stock_movement_id LEFT JOIN inventory_cost_entries ce ON ce.stock_movement_id=sm.id WHERE p.id=?".($lock?' FOR UPDATE':''));
        $q->execute([$postingId]); return $q->fetch() ?: null;
    }
    /** Posted, not merely approved, corrections consume the original returned quantity. */
    public function usage(int $returnPostingId): array { $q=$this->pdo->prepare("SELECT COALESCE(SUM(i.quantity),0) quantity,COALESCE(SUM(i.qty_base),0) qty_base,COALESCE(SUM(p.carrying_value_khr),0) carrying_value_khr FROM purchase_return_correction_items i JOIN purchase_return_corrections c ON c.id=i.correction_id JOIN purchase_return_correction_postings p ON p.correction_item_id=i.id WHERE i.purchase_return_posting_id=? AND c.status='posted'"); $q->execute([$returnPostingId]); return $q->fetch(); }
    public function hasCreditLinks(int $returnItemId): bool { $q=$this->pdo->prepare('SELECT 1 FROM purchase_return_credit_links WHERE return_item_id=? LIMIT 1'); $q->execute([$returnItemId]); return (bool)$q->fetchColumn(); }
    public function evidenceForPurchase(int $purchaseId): array { $q=$this->pdo->prepare("SELECT c.id correction_id,i.id correction_item_id,c.*,i.*,p.id posting_id,p.stock_movement_id,p.posting_key,p.quantity posted_quantity,p.qty_base posted_base,p.unit_cost_khr posted_cost,p.carrying_value_khr posted_value,sm.qty_delta movement_qty,sm.location_id movement_location,sm.product_id movement_product,sm.source_type movement_source,sm.source_id movement_source_id,sm.source_line_id movement_source_line,ce.qty_delta cost_qty,ce.value_delta_khr cost_value,ce.costing_type,ce.source_type cost_source,ce.source_id cost_source_id,ce.source_line_id cost_source_line FROM purchase_return_corrections c JOIN purchase_returns r ON r.id=c.purchase_return_id JOIN purchase_return_correction_items i ON i.correction_id=c.id LEFT JOIN purchase_return_correction_postings p ON p.correction_item_id=i.id LEFT JOIN stock_movements sm ON sm.id=p.stock_movement_id LEFT JOIN inventory_cost_entries ce ON ce.stock_movement_id=sm.id WHERE r.purchase_id=? ORDER BY c.id,i.id"); $q->execute([$purchaseId]); return $q->fetchAll(); }
    private function insert(string $table,array $data): int { $q=$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')'); $q->execute(array_values($data)); return (int)$this->pdo->lastInsertId(); }
}
