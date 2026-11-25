<?php
declare(strict_types=1);
namespace App\Models;

use PDO;

/** Persistence only. Posted rows and credit attributions have no update/delete API. */
final class PurchaseReturn
{
    public function __construct(private PDO $pdo) {}
    public function find(int $id, bool $lock = false): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_returns WHERE id=?'.($lock?' FOR UPDATE':''));
        $q->execute([$id]); return $q->fetch() ?: null;
    }
    public function byKey(string $key): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_returns WHERE document_key=?'); $q->execute([$key]); return $q->fetch() ?: null;
    }
    public function items(int $id, bool $lock = false): array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_return_items WHERE return_id=? ORDER BY product_id,id'.($lock?' FOR UPDATE':''));
        $q->execute([$id]); return $q->fetchAll();
    }
    public function list(int $purchaseId, ?int $page = null): array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_returns WHERE purchase_id=? ORDER BY id'.($page===null?'':' DESC LIMIT 50 OFFSET ?'));
        $q->bindValue(1,$purchaseId,PDO::PARAM_INT); if($page!==null) { $q->bindValue(2,($page-1)*50,PDO::PARAM_INT); }
        $q->execute(); return $q->fetchAll();
    }
    public function create(array $data): int { return $this->insert('purchase_returns',$data); }
    public function createItem(array $data): int { return $this->insert('purchase_return_items',$data); }
    public function post(array $data): int { return $this->insert('purchase_return_postings',$data); }
    public function link(array $data): int { return $this->insert('purchase_return_credit_links',$data); }
    private function insert(string $table, array $data): int
    {
        // Table/column names are internal constants; all values are bound.
        $q=$this->pdo->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')');
        $q->execute(array_values($data)); return (int)$this->pdo->lastInsertId();
    }
    public function transition(array $doc, string $to, int $actor, ?string $reason = null): void
    {
        if(!in_array($to,['submitted','approved','rejected','cancelled'],true)) { throw new \LogicException('Invalid return transition.'); }
        $sql='UPDATE purchase_returns SET status=?,'.$to.'_by=?,'.$to.'_at=NOW()'; $args=[$to,$actor];
        if($reason!==null) { $sql.=',termination_reason=?'; $args[]=$reason; }
        $q=$this->pdo->prepare($sql.' WHERE id=? AND status=?'); $q->execute([...$args,$doc['id'],$doc['status']]);
        if($q->rowCount()!==1) { throw new \DomainException('Return state changed. Reload and retry.'); }
    }
    public function confirm(int $id, int $actor, string $reference): void
    {
        $q=$this->pdo->prepare("UPDATE purchase_returns SET status='confirmed',dispatched_by=?,dispatched_at=NOW(),
            dispatch_reference=?,confirmed_by=?,confirmed_at=NOW() WHERE id=? AND status='approved'");
        $q->execute([$actor,$reference,$actor,$id]); if($q->rowCount()!==1) { throw new \DomainException('Return is not approved.'); }
    }
    public function approve(int $id, string $hash, int $actor): void
    {
        $this->insert('purchase_return_approvals',['return_id'=>$id,'content_hash'=>$hash,'approved_by'=>$actor]);
    }
    public function approval(int $id): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_return_approvals WHERE return_id=?'); $q->execute([$id]); return $q->fetch() ?: null;
    }
    /** Count immutable physical postings, even if a document status was corrupted. */
    public function usage(int $goodsItemId): array
    {
        $q=$this->pdo->prepare('SELECT COALESCE(SUM(p.quantity),0) AS quantity,COALESCE(SUM(p.qty_base),0) AS qty_base,
            COALESCE(SUM(p.receipt_value_khr),0) AS receipt_value_khr FROM purchase_return_postings p
            JOIN purchase_return_items i ON i.id=p.return_item_id WHERE i.goods_receipt_item_id=?');
        $q->execute([$goodsItemId]); return $q->fetch();
    }
    public function postings(int $returnId): array
    {
        $q=$this->pdo->prepare('SELECT p.* FROM purchase_return_postings p JOIN purchase_return_items i ON i.id=p.return_item_id WHERE i.return_id=? ORDER BY p.id');
        $q->execute([$returnId]); return $q->fetchAll();
    }
    public function linksForItem(int $id): array { return $this->links('return_item_id',$id); }
    public function linksForCredit(int $id): array { return $this->links('credit_id',$id); }
    public function linksForMatch(int $id): array { return $this->links('invoice_match_id',$id); }
    private function links(string $column, int $id): array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_return_credit_links WHERE '.$column.'=? ORDER BY id'); $q->execute([$id]); return $q->fetchAll();
    }
    public function linkByKey(string $key): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM purchase_return_credit_links WHERE link_key=?'); $q->execute([$key]); return $q->fetch() ?: null;
    }
    public function evidence(int $purchaseId): array
    {
        $q=$this->pdo->prepare("SELECT i.*,r.status,r.location_id,r.confirmed_by,r.dispatched_by,r.confirmed_at,r.dispatched_at,r.dispatch_reference,
            p.id AS posting_id,p.quantity AS posted_quantity,p.qty_base AS posted_base,p.posting_key,p.stock_movement_id,
            p.unit_cost_khr,p.carrying_value_khr,p.receipt_value_khr,p.carrying_difference_khr,p.posted_by,
            sm.id AS movement_id,sm.product_id AS movement_product,sm.location_id AS movement_location,
            sm.qty_delta AS movement_qty,sm.unit_cost_khr AS movement_cost,sm.movement_key,sm.batch_id,sm.created_by AS movement_actor,
            ce.id AS cost_entry_id,ce.product_id AS cost_product,ce.qty_delta AS cost_qty,ce.value_delta_khr AS cost_value,
            ce.unit_cost_snapshot_khr AS cost_unit,ce.source_type AS cost_source,ce.source_id AS cost_source_id,
            ce.source_line_id AS cost_source_line,ce.costing_type,ce.avg_unit_cost_before_khr,ce.avg_unit_cost_after_khr,
            ce.created_by AS cost_actor
            FROM purchase_returns r JOIN purchase_return_items i ON i.return_id=r.id
            LEFT JOIN purchase_return_postings p ON p.return_item_id=i.id
            LEFT JOIN stock_movements sm ON sm.source_type='purchase_return' AND sm.source_id=r.id AND sm.source_line_id=i.id
            LEFT JOIN inventory_cost_entries ce ON ce.stock_movement_id=sm.id
            WHERE r.purchase_id=? ORDER BY r.id,i.id,sm.id");
        $q->execute([$purchaseId]); return $q->fetchAll();
    }
    public function hasUnattributedMovements(int $purchaseId): bool
    {
        $q=$this->pdo->prepare("SELECT 1 FROM stock_movements sm JOIN purchase_returns r ON r.id=sm.source_id
            LEFT JOIN purchase_return_items i ON i.id=sm.source_line_id AND i.return_id=r.id
            WHERE sm.source_type='purchase_return' AND r.purchase_id=? AND i.id IS NULL LIMIT 1");
        $q->execute([$purchaseId]); return (bool)$q->fetchColumn();
    }
}
