<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\{Payable, User};
use App\Support\Decimal;
use DomainException;
use PDO;

final class AccountsPayableService
{
    public function __construct(private PDO $pdo, private User $users, private Payable $payables, private SupplierPayableBalanceService $balances) {}

    /** Internal composition contract: SupplierInvoiceService holds PO/invoice locks and owns commit. */
    public function recognizeApprovedInvoice(int $actorId, array $invoice): int
    {
        $this->owner($actorId, 'supplier_invoices.post');
        if (!$this->pdo->inTransaction() || $invoice['status'] !== 'approved') {
            throw new \LogicException('AP recognition requires a locked approved invoice in the caller transaction.');
        }
        if ($this->payables->hasLegacy((int) $invoice['purchase_id'])) {
            throw new DomainException('A legacy payable already exists for this PO. Reconcile it before recognizing invoice payables.');
        }
        Decimal::positive((string) $invoice['total_khr'], 4);
        return $this->payables->create($invoice, $actorId);
    }

    public function forSupplier(int $actorId, int $supplierId): array
    {
        $this->owner($actorId, 'supplier_invoices.reconcile');
        $read = function () use ($supplierId): array {
            $rows = [];
            $issues = [];
            $original = $paid = $outstandingTotal = $creditTotal = $recoverableTotal = $reversedTotal = '0.0000';
            $available = true;
            foreach ($this->payables->forSupplier($supplierId) as $row) {
                $balance = $row['supplier_invoice_id'] === null ? null : $this->balances->read($row);
                foreach ($balance['issues'] ?? [] as $issue) { $issues[] = 'Payable ' . $row['id'] . ': ' . $issue; }
                $verified = $balance === null ? '0.0000' : Decimal::subtract($balance['gross_paid_khr'],$balance['reversed_khr'],4);
                $settledOriginal = $balance === null ? '0.0000' : Decimal::subtract($balance['gross_paid_original'],$balance['reversed_original'],4);
                $outstanding = $balance === null ? $row['original_khr'] : $balance['outstanding_khr'];
                if ($row['supplier_invoice_id'] === null) { $issues[] = 'Legacy payable ' . $row['id'] . ' needs invoice attribution review.'; }
                elseif ($row['invoice_status'] !== 'posted' || (int) $row['supplier_id'] !== (int) $row['invoice_supplier_id']
                    || (int) $row['purchase_id'] !== (int) $row['invoice_purchase_id'] || $row['due_date'] !== $row['invoice_due_date']
                    || $row['original_khr'] !== $row['invoice_total_khr'] || $row['amount_original'] !== $row['invoice_total_original']
                    || $row['currency'] !== $row['invoice_currency'] || $row['exchange_rate_to_khr'] !== $row['invoice_exchange_rate']
                    || $row['original_khr'] !== $row['matched_invoice_total_khr']) {
                    $issues[] = 'Payable ' . $row['id'] . ' differs from its posted invoice or match ledger.';
                }
                $outstandingOriginal = $balance['outstanding_original'] ?? null;
                if ($outstandingOriginal !== null && str_starts_with($outstandingOriginal, '-')) { $issues[] = 'Payable ' . $row['id'] . ' is over-settled in invoice currency.'; }
                $rows[] = $row + ['verified_paid_khr' => $verified, 'outstanding_khr' => $outstanding,
                    'balance_available' => $balance['available'] ?? true,
                    'paid_original' => $settledOriginal, 'outstanding_original' => $outstandingOriginal,
                    'credited_original' => $balance['credited_original'] ?? '0.0000', 'credit_applied_khr' => $balance['credit_applied_khr'] ?? '0.0000',
                    'recoverable_original' => $balance['recoverable_original'] ?? '0.0000', 'recoverable_khr' => $balance['recoverable_khr'] ?? '0.0000',
                    'reversed_payment_khr' => $balance['reversed_khr'] ?? '0.0000', 'ap_rounding_adjustment_khr' => $balance['correction_rounding_khr'] ?? '0.0000'];
                $original = Decimal::add($original, (string) $row['original_khr'], 4);
                $paid = Decimal::add($paid, $verified, 4);
                if ($outstanding === null) { $available = false; } else { $outstandingTotal = Decimal::add($outstandingTotal,$outstanding,4); }
                $creditTotal = Decimal::add($creditTotal,$balance['credit_applied_khr'] ?? '0.0000',4);
                $recoverableTotal = Decimal::add($recoverableTotal,$balance['recoverable_khr'] ?? '0.0000',4);
                $reversedTotal = Decimal::add($reversedTotal,$balance['reversed_khr'] ?? '0.0000',4);
            }
            foreach ($this->payables->postedInvoicesWithoutPayables($supplierId) as $id) { $issues[] = 'Posted invoice ' . $id . ' has no payable.'; }
            return ['supplier_id' => $supplierId, 'payables' => $rows, 'original_khr' => $original,
                'balances_available' => $available, 'verified_paid_khr' => $available ? $paid : null, 'outstanding_khr' => $available ? $outstandingTotal : null,
                'credit_applied_khr' => $available ? $creditTotal : null, 'recoverable_khr' => $available ? $recoverableTotal : null, 'reversed_payment_khr' => $available ? $reversedTotal : null,
                'issues' => $issues, 'reconciled' => $issues === []];
        };
        if ($this->pdo->inTransaction()) { return $read(); }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('SET TRANSACTION READ ONLY');
        $this->pdo->beginTransaction();
        try { $result = $read(); $this->pdo->commit(); return $result; }
        catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
    }

    private function owner(int $actorId, string $permission): void
    {
        if (!$this->users->findActiveById($actorId) || !$this->users->isOwner($actorId) || !$this->users->hasPermission($actorId, $permission)) {
            throw new DomainException('Only an authorized Owner may access supplier payables.');
        }
    }
}
