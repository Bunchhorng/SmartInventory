<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class GoodsReceipt
{
    public function __construct(private PDO $pdo) {}

    public function find(int $id, bool $lock = false): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM goods_receipts WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public function byKey(string $key): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM goods_receipts WHERE receipt_key = ? FOR UPDATE');
        $q->execute([$key]);
        return $q->fetch() ?: null;
    }

    public function approval(int $purchaseId): ?array
    {
        $q = $this->pdo->prepare('SELECT * FROM purchase_order_approval_snapshots WHERE purchase_id = ?');
        $q->execute([$purchaseId]);
        return $q->fetch() ?: null;
    }

    public function listForPurchase(int $purchaseId, int $limit, int $offset): array
    {
        $q = $this->pdo->prepare('SELECT * FROM goods_receipts WHERE purchase_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $q->bindValue(1, $purchaseId, PDO::PARAM_INT);
        $q->bindValue(2, $limit, PDO::PARAM_INT);
        $q->bindValue(3, $offset, PDO::PARAM_INT);
        $q->execute();
        return $q->fetchAll();
    }

    public function create(array $data): int
    {
        $q = $this->pdo->prepare(
            'INSERT INTO goods_receipts
             (receipt_no, purchase_id, location_id, receipt_key, request_hash, supplier_id, reference_no,
              delivery_notes, received_by, received_at, inventory_receipt_id, currency_snapshot, exchange_rate_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $q->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    public function createItem(int $receiptId, int $purchaseItemId, int $inventoryItemId, string $qty,
        string $qtyBase, string $factor, string $cost): void
    {
        $q = $this->pdo->prepare(
            'INSERT INTO goods_receipt_items
             (receipt_id, purchase_item_id, inventory_receipt_item_id, received_qty, qty_base,
              conversion_snapshot, approved_cost_per_base_khr, landed_unit_cost_khr)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $q->execute([$receiptId, $purchaseItemId, $inventoryItemId, $qty, $qtyBase, $factor, $cost, $cost]);
    }

    public function items(int $receiptId, bool $lock = false): array
    {
        $q = $this->pdo->prepare(
            'SELECT gi.*, v.id AS variance_id, v.actual_cost_per_base_khr AS variance_cost,
                    v.approved_cost_per_base_khr AS variance_baseline, v.approved_by AS variance_approved_by,
                    v.reason AS variance_reason
             FROM goods_receipt_items gi LEFT JOIN goods_receipt_cost_variances v ON v.goods_receipt_item_id = gi.id
             WHERE gi.receipt_id = ? ORDER BY gi.purchase_item_id' . ($lock ? ' FOR UPDATE' : '')
        );
        $q->execute([$receiptId]);
        return $q->fetchAll();
    }

    public function confirmedTotals(int $purchaseId): array
    {
        $q = $this->pdo->prepare(
            "SELECT gi.purchase_item_id, SUM(gi.received_qty) AS received_qty, SUM(gi.qty_base) AS qty_base
             FROM goods_receipt_items gi JOIN goods_receipts gr ON gr.id = gi.receipt_id
             WHERE gr.purchase_id = ? AND gr.status = 'confirmed' GROUP BY gi.purchase_item_id"
        );
        $q->execute([$purchaseId]);
        $totals = [];
        foreach ($q->fetchAll() as $row) {
            $totals[(int) $row['purchase_item_id']] = $row;
        }
        return $totals;
    }

    public function hasUnresolvedReceipts(int $purchaseId): bool
    {
        $q = $this->pdo->prepare(
            "SELECT 1 FROM goods_receipts WHERE purchase_id = ?
             AND (status = 'draft' OR (status <> 'cancelled' AND inventory_receipt_id IS NULL)) LIMIT 1"
        );
        $q->execute([$purchaseId]);
        return (bool) $q->fetchColumn();
    }

    public function hasLegacyReceipts(int $purchaseId): bool
    {
        $q = $this->pdo->prepare("SELECT 1 FROM goods_receipts WHERE purchase_id = ? AND receipt_key IS NULL AND status <> 'cancelled' LIMIT 1");
        $q->execute([$purchaseId]);
        return (bool) $q->fetchColumn();
    }

    public function approveVariance(int $itemId, string $baseline, string $actual, int $actorId, string $reason): void
    {
        $q = $this->pdo->prepare(
            'INSERT INTO goods_receipt_cost_variances
             (goods_receipt_item_id, approved_cost_per_base_khr, actual_cost_per_base_khr, approved_by, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $q->execute([$itemId, $baseline, $actual, $actorId, $reason]);
        $q = $this->pdo->prepare('UPDATE goods_receipt_items SET landed_unit_cost_khr = ? WHERE id = ?');
        $q->execute([$actual, $itemId]);
    }

    public function markConfirmed(int $receiptId, int $actorId): void
    {
        $q = $this->pdo->prepare("UPDATE goods_receipts SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ? AND status = 'draft'");
        $q->execute([$actorId, $receiptId]);
        if ($q->rowCount() !== 1) { throw new \RuntimeException('Receipt state changed.'); }
    }

    public function markCancelled(int $receiptId, int $inventoryId, int $actorId, string $reason): void
    {
        $q = $this->pdo->prepare("UPDATE goods_receipts SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ? WHERE id = ? AND status = 'draft'");
        $q->execute([$actorId, $reason, $receiptId]);
        if ($q->rowCount() !== 1) { throw new \RuntimeException('Receipt state changed.'); }
        $q = $this->pdo->prepare("UPDATE inventory_receipts SET status = 'cancelled' WHERE id = ? AND status = 'draft'");
        $q->execute([$inventoryId]);
        if ($q->rowCount() !== 1) { throw new \RuntimeException('Inventory receipt state changed.'); }
    }

    public function setReceived(int $purchaseItemId, string $qty): void
    {
        $q = $this->pdo->prepare('UPDATE purchase_items SET received_qty = ? WHERE id = ?');
        $q->execute([$qty, $purchaseItemId]);
    }

    public function setPurchaseStatus(int $purchaseId, string $status): void
    {
        $q = $this->pdo->prepare('UPDATE purchases SET status = ? WHERE id = ?');
        $q->execute([$status, $purchaseId]);
    }

    public function close(int $purchaseId, int $actorId): void
    {
        $q = $this->pdo->prepare("UPDATE purchases SET status = 'closed', closed_by = ?, closed_at = NOW() WHERE id = ? AND status = 'received'");
        $q->execute([$actorId, $purchaseId]);
        if ($q->rowCount() !== 1) { throw new \RuntimeException('Purchase order state changed.'); }
    }

    /** Every receipt line and its linked inventory document/movement/valuation evidence. Read-only. */
    public function evidence(int $purchaseId): array
    {
        $q = $this->pdo->prepare(
            'SELECT gr.id AS goods_receipt_id, gr.status AS goods_status, gr.supplier_id,
                    gr.currency_snapshot, gr.exchange_rate_snapshot, gr.location_id,
                    gi.id AS goods_item_id, gi.purchase_item_id, gi.received_qty, gi.qty_base,
                    gi.conversion_snapshot, gi.approved_cost_per_base_khr, gi.landed_unit_cost_khr,
                    ir.id AS inventory_id, ir.status AS inventory_status, ir.location_id AS inventory_location,
                    ir.receipt_type AS inventory_type, gr.confirmed_by, gr.confirmed_at,
                    ir.confirmed_by AS inventory_confirmed_by, ir.confirmed_at AS inventory_confirmed_at,
                    ii.id AS inventory_item_id, ii.product_unit_id, ii.qty_received AS inventory_qty,
                    ii.qty_base AS inventory_base, ii.cost_per_base_khr,
                    sm.id AS movement_id, sm.product_id AS movement_product, sm.location_id AS movement_location,
                    sm.qty_delta AS movement_qty, sm.unit_cost_khr AS movement_cost,
                    ce.id AS cost_entry_id, ce.product_id AS cost_product, ce.qty_delta AS cost_qty,
                    ce.value_delta_khr AS cost_value, ce.unit_cost_snapshot_khr AS cost_unit,
                    ce.source_type AS cost_source_type, ce.source_id AS cost_source_id,
                    ce.source_line_id AS cost_source_line_id, ce.costing_type,
                    v.actual_cost_per_base_khr AS variance_cost
             FROM goods_receipts gr LEFT JOIN goods_receipt_items gi ON gi.receipt_id = gr.id
             LEFT JOIN inventory_receipts ir ON ir.id = gr.inventory_receipt_id
             LEFT JOIN inventory_receipt_items ii ON ii.id = gi.inventory_receipt_item_id
             LEFT JOIN stock_movements sm ON sm.source_type = \'inventory_receipt\'
                    AND sm.source_id = ir.id AND sm.source_line_id = ii.id
             LEFT JOIN inventory_cost_entries ce ON ce.stock_movement_id = sm.id
             LEFT JOIN goods_receipt_cost_variances v ON v.goods_receipt_item_id = gi.id
             WHERE gr.purchase_id = ? ORDER BY gr.id, gi.id, sm.id'
        );
        $q->execute([$purchaseId]);
        return $q->fetchAll();
    }

    public function hasUnlinkedInventoryRows(int $purchaseId): bool
    {
        $q = $this->pdo->prepare(
            'SELECT 1 FROM goods_receipts gr JOIN inventory_receipt_items ii ON ii.receipt_id = gr.inventory_receipt_id
             LEFT JOIN goods_receipt_items gi ON gi.inventory_receipt_item_id = ii.id AND gi.receipt_id = gr.id
             WHERE gr.purchase_id = ? AND gi.id IS NULL
             UNION ALL
             SELECT 1 FROM goods_receipts gr JOIN stock_movements sm ON sm.source_id = gr.inventory_receipt_id
                AND sm.source_type IN (\'inventory_receipt\',\'inventory_receipt_reversal\')
             LEFT JOIN goods_receipt_items gi ON gi.receipt_id = gr.id AND gi.inventory_receipt_item_id = sm.source_line_id
             WHERE gr.purchase_id = ? AND (gi.id IS NULL OR sm.source_type = \'inventory_receipt_reversal\')
             LIMIT 1'
        );
        $q->execute([$purchaseId, $purchaseId]);
        return (bool) $q->fetchColumn();
    }
}
