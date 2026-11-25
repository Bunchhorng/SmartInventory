<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{AuditLog, GoodsReceipt, Payable, PurchaseOrder, Supplier, SupplierInvoice, User};
use App\Support\Decimal;
use DomainException;
use PDO;

/** Three-way matching and immutable AP recognition; never posts inventory or cash. */
final class SupplierInvoiceService
{
    public function __construct(
        private PDO $pdo, private User $users, private AuditLog $audit,
        private SupplierInvoice $invoices, private PurchaseOrder $orders,
        private GoodsReceipt $goods, private Supplier $suppliers,
        private Payable $payables, private AccountsPayableService $accountsPayable,
    ) {}

    public function createDraft(int $actorId, array $input): array
    {
        $this->permission($actorId, 'supplier_invoices.record');
        $owner = $this->users->isOwner($actorId);
        $allowed = ['_csrf','invoice_key','supplier_invoice_no','supplier_id','purchase_id','invoice_date','due_date','notes'];
        if ($owner) { $this->permission($actorId, 'supplier_invoices.manage'); $allowed = [...$allowed, 'currency','exchange_rate_to_khr','items']; }
        $this->keys($input, $allowed);
        $purchaseId = $this->id($input['purchase_id'] ?? null);
        $supplierId = $this->id($input['supplier_id'] ?? null);
        $key = $this->text($input['invoice_key'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) { throw new DomainException('An 8-80 character invoice request key is required.'); }
        $number = $this->text($input['supplier_invoice_no'] ?? '');
        if ($number === '' || $this->length($number) > 120 || preg_match('/[\x00-\x1F\x7F]/u', $number)) {
            throw new DomainException('A supplier invoice number of 1-120 characters without control characters is required.');
        }
        $normalized = function_exists('mb_strtoupper') ? mb_strtoupper($number, 'UTF-8') : strtoupper($number);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        $date = $this->date($input['invoice_date'] ?? '');
        $notes = $this->notes($input['notes'] ?? '');
        $payload = $input; unset($payload['_csrf']); ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return $this->transaction(function () use ($actorId, $input, $purchaseId, $supplierId, $key, $number, $normalized, $date, $notes, $hash): array {
            $po = $this->orders->find($purchaseId, true);
            if (!$po || (int) $po['supplier_id'] !== $supplierId) { throw new DomainException('The supplier does not own this purchase order.'); }
            $existing = $this->invoices->byKey($key);
            if ($existing) {
                if ((int) $existing['created_by'] !== $actorId || $existing['request_hash'] !== $hash) {
                    throw new DomainException('Invoice request key belongs to a different request.');
                }
                return ['invoice_id' => (int) $existing['id'], 'created' => false];
            }
            $a = $this->approvedPo($po);
            $this->headerCurrency($input, $a);
            $supplier = $this->suppliers->find($supplierId);
            if (!$supplier) { throw new DomainException('Supplier was not found.'); }
            $due = isset($input['due_date']) && $input['due_date'] !== '' ? $this->date($input['due_date'])
                : (new \DateTimeImmutable($date))->modify('+' . (int) $supplier['payment_terms_days'] . ' days')->format('Y-m-d');
            if ($due < $date) { throw new DomainException('Due date cannot precede invoice date.'); }
            $invoiceId = $this->invoices->create([$key, $hash, $number, $normalized, $supplierId, $purchaseId,
                $date, $due, $notes, $a['currency_snapshot'], $a['exchange_rate_to_khr_snapshot'], $actorId]);
            if (array_key_exists('items', $input)) {
                $invoice = $this->invoices->find($invoiceId);
                $lines = $this->materialize($invoice, $input['items'], $this->receiptRows($po, $a, true));
                $this->invoices->replaceItems($invoiceId, $lines);
                [$original, $khr] = $this->totals($lines);
                $this->invoices->setTotals($invoiceId, $original, $khr);
            }
            $this->audit->record($actorId, 'supplier_invoice.draft_created', 'supplier_invoices', $invoiceId);
            return ['invoice_id' => $invoiceId, 'created' => true];
        });
    }

    public function updateDraft(int $actorId, int $invoiceId, array $input): void
    {
        $this->permission($actorId, 'supplier_invoices.record');
        $owner = $this->users->isOwner($actorId);
        $allowed = ['_csrf','invoice_id','invoice_date','due_date','notes'];
        if ($owner) { $this->permission($actorId, 'supplier_invoices.manage'); $allowed = [...$allowed, 'currency','exchange_rate_to_khr','items']; }
        $this->keys($input, $allowed);
        $this->withInvoice($invoiceId, function (array $po, array $invoice) use ($actorId, $owner, $input): void {
            if ($invoice['status'] !== 'draft') { throw new DomainException('Only draft supplier invoices can be edited.'); }
            if (!$owner && (int) $invoice['created_by'] !== $actorId) { throw new DomainException('Staff may edit only their own invoice metadata.'); }
            $a = $this->approvedPo($po);
            $this->headerCurrency($input, $a);
            $date = array_key_exists('invoice_date', $input) ? $this->date($input['invoice_date']) : $invoice['invoice_date'];
            $due = array_key_exists('due_date', $input) ? $this->date($input['due_date']) : $invoice['due_date'];
            if ($due < $date) { throw new DomainException('Due date cannot precede invoice date.'); }
            $this->invoices->updateDraft((int) $invoice['id'], $date, $due,
                array_key_exists('notes', $input) ? $this->notes($input['notes']) : $invoice['notes']);
            if (array_key_exists('items', $input)) {
                $lines = $this->materialize($invoice, $input['items'], $this->receiptRows($po, $a, true));
                $this->invoices->replaceItems((int) $invoice['id'], $lines);
                [$original, $khr] = $this->totals($lines);
                $this->invoices->setTotals((int) $invoice['id'], $original, $khr);
            }
            $this->audit->record($actorId, 'supplier_invoice.draft_updated', 'supplier_invoices', (int) $invoice['id']);
        });
    }

    public function submit(int $actorId, int $invoiceId): array
    {
        $this->permission($actorId, 'supplier_invoices.record');
        return $this->withInvoice($invoiceId, function (array $po, array $invoice) use ($actorId, $invoiceId): array {
            if (!$this->users->isOwner($actorId) && (int) $invoice['created_by'] !== $actorId) { throw new DomainException('Staff may submit only their own invoices.'); }
            if ($invoice['status'] === 'submitted') { return ['invoice_id' => $invoiceId, 'submitted' => false]; }
            if ($invoice['status'] !== 'draft') { throw new DomainException('Only a priced draft can be submitted.'); }
            $this->validateInvoice($po, $invoice);
            $this->invoices->transition($invoiceId, 'draft', 'submitted', $actorId);
            $this->audit->record($actorId, 'supplier_invoice.submitted', 'supplier_invoices', $invoiceId);
            return ['invoice_id' => $invoiceId, 'submitted' => true];
        });
    }

    public function approve(int $actorId, int $invoiceId, string $varianceReason = ''): array
    {
        $this->owner($actorId, 'supplier_invoices.approve');
        return $this->withInvoice($invoiceId, function (array $po, array $invoice) use ($actorId, $invoiceId, $varianceReason): array {
            if (in_array($invoice['status'], ['approved','posted'], true)) { return ['invoice_id' => $invoiceId, 'approved' => false]; }
            if ($invoice['status'] !== 'submitted') { throw new DomainException('Only a submitted invoice can be approved.'); }
            [$lines, $rows, $variance] = $this->validateInvoice($po, $invoice);
            $reason = trim($varianceReason);
            if ($variance) { $reason = $this->reason($reason); }
            elseif ($reason !== '') { $reason = $this->reason($reason); }
            $this->invoices->approve($invoiceId, $this->contentHash($invoice, $lines), (string) $invoice['total_original'],
                (string) $invoice['total_khr'], $variance, $reason ?: null, $actorId);
            $this->invoices->transition($invoiceId, 'submitted', 'approved', $actorId);
            $this->audit->record($actorId, 'supplier_invoice.approved', 'supplier_invoices', $invoiceId, $reason ?: null);
            return ['invoice_id' => $invoiceId, 'approved' => true];
        });
    }

    public function post(int $actorId, int $invoiceId): array
    {
        $this->owner($actorId, 'supplier_invoices.post');
        return $this->withInvoice($invoiceId, function (array $po, array $invoice) use ($actorId, $invoiceId): array {
            if ($invoice['status'] === 'posted') {
                $payable = $this->payables->forInvoice($invoiceId);
                if (!$payable) { throw new DomainException('Posted invoice has no payable. Reconciliation is required.'); }
                return ['invoice_id' => $invoiceId, 'payable_id' => (int) $payable['id'], 'posted' => false];
            }
            if ($invoice['status'] !== 'approved') { throw new DomainException('Only an approved invoice can recognize a payable.'); }
            [$lines, $rows, $variance] = $this->validateInvoice($po, $invoice);
            $approval = $this->invoices->approval($invoiceId);
            if (!$approval || $approval['content_hash'] !== $this->contentHash($invoice, $lines)
                || $approval['total_khr'] !== $invoice['total_khr'] || $approval['total_original'] !== $invoice['total_original']
                || ($variance && (!(bool) $approval['variance_required'] || !$approval['variance_reason']))) {
                throw new DomainException('Invoice no longer matches its immutable approval snapshot.');
            }
            $usage = $this->invoices->usage((int) $po['id']);
            $payableId = $this->accountsPayable->recognizeApprovedInvoice($actorId, $invoice);
            foreach ($lines as $line) {
                $receiptId = (int) $line['goods_receipt_item_id'];
                $receipt = $rows[$receiptId];
                $used = $usage[$receiptId] ?? ['quantity' => '0.000', 'receipt_value_khr' => '0.0000'];
                $cumulative = Decimal::add((string) $used['quantity'], (string) $line['quantity'], 3);
                // Cumulative proportional allocation gives the final match the
                // receipt's exact four-place value, without splitting residue.
                $cumulativeBasis = Decimal::divide(Decimal::multiply((string) $receipt['receipt_value_khr'], $cumulative, 7),
                    (string) $receipt['received_qty'], 4);
                $basis = Decimal::subtract($cumulativeBasis, (string) $used['receipt_value_khr'], 4);
                $difference = Decimal::signedSubtract((string) $line['line_total_khr'], $basis, 4);
                $type = $this->hasPriceVariance($line, $invoice) ? 'price' : 'rounding';
                $this->invoices->createMatch([$line['id'], $invoiceId, $payableId, $receiptId, $line['purchase_item_id'],
                    $line['quantity'], $line['qty_base'], $basis, $line['line_total_khr'], $actorId],
                    $difference, $type, (int) $approval['id']);
            }
            $this->invoices->transition($invoiceId, 'approved', 'posted', $actorId);
            $this->audit->record($actorId, 'supplier_invoice.posted', 'supplier_invoices', $invoiceId);
            return ['invoice_id' => $invoiceId, 'payable_id' => $payableId, 'posted' => true];
        });
    }

    public function reject(int $actorId, int $invoiceId, string $reason): void
    {
        $this->owner($actorId, 'supplier_invoices.approve');
        $reason = $this->reason($reason);
        $this->withInvoice($invoiceId, function (array $po, array $invoice) use ($actorId, $invoiceId, $reason): void {
            if ($invoice['status'] !== 'submitted') { throw new DomainException('Only a submitted invoice can be rejected.'); }
            $this->invoices->transition($invoiceId, 'submitted', 'rejected', $actorId, $reason);
            $this->audit->record($actorId, 'supplier_invoice.rejected', 'supplier_invoices', $invoiceId, $reason);
        });
    }

    public function cancel(int $actorId, int $invoiceId, string $reason): void
    {
        $this->owner($actorId, 'supplier_invoices.manage');
        $reason = $this->reason($reason);
        $this->withInvoice($invoiceId, function (array $po, array $invoice) use ($actorId, $invoiceId, $reason): void {
            if (!in_array($invoice['status'], ['draft','submitted','approved','rejected'], true)
                || $this->payables->forInvoice($invoiceId) || $this->invoices->matches($invoiceId)) {
                throw new DomainException('Only an unposted invoice can be cancelled. Posted liabilities require a future supplier credit-note correction.');
            }
            $this->invoices->transition($invoiceId, $invoice['status'], 'cancelled', $actorId, $reason);
            $this->audit->record($actorId, 'supplier_invoice.cancelled', 'supplier_invoices', $invoiceId, $reason);
        });
    }

    public function forViewer(int $actorId, int $invoiceId): array
    {
        $this->permission($actorId, 'supplier_invoices.view');
        $invoice = $this->invoices->find($invoiceId);
        if (!$invoice) { throw new DomainException('Supplier invoice was not found.'); }
        if (!$this->users->isOwner($actorId)) { return array_intersect_key($invoice, array_flip(self::PUBLIC_FIELDS)); }
        return $invoice + ['items' => $this->invoices->items($invoiceId), 'approval' => $this->invoices->approval($invoiceId),
            'payable' => $this->payables->forInvoice($invoiceId), 'matches' => $this->invoices->matches($invoiceId)];
    }

    public function listForViewer(int $actorId, int $supplierId, int $page = 1): array
    {
        $this->permission($actorId, 'supplier_invoices.view');
        $rows = $this->invoices->list($supplierId, min(100000, max(1, $page)), 25);
        return $this->users->isOwner($actorId) ? $rows : array_map(
            static fn (array $row): array => array_intersect_key($row, array_flip(self::PUBLIC_FIELDS)), $rows);
    }

    public function reconcile(int $actorId, int $purchaseId): array
    {
        $this->owner($actorId, 'supplier_invoices.reconcile');
        return $this->transaction(function () use ($actorId, $purchaseId): array {
            $po = $this->orders->find($purchaseId);
            if (!$po) { throw new DomainException('Purchase order was not found.'); }
            $issues = [];
            $rows = [];
            try { $rows = $this->receiptRows($po, $this->approvedPo($po), false); }
            catch (DomainException $e) { $issues[] = $e->getMessage(); }
            $usage = $this->invoices->usage($purchaseId);
            $quantities = [];
            foreach ($rows as $id => $row) {
                $used = $usage[$id] ?? ['quantity' => '0.000', 'qty_base' => '0.000', 'receipt_value_khr' => '0.0000'];
                $remaining = Decimal::signedSubtract((string) $row['received_qty'], (string) $used['quantity'], 3);
                $quantities[] = ['goods_receipt_item_id' => $id, 'received_qty' => $row['received_qty'],
                    'invoiced_qty' => $used['quantity'], 'remaining_qty' => $remaining,
                    'receipt_value_khr' => $row['receipt_value_khr'], 'matched_receipt_value_khr' => $used['receipt_value_khr']];
                if (str_starts_with($remaining, '-')
                    || Decimal::compare(Decimal::multiply((string) $used['quantity'], (string) $row['conversion_snapshot'], 3), (string) $used['qty_base'], 3) !== 0
                    || Decimal::compare((string) $used['receipt_value_khr'], (string) $row['receipt_value_khr'], 4) > 0
                    || (Decimal::isZeroValue($remaining) && $used['receipt_value_khr'] !== $row['receipt_value_khr'])) {
                    $issues[] = 'Receipt line ' . $id . ' matched quantities or value are inconsistent.';
                }
            }
            if (array_diff_key($usage, $rows)) { $issues[] = 'Matches reference receipts outside eligible confirmed receiving.'; }
            $cumulative = [];
            foreach ($this->invoices->purchaseMatches($purchaseId) as $match) {
                $receiptId = (int) $match['goods_receipt_item_id'];
                if (!isset($rows[$receiptId])) { continue; }
                $previous = $cumulative[$receiptId] ?? ['qty' => '0.000', 'value' => '0.0000'];
                $qty = Decimal::add($previous['qty'], (string) $match['quantity'], 3);
                $expectedValue = Decimal::divide(Decimal::multiply((string) $rows[$receiptId]['receipt_value_khr'], $qty, 7),
                    (string) $rows[$receiptId]['received_qty'], 4);
                if (Decimal::signedSubtract($expectedValue, $previous['value'], 4) !== $match['receipt_value_khr']) {
                    $issues[] = 'Receipt line ' . $receiptId . ' has an inconsistent cumulative matched value.';
                }
                $cumulative[$receiptId] = ['qty' => $qty, 'value' => $expectedValue];
            }
            $invoiceReport = [];
            $approvedTotal = $postedTotal = '0.0000';
            foreach ($this->invoices->forPurchase($purchaseId) as $invoice) {
                $id = (int) $invoice['id'];
                $lines = $this->invoices->items($id);
                $approval = $this->invoices->approval($id);
                $payable = $this->payables->forInvoice($id);
                $matches = $this->invoices->matches($id);
                if (in_array($invoice['status'], ['approved','posted'], true)) {
                    $approvedTotal = Decimal::add($approvedTotal, (string) $invoice['total_khr'], 4);
                    if (!$approval || $approval['content_hash'] !== $this->contentHash($invoice, $lines)
                        || $approval['total_khr'] !== $invoice['total_khr'] || $approval['total_original'] !== $invoice['total_original']) {
                        $issues[] = 'Invoice ' . $id . ' differs from its approval snapshot.';
                    }
                }
                if ($invoice['status'] === 'posted') {
                    $postedTotal = Decimal::add($postedTotal, (string) $invoice['total_khr'], 4);
                    if (!$payable || (int) $payable['purchase_id'] !== $purchaseId || (int) $payable['supplier_id'] !== (int) $invoice['supplier_id']
                        || $payable['original_khr'] !== $invoice['total_khr'] || $payable['amount_original'] !== $invoice['total_original']
                        || $payable['currency'] !== $invoice['currency'] || $payable['exchange_rate_to_khr'] !== $invoice['exchange_rate_to_khr']
                        || $payable['due_date'] !== $invoice['due_date'] || count($matches) !== count($lines)) {
                        $issues[] = 'Invoice ' . $id . ' does not reconcile with its payable/match ledger.';
                    }
                    $lineMap = array_column($lines, null, 'id');
                    foreach ($matches as $match) {
                        $line = $lineMap[(int) $match['invoice_item_id']] ?? null;
                        $difference = Decimal::signedSubtract((string) $match['invoice_value_khr'], (string) $match['receipt_value_khr'], 4);
                        if (!$line || !$payable || (int) $match['payable_id'] !== (int) $payable['id']
                            || (int) $match['goods_receipt_item_id'] !== (int) $line['goods_receipt_item_id']
                            || (int) $match['purchase_item_id'] !== (int) $line['purchase_item_id']
                            || $match['quantity'] !== $line['quantity'] || $match['qty_base'] !== $line['qty_base']
                            || $match['invoice_value_khr'] !== $line['line_total_khr']
                            || ($difference === '0.0000' ? $match['variance_khr'] !== null : $match['variance_khr'] !== $difference)
                            || ($difference !== '0.0000' && (int) $match['approval_id'] !== (int) ($approval['id'] ?? 0))) {
                            $issues[] = 'Invoice ' . $id . ' contains an inconsistent posted match/variance.';
                        }
                    }
                } elseif ($payable || $matches) { $issues[] = 'Unposted invoice ' . $id . ' has financial postings.'; }
                $invoiceReport[] = ['invoice_id' => $id, 'status' => $invoice['status'], 'approved_total_khr' => $approval['total_khr'] ?? null,
                    'posted_payable_khr' => $payable['original_khr'] ?? '0.0000', 'payable_id' => $payable['id'] ?? null];
            }
            $supplierReport = $this->accountsPayable->forSupplier($actorId, (int) $po['supplier_id']);
            $issues = [...$issues, ...$supplierReport['issues']];
            foreach ($invoiceReport as &$row) {
                $row['outstanding_khr'] = '0.0000';
                foreach ($supplierReport['payables'] as $p) {
                    if ((int) $p['id'] === (int) ($row['payable_id'] ?? 0)) { $row['outstanding_khr'] = $p['outstanding_khr']; }
                }
            }
            unset($row);
            return ['purchase_id' => $purchaseId, 'quantities' => $quantities, 'invoices' => $invoiceReport,
                'approved_invoice_total_khr' => $approvedTotal, 'posted_invoice_total_khr' => $postedTotal,
                'supplier' => $supplierReport, 'issues' => array_values(array_unique($issues)), 'reconciled' => $issues === []];
        }, true);
    }

    private function validateInvoice(array $po, array $invoice): array
    {
        $a = $this->approvedPo($po);
        $this->headerCurrency($invoice, $a);
        if ((int) $invoice['supplier_id'] !== (int) $po['supplier_id']) { throw new DomainException('Invoice supplier does not match the PO.'); }
        $rows = $this->receiptRows($po, $a, true);
        $lines = $this->invoices->items((int) $invoice['id'], true);
        if (!$lines) { throw new DomainException('An Owner must supply matched invoice lines before submission.'); }
        $input = array_map(static fn (array $line): array => [
            'goods_receipt_item_id' => $line['goods_receipt_item_id'], 'quantity' => $line['quantity'],
            'product_unit_id' => $line['product_unit_id'], 'unit_purchase_price' => $line['unit_purchase_price'], 'discount_percent' => $line['discount_percent'],
        ], $lines);
        $expected = $this->materialize($invoice, $input, $rows);
        foreach ($lines as $index => $line) {
            foreach ($expected[$index] as $key => $value) {
                if ((string) $line[$key] !== (string) $value) { throw new DomainException('Invoice line snapshots no longer match approved receiving.'); }
            }
        }
        [$original, $khr] = $this->totals($expected);
        if ($invoice['total_original'] !== $original || $invoice['total_khr'] !== $khr) { throw new DomainException('Invoice totals do not match their fixed-point line totals.'); }
        $variance = false;
        foreach ($lines as $line) { $variance = $variance || $this->hasPriceVariance($line, $invoice); }
        return [$lines, $rows, $variance];
    }

    private function materialize(array $invoice, mixed $input, array $rows): array
    {
        if (!is_array($input) || count($input) < 1 || count($input) > 100) { throw new DomainException('A priced invoice requires 1-100 receipt lines.'); }
        $usage = $this->invoices->usage((int) $invoice['purchase_id']);
        $result = [];
        foreach ($input as $line) {
            if (!is_array($line)) { throw new DomainException('Invalid invoice line.'); }
            $this->keys($line, ['goods_receipt_item_id','quantity','product_unit_id','unit_purchase_price','discount_percent']);
            $id = $this->id($line['goods_receipt_item_id'] ?? null);
            if (isset($result[$id])) { throw new DomainException('A received line may appear only once per invoice.'); }
            $r = $rows[$id] ?? null;
            if (!$r) { throw new DomainException('Invoice lines must target confirmed receipts belonging to this PO.'); }
            $qty = $this->quantity($line['quantity'] ?? '', (int) $r['unit_precision_snapshot']);
            $available = Decimal::subtract((string) $r['received_qty'], (string) ($usage[$id]['quantity'] ?? '0.000'), 3);
            if (Decimal::compare($qty, $available, 3) > 0) { throw new DomainException('Invoice quantity exceeds the eligible unmatched received quantity.'); }
            if (isset($line['product_unit_id']) && $this->id($line['product_unit_id']) !== (int) $r['product_unit_id']) {
                throw new DomainException('Invoice purchase unit must match the approved PO and received line.');
            }
            $price = Decimal::positive($this->text($line['unit_purchase_price'] ?? $r['unit_purchase_price']), 4);
            $discount = Decimal::nonNegative($this->text($line['discount_percent'] ?? $r['discount_percent']), 4, 7);
            if (Decimal::compare($discount, '100.0000', 4) >= 0) { throw new DomainException('Invoice discount must be below 100 percent.'); }
            $subtotal = Decimal::multiplyRounded($qty, $price, 4);
            $discountAmount = Decimal::multiplyRounded($subtotal, Decimal::divide($discount, '100', 6), 4);
            $total = Decimal::positive(Decimal::subtract($subtotal, $discountAmount, 4), 4);
            $khr = Decimal::positive(Decimal::multiplyRounded($total, (string) $invoice['exchange_rate_to_khr'], 4), 4);
            $result[$id] = [
                'goods_receipt_item_id' => $id, 'purchase_item_id' => (int) $r['purchase_item_id'], 'product_unit_id' => (int) $r['product_unit_id'],
                'quantity' => $qty, 'qty_base' => Decimal::multiply($qty, (string) $r['conversion_snapshot'], 3),
                'unit_code_snapshot' => $r['unit_code_snapshot'], 'conversion_snapshot' => $r['conversion_snapshot'],
                'unit_purchase_price' => $price, 'discount_percent' => $discount,
                'po_unit_price_snapshot' => $r['unit_purchase_price'], 'po_discount_snapshot' => $r['discount_percent'],
                'receipt_cost_snapshot_khr' => $r['landed_unit_cost_khr'], 'line_subtotal' => $subtotal,
                'line_discount' => $discountAmount, 'line_total' => $total, 'line_total_khr' => $khr,
            ];
        }
        ksort($result, SORT_NUMERIC);
        return array_values($result);
    }

    private function receiptRows(array $po, array $approval, bool $lock): array
    {
        $rows = [];
        $poTotals = [];
        foreach ($this->invoices->receivedLines((int) $po['id'], $lock) as $r) {
            $id = (int) $r['id'];
            if (isset($rows[$id]) || (int) $r['supplier_id'] !== (int) $po['supplier_id'] || (int) $r['purchase_id'] !== (int) $po['id']
                || $r['currency_snapshot'] !== $approval['currency_snapshot'] || $r['exchange_rate_snapshot'] !== $approval['exchange_rate_to_khr_snapshot']
                || $r['inventory_status'] !== 'confirmed' || !$r['movement_id'] || !$r['cost_entry_id']
                || (int) $r['inventory_unit'] !== (int) $r['product_unit_id'] || $r['inventory_qty'] !== $r['received_qty']
                || $r['inventory_base'] !== $r['qty_base'] || $r['movement_qty'] !== $r['qty_base'] || $r['cost_qty'] !== $r['qty_base']
                || (int) $r['movement_product'] !== (int) $r['product_id'] || (int) $r['cost_product'] !== (int) $r['product_id']
                || (int) $r['inventory_location'] !== (int) $r['location_id'] || (int) $r['movement_location'] !== (int) $r['location_id']
                || $r['inventory_cost'] !== $r['landed_unit_cost_khr'] || $r['movement_cost'] !== $r['landed_unit_cost_khr']
                || $r['valuation_cost'] !== $r['landed_unit_cost_khr'] || $r['cost_source_type'] !== 'inventory_receipt'
                || (int) $r['cost_source_id'] !== (int) $r['inventory_id'] || (int) $r['cost_source_line'] !== (int) $r['inventory_item_id']
                || $r['conversion_snapshot'] !== $r['unit_to_base_factor_snapshot'] || $r['unit_precision_snapshot'] === null
                || $r['approved_cost_per_base_khr'] !== $this->netCost((string) $r['unit_purchase_price'], (string) $r['discount_percent'],
                    (string) $approval['exchange_rate_to_khr_snapshot'], (string) $r['conversion_snapshot'])
                || Decimal::compare(Decimal::multiply((string) $r['ordered_qty'], (string) $r['conversion_snapshot'], 6), (string) $r['qty_base_snapshot'], 6) !== 0
                || $r['qty_base'] !== Decimal::multiply((string) $r['received_qty'], (string) $r['conversion_snapshot'], 3)
                || $r['receipt_value_khr'] !== Decimal::multiplyRounded((string) $r['qty_base'], (string) $r['landed_unit_cost_khr'], 4)
                || ($r['landed_unit_cost_khr'] !== $r['approved_cost_per_base_khr'] &&
                    ($r['variance_cost'] !== $r['landed_unit_cost_khr'] || $r['variance_baseline'] !== $r['approved_cost_per_base_khr']))) {
                throw new DomainException('Confirmed receipt ' . $id . ' has unresolved supplier, quantity, price, or ledger discrepancies.');
            }
            $rows[$id] = $r;
            $poLine = (int) $r['purchase_item_id'];
            $poTotals[$poLine] = Decimal::add($poTotals[$poLine] ?? '0.000', (string) $r['received_qty'], 3);
        }
        foreach ($rows as $r) {
            $received = $poTotals[(int) $r['purchase_item_id']];
            if ($received !== $r['po_received_qty'] || Decimal::compare($received, (string) $r['ordered_qty'], 3) > 0) {
                throw new DomainException('Confirmed receiving does not reconcile to approved PO fulfillment.');
            }
        }
        return $rows;
    }

    private function approvedPo(array $po): array
    {
        $a = $this->goods->approval((int) $po['id']);
        if (!in_array($po['status'], ['approved','partially_received','received','closed'], true) || !$a
            || (int) $a['supplier_id_snapshot'] !== (int) $po['supplier_id'] || $a['currency_snapshot'] !== $po['currency']
            || $a['exchange_rate_to_khr_snapshot'] !== $po['exchange_rate_to_khr'] || !in_array($po['currency'], ['KHR','USD'], true)
            || ($po['currency'] === 'KHR' && $po['exchange_rate_to_khr'] !== '1.000000')) {
            throw new DomainException('Invoice must reference a valid approved PO and matching supplier/currency snapshots.');
        }
        Decimal::positive((string) $a['exchange_rate_to_khr_snapshot'], 6);
        return $a;
    }

    private function headerCurrency(array $input, array $approval): void
    {
        if (isset($input['currency']) && strtoupper($this->text($input['currency'])) !== $approval['currency_snapshot']) {
            throw new DomainException('Invoice currency must match the approved PO. Currency variances are not permitted in Phase 5.3.');
        }
        if (isset($input['exchange_rate_to_khr']) && Decimal::positive($this->text($input['exchange_rate_to_khr']), 6) !== $approval['exchange_rate_to_khr_snapshot']) {
            throw new DomainException('Invoice exchange rate must match the approved PO. FX variances require a later policy.');
        }
    }

    private function hasPriceVariance(array $line, array $invoice): bool
    {
        $cost = $this->netCost((string) $line['unit_purchase_price'], (string) $line['discount_percent'],
            (string) $invoice['exchange_rate_to_khr'], (string) $line['conversion_snapshot']);
        return $line['unit_purchase_price'] !== $line['po_unit_price_snapshot'] || $line['discount_percent'] !== $line['po_discount_snapshot']
            || $cost !== $line['receipt_cost_snapshot_khr'];
    }

    private function netCost(string $price, string $discount, string $fx, string $conversion): string
    {
        $fraction = Decimal::divide(Decimal::subtract('100.0000', $discount, 4), '100', 6);
        $net = Decimal::multiply($price, $fraction, 10);
        return Decimal::divide(Decimal::multiply($net, $fx, 16), $conversion, 6);
    }

    private function totals(array $lines): array
    {
        $original = $khr = '0.0000';
        foreach ($lines as $line) { $original = Decimal::add($original, (string) $line['line_total'], 4); $khr = Decimal::add($khr, (string) $line['line_total_khr'], 4); }
        return [Decimal::positive($original, 4), Decimal::positive($khr, 4)];
    }

    /** Internal read-only contract for a locked financial mutation or consistent report. */
    public function assertPostedFinancialEvidence(int $actorId, array $invoice, array $payable): void
    {
        $this->owner($actorId, 'supplier_invoices.reconcile');
        if (!$this->pdo->inTransaction()) { throw new \LogicException('Posted invoice evidence requires the caller transaction.'); }
        $id = (int) $invoice['id']; $lines = $this->invoices->items($id); $approval = $this->invoices->approval($id);
        if ($invoice['status'] !== 'posted' || !$approval || $approval['content_hash'] !== $this->contentHash($invoice, $lines)
            || $approval['total_original'] !== $invoice['total_original'] || $approval['total_khr'] !== $invoice['total_khr']
            || (int) $approval['approved_by'] !== (int) $invoice['approved_by']
            || (int) $payable['supplier_invoice_id'] !== $id || (int) $payable['supplier_id'] !== (int) $invoice['supplier_id']
            || (int) $payable['purchase_id'] !== (int) $invoice['purchase_id'] || $payable['due_date'] !== $invoice['due_date']
            || $payable['amount_original'] !== $invoice['total_original'] || $payable['original_khr'] !== $invoice['total_khr']
            || $payable['currency'] !== $invoice['currency'] || $payable['exchange_rate_to_khr'] !== $invoice['exchange_rate_to_khr']) {
            throw new DomainException('The posted invoice/payable differs from its immutable Owner approval.');
        }
        $matches = $this->invoices->matches($id); $byLine = array_column($lines, null, 'id'); $total = '0.0000';
        if (count($matches) !== count($lines) || !$lines) { throw new DomainException('Posted invoice matching evidence is incomplete.'); }
        foreach ($matches as $match) {
            $line = $byLine[$match['invoice_item_id']] ?? null;
            if (!$line || (int) $match['payable_id'] !== (int) $payable['id'] || (int) $match['invoice_id'] !== $id
                || (int) $match['goods_receipt_item_id'] !== (int) $line['goods_receipt_item_id']
                || (int) $match['purchase_item_id'] !== (int) $line['purchase_item_id'] || $match['quantity'] !== $line['quantity']
                || $match['qty_base'] !== $line['qty_base'] || $match['invoice_value_khr'] !== $line['line_total_khr']) {
                throw new DomainException('Posted invoice matches differ from approved financial lines.');
            }
            $total = Decimal::add($total, $match['invoice_value_khr'], 4);
        }
        if ($total !== $payable['original_khr']) { throw new DomainException('Posted invoice match values do not reconcile to AP.'); }
    }

    private function contentHash(array $invoice, array $lines): string
    {
        $header = array_intersect_key($invoice, array_flip(['id','supplier_invoice_no','supplier_id','purchase_id','invoice_date','due_date','currency','exchange_rate_to_khr','total_original','total_khr','notes']));
        ksort($header);
        $items = [];
        foreach ($lines as $line) { unset($line['id'], $line['invoice_id']); ksort($line); $items[] = $line; }
        return hash('sha256', json_encode([$header, $items], JSON_THROW_ON_ERROR));
    }

    private function withInvoice(int $invoiceId, callable $operation): mixed
    {
        $initial = $this->invoices->find($invoiceId);
        if (!$initial) { throw new DomainException('Supplier invoice was not found.'); }
        return $this->transaction(function () use ($invoiceId, $initial, $operation): mixed {
            $po = $this->orders->find((int) $initial['purchase_id'], true);
            $invoice = $this->invoices->find($invoiceId, true);
            if (!$po || !$invoice || (int) $invoice['purchase_id'] !== (int) $po['id']) { throw new DomainException('Invoice PO linkage changed.'); }
            return $operation($po, $invoice);
        });
    }

    private function permission(int $actorId, string $permission): void
    {
        if (!$this->users->findActiveById($actorId) || !$this->users->hasPermission($actorId, $permission)) { throw new DomainException('You do not have permission for this invoice action.'); }
    }
    private function owner(int $actorId, string $permission): void
    {
        $this->permission($actorId, $permission);
        if (!$this->users->isOwner($actorId)) { throw new DomainException('Only an Owner may access invoice prices, approvals, postings, and reconciliation.'); }
    }
    private function id(mixed $value): int
    {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) { throw new DomainException('A positive record identifier is required.'); }
        return (int) $value;
    }
    private function text(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) { throw new DomainException('A text or decimal value is required.'); }
        return trim((string) $value);
    }
    private function date(mixed $value): string
    {
        $raw = $this->text($value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$date || $date->format('Y-m-d') !== $raw) { throw new DomainException('Invoice dates must use valid YYYY-MM-DD dates.'); }
        return $raw;
    }
    private function notes(mixed $value): ?string
    {
        $value = $this->text($value);
        if ($this->length($value) > 1000) { throw new DomainException('Invoice notes may contain at most 1000 characters.'); }
        return $value === '' ? null : $value;
    }
    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($this->length($reason) < 3 || $this->length($reason) > 500) { throw new DomainException('An explicit Owner reason of 3-500 characters is required.'); }
        return $reason;
    }
    private function quantity(mixed $value, int $precision): string
    {
        $value = $this->text($value);
        if (str_contains($value, '.')) { $value = rtrim(rtrim($value, '0'), '.'); }
        return Decimal::positive(Decimal::positive($value, $precision), 3);
    }
    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) { throw new DomainException('Unsupported invoice fields. Staff may record metadata only; prices and invoice lines require an Owner.'); }
    }
    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value); }
    private const PUBLIC_FIELDS = ['id','supplier_invoice_no','supplier_id','purchase_id','invoice_date','due_date','status','created_by','created_at'];
    private function transaction(callable $operation, bool $readOnly = false): mixed
    {
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . ($readOnly ? 'REPEATABLE READ' : 'READ COMMITTED'));
        if ($readOnly) { $this->pdo->exec('SET TRANSACTION READ ONLY'); }
        $this->pdo->beginTransaction();
        try { $result = $operation(); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            if ($e instanceof \PDOException) { throw new DomainException('Supplier invoice conflicted with an existing invoice number or posting. No changes were committed.', 0, $e); }
            throw $e;
        }
    }
}
