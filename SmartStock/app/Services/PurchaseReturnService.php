<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{AuditLog,GoodsReceipt,InventoryReceipt,PurchaseOrder,PurchaseReturn,Supplier,SupplierAdjustment,SupplierInvoice,Payable,User};
use App\Support\Decimal as D;
use DomainException;
use PDO;

/** Physical dispatch and financial attribution are deliberately separate transactions. */
final class PurchaseReturnService
{
    public function __construct(private PDO $pdo, private User $users, private AuditLog $audit,
        private PurchaseReturn $returns, private PurchaseOrder $orders, private GoodsReceipt $goods,
        private InventoryReceipt $receipts, private InventoryService $inventory, private GoodsReceivingService $receiving,
        private Supplier $suppliers, private SupplierInvoice $invoices, private Payable $payables,
        private SupplierAdjustment $credits, private SupplierAdjustmentService $adjustments) {}

    public function createDraft(int $actor, array $input): array
    {
        $this->permission($actor,'request');
        $this->keys($input,['_csrf','document_key','purchase_id','supplier_id','goods_receipt_id','reason','items']);
        $purchase=$this->id($input['purchase_id']??null); $supplier=$this->id($input['supplier_id']??null);
        $receipt=$this->id($input['goods_receipt_id']??null); $key=$this->key($input['document_key']??null);
        $reason=$this->reason($input['reason']??null); $raw=$input['items']??null;
        if (!is_array($raw) || !$raw || count($raw)>100) { throw new DomainException('A return needs 1-100 receipt lines.'); }
        $requested=[];
        foreach ($raw as $line) {
            if (!is_array($line)) { throw new DomainException('Invalid return line.'); }
            $this->keys($line,['goods_receipt_item_id','quantity','reason']); $id=$this->id($line['goods_receipt_item_id']??null);
            if (isset($requested[$id])) { throw new DomainException('Each received line may occur only once.'); }
            $requested[$id]=[D::positive($this->text($line['quantity']??null),3),$this->reason($line['reason']??null)];
        }
        ksort($requested,SORT_NUMERIC); $hash=$this->digest([$purchase,$supplier,$receipt,$reason,$requested]);
        return $this->transaction(function () use ($actor,$purchase,$supplier,$receipt,$key,$reason,$requested,$hash): array {
            $this->lockPurchase($purchase,$supplier);
            $existing=$this->returns->byKey($key);
            if ($existing) {
                if ((int)$existing['created_by']!==$actor || $existing['request_hash']!==$hash) { throw new DomainException('Return key belongs to another request.'); }
                return ['return_id'=>(int)$existing['id'],'created'=>false];
            }
            $gr=$this->goods->find($receipt,true);
            if (!$gr || (int)$gr['purchase_id']!==$purchase || (int)$gr['supplier_id']!==$supplier) { throw new DomainException('Receipt, supplier and purchase order do not match.'); }
            $source=$this->source($gr); $prepared=[];
            foreach ($requested as $id=>[$qty,$lineReason]) {
                $s=$source[$id]??null;
                if (!$s) { throw new DomainException('Return line is outside the selected confirmed receipt.'); }
                $qty=$this->quantity($qty,(int)$s['unit_precision_snapshot']);
                $base=D::positive(D::multiply($qty,$s['conversion_snapshot'],3),3);
                $this->remaining($s,$qty,$base);
                $prepared[]=['goods_receipt_item_id'=>$id,'purchase_item_id'=>(int)$s['purchase_item_id'],
                    'product_id'=>(int)$s['product_id'],'product_unit_id'=>(int)$s['product_unit_id'],
                    'quantity'=>$qty,'qty_base'=>$base,'conversion_snapshot'=>$s['conversion_snapshot'],
                    'unit_precision_snapshot'=>(int)$s['unit_precision_snapshot'],'reason'=>$lineReason];
            }
            $id=$this->returns->create(['return_no'=>'PR-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
                'document_key'=>$key,'request_hash'=>$hash,'supplier_id'=>$supplier,'purchase_id'=>$purchase,
                'goods_receipt_id'=>$receipt,'location_id'=>(int)$gr['location_id'],'reason'=>$reason,'created_by'=>$actor]);
            foreach ($prepared as $line) { $this->returns->createItem(['return_id'=>$id]+$line); }
            $this->audit->record($actor,'purchase_return.draft_created','purchase_returns',$id);
            return ['return_id'=>$id,'created'=>true];
        });
    }

    public function submit(int $actor, int $id): array
    {
        $this->permission($actor,'request');
        return $this->transaction(function () use ($actor,$id): array {
            $doc=$this->locked($id);
            if (!$this->users->isOwner($actor) && (int)$doc['created_by']!==$actor) { throw new DomainException('Only the requester or Owner may submit this return.'); }
            if ($doc['status']==='submitted') { return ['return_id'=>$id,'submitted'=>false]; }
            $this->state($doc,['draft']); $this->validate($doc,$this->returns->items($id,true));
            $this->returns->transition($doc,'submitted',$actor); $this->audit->record($actor,'purchase_return.submitted','purchase_returns',$id);
            return ['return_id'=>$id,'submitted'=>true];
        });
    }
    public function approve(int $actor, int $id): array
    {
        $this->owner($actor,'approve');
        return $this->transaction(function () use ($actor,$id): array {
            $doc=$this->locked($id); $items=$this->returns->items($id,true);
            if ($doc['status']==='approved') { $this->approved($doc,$items); return ['return_id'=>$id,'approved'=>false]; }
            $this->state($doc,['submitted']); $this->validate($doc,$items);
            $this->returns->approve($id,$this->contentHash($doc,$items),$actor);
            $this->returns->transition($doc,'approved',$actor); $this->audit->record($actor,'purchase_return.approved','purchase_returns',$id);
            return ['return_id'=>$id,'approved'=>true];
        });
    }
    /** Physical handover: dispatch and stock confirmation share one commit, not an in-transit stock reservation. */
    public function confirm(int $actor, int $id, string $reference): array
    {
        $this->owner($actor,'confirm'); $reference=trim($reference);
        if (strlen($reference)<3 || strlen($reference)>120) { throw new DomainException('A physical dispatch reference of 3-120 bytes is required.'); }
        return $this->transaction(function () use ($actor,$id,$reference): array {
            $doc=$this->locked($id); $items=$this->returns->items($id,true); $this->approved($doc,$items);
            if ($doc['status']==='confirmed') {
                if ($doc['dispatch_reference']!==$reference) { throw new DomainException('Dispatch was already confirmed with a different reference.'); }
                $this->assertPhysicalEvidence($doc,$items);
                return ['return_id'=>$id,'confirmed'=>false];
            }
            $this->state($doc,['approved']); $source=$this->validate($doc,$items);
            $quotes=$this->inventory->postLockedPurchaseReturn($actor,$doc,$items);
            foreach ($items as $item) {
                $line=(int)$item['id']; $s=$source[(int)$item['goods_receipt_item_id']]; $used=$this->returns->usage((int)$s['id']);
                $receiptValue=$this->share($s['receipt_value_khr'],D::add($used['quantity'],$item['quantity'],3),$s['received_qty'],$used['receipt_value_khr']);
                $q=$quotes[$line];
                $this->returns->post(['return_item_id'=>$line,'stock_movement_id'=>$q['movement_id'],'posting_key'=>'purchase-return:'.$id.':'.$line,
                    'quantity'=>$item['quantity'],'qty_base'=>$item['qty_base'],'unit_cost_khr'=>$q['unit_cost_khr'],'carrying_value_khr'=>$q['value_khr'],
                    'receipt_value_khr'=>$receiptValue,'carrying_difference_khr'=>D::signedSubtract($q['value_khr'],$receiptValue,4),'posted_by'=>$actor]);
            }
            $this->returns->confirm($id,$actor,$reference);
            $this->audit->record($actor,'purchase_return.dispatched','purchase_returns',$id,$reference);
            $this->audit->record($actor,'purchase_return.confirmed','purchase_returns',$id);
            return ['return_id'=>$id,'confirmed'=>true];
        });
    }
    public function reject(int $actor, int $id, string $reason): void { $this->terminate($actor,$id,'rejected',$reason); }
    public function cancel(int $actor, int $id, string $reason): void { $this->terminate($actor,$id,'cancelled',$reason); }
    private function terminate(int $actor, int $id, string $to, string $reason): void
    {
        $this->owner($actor,'approve'); $reason=$this->reason($reason);
        $this->transaction(function () use ($actor,$id,$to,$reason): void {
            $doc=$this->locked($id); $this->state($doc,$to==='rejected'?['submitted','approved']:['draft','submitted','approved','rejected']);
            if ($this->returns->postings($id)) { throw new DomainException('Physical posting history cannot be cancelled or rejected.'); }
            $this->returns->transition($doc,$to,$actor,$reason); $this->audit->record($actor,'purchase_return.'.$to,'purchase_returns',$id,$reason);
        });
    }

    public function linkCredit(int $actor, int $id, array $input): array
    {
        $this->owner($actor,'link_credit');
        $this->keys($input,['_csrf','return_id','link_key','return_item_id','credit_id','invoice_match_id','quantity','amount_original','reason']);
        if (isset($input['return_id']) && $this->id($input['return_id'])!==$id) { throw new DomainException('Return identifier mismatch.'); }
        $itemId=$this->id($input['return_item_id']??null); $creditId=$this->id($input['credit_id']??null);
        $matchId=$this->id($input['invoice_match_id']??null); $key=$this->key($input['link_key']??null);
        $qty=D::positive($this->text($input['quantity']??null),3); $amount=D::positive($this->text($input['amount_original']??null),4);
        $reason=$this->reason($input['reason']??null); $hash=$this->digest([$id,$itemId,$creditId,$matchId,$qty,$amount,$reason]);
        return $this->transaction(function () use ($actor,$id,$itemId,$creditId,$matchId,$key,$qty,$amount,$reason,$hash): array {
            $doc=$this->locked($id); $credit=$this->credits->find('credit',$creditId,true);
            if (!$credit || (int)$credit['supplier_id']!==(int)$doc['supplier_id']) { throw new DomainException('Credit belongs to another supplier.'); }
            $invoice=$this->invoices->find((int)$credit['invoice_id'],true);
            $this->payables->forInvoice((int)$credit['invoice_id'],true);
            if (!$invoice || (int)$invoice['purchase_id']!==(int)$doc['purchase_id']) { throw new DomainException('Credit invoice belongs to another purchase order.'); }
            $allocation=$this->adjustments->assertPostedCreditEvidence($actor,$credit);
            $items=$this->returns->items($id,true); $this->approved($doc,$items); $this->state($doc,['confirmed']);
            $this->assertPhysicalEvidence($doc,$items);
            $item=array_column($items,null,'id')[$itemId]??null;
            $posting=array_column($this->returns->postings($id),null,'return_item_id')[$itemId]??null;
            $match=array_column($this->invoices->matches((int)$invoice['id']),null,'id')[$matchId]??null;
            if (!$item || !$posting || !$match || (int)$match['goods_receipt_item_id']!==(int)$item['goods_receipt_item_id']) {
                throw new DomainException('Credit must match the returned line through its posted original invoice receipt match.');
            }
            $prior=$this->returns->linkByKey($key);
            if ($prior) {
                if ($prior['request_hash']!==$hash || (int)$prior['linked_by']!==$actor) { throw new DomainException('Credit link key belongs to another request.'); }
                return ['link_id'=>(int)$prior['id'],'linked'=>false];
            }
            $qty=$this->quantity($qty,(int)$item['unit_precision_snapshot']); $base=D::positive(D::multiply($qty,$item['conversion_snapshot'],3),3);
            $itemLinks=$this->returns->linksForItem($itemId); $creditLinks=$this->returns->linksForCredit($creditId); $matchLinks=$this->returns->linksForMatch($matchId);
            $nextQty=D::add($this->sum($itemLinks,'quantity',3),$qty,3);
            $nextAmount=D::add($this->sum($creditLinks,'amount_original',4),$amount,4);
            if (D::compare($nextQty,$item['quantity'],3)>0 || D::compare(D::add($this->sum($itemLinks,'qty_base',3),$base,3),$item['qty_base'],3)>0
                || D::compare(D::add($this->sum($matchLinks,'quantity',3),$qty,3),$match['quantity'],3)>0
                || D::compare(D::add($this->sum($matchLinks,'qty_base',3),$base,3),$match['qty_base'],3)>0
                || D::compare($nextAmount,$allocation['amount_original'],4)>0) { throw new DomainException('Return quantity, invoice match or credit amount has already been attributed.'); }
            $creditValue=$this->share($allocation['amount_khr'],$nextAmount,$allocation['amount_original'],$this->sum($creditLinks,'credit_value_khr',4));
            $carrying=$this->share($posting['carrying_value_khr'],$nextQty,$item['quantity'],$this->sum($itemLinks,'carrying_value_khr',4));
            $link=$this->returns->link(['link_key'=>$key,'request_hash'=>$hash,'return_item_id'=>$itemId,'credit_id'=>$creditId,'invoice_match_id'=>$matchId,
                'quantity'=>$qty,'qty_base'=>$base,'amount_original'=>$amount,'credit_value_khr'=>$creditValue,'carrying_value_khr'=>$carrying,
                'difference_khr'=>D::signedSubtract($creditValue,$carrying,4),'reason'=>$reason,'linked_by'=>$actor]);
            $this->audit->record($actor,'purchase_return.credit_linked','purchase_returns',$id,'Credit attribution '.$link);
            return ['link_id'=>$link,'linked'=>true];
        });
    }

    public function forViewer(int $actor, int $id): array
    {
        $this->permission($actor,'view'); $doc=$this->returns->find($id);
        if (!$doc) { throw new DomainException('Purchase return not found.'); }
        $items=$this->returns->items($id);
        if (!$this->users->isOwner($actor)) {
            $doc=array_intersect_key($doc,array_flip(self::PUBLIC_HEADER));
            $items=array_map(fn(array $i): array=>array_intersect_key($i,array_flip(self::PUBLIC_LINE)),$items);
        } else {
            $doc['approval']=$this->returns->approval($id); $doc['postings']=$this->returns->postings($id);
            $doc['credit_links']=[]; foreach ($items as $item) { array_push($doc['credit_links'],...$this->returns->linksForItem((int)$item['id'])); }
        }
        return $doc+['items'=>$items];
    }
    public function listForViewer(int $actor, int $purchase, int $page = 1): array
    {
        $this->permission($actor,'view'); if ($page<1 || $page>100000) { throw new DomainException('Invalid page.'); }
        $rows=$this->returns->list($purchase,$page);
        return $this->users->isOwner($actor)?$rows:array_map(fn(array $r): array=>array_intersect_key($r,array_flip(self::PUBLIC_HEADER)),$rows);
    }

    /** Never locks/materializes cost rows or repairs history. Unmatched is a business exception, not ledger corruption. */
    public function reconcile(int $actor, int $purchase): array
    {
        $this->owner($actor,'reconcile');
        return $this->transaction(function () use ($actor,$purchase): array {
            $po=$this->orders->find($purchase); if (!$po) { throw new DomainException('Purchase order not found.'); }
            try { $receiving=$this->receiving->reconcile($actor,$purchase); }
            catch (DomainException $e) { $receiving=['purchase_id'=>$purchase,'issues'=>['Receiving/valuation report unavailable: '.$e->getMessage()],'reconciled'=>false]; }
            $issues=$receiving['issues'];
            $source=array_column($this->invoices->receivedLines($purchase),null,'id'); $lines=$unmatched=$links=[];
            foreach ($source as $id=>$s) {
                $used=$this->returns->usage((int)$id);
                $remaining=D::signedSubtract($s['received_qty'],$used['quantity'],3); $remainingBase=D::signedSubtract($s['qty_base'],$used['qty_base'],3);
                if (str_starts_with($remaining,'-') || str_starts_with($remainingBase,'-')) { $issues[]='Receipt line '.$id.' is over-returned.'; }
                $lines[]=['goods_receipt_item_id'=>$id,'received_quantity'=>$s['received_qty'],'received_base'=>$s['qty_base'],
                    'returned_quantity'=>$used['quantity'],'returned_base'=>$used['qty_base'],'remaining_quantity'=>$remaining,'remaining_base'=>$remainingBase];
            }
            $seen=[]; $receiptQty=$receiptValues=[];
            $evidence=$this->returns->evidence($purchase);
            usort($evidence,static fn(array $a,array $b): int => (int)$a['posting_id']<=>(int)$b['posting_id']);
            foreach ($this->returns->list($purchase) as $doc) {
                $items=$this->returns->items((int)$doc['id']);
                if (in_array($doc['status'],['approved','confirmed'],true)) {
                    try { $this->approved($doc,$items); } catch (DomainException $e) { $issues[]=$e->getMessage(); }
                }
                if ((int)$doc['supplier_id']!==(int)$po['supplier_id']) { $issues[]='Return supplier/PO mismatch.'; }
            }
            foreach ($evidence as $row) {
                $id=(int)$row['id']; $s=$source[(int)$row['goods_receipt_item_id']]??null;
                $bad=!$s || (int)$s['receipt_id']!==(int)($this->returns->find((int)$row['return_id'])['goods_receipt_id']??0)
                    || (int)$row['location_id']!==(int)($s['location_id']??0) || !$this->sameSnapshot($row,$s??[]);
                if ($row['status']==='confirmed') {
                    $negative='-'.$row['qty_base'];
                    $bad=$bad || isset($seen[$id]) || !$row['posting_id'] || !$row['movement_id'] || !$row['cost_entry_id']
                        || !$row['confirmed_at'] || !$row['dispatched_at'] || !$row['dispatch_reference']
                        || (int)$row['stock_movement_id']!==(int)$row['movement_id'] || $row['posted_quantity']!==$row['quantity'] || $row['posted_base']!==$row['qty_base']
                        || $row['movement_qty']!==$negative || $row['cost_qty']!==$negative || $row['batch_id']!==null
                        || (int)$row['movement_product']!==(int)$row['product_id'] || (int)$row['cost_product']!==(int)$row['product_id']
                        || (int)$row['movement_location']!==(int)$row['location_id'] || $row['cost_source']!=='purchase_return'
                        || (int)$row['cost_source_id']!==(int)$row['return_id'] || (int)$row['cost_source_line']!==$id || $row['costing_type']!=='purchase_return'
                        || $row['movement_key']!=='purchase-return:'.$row['return_id'].':'.$id || $row['posting_key']!==$row['movement_key']
                        || $row['cost_value']!==D::signedSubtract('0',$row['carrying_value_khr']??'0',4)
                        || $row['unit_cost_khr']!==$row['movement_cost'] || $row['unit_cost_khr']!==$row['cost_unit'] || $row['cost_unit']!==$row['avg_unit_cost_before_khr']
                        || (int)$row['confirmed_by']!==(int)$row['posted_by'] || (int)$row['confirmed_by']!==(int)$row['dispatched_by']
                        || (int)$row['confirmed_by']!==(int)$row['movement_actor'] || (int)$row['confirmed_by']!==(int)$row['cost_actor'];
                    if ($s && $row['posting_id'] && $s['receipt_value_khr']!==null) {
                        $sid=(int)$s['id']; $receiptQty[$sid]=D::add($receiptQty[$sid]??'0',$row['quantity'],3);
                        try { $expected=$this->share($s['receipt_value_khr'],$receiptQty[$sid],$s['received_qty'],$receiptValues[$sid]??'0'); }
                        catch (DomainException) { $expected=null; }
                        $receiptValues[$sid]=D::add($receiptValues[$sid]??'0',$row['receipt_value_khr'],4);
                        if ($expected!==$row['receipt_value_khr'] || D::signedSubtract($row['carrying_value_khr'],$row['receipt_value_khr'],4)!==$row['carrying_difference_khr']) { $bad=true; }
                    }
                    $itemLinks=$this->returns->linksForItem($id); array_push($links,...$itemLinks);
                    $left=D::signedSubtract($row['quantity'],$this->sum($itemLinks,'quantity',3),3);
                    if (str_starts_with($left,'-')) { $issues[]='Return credit quantity over-attributed.'; }
                    elseif (D::compare($left,'0',3)>0) { $unmatched[]=['return_item_id'=>$id,'quantity'=>$left]; }
                    $seen[$id]=true;
                } elseif ($row['posting_id'] || $row['movement_id'] || $row['cost_entry_id'] || $this->returns->linksForItem($id)) { $bad=true; }
                if ($bad) { $issues[]='Return line '.$id.' source/stock/valuation evidence is inconsistent.'; }
            }
            $unmatchedCredits=[]; $allCredits=$this->credits->list('credit',(int)$po['supplier_id'],null);
            foreach ($allCredits as $credit) {
                $invoice=$this->invoices->find((int)$credit['invoice_id']);
                if (!$invoice || (int)$invoice['purchase_id']!==$purchase || $credit['status']!=='posted') { continue; }
                try { $allocation=$this->adjustments->assertPostedCreditEvidence($actor,$credit); }
                catch (DomainException $e) { $issues[]=$e->getMessage(); continue; }
                $creditLinks=$this->returns->linksForCredit((int)$credit['id']);
                $remaining=D::signedSubtract($allocation['amount_original'],$this->sum($creditLinks,'amount_original',4),4);
                if (str_starts_with($remaining,'-')) { $issues[]='Credit amount over-attributed.'; }
                elseif (D::compare($remaining,'0',4)>0) { $unmatchedCredits[]=['credit_id'=>(int)$credit['id'],'currency'=>$credit['currency'],'amount_original'=>$remaining]; }
                $amountUsed=$valueUsed='0.0000';
                foreach ($creditLinks as $link) {
                    $amountUsed=D::add($amountUsed,$link['amount_original'],4);
                    try { $expected=$this->share($allocation['amount_khr'],$amountUsed,$allocation['amount_original'],$valueUsed); }
                    catch (DomainException) { $expected=null; }
                    $valueUsed=D::add($valueUsed,$link['credit_value_khr'],4);
                    if ($expected!==$link['credit_value_khr']) { $issues[]='Credit attribution KHR carrying share mismatch.'; }
                }
            }
            $itemQty=$itemCarry=[];
            usort($links,static fn(array $a,array $b): int=>(int)$a['id']<=>(int)$b['id']);
            foreach ($links as $link) {
                $itemId=(int)$link['return_item_id']; $item=null; $post=null;
                foreach ($evidence as $row) { if ((int)$row['id']===$itemId) { $item=$row; $post=$row; break; } }
                $credit=$this->credits->find('credit',(int)$link['credit_id']);
                $invoice=$credit?$this->invoices->find((int)$credit['invoice_id']):null;
                $match=$invoice?(array_column($this->invoices->matches((int)$invoice['id']),null,'id')[$link['invoice_match_id']]??null):null;
                $matchLinks=$this->returns->linksForMatch((int)$link['invoice_match_id']);
                $qty=$this->sum($matchLinks,'quantity',3); $base=$this->sum($matchLinks,'qty_base',3);
                $itemQty[$itemId]=D::add($itemQty[$itemId]??'0',$link['quantity'],3);
                try { $expected=$post && $post['carrying_value_khr']!==null?$this->share($post['carrying_value_khr'],$itemQty[$itemId],$item['quantity'],$itemCarry[$itemId]??'0'):null; }
                catch (DomainException) { $expected=null; }
                $itemCarry[$itemId]=D::add($itemCarry[$itemId]??'0',$link['carrying_value_khr'],4);
                $linkHash=$this->digest([(int)($item['return_id']??0),$itemId,(int)$link['credit_id'],(int)$link['invoice_match_id'],$link['quantity'],$link['amount_original'],$link['reason']]);
                try { $baseMatches=$item && D::multiply($link['quantity'],$item['conversion_snapshot'],3)===$link['qty_base']; }
                catch (DomainException) { $baseMatches=false; }
                if (!$item || !$credit || !$invoice || !$match || $credit['status']!=='posted' || (int)$credit['supplier_id']!==(int)$po['supplier_id']
                    || (int)$invoice['purchase_id']!==$purchase || (int)$match['goods_receipt_item_id']!==(int)$item['goods_receipt_item_id']
                    || D::compare($qty,$match['quantity'],3)>0 || D::compare($base,$match['qty_base'],3)>0 || !$baseMatches
                    || $linkHash!==$link['request_hash'] || $expected!==$link['carrying_value_khr'] || D::signedSubtract($link['credit_value_khr'],$link['carrying_value_khr'],4)!==$link['difference_khr']) {
                    $issues[]='Credit link '.$link['id'].' quantity/financial attribution is inconsistent.';
                }
            }
            if ($this->returns->hasUnattributedMovements($purchase)) { $issues[]='Purchase return has unlinked outbound movements.'; }
            return ['purchase_id'=>$purchase,'receiving'=>$receiving,'receipt_lines'=>$lines,'returns'=>$evidence,
                'credit_links'=>$links,'unmatched_returns'=>$unmatched,'unmatched_credits'=>$unmatchedCredits,
                'credit_inventory_difference_khr'=>$this->signedSum($links,'difference_khr'),
                'issues'=>array_values(array_unique($issues)),'reconciled'=>$issues===[]];
        },true);
    }

    /** Read confirmed immutable source evidence; live conversion factors are intentionally ignored. */
    private function source(array $gr, bool $available = true): array
    {
        if ($gr['status']!=='confirmed' || !$gr['inventory_receipt_id']) { throw new DomainException('A linked confirmed purchase receipt is required.'); }
        $ir=$this->receipts->find((int)$gr['inventory_receipt_id'],true);
        if (!$ir || $ir['status']!=='confirmed' || $ir['receipt_type']!=='purchase' || (int)$ir['location_id']!==(int)$gr['location_id']
            || !$gr['confirmed_at'] || !$ir['confirmed_at'] || (int)$ir['confirmed_by']!==(int)$gr['confirmed_by']) { throw new DomainException('Original inventory receipt is not confirmed at this location.'); }
        $location=$this->receipts->findActiveLocation((int)$gr['location_id']);
        if ($available && (!$location || !in_array($location['type'],['retail','storage'],true))) { throw new DomainException('Only available retail/storage receipt stock may be returned.'); }
        $lines=array_column($this->orders->items((int)$gr['purchase_id'],true),null,'id');
        $approval=$this->goods->approval((int)$gr['purchase_id']);
        if (!$approval || (int)$approval['supplier_id_snapshot']!==(int)$gr['supplier_id']
            || $approval['currency_snapshot']!==$gr['currency_snapshot'] || $approval['exchange_rate_to_khr_snapshot']!==$gr['exchange_rate_snapshot']) {
            throw new DomainException('Receipt supplier/currency differs from its approved purchase order.');
        }
        $found=[];
        foreach ($this->invoices->receivedLines((int)$gr['purchase_id']) as $s) {
            if ((int)$s['receipt_id']!==(int)$gr['id']) { continue; }
            $poLine=$lines[(int)$s['purchase_item_id']]??null;
            if (isset($found[$s['id']]) || !$poLine || ($available && ($poLine['product_status']!=='active' || (bool)$poLine['track_batches'] || $poLine['unit_status']!=='active'))
                || $s['inventory_status']!=='confirmed'
                || (int)$s['supplier_id']!==(int)$gr['supplier_id'] || (int)$s['purchase_id']!==(int)$gr['purchase_id']
                || (int)$s['inventory_location']!==(int)$gr['location_id'] || (int)$s['inventory_unit']!==(int)$s['product_unit_id']
                || $s['conversion_snapshot']!==$s['unit_to_base_factor_snapshot'] || $s['unit_precision_snapshot']===null
                || $s['qty_base']!==D::multiply($s['received_qty'],$s['conversion_snapshot'],3)
                || $s['inventory_qty']!==$s['received_qty'] || $s['inventory_base']!==$s['qty_base'] || $s['inventory_cost']!==$s['landed_unit_cost_khr']
                || !$s['movement_id'] || !$s['cost_entry_id'] || $s['movement_qty']!==$s['qty_base'] || $s['cost_qty']!==$s['qty_base']
                || (int)$s['movement_product']!==(int)$s['product_id'] || (int)$s['cost_product']!==(int)$s['product_id']
                || (int)$s['movement_location']!==(int)$gr['location_id'] || $s['movement_cost']!==$s['landed_unit_cost_khr']
                || $s['valuation_cost']!==$s['landed_unit_cost_khr'] || $s['cost_source_type']!=='inventory_receipt'
                || (int)$s['cost_source_id']!==(int)$s['inventory_id'] || (int)$s['cost_source_line']!==(int)$s['inventory_item_id']
                || $s['receipt_value_khr']!==D::multiplyRounded($s['qty_base'],$s['landed_unit_cost_khr'],4)) {
                throw new DomainException('Original receipt/PO/stock/valuation evidence needs reconciliation.');
            }
            $found[(int)$s['id']]=$s;
        }
        if (count($found)!==count($this->goods->items((int)$gr['id'],true)) || !$found) { throw new DomainException('Original receipt line evidence is incomplete.'); }
        return $found;
    }
    private function validate(array $doc, array $items, bool $physical = true): array
    {
        $gr=$this->goods->find((int)$doc['goods_receipt_id'],true);
        if (!$gr || (int)$gr['supplier_id']!==(int)$doc['supplier_id'] || (int)$gr['purchase_id']!==(int)$doc['purchase_id']
            || (int)$gr['location_id']!==(int)$doc['location_id'] || !$items) { throw new DomainException('Return header differs from its original receipt.'); }
        $source=$this->source($gr,$physical); $seen=[];
        foreach ($items as $item) {
            $s=$source[(int)$item['goods_receipt_item_id']]??null;
            if (!$s || isset($seen[$s['id']]) || !$this->sameSnapshot($item,$s)) { throw new DomainException('Return item differs from its original receipt snapshot.'); }
            if ($physical) { $this->remaining($s,$item['quantity'],$item['qty_base']); } $seen[$s['id']]=true;
        }
        return $source;
    }
    private function sameSnapshot(array $i, array $s): bool
    {
        try { return $s && (int)$i['product_id']===(int)$s['product_id'] && (int)$i['product_unit_id']===(int)$s['product_unit_id']
            && (int)$i['purchase_item_id']===(int)$s['purchase_item_id'] && $i['conversion_snapshot']===$s['conversion_snapshot']
            && (int)$i['unit_precision_snapshot']===(int)$s['unit_precision_snapshot']
            && $this->quantity($i['quantity'],(int)$i['unit_precision_snapshot'])===$i['quantity']
            && D::multiply($i['quantity'],$i['conversion_snapshot'],3)===$i['qty_base']; }
        catch (DomainException) { return false; }
    }
    /** Do not allow credit attribution or successful duplicate replies over broken physical evidence. */
    private function assertPhysicalEvidence(array $doc, array $items): void
    {
        $this->validate($doc,$items,false);
        $rows=array_values(array_filter($this->returns->evidence((int)$doc['purchase_id']),fn(array $r): bool=>(int)$r['return_id']===(int)$doc['id']));
        if (count($rows)!==count($items) || !$rows) { throw new DomainException('Confirmed physical return evidence is incomplete.'); }
        $seen=[];
        foreach ($rows as $r) {
            $id=(int)$r['id']; $qty='-'.$r['qty_base'];
            if (isset($seen[$id]) || !$r['posting_id'] || !$r['movement_id'] || !$r['cost_entry_id'] || $r['status']!=='confirmed'
                || (int)$r['stock_movement_id']!==(int)$r['movement_id'] || $r['posted_quantity']!==$r['quantity'] || $r['posted_base']!==$r['qty_base']
                || $r['movement_qty']!==$qty || $r['cost_qty']!==$qty || $r['batch_id']!==null
                || (int)$r['movement_product']!==(int)$r['product_id'] || (int)$r['cost_product']!==(int)$r['product_id']
                || (int)$r['movement_location']!==(int)$doc['location_id'] || $r['movement_key']!=='purchase-return:'.$doc['id'].':'.$id
                || $r['posting_key']!==$r['movement_key'] || $r['cost_source']!=='purchase_return' || $r['costing_type']!=='purchase_return'
                || (int)$r['cost_source_id']!==(int)$doc['id'] || (int)$r['cost_source_line']!==$id
                || $r['unit_cost_khr']!==$r['movement_cost'] || $r['unit_cost_khr']!==$r['cost_unit'] || $r['cost_unit']!==$r['avg_unit_cost_before_khr']
                || $r['cost_value']!==D::signedSubtract('0',$r['carrying_value_khr'],4)
                || !$doc['confirmed_at'] || !$doc['dispatched_at'] || !$doc['dispatch_reference']
                || (int)$r['posted_by']!==(int)$doc['confirmed_by'] || (int)$r['movement_actor']!==(int)$doc['confirmed_by']
                || (int)$r['cost_actor']!==(int)$doc['confirmed_by'] || (int)$doc['dispatched_by']!==(int)$doc['confirmed_by']) {
                throw new DomainException('Confirmed physical return posting evidence needs reconciliation.');
            }
            $seen[$id]=true;
        }
    }
    private function remaining(array $s, string $qty, string $base): void
    {
        $used=$this->returns->usage((int)$s['id']);
        if (D::compare(D::add($used['quantity'],$qty,3),$s['received_qty'],3)>0 || D::compare(D::add($used['qty_base'],$base,3),$s['qty_base'],3)>0) {
            throw new DomainException('Return exceeds confirmed received quantity less previous physical returns.');
        }
    }
    private function locked(int $id): array
    {
        $doc=$this->returns->find($id); if (!$doc) { throw new DomainException('Purchase return not found.'); }
        $this->lockPurchase((int)$doc['purchase_id'],(int)$doc['supplier_id']);
        $locked=$this->returns->find($id,true);
        if (!$locked || $locked['purchase_id']!==$doc['purchase_id'] || $locked['supplier_id']!==$doc['supplier_id']) { throw new DomainException('Return relationships changed.'); }
        return $locked;
    }
    private function lockPurchase(int $id, int $supplier): void
    {
        if (!$this->suppliers->find($supplier,true)) { throw new DomainException('Supplier not found.'); }
        $po=$this->orders->find($id,true);
        if (!$po || (int)$po['supplier_id']!==$supplier || !in_array($po['status'],['partially_received','received','closed'],true)) {
            throw new DomainException('A received purchase order from the original supplier is required.');
        }
    }
    private function approved(array $doc, array $items): void
    {
        $approval=$this->returns->approval((int)$doc['id']);
        if (!$approval || $approval['content_hash']!==$this->contentHash($doc,$items) || (int)$approval['approved_by']!==(int)$doc['approved_by']) {
            throw new DomainException('Return differs from its immutable Owner approval.');
        }
    }
    private function contentHash(array $doc, array $items): string
    {
        $header=array_intersect_key($doc,array_flip(['id','supplier_id','purchase_id','goods_receipt_id','location_id','reason','created_by'])); ksort($header);
        foreach ($items as &$item) { ksort($item); } unset($item);
        return $this->digest([$header,$items]);
    }
    private function share(string $value, string $cumulative, string $total, string $used): string
    {
        $scale=max(strlen(explode('.',$cumulative)[1]??''),strlen(explode('.',$total)[1]??''));
        $target=D::compare($cumulative,$total,$scale)===0?D::nonNegative($value,4):D::divide(D::multiplyRounded($value,$cumulative,10,40),$total,4);
        return D::subtract($target,D::nonNegative($used,4),4);
    }
    private function sum(array $rows, string $column, int $scale): string
    {
        $sum=D::nonNegative('0',$scale); foreach ($rows as $row) { $sum=D::add($sum,$row[$column],$scale); } return $sum;
    }
    private function signedSum(array $rows, string $column): string
    {
        $sum='0.0000'; foreach ($rows as $row) { $sum=\App\Support\FinancialMath::sum($sum,$row[$column]); } return $sum;
    }
    private function quantity(string $qty, int $precision): string
    {
        if ($precision<0 || $precision>3) { throw new DomainException('Unsupported receipt unit precision.'); }
        $trim=str_contains($qty,'.')?rtrim(rtrim($qty,'0'),'.'):$qty;
        return D::positive(D::positive($trim,$precision),3);
    }
    private function permission(int $actor, string $action): void
    {
        if (!$this->users->findActiveById($actor) || !$this->users->hasPermission($actor,'purchase_returns.'.$action)) { throw new DomainException('Not authorized for this purchase-return action.'); }
    }
    private function owner(int $actor, string $action): void
    {
        $this->permission($actor,$action); if (!$this->users->isOwner($actor)) { throw new DomainException('Only an active Owner may approve, dispatch, link credits or reconcile purchase returns.'); }
    }
    private function state(array $doc, array $allowed): void { if (!in_array($doc['status'],$allowed,true)) { throw new DomainException('Invalid purchase-return state transition.'); } }
    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) { throw new DomainException('A positive record identifier is required.'); } return (int)$id;
    }
    private function key(mixed $key): string { $key=$this->text($key); if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/',$key)) { throw new DomainException('A request key of 8-80 letters, digits, dashes or underscores is required.'); } return $key; }
    private function text(mixed $value): string { if (!is_string($value) && !is_int($value)) { throw new DomainException('Text/decimal input is required.'); } return (string)$value; }
    private function reason(mixed $value): string { $value=trim($this->text($value)); if (strlen($value)<3 || strlen($value)>500) { throw new DomainException('A reason of 3-500 bytes is required.'); } return $value; }
    private function keys(array $input, array $allowed): void { if (array_diff(array_keys($input),$allowed)) { throw new DomainException('Unsupported input. Return requests accept receipt quantities and reasons, never costs or financial fields.'); } }
    private function digest(array $value): string { return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR)); }
    private const PUBLIC_HEADER=['id','return_no','supplier_id','purchase_id','goods_receipt_id','location_id','status','created_by','created_at','submitted_at','confirmed_at','dispatched_at'];
    private const PUBLIC_LINE=['id','return_id','goods_receipt_item_id','purchase_item_id','product_id','product_unit_id','quantity','qty_base','conversion_snapshot','unit_precision_snapshot'];
    private function transaction(callable $operation, bool $readOnly = false): mixed
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Purchase-return service requires its own transaction.'); }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL '.($readOnly?'REPEATABLE READ':'READ COMMITTED'));
        if ($readOnly) { $this->pdo->exec('SET TRANSACTION READ ONLY'); }
        $this->pdo->beginTransaction();
        try { $result=$operation(); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException) { throw new DomainException('Purchase-return posting conflicted with existing records. No changes were committed.',0,$e); }
            throw $e;
        }
    }
}
