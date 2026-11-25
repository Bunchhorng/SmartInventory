<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/** Invoice liabilities and immutable supplier settlement history. */
final class Payable
{
    public function __construct(private PDO $pdo) {}

    public function hasLegacy(int $purchaseId): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM payables WHERE purchase_id = ? AND supplier_invoice_id IS NULL LIMIT 1');
        $q->execute([$purchaseId]);
        return (bool) $q->fetchColumn();
    }

    public function forInvoice(int $invoiceId, bool $lock = false): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM payables WHERE supplier_invoice_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$invoiceId]);
        return $q->fetch() ?: null;
    }

    public function create(array $invoice, int $actorId): int
    {
        $q = $this->pdo->prepare('INSERT INTO payables (purchase_id, supplier_id, due_date, original_khr,
            supplier_invoice_id, posting_key, currency, amount_original, exchange_rate_to_khr, posted_by, posted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $q->execute([$invoice['purchase_id'], $invoice['supplier_id'], $invoice['due_date'], $invoice['total_khr'],
            $invoice['id'], 'supplier-invoice:' . $invoice['id'], $invoice['currency'], $invoice['total_original'],
            $invoice['exchange_rate_to_khr'], $actorId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function forSupplier(int $supplierId): array
    {
        $q = $this->pdo->prepare(
            'SELECT p.*, i.status AS invoice_status, i.supplier_id AS invoice_supplier_id,
                    i.purchase_id AS invoice_purchase_id, i.due_date AS invoice_due_date,
                    i.total_khr AS invoice_total_khr, i.total_original AS invoice_total_original,
                    i.currency AS invoice_currency, i.exchange_rate_to_khr AS invoice_exchange_rate,
                    (SELECT COALESCE(SUM(m.invoice_value_khr), 0) FROM supplier_invoice_matches m WHERE m.invoice_id = i.id) AS matched_invoice_total_khr
             FROM payables p LEFT JOIN supplier_invoices i ON i.id = p.supplier_invoice_id
             WHERE p.supplier_id = ? ORDER BY p.id'
        );
        $q->execute([$supplierId]);
        return $q->fetchAll();
    }

    public function postedInvoicesWithoutPayables(int $supplierId): array
    {
        $q = $this->pdo->prepare("SELECT i.id FROM supplier_invoices i LEFT JOIN payables p ON p.supplier_invoice_id = i.id
            WHERE i.supplier_id = ? AND i.status = 'posted' AND p.id IS NULL ORDER BY i.id");
        $q->execute([$supplierId]);
        return $q->fetchAll(PDO::FETCH_COLUMN);
    }

    public function allocations(int $payableId): array
    {
        $q = $this->pdo->prepare('SELECT a.*, p.direction, p.verification_status, p.customer_id, p.supplier_id AS payment_supplier_id,
             p.payment_purpose, d.status AS document_status, d.supplier_id AS document_supplier_id,
             d.payment_id AS document_payment_id, v.fx_difference_khr, v.rounding_difference_khr
             FROM payable_allocations a JOIN payments p ON p.id = a.payment_id
             LEFT JOIN supplier_payments d ON d.id = a.supplier_payment_id
             LEFT JOIN supplier_payment_differences v ON v.allocation_id = a.id WHERE a.payable_id = ? ORDER BY a.id');
        $q->execute([$payableId]);
        return $q->fetchAll();
    }
}
