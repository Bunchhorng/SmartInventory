<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{Payable, SupplierAdjustment, SupplierCreditReturnCorrection};
use App\Support\{Decimal, FinancialMath as F};
use DomainException;

/** Replays existing AP allocations and ordered compensating evidence, without writes. */
final class SupplierPayableBalanceService
{
    public function __construct(private Payable $payables, private SupplierAdjustment $adjustments, private SupplierCreditReturnCorrection $returnCorrections) {}

    public function read(array $payable): array
    {
        $s = array_fill_keys(['discharged_original','discharged_khr','gross_paid_original','gross_paid_khr','reversed_original','reversed_khr',
            'credited_original','credited_khr','credit_applied_original','credit_applied_khr','recoverable_original','recoverable_khr','correction_rounding_khr',
            'credit_returned_original','credit_returned_khr','credit_returned_ap_original','credit_returned_ap_khr','credit_returned_recoverable_original','credit_returned_recoverable_khr'], '0.0000');
        $s += ['issues' => [], 'credits' => [], 'payment_carrying' => [], 'available' => true];
        try {
            $payments = []; $processed = []; $corrected = []; $counts = ['payment'=>0,'credit'=>0,'correction'=>0,'reapplication'=>0,'credit_return_correction'=>0];
            foreach ($this->payables->allocations((int) $payable['id']) as $a) {
                $payments[(int) $a['id']] = $a;
                if ((int) $a['posting_version'] === 1) {
                    if (!$this->validPayment($a,$payable)) { $s['issues'][] = 'Invalid historical payment allocation ' . $a['id']; continue; }
                    $next = Decimal::add($s['discharged_original'],$a['invoice_amount_original'],4);
                    $expected = F::sum(F::carrying($payable,$next),F::negate($s['discharged_khr']));
                    if ($expected !== $a['amount_khr']) { $s['issues'][] = 'Historical payment carrying value mismatch.'; }
                    $this->applyPayment($s,$a,$expected); $processed[(int) $a['id']] = $a;
                }
            }
            foreach ($this->adjustments->activities((int) $payable['id']) as $event) {
                if ($event['payment_allocation_id'] !== null) {
                    ++$counts['payment']; $id = (int) $event['payment_allocation_id']; $a = $payments[$id] ?? null;
                    if (!$a || (int) $a['posting_version'] !== 2 || isset($processed[$id])) { throw new DomainException('Payment activity provenance mismatch.'); }
                    if (!$this->validPayment($a,$payable)) { $s['issues'][] = 'Invalid posted payment allocation ' . $id; continue; }
                    $next = Decimal::add($s['discharged_original'],$a['invoice_amount_original'],4);
                    $expected = F::sum(F::carrying($payable,$next),F::negate($s['discharged_khr']));
                    if ($expected !== $a['amount_khr']) { $s['issues'][] = 'Payment ' . $id . ' carrying value differs from its event-time AP balance.'; }
                    $this->applyPayment($s,$a,$expected); $processed[$id] = $a;
                } elseif ($event['credit_allocation_id'] !== null) {
                    ++$counts['credit']; $a = $this->adjustments->creditAllocation((int) $event['credit_allocation_id']);
                    $doc = $a ? $this->adjustments->find('credit',(int) $a['credit_id']) : null;
                    if (!$a || !$doc || $doc['status'] !== 'posted' || (int) $a['payable_id'] !== (int) $payable['id']
                        || (int) $doc['invoice_id'] !== (int) $payable['supplier_invoice_id'] || (int) $doc['supplier_id'] !== (int) $payable['supplier_id']
                        || $doc['currency'] !== $payable['currency'] || $doc['exchange_rate_to_khr'] !== $payable['exchange_rate_to_khr']
                        || $doc['amount_original'] !== $a['amount_original'] || $a['posting_key'] !== 'supplier-credit:' . $doc['id']
                        || (int) $a['posted_by'] !== (int) $doc['posted_by']) { throw new DomainException('Supplier credit allocation provenance mismatch.'); }
                    $expected = $this->creditPlan($payable,$s,$a['amount_original']);
                    foreach ($expected as $field => $value) { if ($a[$field] !== $value) { $s['issues'][] = 'Credit ' . $doc['id'] . ' split/rounding mismatch: ' . $field; } }
                    $s['credited_original'] = Decimal::add($s['credited_original'],$a['amount_original'],4);
                    $s['credited_khr'] = Decimal::add($s['credited_khr'],$a['amount_khr'],4);
                    $this->applyCredit($s,$a['applied_original'],$a['applied_khr']);
                    $s['credits'][(int) $a['id']] = $a + ['remaining_original'=>$a['recoverable_original'],'remaining_khr'=>$a['recoverable_khr'], 'consumed_original'=>'0.0000','consumed_khr'=>'0.0000'];
                } elseif ($event['correction_allocation_id'] !== null) {
                    ++$counts['correction']; $a = $this->adjustments->correctionAllocation((int) $event['correction_allocation_id']);
                    $doc = $a ? $this->adjustments->find('correction',(int) $a['correction_id']) : null;
                    $original = $a ? ($processed[(int) $a['original_allocation_id']] ?? null) : null;
                    if (!$a || !$doc || !$original || $doc['status'] !== 'posted' || (int) $a['payable_id'] !== (int) $payable['id']
                        || (int) $doc['supplier_payment_id'] !== (int) $original['supplier_payment_id'] || isset($corrected[(int) $original['id']])
                        || (int) $doc['supplier_id'] !== (int) $payable['supplier_id'] || (int) $a['posted_by'] !== (int) $doc['posted_by']
                        || $a['posting_key'] !== 'supplier-correction:' . $doc['id'] . ':' . $original['id']) { throw new DomainException('Payment correction provenance mismatch.'); }
                    $expected = $this->correctionPlan($payable,$s,$original);
                    foreach ($expected as $field => $value) { if ($a[$field] !== $value) { $s['issues'][] = 'Correction ' . $doc['id'] . ' allocation mismatch: ' . $field; } }
                    $s['discharged_original'] = Decimal::subtract($s['discharged_original'],$a['reversed_original'],4);
                    $s['discharged_khr'] = Decimal::nonNegative(F::sum($s['discharged_khr'],F::negate($a['reversed_carrying_khr']),$a['ap_rounding_adjustment_khr']),4);
                    $s['reversed_original'] = Decimal::add($s['reversed_original'],$a['reversed_original'],4);
                    $s['reversed_khr'] = Decimal::add($s['reversed_khr'],$a['reversed_carrying_khr'],4);
                    $s['correction_rounding_khr'] = F::sum($s['correction_rounding_khr'],$a['ap_rounding_adjustment_khr']);
                    $corrected[(int) $original['id']] = true;
                } elseif ($event['reapplication_id'] !== null) {
                    ++$counts['reapplication']; $a = $this->adjustments->reapplication((int) $event['reapplication_id']);
                    $credit = $a ? ($s['credits'][(int) $a['credit_allocation_id']] ?? null) : null;
                    $correction = $a ? $this->adjustments->correctionAllocation((int) $a['correction_allocation_id']) : null;
                    if (!$a || !$credit || !$correction || (int) $a['payable_id'] !== (int) $payable['id'] || (int) $correction['payable_id'] !== (int) $payable['id']
                        || !isset($corrected[(int) $correction['original_allocation_id']])) { throw new DomainException('Recoverable reapplication provenance mismatch.'); }
                    $expected = $this->reapplicationPlan($payable,$s,$credit,$a['applied_original']);
                    foreach ($expected as $field => $value) { if ($a[$field] !== $value) { $s['issues'][] = 'Recoverable reapplication carrying/rounding mismatch.'; } }
                    $this->applyCredit($s,$a['applied_original'],$a['applied_khr']);
                    $id = (int) $a['credit_allocation_id'];
                    $s['credits'][$id]['remaining_original'] = Decimal::subtract($credit['remaining_original'],$a['applied_original'],4);
                    $s['credits'][$id]['remaining_khr'] = Decimal::subtract($credit['remaining_khr'],$a['consumed_recoverable_khr'],4);
                    $s['credits'][$id]['consumed_original'] = Decimal::add($credit['consumed_original'],$a['applied_original'],4);
                    $s['credits'][$id]['consumed_khr'] = Decimal::add($credit['consumed_khr'],$a['consumed_recoverable_khr'],4);
                } elseif ($event['credit_return_correction_posting_id'] !== null) {
                    ++$counts['credit_return_correction'];
                    $a = $this->returnCorrections->postingById((int) $event['credit_return_correction_posting_id']);
                    $doc = $a ? $this->returnCorrections->find((int) $a['correction_id']) : null;
                    $credit = $a ? ($s['credits'][(int) $a['credit_allocation_id']] ?? null) : null;
                    if (!$a || !$doc || !$credit || $doc['status'] !== 'posted' || (int) $a['payable_id'] !== (int) $payable['id']
                        || (int) $a['credit_id'] !== (int) $credit['credit_id'] || (int) $a['credit_allocation_id'] !== (int) $credit['id']
                        || (int) $doc['supplier_id'] !== (int) $payable['supplier_id'] || (int) $a['posted_by'] !== (int) $doc['posted_by']
                        || $a['posting_key'] !== 'supplier-credit-return-correction:' . $doc['id']) { throw new DomainException('Linked supplier-credit correction provenance mismatch.'); }
                    if (Decimal::compare($a['restored_ap_original'],$s['discharged_original'],4)>0 || Decimal::compare($a['restored_ap_khr'],$s['discharged_khr'],4)>0
                        || Decimal::compare($a['reduced_recoverable_original'],$credit['remaining_original'],4)>0 || Decimal::compare($a['reduced_recoverable_khr'],$credit['remaining_khr'],4)>0) { throw new DomainException('Linked supplier-credit correction exceeds available AP or recoverable balance.'); }
                    $s['discharged_original'] = Decimal::subtract($s['discharged_original'],$a['restored_ap_original'],4);
                    $s['discharged_khr'] = Decimal::subtract($s['discharged_khr'],$a['restored_ap_khr'],4);
                    $id=(int)$a['credit_allocation_id'];
                    $s['credits'][$id]['remaining_original'] = Decimal::subtract($credit['remaining_original'],$a['reduced_recoverable_original'],4);
                    $s['credits'][$id]['remaining_khr'] = Decimal::subtract($credit['remaining_khr'],$a['reduced_recoverable_khr'],4);
                    foreach (['credit_returned_original'=>'amount_original','credit_returned_khr'=>'amount_khr','credit_returned_ap_original'=>'restored_ap_original','credit_returned_ap_khr'=>'restored_ap_khr','credit_returned_recoverable_original'=>'reduced_recoverable_original','credit_returned_recoverable_khr'=>'reduced_recoverable_khr'] as $field=>$source) { $s[$field]=Decimal::add($s[$field],$a[$source],4); }
                } else {
                    throw new DomainException('Payable activity has no supported source.');
                }
                if (Decimal::compare($s['discharged_original'],$payable['amount_original'],4) > 0 || Decimal::compare($s['discharged_khr'],$payable['original_khr'],4) > 0) { throw new DomainException('AP is over-settled.'); }
            }
            $expectedPayments = count(array_filter($payments,fn ($a) => (int) $a['posting_version'] === 2));
            if ($counts['payment'] !== $expectedPayments) { throw new DomainException('Missing ordered payment activity; balance is unavailable.'); }
            foreach ($this->adjustments->postingCounts((int) $payable['id']) as $kind => $count) { if ($counts[$kind] !== $count) { throw new DomainException('Missing ordered ' . $kind . ' activity; balance is unavailable.'); } }
            foreach ($s['credits'] as $credit) {
                $s['recoverable_original'] = Decimal::add($s['recoverable_original'],$credit['remaining_original'],4);
                $s['recoverable_khr'] = Decimal::add($s['recoverable_khr'],$credit['remaining_khr'],4);
            }
            $s['outstanding_original'] = Decimal::subtract($payable['amount_original'],$s['discharged_original'],4);
            $s['outstanding_khr'] = Decimal::subtract($payable['original_khr'],$s['discharged_khr'],4);
        } catch (DomainException $e) {
            $s['issues'][] = $e->getMessage(); $s['available'] = false;
            $s['outstanding_original'] = $s['outstanding_khr'] = null;
        }
        return $s;
    }

    public function requireSound(array $state): void
    {
        if (!$state['available'] || $state['issues']) { throw new DomainException('Supplier AP history is inconsistent. Reconcile before posting.'); }
    }
    public function creditPlan(array $p, array $s, string $amount): array
    {
        $credited = Decimal::add($s['credited_original'],$amount,4);
        if (Decimal::compare($credited,$p['amount_original'],4) > 0) { throw new DomainException('The credit exceeds the remaining creditable invoice amount.'); }
        $outstanding = Decimal::subtract($p['amount_original'],$s['discharged_original'],4);
        $ap = F::min($amount,$outstanding); $excess = Decimal::subtract($amount,$ap,4);
        $apKhr = Decimal::nonNegative(F::sum(F::carrying($p,Decimal::add($s['discharged_original'],$ap,4)),F::negate($s['discharged_khr'])),4);
        $totalKhr = Decimal::subtract(F::carrying($p,$credited),$s['credited_khr'],4);
        $excessKhr = F::carrying($p,$excess);
        return ['amount_original'=>$amount,'amount_khr'=>$totalKhr,'applied_original'=>$ap,'applied_khr'=>$apKhr,
            'recoverable_original'=>$excess,'recoverable_khr'=>$excessKhr,'rounding_difference_khr'=>F::sum($totalKhr,F::negate($apKhr),F::negate($excessKhr))];
    }
    public function correctionPlan(array $p, array $s, array $a): array
    {
        $after = Decimal::subtract($s['discharged_original'],$a['invoice_amount_original'],4);
        $rawKhr = F::sum($s['discharged_khr'],F::negate($a['amount_khr']));
        return ['reversed_original'=>$a['invoice_amount_original'],'reversed_carrying_khr'=>$a['amount_khr'],
            'cash_original'=>$a['payment_amount_original'],'cash_khr'=>$a['cash_amount_khr'],
            'fx_reversal_khr'=>F::sum(F::negate($a['fx_difference_khr'])), 'rounding_reversal_khr'=>F::sum(F::negate($a['rounding_difference_khr'])),
            'ap_rounding_adjustment_khr'=>F::sum(F::carrying($p,$after),F::negate($rawKhr))];
    }
    public function reapplicationPlan(array $p, array $s, array $credit, string $amount): array
    {
        if (Decimal::compare($amount,$credit['remaining_original'],4) > 0 || Decimal::compare($amount,Decimal::subtract($p['amount_original'],$s['discharged_original'],4),4) > 0) { throw new DomainException('Recoverable reapplication exceeds available credit or debt.'); }
        $apKhr = Decimal::subtract(F::carrying($p,Decimal::add($s['discharged_original'],$amount,4)),$s['discharged_khr'],4);
        $consumed = Decimal::add($credit['consumed_original'],$amount,4);
        $debitKhr = Decimal::subtract(Decimal::divide(Decimal::multiplyRounded($credit['recoverable_khr'],$consumed,8,40),$credit['recoverable_original'],4),$credit['consumed_khr'],4);
        return ['applied_original'=>$amount,'applied_khr'=>$apKhr,'consumed_recoverable_khr'=>$debitKhr,'rounding_difference_khr'=>F::sum($debitKhr,F::negate($apKhr))];
    }
    private function applyPayment(array &$s, array $a, string $expected): void
    {
        foreach (['discharged_original'=>'invoice_amount_original','discharged_khr'=>'amount_khr','gross_paid_original'=>'invoice_amount_original','gross_paid_khr'=>'amount_khr'] as $key=>$field) { $s[$key] = Decimal::add($s[$key],$a[$field],4); }
        $s['payment_carrying'][(int) $a['id']] = $expected;
    }
    private function applyCredit(array &$s, string $original, string $khr): void
    {
        foreach (['discharged_original','credit_applied_original'] as $key) { $s[$key] = Decimal::add($s[$key],$original,4); }
        foreach (['discharged_khr','credit_applied_khr'] as $key) { $s[$key] = Decimal::add($s[$key],$khr,4); }
    }
    private function validPayment(array $a, array $p): bool
    {
        return $a['direction'] === 'out' && $a['customer_id'] === null && $a['payment_purpose'] === 'supplier_payment'
            && $a['verification_status'] === 'verified' && $a['document_status'] === 'posted'
            && (int) $a['payment_supplier_id'] === (int) $p['supplier_id'] && (int) $a['document_supplier_id'] === (int) $p['supplier_id']
            && (int) $a['document_payment_id'] === (int) $a['payment_id'] && $a['invoice_amount_original'] !== null
            && $a['payment_amount_original'] !== null && $a['cash_amount_khr'] !== null && $a['allocated_by'] !== null
            && $a['fx_difference_khr'] !== null && $a['rounding_difference_khr'] !== null;
    }
}
