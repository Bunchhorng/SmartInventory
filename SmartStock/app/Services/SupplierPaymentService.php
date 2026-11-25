<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{AuditLog, Payable, Payment, Supplier, SupplierInvoice, SupplierPayment, User};
use App\Support\Decimal;
use DomainException;
use PDO;

final class SupplierPaymentService
{
    public function __construct(private PDO $pdo, private User $users, private AuditLog $audit,
        private Supplier $suppliers, private SupplierInvoice $invoices, private Payable $payables,
        private Payment $payments, private SupplierPayment $documents, private AccountsPayableService $ap,
        private SupplierPayableBalanceService $balances) {}

    public function createDraft(int $actor, array $input): array
    {
        $this->permit($actor, 'request');
        $owner = $this->users->isOwner($actor);
        $this->fields($input, $owner ? ['payment_key','supplier_id','reference_no','notes', ...$this->financialFields()] : ['payment_key','supplier_id','reference_no','notes']);
        if ($owner && array_intersect(array_keys($input), $this->financialFields())) { $this->permit($actor, 'manage', true); }
        $key = $this->text($input['payment_key'] ?? '', 80, true);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,79}$/', $key)) { throw new DomainException('A 16-80 character payment request key is required.'); }
        $supplier = $this->id($input['supplier_id'] ?? null);
        $reference = $this->text($input['reference_no'] ?? '', 120);
        $notes = $this->text($input['notes'] ?? '', 1000);
        $canonical = $input; unset($canonical['_csrf']); ksort($canonical);
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
        return $this->transaction(function () use ($actor, $input, $key, $hash, $supplier, $reference, $notes): array {
            $this->supplier($supplier);
            $existing = $this->documents->byKey($key);
            if ($existing) {
                $this->accessible($actor, $existing);
                if ($existing['request_hash'] !== $hash || (int) $existing['supplier_id'] !== $supplier) { throw new DomainException('This payment request key was already used for different content.'); }
                return ['supplier_payment_id' => (int) $existing['id'], 'created' => false];
            }
            $id = $this->documents->create($key, $hash, $supplier, $reference, $notes, $actor);
            if (array_intersect(array_keys($input), $this->financialFields())) { $this->price($id, $input); }
            $this->audit->record($actor, 'supplier_payment.created', 'supplier_payments', $id);
            return ['supplier_payment_id' => $id, 'created' => true];
        });
    }

    public function updateDraft(int $actor, int $id, array $input): void
    {
        $this->permit($actor, 'request');
        $owner = $this->users->isOwner($actor);
        $this->fields($input, $owner ? ['supplier_payment_id','reference_no','notes', ...$this->financialFields()] : ['supplier_payment_id','reference_no','notes']);
        if ($owner) { $this->permit($actor, 'manage', true); }
        $this->transaction(function () use ($actor, $id, $input, $owner): void {
            $doc = $this->locked($id); $this->accessible($actor, $doc); $this->state($doc, ['draft']);
            if (!$owner && $doc['amount_original'] !== null) { throw new DomainException('Only an Owner may edit a priced payment request.'); }
            $data = [];
            foreach (['reference_no' => 120, 'notes' => 1000] as $field => $length) {
                if (array_key_exists($field, $input)) { $data[$field] = $this->text($input[$field], $length); }
            }
            if (array_key_exists('reference_no', $data)) { $data['normalized_reference'] = SupplierPayment::normalizeReference($data['reference_no']); }
            $this->documents->update($id, $data);
            if (array_intersect(array_keys($input), $this->financialFields())) { $this->price($id, $input); }
            // Metadata changes must not invalidate bank reference requirements.
            if ($this->documents->find($id)['amount_original'] !== null) { $this->validate($this->documents->find($id)); }
            $this->audit->record($actor, 'supplier_payment.updated', 'supplier_payments', $id);
        });
    }

    public function submit(int $actor, int $id): array
    {
        $this->permit($actor, 'request');
        return $this->transaction(function () use ($actor, $id): array {
            $doc = $this->locked($id); $this->accessible($actor, $doc);
            if ($doc['status'] === 'submitted') { return ['submitted' => false]; }
            $this->state($doc, ['draft']); $this->validate($doc);
            $this->documents->transition($id, 'draft', 'submitted', $actor);
            $this->audit->record($actor, 'supplier_payment.submitted', 'supplier_payments', $id);
            return ['submitted' => true];
        });
    }

    public function approve(int $actor, int $id): array
    {
        $this->permit($actor, 'approve', true);
        return $this->transaction(function () use ($actor, $id): array {
            $doc = $this->locked($id);
            if (in_array($doc['status'], ['approved','posted'], true)) { $this->approved($doc); return ['approved' => false]; }
            $this->state($doc, ['submitted']); $this->validate($doc);
            $this->documents->approve($id, $this->hash($doc), $actor);
            $this->documents->transition($id, 'submitted', 'approved', $actor);
            $this->audit->record($actor, 'supplier_payment.approved', 'supplier_payments', $id, 'Original-currency invoice balances reserved; not settled.');
            return ['approved' => true];
        });
    }

    public function verify(int $actor, int $id, string $evidence): array
    {
        $this->permit($actor, 'verify', true);
        $evidence = $this->text($evidence, 1000, true);
        return $this->transaction(function () use ($actor, $id, $evidence): array {
            $doc = $this->locked($id); $this->state($doc, ['approved']); $this->approved($doc);
            if (!in_array($doc['method_code'], ['bank','khqr'], true)) { throw new DomainException('Cash is verified atomically on posting; manual verification is for bank/KHQR only.'); }
            $this->validate($doc);
            $existing = $this->documents->verification($id);
            if ($existing) {
                if ($existing['content_hash'] !== $this->hash($doc) || $existing['evidence'] !== $evidence) { throw new DomainException('Verification evidence is immutable.'); }
                return ['verified' => false];
            }
            $this->documents->verify($id, $this->hash($doc), $evidence, $actor);
            $this->audit->record($actor, 'supplier_payment.verified', 'supplier_payments', $id, $doc['method_code'] . ': manual evidence recorded');
            return ['verified' => true];
        });
    }

    public function post(int $actor, int $id): array
    {
        $this->permit($actor, 'post', true);
        return $this->transaction(function () use ($actor, $id): array {
            $doc = $this->locked($id);
            if ($doc['status'] === 'posted') { $this->approved($doc); return ['posted' => false, 'payment_id' => (int) $doc['payment_id']]; }
            $this->state($doc, ['approved']); $this->approved($doc);
            $verification = $this->documents->verification($id);
            if ($doc['method_code'] !== 'cash' && (!$verification || $verification['content_hash'] !== $this->hash($doc))) {
                throw new DomainException('Owner-documented bank/KHQR verification is required before posting.');
            }
            $lines = $this->validate($doc);
            $paymentId = $this->documents->postLedger($doc, $actor, $verification);
            foreach ($lines as $line) { $this->documents->allocate($id, $paymentId, $line, $actor); }
            $this->documents->transition($id, 'approved', 'posted', $actor, paymentId: $paymentId);
            $this->audit->record($actor, 'supplier_payment.posted', 'supplier_payments', $id, 'Immutable outgoing cash flow and AP allocations; no inventory or expense posting.');
            return ['posted' => true, 'payment_id' => $paymentId];
        });
    }

    public function reject(int $actor, int $id, string $reason): void { $this->terminate($actor, $id, 'rejected', $reason); }
    public function cancel(int $actor, int $id, string $reason): void { $this->terminate($actor, $id, 'cancelled', $reason); }
    private function terminate(int $actor, int $id, string $to, string $reason): void
    {
        $this->permit($actor, $to === 'rejected' ? 'approve' : 'manage', true);
        $reason = $this->text($reason, 500, true);
        $this->transaction(function () use ($actor, $id, $to, $reason): void {
            $doc = $this->locked($id);
            $this->state($doc, $to === 'rejected' ? ['submitted','approved'] : ['draft','submitted','approved','rejected']);
            if ($this->documents->verification($id)) { throw new DomainException('A verified transfer cannot be cancelled or rejected. Post it or use a future compensating supplier-payment correction.'); }
            $this->documents->transition($id, $doc['status'], $to, $actor, $reason);
            $this->audit->record($actor, 'supplier_payment.' . $to, 'supplier_payments', $id, $reason);
        });
    }

    public function forViewer(int $actor, int $id): array
    {
        $this->permit($actor, 'view');
        $doc = $this->documents->find($id);
        if (!$doc) { throw new DomainException('The payment request is unavailable.'); }
        $this->accessible($actor, $doc);
        if (!$this->users->isOwner($actor)) {
            return array_intersect_key($doc, array_flip(['id','payment_key','supplier_id','status','reference_no','created_at','updated_at']));
        }
        return $doc + ['items' => $this->documents->items($id), 'approval' => $this->documents->approval($id),
            'verification' => $this->documents->verification($id), 'allocations' => $this->documents->allocations($id)];
    }

    public function listForViewer(int $actor, int $supplierId, int $page = 1): array
    {
        $this->permit($actor, 'view');
        if ($page < 1 || $page > 100000) { throw new DomainException('Invalid page.'); }
        return array_map(fn (int $id) => $this->forViewer($actor, $id),
            $this->documents->list($supplierId, $this->users->isOwner($actor) ? null : $actor, $page));
    }

    /** Consistent, read-only evidence report. Never repairs documents or ledger state. */
    public function reconcile(int $actor, int $supplierId): array
    {
        $this->permit($actor, 'reconcile', true);
        $read = function () use ($actor, $supplierId): array {
            if (!$this->suppliers->find($supplierId)) { throw new DomainException('Supplier not found.'); }
            $ap = $this->ap->forSupplier($actor, $supplierId);
            $issues = $ap['issues']; $rows = []; $cash = $released = '0.0000'; $fx = $rounding = [];
            foreach ($this->documents->list($supplierId, null, 1, null) as $id) {
                $doc = $this->documents->find($id); $items = $this->documents->items($id);
                $approval = $this->documents->approval($id); $verification = $this->documents->verification($id);
                $allocations = $this->documents->allocations($id); $local = [];
                if ($approval && $approval['content_hash'] !== $this->hash($doc)) { $local[] = 'Approval content mismatch.'; }
                if (in_array($doc['status'], ['approved','posted'], true) && (!$approval || (int) $approval['approved_by'] !== (int) $doc['approved_by'])) { $local[] = 'Missing or inconsistent approval.'; }
                if ($verification && ($verification['content_hash'] !== $this->hash($doc) || !in_array($doc['status'], ['approved','posted'], true))) { $local[] = 'Invalid verification evidence/state.'; }
                if ($doc['status'] !== 'posted' && ($allocations || $doc['payment_id'] !== null)) { $local[] = 'Unposted document has settlement ledger entries.'; }
                if ($doc['amount_original'] !== null) {
                    if (!in_array($doc['currency'], ['KHR','USD'], true) || ($doc['currency'] === 'KHR' && $doc['exchange_rate_to_khr'] !== '1.000000')
                        || ($doc['currency'] === 'USD' && $doc['exchange_rate_to_khr'] !== $doc['settlement_usd_rate_to_khr'])
                        || Decimal::multiplyRounded($doc['amount_original'], $doc['exchange_rate_to_khr'], 4) !== $doc['amount_khr']) { $local[] = 'Currency/FX snapshots disagree.'; }
                    $sum = '0.0000';
                    foreach ($items as $item) {
                        $invoice = $this->invoices->find((int) $item['invoice_id']);
                        if (!$invoice || $invoice['status'] !== 'posted' || (int) $invoice['supplier_id'] !== $supplierId) { $local[] = 'Plan targets an ineligible invoice.'; continue; }
                        if ($this->cashShare($invoice['currency'], $doc, $item['invoice_amount_original']) !== $item['payment_amount_original']) { $local[] = 'Allocation currency conversion mismatch.'; }
                        $sum = Decimal::add($sum, $item['payment_amount_original'], 4);
                    }
                    if ($sum !== $doc['amount_original']) { $local[] = 'Planned allocations do not equal payment.'; }
                }
                if ($doc['status'] === 'posted') {
                    $payment = $this->payments->find((int) $doc['payment_id']);
                    if (!$payment || $payment['direction'] !== 'out' || $payment['customer_id'] !== null || $payment['payment_purpose'] !== 'supplier_payment'
                        || $payment['verification_status'] !== 'verified' || $payment['payment_key'] !== 'supplier-payment:' . $id
                        || (int) $payment['supplier_id'] !== $supplierId || $payment['verified_by'] === null || $payment['verified_at'] === null
                        || (int) $payment['recorded_by'] !== (int) $doc['posted_by']) { $local[] = 'Missing or invalid verified outgoing payment.'; }
                    foreach (['payment_method_id','currency','amount_original','exchange_rate_to_khr','amount_khr','reference_no','paid_at'] as $field) {
                        if ($payment && $payment[$field] !== $doc[$field]) { $local[] = 'Payment snapshot mismatch: ' . $field; }
                    }
                    if ($doc['method_code'] !== 'cash' && (!$verification || !$doc['reference_no']
                        || ($payment && ($payment['verified_by'] !== $verification['verified_by'] || $payment['verified_at'] !== $verification['verified_at'])))) { $local[] = 'Manual bank/KHQR verification is missing or inconsistent.'; }
                    if (count($items) !== count($allocations)) { $local[] = 'Plan/allocation count mismatch.'; }
                    $byInvoice = array_column($allocations, null, 'invoice_id');
                    $runningOriginal = $runningKhr = $apAmount = '0.0000';
                    foreach ($items as $item) {
                        $a = $byInvoice[$item['invoice_id']] ?? null;
                        if (!$a) { $local[] = 'Missing posted allocation.'; continue; }
                        $invoice = $this->invoices->find((int) $item['invoice_id']);
                        if (!$invoice) { continue; }
                        $runningOriginal = Decimal::add($runningOriginal, $item['payment_amount_original'], 4);
                        $nextCash = Decimal::multiplyRounded($runningOriginal, $doc['exchange_rate_to_khr'], 4);
                        $expectedCash = Decimal::subtract($nextCash, $runningKhr, 4); $runningKhr = $nextCash;
                        $balance = $this->balances->read($this->payables->forInvoice((int) $invoice['id']));
                        $expectedCarry = $balance['payment_carrying'][(int) $a['id']] ?? null;
                        $settlementValue = $invoice['currency'] === 'USD' ? Decimal::multiplyRounded($item['invoice_amount_original'], $doc['settlement_usd_rate_to_khr'], 4) : $item['invoice_amount_original'];
                        $historicalValue = Decimal::multiplyRounded($item['invoice_amount_original'], $invoice['exchange_rate_to_khr'], 4);
                        $expectedFx = Decimal::signedSubtract($settlementValue, $historicalValue, 4);
                        $expectedRounding = $this->signedSum([$expectedCash, '-' . $a['amount_khr'], str_starts_with($expectedFx, '-') ? substr($expectedFx, 1) : '-' . $expectedFx]);
                        if ((int) $a['payment_id'] !== (int) $doc['payment_id'] || $a['invoice_amount_original'] !== $item['invoice_amount_original']
                            || $a['payment_amount_original'] !== $item['payment_amount_original'] || $a['cash_amount_khr'] !== $expectedCash
                            || $a['amount_khr'] !== $expectedCarry || (int) $a['allocated_by'] !== (int) $doc['posted_by']
                            || $a['fx_difference_khr'] !== $expectedFx
                            || $a['rounding_difference_khr'] !== $expectedRounding) { $local[] = 'Immutable allocation/FX/rounding evidence mismatch.'; }
                        $apAmount = Decimal::add($apAmount, $a['amount_khr'], 4);
                        $fx[] = $a['fx_difference_khr'] ?? '0.0000'; $rounding[] = $a['rounding_difference_khr'] ?? '0.0000';
                    }
                    if ($runningKhr !== $doc['amount_khr']) { $local[] = 'Cash allocations do not equal the posted payment.'; }
                    $cash = Decimal::add($cash, $doc['amount_khr'], 4); $released = Decimal::add($released, $apAmount, 4);
                }
                foreach ($local as $issue) { $issues[] = 'Payment ' . $id . ': ' . $issue; }
                $rows[] = ['id' => $id, 'status' => $doc['status'], 'verification_status' => $doc['status'] === 'posted' ? 'verified' : ($verification ? 'verified_unposted' : 'not_verified'),
                    'amount_original' => $doc['amount_original'], 'currency' => $doc['currency'], 'amount_khr' => $doc['amount_khr'], 'issues' => $local];
            }
            foreach ($ap['payables'] as $payable) {
                if ($payable['supplier_invoice_id'] === null) { continue; }
                $reserved = $this->documents->reservedOriginal((int) $payable['supplier_invoice_id'], 0);
                if ($payable['outstanding_original'] === null || str_starts_with($payable['outstanding_original'], '-') || Decimal::compare($reserved, $payable['outstanding_original'], 4) > 0) { $issues[] = 'Invoice reservations exceed or cannot reconcile to remaining debt.'; }
            }
            $duplicates = $this->documents->duplicateReferences($supplierId);
            if ($duplicates) { $issues[] = 'Duplicate supplier/method references require review.'; }
            // Find orphan supplier-purpose cash flow, including documents misattributed to another supplier.
            $q = $this->pdo->prepare("SELECT p.id FROM payments p LEFT JOIN supplier_payments d ON d.payment_id = p.id
                WHERE p.supplier_id = ? AND p.payment_purpose = 'supplier_payment' AND (d.id IS NULL OR d.status <> 'posted' OR d.supplier_id <> p.supplier_id)");
            $q->execute([$supplierId]); $orphans = $q->fetchAll(PDO::FETCH_COLUMN);
            if ($orphans) { $issues[] = 'Supplier cash flow has missing or inconsistent source documents.'; }
            return ['supplier_id' => $supplierId, 'base_currency' => 'KHR', 'payables' => $ap, 'payments' => $rows,
                'posted_cash_khr' => $cash, 'settled_carrying_khr' => $released, 'fx_difference_khr' => $this->signedSum($fx),
                'rounding_difference_khr' => $this->signedSum($rounding), 'duplicate_references' => $duplicates,
                'orphan_payment_ids' => $orphans, 'issues' => $issues, 'reconciled' => $issues === []];
        };
        // Internal financial composition can reuse a caller's consistent read or
        // locked transaction; this report never performs a write.
        if ($this->pdo->inTransaction()) { return $read(); }
        return $this->transaction($read, true);
    }

    private function signedSum(array $values): string
    {
        $positive = $negative = '0.0000';
        foreach ($values as $value) {
            if (str_starts_with($value, '-')) { $negative = Decimal::add($negative, substr($value, 1), 4); }
            else { $positive = Decimal::add($positive, $value, 4); }
        }
        return Decimal::signedSubtract($positive, $negative, 4);
    }

    /** Validate snapshots and compute cumulative carrying-value/cash allocations under invoice locks. */
    private function validate(array $doc): array
    {
        if (!$doc['amount_original'] || !$doc['payment_method_id'] || !$doc['paid_at']) { throw new DomainException('An Owner must price and allocate the draft before submission.'); }
        $amount = Decimal::positive($doc['amount_original'], 4); $rate = Decimal::positive($doc['exchange_rate_to_khr'], 6);
        $usdRate = Decimal::positive($doc['settlement_usd_rate_to_khr'], 6);
        if (!in_array($doc['currency'], ['KHR','USD'], true) || !in_array($doc['method_code'], ['cash','bank','khqr'], true)
            || ($doc['currency'] === 'KHR' && $rate !== '1.000000') || ($doc['currency'] === 'USD' && $rate !== $usdRate)
            || Decimal::multiplyRounded($amount, $rate, 4) !== $doc['amount_khr']) { throw new DomainException('The payment currency/FX snapshots are inconsistent.'); }
        if ($doc['method_code'] !== 'cash' && !$doc['reference_no']) { throw new DomainException('Bank/KHQR requires a manual payment reference.'); }
        $items = $this->documents->items((int) $doc['id']);
        if (!$items || count($items) > 100) { throw new DomainException('One to 100 invoice allocations are required.'); }
        $lines = []; $cashOriginal = $cashKhr = '0.0000';
        // Items are ordered by invoice ID. Lock all invoice rows before payable rows.
        $invoices = [];
        foreach ($items as $item) {
            $invoice = $this->invoices->find((int) $item['invoice_id'], true);
            if (!$invoice || $invoice['status'] !== 'posted' || (int) $invoice['supplier_id'] !== (int) $doc['supplier_id']) {
                throw new DomainException('Allocations require posted invoices from this supplier.');
            }
            $invoices[(int) $invoice['id']] = $invoice;
        }
        $payables = [];
        foreach ($invoices as $invoice) {
            $payable = $this->payables->forInvoice((int) $invoice['id']);
            if (!$payable) { throw new DomainException('Every invoice must have a posted payable.'); }
            $payables[(int) $payable['id']] = $payable;
        }
        ksort($payables, SORT_NUMERIC);
        foreach ($payables as $payable) { $this->payables->forInvoice((int) $payable['supplier_invoice_id'], true); }
        $byInvoice = array_column($payables, null, 'supplier_invoice_id');
        foreach ($items as $item) {
            $invoice = $invoices[(int) $item['invoice_id']]; $payable = $byInvoice[$item['invoice_id']];
            if ((int) $payable['supplier_id'] !== (int) $doc['supplier_id'] || $payable['currency'] !== $invoice['currency']
                || $payable['amount_original'] !== $invoice['total_original'] || $payable['original_khr'] !== $invoice['total_khr']
                || $payable['exchange_rate_to_khr'] !== $invoice['exchange_rate_to_khr']) { throw new DomainException('The payable differs from its immutable invoice. Reconcile before settlement.'); }
            $balance = $this->balances->read($payable); $this->balances->requireSound($balance);
            $settled = $balance['discharged_original']; $carrying = $balance['discharged_khr'];
            $reserved = $this->documents->reservedOriginal((int) $invoice['id'], (int) $doc['id']);
            $next = Decimal::add($settled, $item['invoice_amount_original'], 4);
            if (Decimal::compare(Decimal::add($next, $reserved, 4), $invoice['total_original'], 4) > 0) { throw new DomainException('This allocation exceeds the unreserved invoice balance.'); }
            $expectedShare = $this->cashShare($invoice['currency'], $doc, $item['invoice_amount_original']);
            if ($expectedShare !== $item['payment_amount_original']) { throw new DomainException('Payment allocation currency conversion does not match the documented settlement rate.'); }
            $expectedCarrying = Decimal::divide(Decimal::multiplyRounded($invoice['total_khr'], $settled, 8, 40), $invoice['total_original'], 4);
            if ($expectedCarrying !== $carrying) { throw new DomainException('Historical AP allocation rounding is inconsistent. Reconcile before settlement.'); }
            $newCarrying = Decimal::divide(Decimal::multiplyRounded($invoice['total_khr'], $next, 8, 40), $invoice['total_original'], 4);
            $released = Decimal::positive(Decimal::subtract($newCarrying, $carrying, 4), 4);
            $cashOriginal = Decimal::add($cashOriginal, $item['payment_amount_original'], 4);
            $nextCash = Decimal::multiplyRounded($cashOriginal, $rate, 4);
            $cash = Decimal::positive(Decimal::subtract($nextCash, $cashKhr, 4), 4); $cashKhr = $nextCash;
            $settlementValue = $invoice['currency'] === 'USD' ? Decimal::multiplyRounded($item['invoice_amount_original'], $usdRate, 4) : $item['invoice_amount_original'];
            $historicalValue = Decimal::multiplyRounded($item['invoice_amount_original'], $invoice['exchange_rate_to_khr'], 4);
            $fx = Decimal::signedSubtract($settlementValue, $historicalValue, 4);
            $rounding = $this->signedSum([$cash, '-' . $released, str_starts_with($fx, '-') ? substr($fx, 1) : '-' . $fx]);
            $lines[] = $item + ['payable_id' => (int) $payable['id'], 'amount_khr' => $released, 'cash_amount_khr' => $cash,
                'fx_difference_khr' => $fx, 'rounding_difference_khr' => $rounding];
        }
        if ($cashOriginal !== $amount || $cashKhr !== $doc['amount_khr']) { throw new DomainException('Allocations must exactly consume the original payment amount and its KHR conversion. Prepayments/overpayments are not supported.'); }
        return $lines;
    }

    private function cashShare(string $invoiceCurrency, array $doc, string $invoiceAmount): string
    {
        if ($invoiceCurrency === $doc['currency']) { return $invoiceAmount; }
        return $invoiceCurrency === 'USD' ? Decimal::multiplyRounded($invoiceAmount, $doc['settlement_usd_rate_to_khr'], 4)
            : Decimal::divide($invoiceAmount, $doc['exchange_rate_to_khr'], 4);
    }

    private function price(int $id, array $input): void
    {
        $doc = $this->documents->find($id);
        $method = $this->payments->activeMethod(strtolower($this->text($input['payment_method'] ?? '', 20, true)));
        if (!$method || !in_array($method['code'], ['cash','bank','khqr'], true)) { throw new DomainException('Select active cash, bank, or KHQR.'); }
        $currency = strtoupper($this->text($input['currency'] ?? '', 3, true));
        $amount = Decimal::positive($this->text($input['amount_original'] ?? '', 40, true), 4);
        $rate = Decimal::positive($this->text($input['exchange_rate_to_khr'] ?? '', 40, true), 6);
        $usdRate = $currency === 'USD' ? $rate : Decimal::positive($this->text($input['settlement_usd_rate_to_khr'] ?? '1', 40, true), 6);
        if ($currency === 'USD' && isset($input['settlement_usd_rate_to_khr']) && Decimal::positive($this->text($input['settlement_usd_rate_to_khr'], 40, true), 6) !== $rate) { throw new DomainException('USD payment and settlement exchange rates must match.'); }
        $rawItems = $input['allocations'] ?? null;
        if (!is_array($rawItems) || !$rawItems || count($rawItems) > 100) { throw new DomainException('One to 100 allocations are required.'); }
        $items = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) { throw new DomainException('Invalid allocation.'); }
            $this->fields($item, ['invoice_id','invoice_amount_original','payment_amount_original']);
            $invoiceId = $this->id($item['invoice_id'] ?? null);
            if (isset($items[$invoiceId])) { throw new DomainException('Allocate each invoice only once per payment.'); }
            $items[$invoiceId] = ['invoice_id' => $invoiceId,
                'invoice_amount_original' => Decimal::positive($this->text($item['invoice_amount_original'] ?? '', 40, true), 4),
                'payment_amount_original' => Decimal::positive($this->text($item['payment_amount_original'] ?? '', 40, true), 4)];
            $invoice = $this->invoices->find($invoiceId);
            if ($invoice && $invoice['currency'] === 'USD' && $currency === 'KHR' && !isset($input['settlement_usd_rate_to_khr'])) { throw new DomainException('KHR settlement of USD debt requires an explicit USD settlement rate.'); }
        }
        $paidAt = $this->text($input['paid_at'] ?? '', 19, true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $paidAt);
        if (!$date || $date->format('Y-m-d H:i:s') !== $paidAt) { throw new DomainException('Payment time must use YYYY-MM-DD HH:MM:SS.'); }
        $data = ['payment_method_id' => (int) $method['id'], 'currency' => $currency, 'amount_original' => $amount,
            'exchange_rate_to_khr' => $rate, 'amount_khr' => Decimal::positive(Decimal::multiplyRounded($amount, $rate, 4), 4),
            'settlement_usd_rate_to_khr' => $usdRate, 'paid_at' => $paidAt];
        foreach (['reference_no' => 120,'notes' => 1000] as $field => $length) {
            if (array_key_exists($field, $input)) { $data[$field] = $this->text($input[$field], $length); }
        }
        $data['normalized_reference'] = SupplierPayment::normalizeReference($data['reference_no'] ?? $doc['reference_no']);
        $this->documents->update($id, $data); ksort($items, SORT_NUMERIC); $this->documents->replaceItems($id, $items);
        $this->validate($this->documents->find($id));
    }

    private function approved(array $doc): void
    {
        $approval = $this->documents->approval((int) $doc['id']);
        if (!$approval || $approval['content_hash'] !== $this->hash($doc) || (int) $approval['approved_by'] !== (int) $doc['approved_by']) {
            throw new DomainException('The payment no longer matches its immutable Owner approval.');
        }
    }
    private function hash(array $doc): string
    {
        $keys = ['id','supplier_id','payment_method_id','currency','amount_original','exchange_rate_to_khr','amount_khr',
            'settlement_usd_rate_to_khr','reference_no','normalized_reference','paid_at','notes'];
        $data = array_intersect_key($doc, array_flip($keys));
        $data['items'] = array_map(fn ($i) => array_intersect_key($i, array_flip(['invoice_id','invoice_amount_original','payment_amount_original'])), $this->documents->items((int) $doc['id']));
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
    private function locked(int $id): array
    {
        $doc = $this->documents->find($id);
        if (!$doc) { throw new DomainException('The payment request is unavailable.'); }
        $this->supplier((int) $doc['supplier_id'], false);
        return $this->documents->find($id, true);
    }
    private function supplier(int $id, bool $active = true): void
    {
        $supplier = $this->suppliers->find($id, true);
        if (!$supplier || ($active && $supplier['status'] !== 'active')) { throw new DomainException('An active supplier is required for a new request.'); }
        // Existing invoices may be paid even after supplier deactivation.
    }
    private function accessible(int $actor, array $doc): void
    {
        if (!$this->users->isOwner($actor) && (int) $doc['created_by'] !== $actor) { throw new DomainException('The payment request is unavailable.'); }
    }
    private function state(array $doc, array $states): void
    {
        if (!in_array($doc['status'], $states, true)) { throw new DomainException('This action is not valid in the current payment state.'); }
    }
    private function permit(int $actor, string $suffix, bool $owner = false): void
    {
        if (!$this->users->findActiveById($actor) || !$this->users->hasPermission($actor, 'supplier_payments.' . $suffix)
            || ($owner && !$this->users->isOwner($actor))) { throw new DomainException('You are not authorized for this supplier payment action.'); }
    }
    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), [...$allowed, '_csrf'])) { throw new DomainException('Unsupported or restricted supplier payment fields.'); }
    }
    private function financialFields(): array { return ['payment_method','currency','amount_original','exchange_rate_to_khr','settlement_usd_rate_to_khr','paid_at','allocations']; }
    private function text(mixed $value, int $max, bool $required = false): ?string
    {
        if (!is_string($value)) { throw new DomainException('A text value is required.'); }
        $value = trim($value);
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (preg_match('//u', $value) !== 1 || ($required && $value === '') || $length > $max) { throw new DomainException('A required text value is invalid, empty or too long.'); }
        return $value === '' ? null : $value;
    }
    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) { throw new DomainException('A valid identifier is required.'); }
        return (int) $id;
    }
    private function transaction(callable $operation, bool $readOnly = false): mixed
    {
        if ($this->pdo->inTransaction()) { throw new \LogicException('Supplier payment transactions cannot be nested.'); }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . ($readOnly ? 'REPEATABLE READ' : 'READ COMMITTED'));
        if ($readOnly) { $this->pdo->exec('SET TRANSACTION READ ONLY'); }
        $this->pdo->beginTransaction();
        try { $result = $operation(); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException) { throw new DomainException('The supplier payment conflicts with existing data; no changes were committed.', 0, $e); }
            throw $e;
        }
    }
}
