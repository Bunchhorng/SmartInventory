<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{AuditLog, Payable, Payment, Supplier, SupplierAdjustment, SupplierInvoice, SupplierPayment, User};
use App\Support\{Decimal, FinancialMath as F};
use DomainException;
use PDO;

final class SupplierAdjustmentService
{
    public function __construct(private PDO $pdo, private User $users, private AuditLog $audit, private Supplier $suppliers,
        private SupplierInvoice $invoices, private Payable $payables, private Payment $payments, private SupplierPayment $paymentDocs,
        private SupplierAdjustment $documents, private SupplierPayableBalanceService $balances, private SupplierPaymentService $paymentService,
        private SupplierInvoiceService $invoiceService) {}

    public function createCreditDraft(int $actor, array $input): array { return $this->create($actor,'credit',$input); }
    public function createCorrectionDraft(int $actor, array $input): array { return $this->create($actor,'correction',$input); }
    private function create(int $actor, string $kind, array $input): array
    {
        $this->owner($actor,$kind,'record');
        $allowed = $kind === 'credit' ? ['invoice_id','credit_no','amount_original','currency','exchange_rate_to_khr']
            : ['supplier_payment_id','resolution','evidence_reference','evidence','confirm_full_reversal'];
        $this->fields($input,['document_key','supplier_id','reason',...$allowed]);
        $key = $this->text($input['document_key'] ?? '',80);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/',$key)) { throw new DomainException('A 16-80 character document key is required.'); }
        $supplierId = $this->id($input['supplier_id'] ?? null); $reason = $this->text($input['reason'] ?? '',500);
        $canonical = $input; unset($canonical['_csrf']); ksort($canonical); $requestHash = $this->digest($canonical);
        return $this->transaction(function () use ($actor,$kind,$input,$key,$supplierId,$reason,$requestHash): array {
            $this->supplier($supplierId);
            $existing = $this->documents->byKey($kind,$key);
            if ($existing) {
                if ($existing['request_hash'] !== $requestHash || (int) $existing['supplier_id'] !== $supplierId) { throw new DomainException('The document key belongs to different content.'); }
                return ['adjustment_id'=>(int) $existing['id'],'created'=>false];
            }
            $data = ['document_key'=>$key,'request_hash'=>$requestHash,'supplier_id'=>$supplierId,'reason'=>$reason,'created_by'=>$actor];
            if ($kind === 'credit') {
                $invoiceId = $this->id($input['invoice_id'] ?? null); [$invoice,$payable,$balance] = $this->invoice($actor,$invoiceId,$supplierId);
                $amount = Decimal::positive($this->text($input['amount_original'] ?? '',40),4);
                $this->balances->creditPlan($payable,$balance,$amount);
                if ((isset($input['currency']) && $this->text($input['currency'],3) !== $invoice['currency'])
                    || (isset($input['exchange_rate_to_khr']) && Decimal::positive($this->text($input['exchange_rate_to_khr'],40),6) !== $invoice['exchange_rate_to_khr'])) { throw new DomainException('A supplier credit must preserve its original invoice currency and FX.'); }
                $number = $this->text($input['credit_no'] ?? '',120);
                $data += ['invoice_id'=>$invoiceId,'credit_no'=>$number,'normalized_credit_no'=>SupplierPayment::normalizeReference($number),
                    'currency'=>$invoice['currency'],'exchange_rate_to_khr'=>$invoice['exchange_rate_to_khr'],'amount_original'=>$amount];
            } else {
                if (($input['confirm_full_reversal'] ?? '') !== '1') { throw new DomainException('Explicit confirmation of full original-currency settlement reversal/non-settlement is required. Partial corrections are deferred.'); }
                $paymentId = $this->id($input['supplier_payment_id'] ?? null);
                $source = $this->source($actor,$paymentId,$supplierId);
                if ($this->documents->activeCorrection($paymentId)) { throw new DomainException('A live or posted correction already exists for this payment.'); }
                $data += ['supplier_payment_id'=>$paymentId,'source_hash'=>$this->digest($source),...$this->correctionEvidence($input)];
            }
            $id = $this->documents->create($kind,$data); $this->audit($actor,$kind,'created',$id);
            return ['adjustment_id'=>$id,'created'=>true];
        });
    }

    public function updateDraft(int $actor, string $kind, int $id, array $input): void
    {
        $this->owner($actor,$kind,'record');
        $this->fields($input,['adjustment_id','reason',...($kind === 'credit' ? ['credit_no','amount_original'] : ['resolution','evidence_reference','evidence'])]);
        $this->transaction(function () use ($actor,$kind,$id,$input): void {
            $doc = $this->locked($kind,$id); $this->state($doc,['draft']); $data = [];
            if (isset($input['reason'])) { $data['reason'] = $this->text($input['reason'],500); }
            if ($kind === 'credit') {
                if (isset($input['credit_no'])) { $data['credit_no'] = $this->text($input['credit_no'],120); $data['normalized_credit_no'] = SupplierPayment::normalizeReference($data['credit_no']); }
                if (isset($input['amount_original'])) { $data['amount_original'] = Decimal::positive($this->text($input['amount_original'],40),4); }
                $newDoc = array_replace($doc,$data); $this->creditPlan($actor,$newDoc);
            } else {
                $data += $this->correctionEvidence(array_replace($doc,$input)); $this->assertSource($actor,$doc);
            }
            $this->documents->update($kind,$id,$data); $this->audit($actor,$kind,'updated',$id);
        });
    }
    public function submit(int $actor, string $kind, int $id): array
    {
        $this->owner($actor,$kind,'record');
        return $this->transaction(function () use ($actor,$kind,$id): array {
            $doc = $this->locked($kind,$id);
            if ($doc['status'] === 'submitted') { return ['submitted'=>false]; }
            $this->state($doc,['draft']); $this->eligible($actor,$kind,$doc);
            $this->documents->transition($kind,$doc,'submitted',$actor); $this->audit($actor,$kind,'submitted',$id);
            return ['submitted'=>true];
        });
    }
    public function approve(int $actor, string $kind, int $id): array
    {
        $this->owner($actor,$kind,'approve');
        return $this->transaction(function () use ($actor,$kind,$id): array {
            $doc = $this->locked($kind,$id);
            if (in_array($doc['status'],['approved','posted'],true)) { $this->approved($kind,$doc); return ['approved'=>false]; }
            $this->state($doc,['submitted']); $this->eligible($actor,$kind,$doc);
            $this->documents->approve($kind,$id,$this->contentHash($kind,$doc),$actor);
            $this->documents->transition($kind,$doc,'approved',$actor); $this->audit($actor,$kind,'approved',$id);
            return ['approved'=>true];
        });
    }
    public function post(int $actor, string $kind, int $id): array
    {
        $this->owner($actor,$kind,'post');
        return $this->transaction(function () use ($actor,$kind,$id): array {
            $doc = $this->locked($kind,$id);
            if ($doc['status'] === 'posted') { $this->approved($kind,$doc); return ['posted'=>false]; }
            $this->state($doc,['approved']); $this->approved($kind,$doc);
            if ($kind === 'credit') {
                [$payable,$balance,$plan] = $this->creditPlan($actor,$doc);
                $remaining = Decimal::subtract($balance['outstanding_original'],$plan['applied_original'],4);
                if (Decimal::compare($this->paymentDocs->reservedOriginal((int) $doc['invoice_id'],0),$remaining,4) > 0) { throw new DomainException('Complete or cancel approved payment reservations before applying this credit.'); }
                $this->documents->transition($kind,$doc,'posted',$actor);
                $posting = $this->documents->postCredit(['credit_id'=>$id,'payable_id'=>$payable['id'],'posting_key'=>'supplier-credit:' . $id,...$plan,'posted_by'=>$actor]);
                $this->documents->activity((int) $payable['id'],'credit',$posting);
                $this->balances->requireSound($this->balances->read($payable));
            } else {
                $source = $this->assertSource($actor,$doc); $locked = $this->lockSourceInvoices($actor,$source['document']);
                $this->documents->transition($kind,$doc,'posted',$actor);
                foreach ($source['document']['allocations'] as $allocation) {
                    $payable = $locked[(int) $allocation['invoice_id']]; $balance = $this->balances->read($payable); $this->balances->requireSound($balance);
                    $plan = $this->balances->correctionPlan($payable,$balance,$allocation);
                    $posting = $this->documents->postCorrection(['correction_id'=>$id,'original_allocation_id'=>$allocation['id'],'payable_id'=>$payable['id'],
                        'posting_key'=>'supplier-correction:' . $id . ':' . $allocation['id'],...$plan,'posted_by'=>$actor]);
                    $this->documents->activity((int) $payable['id'],'correction',$posting);
                    // Existing excess from credits on this invoice reduces reopened
                    // debt before any new payment can reserve it. No cross-invoice offset.
                    $balance = $this->balances->read($payable); $this->balances->requireSound($balance);
                    foreach (array_keys($balance['credits']) as $creditId) {
                        $credit = $balance['credits'][$creditId];
                        $amount = F::min($credit['remaining_original'],$balance['outstanding_original']);
                        if (Decimal::isZeroValue($amount)) { continue; }
                        $reapplication = $this->balances->reapplicationPlan($payable,$balance,$credit,$amount);
                        $reapplyId = $this->documents->reapply(['credit_allocation_id'=>$creditId,'correction_allocation_id'=>$posting,'payable_id'=>$payable['id'],...$reapplication]);
                        $this->documents->activity((int) $payable['id'],'reapplication',$reapplyId);
                        $balance = $this->balances->read($payable); $this->balances->requireSound($balance);
                    }
                }
            }
            $this->audit($actor,$kind,'posted',$id); return ['posted'=>true];
        });
    }
    public function reject(int $actor, string $kind, int $id, string $reason): void { $this->terminate($actor,$kind,$id,'rejected',$reason); }
    public function cancel(int $actor, string $kind, int $id, string $reason): void { $this->terminate($actor,$kind,$id,'cancelled',$reason); }
    private function terminate(int $actor, string $kind, int $id, string $to, string $reason): void
    {
        $this->owner($actor,$kind,$to === 'rejected' ? 'approve' : 'record'); $reason = $this->text($reason,500);
        $this->transaction(function () use ($actor,$kind,$id,$to,$reason): void {
            $doc = $this->locked($kind,$id); $this->state($doc,$to === 'rejected' ? ['submitted','approved'] : ['draft','submitted','approved','rejected']);
            $this->documents->transition($kind,$doc,$to,$actor,$reason); $this->audit($actor,$kind,$to,$id,$reason);
        });
    }
    public function forViewer(int $actor, string $kind, int $id): array
    {
        $this->owner($actor,$kind,'view'); $doc = $this->documents->find($kind,$id);
        if (!$doc) { throw new DomainException('Supplier adjustment not found.'); }
        return $doc + ['approval'=>$this->documents->approval($kind,$id), 'postings'=>$kind === 'credit' ? $this->documents->creditPosting($id) : $this->documents->corrections($id)];
    }
    public function listForViewer(int $actor, string $kind, int $supplierId, int $page = 1): array
    {
        $this->owner($actor,$kind,'view'); if ($page < 1 || $page > 100000) { throw new DomainException('Invalid page.'); }
        return $this->documents->list($kind,$supplierId,$page);
    }

    public function reconcile(int $actor, int $supplierId): array
    {
        $this->owner($actor,'credit','reconcile');
        return $this->transaction(function () use ($actor,$supplierId): array {
            $payments = $this->paymentService->reconcile($actor,$supplierId); $issues = $payments['issues'];
            $credits = $corrections = []; $credited = $returned = $notSettled = $reversedCarrying = '0.0000';
            $fx = $rounding = $creditRounding = $apRounding = $reapplicationRounding = '0.0000';
            foreach (['credit','correction'] as $kind) {
                foreach ($this->documents->list($kind,$supplierId,null) as $doc) {
                    $id = (int) $doc['id']; $local = []; $approval = $this->documents->approval($kind,$id);
                    if ($approval && $approval['content_hash'] !== $this->contentHash($kind,$doc)) { $local[] = 'Approval content mismatch.'; }
                    if (in_array($doc['status'],['approved','posted'],true) && (!$approval || (int) $approval['approved_by'] !== (int) $doc['approved_by'])) { $local[] = 'Missing/inconsistent Owner approval.'; }
                    if ($kind === 'credit') {
                        $posting = $this->documents->creditPosting($id);
                        if (($doc['status'] === 'posted') !== ($posting !== null)) { $local[] = 'Credit posting/state mismatch.'; }
                        $invoice = $this->invoices->find((int) $doc['invoice_id']);
                        if (!$invoice || $invoice['status'] !== 'posted' || (int) $invoice['supplier_id'] !== $supplierId || $doc['currency'] !== $invoice['currency']
                            || $doc['exchange_rate_to_khr'] !== $invoice['exchange_rate_to_khr']) { $local[] = 'Credit invoice/currency/FX mismatch.'; }
                        $payable = $invoice ? $this->payables->forInvoice((int) $invoice['id']) : null;
                        if ($invoice && $payable) {
                            try { $this->invoiceService->assertPostedFinancialEvidence($actor,$invoice,$payable); }
                            catch (DomainException $e) { $local[] = $e->getMessage(); }
                        }
                        if ($posting && $doc['status'] === 'posted') { $credited = Decimal::add($credited,$posting['amount_khr'],4); $creditRounding = F::sum($creditRounding,$posting['rounding_difference_khr']); }
                        $credits[] = $doc + ['allocation'=>$posting,'issues'=>$local];
                    } else {
                        $postings = $this->documents->corrections($id);
                        $source = $this->sourceSnapshot($actor,(int) $doc['supplier_payment_id']);
                        if ($doc['source_hash'] !== $this->digest($source) || (int) $source['document']['supplier_id'] !== $supplierId) { $local[] = 'Original payment/verification/allocation evidence changed.'; }
                        if (($doc['status'] === 'posted' && count($postings) !== count($source['document']['allocations'])) || ($doc['status'] !== 'posted' && $postings)) { $local[] = 'Full-payment correction posting/state mismatch.'; }
                        if ($doc['status'] === 'posted') {
                            foreach ($postings as $posting) {
                                if ($doc['resolution'] === 'funds_returned') { $returned = Decimal::add($returned,$posting['cash_khr'],4); }
                                else { $notSettled = Decimal::add($notSettled,$posting['cash_khr'],4); }
                                $reversedCarrying = Decimal::add($reversedCarrying,$posting['reversed_carrying_khr'],4);
                                $fx = F::sum($fx,$posting['fx_reversal_khr']); $rounding = F::sum($rounding,$posting['rounding_reversal_khr']);
                                $apRounding = F::sum($apRounding,$posting['ap_rounding_adjustment_khr']);
                            }
                        }
                        $corrections[] = $doc + ['allocations'=>$postings,'issues'=>$local];
                    }
                    foreach ($local as $issue) { $issues[] = ucfirst($kind) . ' ' . $id . ': ' . $issue; }
                }
            }
            $reapplications = $this->documents->reapplicationsForSupplier($supplierId);
            foreach ($reapplications as $row) { $reapplicationRounding = F::sum($reapplicationRounding,$row['rounding_difference_khr']); }
            $ap = $payments['payables'];
            if ($ap['outstanding_khr'] !== null) {
                if (F::sum($ap['original_khr'],F::negate($ap['outstanding_khr'])) !== F::sum($ap['verified_paid_khr'],$ap['credit_applied_khr'],$apRounding)) { $issues[] = 'Supplier AP totals differ from immutable allocations.'; }
                if ($credited !== F::sum($ap['credit_applied_khr'],$ap['recoverable_khr'],$creditRounding,$reapplicationRounding)) { $issues[] = 'Supplier credit/recoverable totals do not reconcile.'; }
            }
            return ['supplier_id'=>$supplierId,'base_currency'=>'KHR','payments'=>$payments,'credits'=>$credits,'corrections'=>$corrections,
                'recoverable_reapplications'=>$reapplications,
                'cash_values_basis'=>'original_payment_fx_not_bank_revaluation','cash_return_payment_created'=>false,
                'original_invoice_khr'=>$payments['payables']['original_khr'],'supplier_credits_khr'=>$credited,
                'credit_applied_khr'=>$payments['payables']['credit_applied_khr'],'recoverable_khr'=>$payments['payables']['recoverable_khr'],
                'outstanding_khr'=>$payments['payables']['outstanding_khr'],'gross_posted_cash_khr'=>$payments['posted_cash_khr'],
                'evidenced_returned_cash_khr'=>$returned,'proven_not_settled_cash_khr'=>$notSettled,
                'net_settled_cash_khr'=>F::sum($payments['posted_cash_khr'],F::negate($returned),F::negate($notSettled)),
                'reversed_carrying_khr'=>$reversedCarrying,'net_fx_difference_khr'=>F::sum($payments['fx_difference_khr'],$fx),
                'net_payment_rounding_khr'=>F::sum($payments['rounding_difference_khr'],$rounding),
                'credit_recognition_rounding_khr'=>$creditRounding,'recoverable_reapplication_rounding_khr'=>$reapplicationRounding,'ap_correction_rounding_khr'=>$apRounding,
                'issues'=>$issues,'reconciled'=>$issues === []];
        },true);
    }

    /** Read-only composition guard for attributing an already-posted financial credit.
     * Caller owns its transaction and any required supplier/credit/invoice/payable locks.
     */
    public function assertPostedCreditEvidence(int $actor, array $doc): array
    {
        $this->owner($actor,'credit','reconcile');
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Credit evidence requires the caller transaction.'); }
        $this->approved('credit',$doc);
        $invoice=$this->invoices->find((int)$doc['invoice_id']); $p=$this->payables->forInvoice((int)$doc['invoice_id']);
        $posting=$this->documents->creditPosting((int)$doc['id']);
        if ($doc['status']!=='posted' || !$invoice || !$p || !$posting
            || (int)$doc['supplier_id']!==(int)$invoice['supplier_id'] || (int)$posting['payable_id']!==(int)$p['id']
            || $doc['currency']!==$invoice['currency'] || $doc['exchange_rate_to_khr']!==$invoice['exchange_rate_to_khr']
            || $doc['amount_original']!==$posting['amount_original'] || !$doc['posted_by'] || !$doc['posted_at']) {
            throw new DomainException('A reconciled posted supplier credit with original invoice evidence is required.');
        }
        $this->invoiceService->assertPostedFinancialEvidence($actor,$invoice,$p);
        $this->balances->requireSound($this->balances->read($p));
        return $posting;
    }

    private function creditPlan(int $actor, array $doc): array
    {
        [$invoice,$p,$s] = $this->invoice($actor,(int) $doc['invoice_id'],(int) $doc['supplier_id']);
        if ($doc['currency'] !== $invoice['currency'] || $doc['exchange_rate_to_khr'] !== $invoice['exchange_rate_to_khr']) { throw new DomainException('Credit currency/FX differs from its original invoice.'); }
        return [$p,$s,$this->balances->creditPlan($p,$s,$doc['amount_original'])];
    }
    private function invoice(int $actor, int $invoiceId, int $supplier): array
    {
        $invoice = $this->invoices->find($invoiceId,true); $p = $this->payables->forInvoice($invoiceId,true);
        if (!$invoice || $invoice['status'] !== 'posted' || (int) $invoice['supplier_id'] !== $supplier || !$p
            || (int) $p['supplier_id'] !== $supplier || $p['original_khr'] !== $invoice['total_khr'] || $p['amount_original'] !== $invoice['total_original']
            || $p['currency'] !== $invoice['currency'] || $p['exchange_rate_to_khr'] !== $invoice['exchange_rate_to_khr']) { throw new DomainException('An eligible posted same-supplier invoice and matching payable are required.'); }
        $this->invoiceService->assertPostedFinancialEvidence($actor,$invoice,$p);
        $s = $this->balances->read($p); $this->balances->requireSound($s); return [$invoice,$p,$s];
    }
    private function sourceSnapshot(int $actor, int $id): array
    {
        $document = $this->paymentService->forViewer($actor,$id);
        return ['document'=>$document,'cash_ledger'=>$document['payment_id'] ? $this->payments->find((int) $document['payment_id']) : null];
    }
    private function source(int $actor, int $id, int $supplier): array
    {
        $this->paymentDocs->find($id,true); $source = $this->sourceSnapshot($actor,$id);
        if ($source['document']['status'] !== 'posted' || (int) $source['document']['supplier_id'] !== $supplier || !$source['document']['allocations']
            || !$source['cash_ledger'] || $source['cash_ledger']['verification_status'] !== 'verified') { throw new DomainException('Only a fully posted and verified same-supplier payment can be corrected.'); }
        if (!$this->paymentService->reconcile($actor,$supplier)['reconciled']) { throw new DomainException('Reconcile original supplier payment evidence before correction.'); }
        return $source;
    }
    private function assertSource(int $actor, array $doc): array
    {
        $source = $this->source($actor,(int) $doc['supplier_payment_id'],(int) $doc['supplier_id']);
        if ($doc['source_hash'] !== $this->digest($source)) { throw new DomainException('Original payment evidence differs from the correction request.'); }
        $this->correctionEvidence($doc); return $source;
    }
    private function lockSourceInvoices(int $actor, array $doc): array
    {
        $invoiceIds = array_column($doc['allocations'],'invoice_id'); sort($invoiceIds,SORT_NUMERIC); $payables = [];
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoices->find((int) $invoiceId,true); $p = $this->payables->forInvoice((int) $invoiceId);
            if (!$invoice || $invoice['status'] !== 'posted' || !$p || (int) $invoice['supplier_id'] !== (int) $doc['supplier_id']) { throw new DomainException('Correction invoice/payable is no longer eligible.'); }
            $payables[(int) $p['id']] = $p;
        }
        ksort($payables,SORT_NUMERIC); $byInvoice = [];
        foreach ($payables as $p) {
            $p = $this->payables->forInvoice((int) $p['supplier_invoice_id'],true);
            $this->invoiceService->assertPostedFinancialEvidence($actor,$this->invoices->find((int) $p['supplier_invoice_id']),$p);
            $byInvoice[(int) $p['supplier_invoice_id']] = $p;
        }
        return $byInvoice;
    }
    private function eligible(int $actor, string $kind, array $doc): void
    {
        if ($kind === 'credit') { $this->creditPlan($actor,$doc); } else { $this->assertSource($actor,$doc); }
    }
    private function correctionEvidence(array $input): array
    {
        $resolution = $this->text($input['resolution'] ?? '',30);
        if (!in_array($resolution,['funds_returned','proven_not_settled'],true)) { throw new DomainException('A bookkeeping-only reversal is not settlement evidence. Confirm funds returned or proven non-settlement.'); }
        return ['resolution'=>$resolution,'evidence_reference'=>$this->text($input['evidence_reference'] ?? '',120),'evidence'=>$this->text($input['evidence'] ?? '',1000)];
    }
    private function contentHash(string $kind, array $doc): string
    {
        $fields = $kind === 'credit' ? ['id','supplier_id','invoice_id','credit_no','normalized_credit_no','currency','exchange_rate_to_khr','amount_original','reason']
            : ['id','supplier_id','supplier_payment_id','source_hash','reason','resolution','evidence_reference','evidence'];
        return $this->digest(array_intersect_key($doc,array_flip($fields)));
    }
    private function approved(string $kind, array $doc): void
    {
        $a = $this->documents->approval($kind,(int) $doc['id']);
        if (!$a || $a['content_hash'] !== $this->contentHash($kind,$doc) || (int) $a['approved_by'] !== (int) $doc['approved_by']) { throw new DomainException('The adjustment differs from its immutable Owner approval.'); }
    }
    private function locked(string $kind, int $id): array
    {
        $doc = $this->documents->find($kind,$id); if (!$doc) { throw new DomainException('Supplier adjustment not found.'); }
        $this->supplier((int) $doc['supplier_id']); return $this->documents->find($kind,$id,true);
    }
    private function supplier(int $id): void { if (!$this->suppliers->find($id,true)) { throw new DomainException('Supplier not found.'); } }
    private function state(array $doc, array $allowed): void { if (!in_array($doc['status'],$allowed,true)) { throw new DomainException('Invalid adjustment state transition.'); } }
    private function owner(int $actor, string $kind, string $action): void
    {
        $this->documents->table($kind); $prefix = $kind === 'credit' ? 'supplier_credits.' : 'supplier_payment_corrections.';
        if (!$this->users->findActiveById($actor) || !$this->users->isOwner($actor) || !$this->users->hasPermission($actor,$prefix . $action)) { throw new DomainException('Only an authorized active Owner may access supplier financial adjustments.'); }
    }
    private function audit(int $actor, string $kind, string $action, int $id, ?string $reason = null): void { $this->audit->record($actor,'supplier_' . $kind . '.' . $action,$this->documents->table($kind),$id,$reason); }
    private function fields(array $input, array $allowed): void { if (array_diff(array_keys($input),[...$allowed,'_csrf'])) { throw new DomainException('Unsupported or restricted adjustment fields.'); } }
    private function text(mixed $value, int $max): string
    {
        if (!is_string($value)) { throw new DomainException('A text value is required.'); } $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value,'UTF-8') : strlen($value);
        if ($value === '' || $length > $max || preg_match('//u',$value) !== 1) { throw new DomainException('A required text field is invalid, empty or too long.'); } return $value;
    }
    private function id(mixed $value): int
    {
        if (!is_scalar($value) || filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) === false) { throw new DomainException('A valid identifier is required.'); } return (int) $value;
    }
    private function digest(array $data): string { return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR)); }
    private function transaction(callable $fn, bool $readOnly = false): mixed
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Supplier adjustment transactions cannot be nested.'); }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . ($readOnly ? 'REPEATABLE READ' : 'READ COMMITTED'));
        if ($readOnly) { $this->pdo->exec('SET TRANSACTION READ ONLY'); } $this->pdo->beginTransaction();
        try { $result = $fn(); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException) { throw new DomainException('The supplier financial adjustment conflicts with existing data; no changes were committed.',0,$e); } throw $e;
        }
    }
}
