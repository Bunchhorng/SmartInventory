<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class SupplierInvoice
{
    public function __construct(private PDO $pdo) {}

    public function find(int $id, bool $lock = false): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_invoices WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function byKey(string $key): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_invoices WHERE invoice_key = ? FOR UPDATE');
        $q->execute([$key]);
        return $q->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $q = $this->pdo->prepare(
            'INSERT INTO supplier_invoices (invoice_key, request_hash, supplier_invoice_no, normalized_invoice_no,
             supplier_id, purchase_id, invoice_date, due_date, notes, currency, exchange_rate_to_khr, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $q->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function updateDraft(int $id, string $date, string $due, ?string $notes): void
    {
        $q = $this->pdo->prepare("UPDATE supplier_invoices SET invoice_date = ?, due_date = ?, notes = ? WHERE id = ? AND status = 'draft'");
        $q->execute([$date, $due, $notes, $id]);
    }

    public function replaceItems(int $id, array $lines): void
    {
        $this->pdo->prepare('DELETE FROM supplier_invoice_items WHERE invoice_id = ?')->execute([$id]);
        $q = $this->pdo->prepare(
            'INSERT INTO supplier_invoice_items (invoice_id, goods_receipt_item_id, purchase_item_id, product_unit_id,
             quantity, qty_base, unit_code_snapshot, conversion_snapshot, unit_purchase_price, discount_percent,
             po_unit_price_snapshot, po_discount_snapshot, receipt_cost_snapshot_khr,
             line_subtotal, line_discount, line_total, line_total_khr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) { $q->execute([$id, ...array_values($line)]); }
    }

    public function setTotals(int $id, ?string $original, ?string $khr): void
    {
        $q = $this->pdo->prepare('UPDATE supplier_invoices SET total_original = ?, total_khr = ? WHERE id = ?');
        $q->execute([$original, $khr, $id]);
    }

    public function items(int $id, bool $lock = false): array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_invoice_items WHERE invoice_id = ? ORDER BY goods_receipt_item_id' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]);
        return $q->fetchAll();
    }

    public function list(int $supplierId, int $page, int $limit): array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_invoices WHERE supplier_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $q->bindValue(1, $supplierId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->bindValue(3, ($page - 1) * $limit, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll();
    }

    public function forPurchase(int $purchaseId): array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_invoices WHERE purchase_id = ? ORDER BY id');
        $q->execute([$purchaseId]);
        return $q->fetchAll();
    }

    public function transition(int $id, string $from, string $to, int $actorId, ?string $reason = null): void
    {
        $prefix = match ($to) {
            'submitted' => 'submitted', 'approved' => 'approved', 'posted' => 'posted',
            'rejected' => 'rejected', 'cancelled' => 'cancelled',
            default => throw new \LogicException('Unsupported invoice transition.'),
        };
        $sql = "UPDATE supplier_invoices SET status = ?, {$prefix}_by = ?, {$prefix}_at = NOW()";
        $params = [$to, $actorId];
        if (in_array($to, ['rejected','cancelled'], true)) {
            $sql .= ', ' . ($to === 'rejected' ? 'rejection_reason' : 'cancellation_reason') . ' = ?';
            $params[] = $reason;
        }
        $q = $this->pdo->prepare($sql . ' WHERE id = ? AND status = ?');
        $q->execute([...$params, $id, $from]);
        if ($q->rowCount() !== 1) { throw new \RuntimeException('Invoice state changed.'); }
    }

    public function approval(int $id): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM supplier_invoice_approvals WHERE invoice_id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function approve(int $id, string $hash, string $original, string $khr, bool $variance, ?string $reason, int $actorId): void
    {
        $q = $this->pdo->prepare('INSERT INTO supplier_invoice_approvals (invoice_id, content_hash, total_original, total_khr, variance_required, variance_reason, approved_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $q->execute([$id, $hash, $original, $khr, (int) $variance, $reason, $actorId]);
    }

    /** Purchase and receipt snapshots plus immutable posting evidence. */
    public function receivedLines(int $purchaseId, bool $lock = false): array
    {
        $q = $this->pdo->prepare(
            "SELECT gi.id, gi.receipt_id, gi.purchase_item_id, gi.received_qty, gi.qty_base,
                    gi.conversion_snapshot, gi.approved_cost_per_base_khr, gi.landed_unit_cost_khr,
                    gr.supplier_id, gr.currency_snapshot, gr.exchange_rate_snapshot, gr.location_id,
                    pi.purchase_id, pi.product_unit_id, pi.ordered_qty, pi.received_qty AS po_received_qty,
                    pi.unit_purchase_price, pi.discount_percent,
                    pi.unit_to_base_factor_snapshot, pi.qty_base_snapshot, pi.unit_precision_snapshot, pi.unit_code_snapshot,
                    pu.product_id, ir.status AS inventory_status, ir.location_id AS inventory_location,
                    ii.product_unit_id AS inventory_unit, ii.qty_received AS inventory_qty, ii.qty_base AS inventory_base,
                    ii.cost_per_base_khr AS inventory_cost,
                    sm.id AS movement_id, sm.product_id AS movement_product, sm.location_id AS movement_location,
                    sm.qty_delta AS movement_qty, sm.unit_cost_khr AS movement_cost,
                    ce.id AS cost_entry_id, ce.qty_delta AS cost_qty, ce.value_delta_khr AS receipt_value_khr,
                    ce.unit_cost_snapshot_khr AS valuation_cost, ce.product_id AS cost_product,
                    ce.source_type AS cost_source_type, ce.source_id AS cost_source_id,
                    ce.source_line_id AS cost_source_line, ir.id AS inventory_id, ii.id AS inventory_item_id,
                    v.actual_cost_per_base_khr AS variance_cost, v.approved_cost_per_base_khr AS variance_baseline
             FROM goods_receipt_items gi JOIN goods_receipts gr ON gr.id = gi.receipt_id
             JOIN purchase_items pi ON pi.id = gi.purchase_item_id
             JOIN product_units pu ON pu.id = pi.product_unit_id
             LEFT JOIN inventory_receipts ir ON ir.id = gr.inventory_receipt_id
             LEFT JOIN inventory_receipt_items ii ON ii.id = gi.inventory_receipt_item_id AND ii.receipt_id = ir.id
             LEFT JOIN stock_movements sm ON sm.source_type = 'inventory_receipt'
                AND sm.source_id = ir.id AND sm.source_line_id = ii.id
             LEFT JOIN inventory_cost_entries ce ON ce.stock_movement_id = sm.id
             LEFT JOIN goods_receipt_cost_variances v ON v.goods_receipt_item_id = gi.id
             WHERE gr.purchase_id = ? AND gr.status = 'confirmed' ORDER BY gi.id" . ($lock ? ' FOR UPDATE' : '')
        );
        $q->execute([$purchaseId]);
        return $q->fetchAll();
    }

    public function usage(int $purchaseId): array
    {
        $q = $this->pdo->prepare(
            'SELECT m.goods_receipt_item_id, SUM(m.quantity) AS quantity, SUM(m.qty_base) AS qty_base,
                    SUM(m.receipt_value_khr) AS receipt_value_khr
             FROM supplier_invoice_matches m JOIN supplier_invoices i ON i.id = m.invoice_id
             WHERE i.purchase_id = ? GROUP BY m.goods_receipt_item_id'
        );
        $q->execute([$purchaseId]);
        return array_column($q->fetchAll(), null, 'goods_receipt_item_id');
    }

    public function matches(int $invoiceId): array
    {
        $q = $this->pdo->prepare(
            'SELECT m.*, v.amount_khr AS variance_khr, v.variance_type, v.approval_id
             FROM supplier_invoice_matches m LEFT JOIN supplier_invoice_price_variances v ON v.match_id = m.id
             WHERE m.invoice_id = ? ORDER BY m.goods_receipt_item_id'
        );
        $q->execute([$invoiceId]);
        return $q->fetchAll();
    }

    public function purchaseMatches(int $purchaseId): array
    {
        $q = $this->pdo->prepare('SELECT m.* FROM supplier_invoice_matches m JOIN supplier_invoices i ON i.id = m.invoice_id WHERE i.purchase_id = ? ORDER BY m.id');
        $q->execute([$purchaseId]);
        return $q->fetchAll();
    }

    public function createMatch(array $data, string $variance, string $type, int $approvalId): void
    {
        $q = $this->pdo->prepare('INSERT INTO supplier_invoice_matches (invoice_item_id, invoice_id, payable_id,
            goods_receipt_item_id, purchase_item_id, quantity, qty_base, receipt_value_khr, invoice_value_khr, posted_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $q->execute(array_values($data));
        $matchId = (int) $this->pdo->lastInsertId();
        if ($variance !== '0.0000') {
            $this->pdo->prepare('INSERT INTO supplier_invoice_price_variances (match_id, amount_khr, variance_type, approval_id) VALUES (?, ?, ?, ?)')
                ->execute([$matchId, $variance, $type, $approvalId]);
        }
    }
}
