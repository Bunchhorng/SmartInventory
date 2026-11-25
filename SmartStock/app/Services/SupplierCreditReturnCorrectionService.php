<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{AuditLog, Payable, PurchaseReturn, Supplier, SupplierAdjustment, SupplierCreditReturnCorrection, User};
use App\Support\{Decimal as D, FinancialMath as F};
use DomainException;
use PDO;

/**
 * Phase 5.8 compensates a posted supplier credit that was attributed to a confirmed
 * physical return. It deliberately has no inventory dependency: P5.7 remains the
 * only stock/valuation restoration workflow.
 */
final class SupplierCreditReturnCorrectionService
{
    public function __construct(private PDO $pdo, private User $users, private AuditLog $audit, private Supplier $suppliers,
        private PurchaseReturn $returns, private SupplierAdjustment $adjustments, private SupplierAdjustmentService $creditEvidence,
        private Payable $payables, private SupplierPayableBalanceService $balances, private SupplierCreditReturnCorrection $documents,
        private $postWriteFault = null) {}

    public function createDraft(int $actor, array $input): array
    {
        $this->owner($actor, 'record');
        $this->fields($input, ['document_key','supplier_id','credit_id','purchase_return_id','purchase_return_credit_link_id','amount_original','quantity','reason','evidence_reference']);
        $key=$this->key($input['document_key']??null); $supplier=$this->id($input['supplier_id']??null); $credit=$this->id($input['credit_id']??null);
        $return=$this->id($input['purchase_return_id']??null); $link=$this->id($input['purchase_return_credit_link_id']??null);
        $amount=D::positive($this->text($input['amount_original']??null),4); $quantity=D::positive($this->text($input['quantity']??null),3);
        $reason=$this->reason($input['reason']??null); $evidence=$this->evidence($input['evidence_reference']??null);
        $canonical=['document_key'=>$key,'supplier_id'=>$supplier,'credit_id'=>$credit,'purchase_return_id'=>$return,'purchase_return_credit_link_id'=>$link,'amount_original'=>$amount,'quantity'=>$quantity,'reason'=>$reason,'evidence_reference'=>$evidence];
        return $this->transaction(function () use ($actor,$key,$supplier,$credit,$return,$link,$amount,$quantity,$reason,$evidence,$canonical): array {
            $source=$this->source($actor,$link,true);
            $this->assertSource($source,$supplier,$credit,$return);
            $existing=$this->documents->byKey($key,true);
            $hash=$this->digest($canonical);
            if ($existing) {
                if ($existing['request_hash']!==$hash || (int)$existing['created_by']!==$actor) { throw new DomainException('The document key belongs to different correction content.'); }
                return ['correction_id'=>(int)$existing['id'],'created'=>false];
            }
            [$qty,$base]=$this->quantity($quantity,$source);
            $this->capacity($source,$amount,$qty,$base);
            $id=$this->documents->create(['correction_no'=>'SCRC-'.strtoupper(substr(hash('sha256',$key),0,16)),'document_key'=>$key,'request_hash'=>$hash,
                'supplier_id'=>$supplier,'credit_id'=>$credit,'purchase_return_id'=>$return,'purchase_return_credit_link_id'=>$link,'invoice_id'=>$source['credit_invoice_id'],
                'currency'=>$source['credit_currency'],'exchange_rate_to_khr'=>$source['credit_exchange_rate'],'amount_original'=>$amount,'quantity'=>$qty,'qty_base'=>$base,
                'reason'=>$reason,'evidence_reference'=>$evidence,'created_by'=>$actor]);
            $this->audit($actor,'created',$id); return ['correction_id'=>$id,'created'=>true];
        });
    }

    public function submit(int $actor, int $id): array
    {
        $this->owner($actor,'record');
        return $this->transaction(function () use ($actor,$id): array {
            $doc=$this->locked($id); if ($doc['status']==='submitted') { return ['submitted'=>false]; }
            $this->state($doc,['draft']); $source=$this->source($actor,(int)$doc['purchase_return_credit_link_id'],true);
            $this->assertDocSource($doc,$source); $this->capacity($source,$doc['amount_original'],$doc['quantity'],$doc['qty_base']);
            $this->documents->transition($doc,'submitted',$actor); $this->audit($actor,'submitted',$id); return ['submitted'=>true];
        });
    }

    public function approve(int $actor, int $id): array
    {
        $this->owner($actor,'approve');
        return $this->transaction(function () use ($actor,$id): array {
            $doc=$this->locked($id); if (in_array($doc['status'],['approved','posted'],true)) { $this->approved($doc); return ['approved'=>false]; }
            $this->state($doc,['submitted']); $source=$this->source($actor,(int)$doc['purchase_return_credit_link_id'],true);
            $this->assertDocSource($doc,$source); $this->capacity($source,$doc['amount_original'],$doc['quantity'],$doc['qty_base']);
            $this->documents->approve($id,$this->contentHash($doc),$actor); $this->documents->transition($doc,'approved',$actor); $this->audit($actor,'approved',$id);
            return ['approved'=>true];
        });
    }

    public function post(int $actor, int $id): array
    {
        $this->owner($actor,'post');
        return $this->transaction(function () use ($actor,$id): array {
            $doc=$this->locked($id); $source=$this->source($actor,(int)$doc['purchase_return_credit_link_id'],true); $this->assertDocSource($doc,$source);
            if ($doc['status']==='posted') {
                if (!$this->documents->posting($id)) { throw new DomainException('Posted correction is missing immutable posting evidence.'); }
                return ['correction_id'=>$id,'posted'=>false];
            }
            $this->state($doc,['approved']); $this->approved($doc); $this->capacity($source,$doc['amount_original'],$doc['quantity'],$doc['qty_base']);
            $this->assertNoLaterSettlement((int)$source['payable_id'],(int)$source['credit_allocation_id']);
            $payable=$this->payables->forInvoice((int)$source['supplier_invoice_id'],true);
            if (!$payable) { throw new DomainException('Original supplier payable is missing.'); }
            $before=$this->balances->read($payable); $this->balances->requireSound($before);
            $plan=$this->plan($source,$doc);
            if (D::compare($plan['restored_ap_original'],$before['discharged_original'],4)>0 || D::compare($plan['restored_ap_khr'],$before['discharged_khr'],4)>0) { throw new DomainException('The correction would reverse an already-settled supplier-credit allocation.'); }
            $credit=$before['credits'][(int)$source['credit_allocation_id']]??null;
            if (!$credit || D::compare($plan['reduced_recoverable_original'],$credit['remaining_original'],4)>0 || D::compare($plan['reduced_recoverable_khr'],$credit['remaining_khr'],4)>0) { throw new DomainException('The correction would make the original supplier recoverable negative.'); }
            $posting=$this->documents->post(['correction_id'=>$id,'credit_id'=>$source['credit_id'],'credit_allocation_id'=>$source['credit_allocation_id'],
                'purchase_return_credit_link_id'=>$source['id'],'payable_id'=>$source['payable_id'],'posting_key'=>'supplier-credit-return-correction:'.$id,
                ...$plan,'quantity'=>$doc['quantity'],'qty_base'=>$doc['qty_base'],'posted_by'=>$actor]);
            $this->adjustments->activity((int)$source['payable_id'],'credit_return_correction',$posting);
            if ($this->postWriteFault !== null) { ($this->postWriteFault)(); }
            $this->documents->transition($doc,'posted',$actor); $this->balances->requireSound($this->balances->read($payable));
            $this->audit($actor,'posted',$id); return ['correction_id'=>$id,'posted'=>true];
        });
    }

    public function reject(int $actor,int $id,string $reason): void { $this->terminate($actor,$id,'rejected',$reason); }
    public function cancel(int $actor,int $id,string $reason): void { $this->terminate($actor,$id,'cancelled',$reason); }

    public function forViewer(int $actor,int $id): array
    {
        $this->owner($actor,'view'); $doc=$this->documents->find($id); if(!$doc){throw new DomainException('Linked supplier-credit correction not found.');}
        return ['correction'=>$doc,'approval'=>$this->documents->approval($id),'posting'=>$this->documents->posting($id)];
    }
    public function listForViewer(int $actor,int $supplier): array { $this->owner($actor,'view'); return $this->documents->list($supplier); }

    /** Read-only evidence and reconciliation; no repair path is intentionally exposed. */
    public function reconcile(int $actor,int $purchase): array
    {
        $this->owner($actor,'reconcile');
        return $this->transaction(function () use ($purchase): array {
            $rows=$this->documents->allForPurchase($purchase); $net=[]; $issues=[];
            foreach($rows as $row) {
                $link=(int)$row['purchase_return_credit_link_id'];
                $net[$link]??=['link_id'=>$link,'linked_amount_original'=>$row['linked_amount_original'],'linked_quantity'=>$row['linked_quantity'],'linked_qty_base'=>$row['linked_qty_base'],
                    'financial_corrections_original'=>'0.0000','eligible_physical_quantity'=>'0.000','eligible_physical_qty_base'=>'0.000','net_effective_credit_original'=>$row['linked_amount_original'],
                    'payable_restored_original'=>'0.0000','recoverable_reduced_original'=>'0.0000'];
                if($row['status']==='posted') {
                    if(!$row['posting_id']){$issues[]='Posted financial correction lacks immutable posting evidence.';continue;}
                    $net[$link]['financial_corrections_original']=D::add($net[$link]['financial_corrections_original'],$row['amount_original'],4);
                    $net[$link]['eligible_physical_quantity']=D::add($net[$link]['eligible_physical_quantity'],$row['quantity'],3);
                    $net[$link]['eligible_physical_qty_base']=D::add($net[$link]['eligible_physical_qty_base'],$row['qty_base'],3);
                    $net[$link]['payable_restored_original']=D::add($net[$link]['payable_restored_original'],$row['restored_ap_original'],4);
                    $net[$link]['recoverable_reduced_original']=D::add($net[$link]['recoverable_reduced_original'],$row['reduced_recoverable_original'],4);
                }
            }
            foreach($net as &$row) {
                try {$row['net_effective_credit_original']=D::subtract($row['linked_amount_original'],$row['financial_corrections_original'],4);
                    if(D::compare($row['eligible_physical_quantity'],$row['linked_quantity'],3)>0 || D::compare($row['eligible_physical_qty_base'],$row['linked_qty_base'],3)>0){$issues[]='Financial correction releases more physical quantity than its exact link.';}
                } catch(DomainException) {$issues[]='Financial correction exceeds its original credit attribution.';}
                $row['remaining_unmatched_financial_original']=$row['net_effective_credit_original'];
                $row['remaining_unmatched_physical_quantity']=D::subtract($row['linked_quantity'],F::min($row['linked_quantity'],$row['eligible_physical_quantity']),3);
            } unset($row);
            return ['purchase_id'=>$purchase,'corrections'=>$rows,'links'=>array_values($net),'issues'=>array_values(array_unique($issues)),'reconciled'=>$issues===[]];
        },true);
    }

    private function terminate(int $actor,int $id,string $to,string $reason): void
    {
        $this->owner($actor,'approve'); $reason=$this->reason($reason);
        $this->transaction(function () use($actor,$id,$to,$reason):void {$doc=$this->locked($id);$this->state($doc,$to==='rejected'?['submitted','approved']:['draft','submitted','approved','rejected']);
            if($this->documents->posting($id)){throw new DomainException('Posted correction history cannot be cancelled or rejected.');}$this->documents->transition($doc,$to,$actor,$reason);$this->audit($actor,$to,$id,$reason);});
    }

    private function source(int $actor,int $link,bool $lock): array
    {
        $source=$this->documents->sourceLink($link,$lock); if(!$source){throw new DomainException('The linked purchase-return credit evidence is missing.');}
        $credit=$this->adjustments->find('credit',(int)$source['credit_id'],true); if(!$credit){throw new DomainException('Original supplier credit is missing.');}
        $this->creditEvidence->assertPostedCreditEvidence($actor,$credit); return $source;
    }

    private function assertSource(array $s,int $supplier,int $credit,int $return): void
    {
        if((int)$s['return_supplier_id']!==$supplier || (int)$s['credit_supplier_id']!==$supplier || (int)$s['invoice_supplier_id']!==$supplier || (int)$s['payable_supplier_id']!==$supplier
            || (int)$s['credit_id']!==$credit || (int)$s['return_id']!==$return || $s['return_status']!=='confirmed' || $s['credit_status']!=='posted' || $s['invoice_status']!=='posted'
            || (int)$s['credit_invoice_id']!==(int)$s['match_invoice_id'] || (int)$s['credit_invoice_id']!==(int)$s['supplier_invoice_id']
            || (int)$s['match_goods_receipt_item_id']!==(int)$s['goods_receipt_item_id'] || $s['credit_currency']!==$s['invoice_currency'] || $s['credit_currency']!==$s['payable_currency']
            || $s['credit_exchange_rate']!==$s['invoice_exchange_rate'] || $s['credit_exchange_rate']!==$s['payable_exchange_rate']) { throw new DomainException('Correction must use the exact posted same-supplier credit and purchase-return attribution evidence.'); }
    }
    private function assertDocSource(array $doc,array $s): void
    {
        $this->assertSource($s,(int)$doc['supplier_id'],(int)$doc['credit_id'],(int)$doc['purchase_return_id']);
        if((int)$doc['purchase_return_credit_link_id']!==(int)$s['id'] || (int)$doc['invoice_id']!==(int)$s['credit_invoice_id'] || $doc['currency']!==$s['credit_currency'] || $doc['exchange_rate_to_khr']!==$s['credit_exchange_rate']) {throw new DomainException('Correction source differs from its immutable draft snapshot.');}
    }
    private function quantity(string $qty,array $s): array { $precision=(int)$s['unit_precision_snapshot'];$qty=str_contains($qty,'.')?rtrim(rtrim($qty,'0'),'.'):$qty;$qty=D::positive($qty,$precision);$qty=D::positive($qty,3);return[$qty,D::positive(D::multiply($qty,$s['conversion_snapshot'],3),3)]; }
    private function capacity(array $s,string $amount,string $qty,string $base): void
    {
        if(D::compare($amount,$s['amount_original'],4)>0 || D::compare($qty,$s['quantity'],3)>0 || D::compare($base,$s['qty_base'],3)>0){throw new DomainException('Correction exceeds the exact linked credit amount or physical quantity.');}
        $used=$this->documents->totalsForLink((int)$s['id']);
        if(D::compare(D::add($used['amount_original'],$amount,4),$s['amount_original'],4)>0 || D::compare(D::add($used['quantity'],$qty,3),$s['quantity'],3)>0 || D::compare(D::add($used['qty_base'],$base,3),$s['qty_base'],3)>0){throw new DomainException('This linked credit attribution has already been fully corrected.');}
        $credit=$this->documents->totalsForCredit((int)$s['credit_id']);
        if(D::compare(D::add($credit['amount_original'],$amount,4),$s['allocation_original'],4)>0){throw new DomainException('The original supplier credit has already been financially corrected.');}
    }
    private function plan(array $s,array $doc): array
    {
        $prior=$this->documents->totalsForCredit((int)$s['credit_id']); $cumulative=D::add($prior['amount_original'],$doc['amount_original'],4);
        $amountKhr=$this->share($s['allocation_khr'],$cumulative,$s['allocation_original'],$prior['amount_khr']??'0.0000');
        $ap=$this->share($s['applied_original'],$cumulative,$s['allocation_original'],$prior['restored_ap_original']??'0.0000');
        $apKhr=$this->share($s['applied_khr'],$cumulative,$s['allocation_original'],$prior['restored_ap_khr']??'0.0000');
        $recoverable=D::subtract($doc['amount_original'],$ap,4);
        $recoverableKhr=$this->share($s['recoverable_khr'],$cumulative,$s['allocation_original'],$prior['reduced_recoverable_khr']??'0.0000');
        return ['amount_original'=>$doc['amount_original'],'amount_khr'=>$amountKhr,'restored_ap_original'=>$ap,'restored_ap_khr'=>$apKhr,
            'reduced_recoverable_original'=>$recoverable,'reduced_recoverable_khr'=>$recoverableKhr,'rounding_difference_khr'=>F::sum($amountKhr,F::negate($apKhr),F::negate($recoverableKhr))];
    }
    private function share(string $total,string $cumulative,string $capacity,string $used): string { $target=D::compare($cumulative,$capacity,4)===0?D::nonNegative($total,4):D::divide(D::multiplyRounded($total,$cumulative,10,40),$capacity,4);return D::subtract($target,D::nonNegative($used,4),4); }
    private function assertNoLaterSettlement(int $payable,int $creditAllocation): void
    {
        $events=$this->adjustments->activities($payable);$seen=false;foreach($events as $event){if((int)($event['credit_allocation_id']??0)===$creditAllocation){$seen=true;continue;}if($seen&&$event['credit_return_correction_posting_id']===null){throw new DomainException('A later payment, reapplication or adjustment exists; a separately verified settlement adjustment is required.');}}if(!$seen){throw new DomainException('Original supplier-credit provenance is missing.');}
    }
    private function locked(int $id): array {$doc=$this->documents->find($id,true);if(!$doc){throw new DomainException('Linked supplier-credit correction not found.');}$this->suppliers->find((int)$doc['supplier_id'],true)?:throw new DomainException('Supplier not found.');return$doc;}
    private function approved(array $doc):void{$a=$this->documents->approval((int)$doc['id']);if(!$a||$a['content_hash']!==$this->contentHash($doc)||(int)$a['approved_by']!==(int)$doc['approved_by']){throw new DomainException('Correction differs from its immutable Owner approval.');}}
    private function contentHash(array $doc):string{return $this->digest(array_intersect_key($doc,array_flip(['id','supplier_id','credit_id','purchase_return_id','purchase_return_credit_link_id','invoice_id','currency','exchange_rate_to_khr','amount_original','quantity','qty_base','reason','evidence_reference','created_by'])));}
    private function state(array $doc,array $allowed):void{if(!in_array($doc['status'],$allowed,true)){throw new DomainException('Invalid linked supplier-credit correction state transition.');}}
    private function owner(int $actor,string $action):void{if(!$this->users->findActiveById($actor)||!$this->users->isOwner($actor)||!$this->users->hasPermission($actor,'supplier_credit_return_corrections.'.$action)){throw new DomainException('Only an authorized active Owner may access linked supplier-credit corrections.');}}
    private function audit(int $actor,string $action,int $id,?string $reason=null):void{$this->audit->record($actor,'supplier_credit_return_correction.'.$action,'supplier_credit_return_corrections',$id,$reason);}
    private function fields(array $input,array $allowed):void{if(array_diff(array_keys($input),[...$allowed,'_csrf'])){throw new DomainException('Unsupported linked supplier-credit correction fields.');}}
    private function id(mixed $v):int{if(!is_scalar($v)||filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false){throw new DomainException('A positive identifier is required.');}return(int)$v;}
    private function text(mixed $v):string{if(!is_string($v)&&!is_int($v)){throw new DomainException('Text input is required.');}return(string)$v;}
    private function reason(mixed $v):string{$v=trim($this->text($v));if(strlen($v)<3||strlen($v)>500){throw new DomainException('A reason of 3-500 bytes is required.');}return$v;}
    private function evidence(mixed $v):string{$v=trim($this->text($v));if(strlen($v)<3||strlen($v)>500){throw new DomainException('A supporting evidence reference of 3-500 bytes is required.');}return$v;}
    private function key(mixed $v):string{$v=$this->text($v);if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/',$v)){throw new DomainException('A 16-80 character document key is required.');}return$v;}
    private function digest(array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR));}
    private function transaction(callable $fn,bool $readOnly=false):mixed{if($this->pdo->inTransaction()){throw new \LogicException('Linked supplier-credit correction transactions cannot be nested.');}$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL '.($readOnly?'REPEATABLE READ':'READ COMMITTED'));if($readOnly){$this->pdo->exec('SET TRANSACTION READ ONLY');}$this->pdo->beginTransaction();try{$r=$fn();$this->pdo->commit();return$r;}catch(\Throwable $e){if($this->pdo->inTransaction()){$this->pdo->rollBack();}if($e instanceof \PDOException){throw new DomainException('Linked supplier-credit correction conflicted with existing records; no changes were committed.',0,$e);}throw$e;}}
}
