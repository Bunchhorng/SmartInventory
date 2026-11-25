<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class PurchaseOrder
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $purchaseId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.purchase_no, p.purchase_key, p.supplier_id, p.status, p.currency,
                    p.exchange_rate_to_khr, p.ordered_at, p.expected_delivery_date, p.notes,
                    p.estimated_total, p.created_by, p.created_at, p.updated_at, p.submitted_by,
                    p.submitted_at, p.approved_by, p.approved_at, p.rejected_by, p.rejected_at,
                    p.rejection_reason, p.cancelled_by, p.cancelled_at, p.cancellation_reason,
                    s.supplier_code, s.name AS supplier_name, s.status AS supplier_status
             FROM purchases p
             JOIN suppliers s ON s.id = p.supplier_id
             WHERE p.id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$purchaseId]);
        $purchase = $statement->fetch();
        return is_array($purchase) ? $purchase : null;
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $purchaseKey, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, purchase_no, purchase_key, status, created_by FROM purchases
             WHERE purchase_key = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$purchaseKey]);
        $purchase = $statement->fetch();
        return is_array($purchase) ? $purchase : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $search, string $status, int $limit, int $offset): array
    {
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(p.purchase_no LIKE ? OR p.purchase_key LIKE ? OR s.supplier_code LIKE ? OR s.name LIKE ?)';
            $like = '%' . $search . '%';
            $parameters = [$like, $like, $like, $like];
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'p.status = ?';
            $parameters[] = $status;
        }
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.purchase_no, p.purchase_key, p.supplier_id, p.status, p.currency, p.exchange_rate_to_khr,
                    p.expected_delivery_date, p.notes, p.estimated_total, p.created_at, p.updated_at,
                    p.submitted_at, p.approved_at, p.rejected_at, p.cancelled_at, p.rejection_reason,
                    p.cancellation_reason, s.supplier_code, s.name AS supplier_name,
                    creator.full_name AS created_by_name, submitter.full_name AS submitted_by_name,
                    approver.full_name AS approved_by_name
             FROM purchases p
             JOIN suppliers s ON s.id = p.supplier_id
             JOIN users creator ON creator.id = p.created_by
             LEFT JOIN users submitter ON submitter.id = p.submitted_by
             LEFT JOIN users approver ON approver.id = p.approved_by'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?'
        );
        foreach ($parameters as $index => $value) {
            $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(count($parameters) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($parameters) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function count(string $search, string $status): int
    {
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(p.purchase_no LIKE ? OR p.purchase_key LIKE ? OR s.supplier_code LIKE ? OR s.name LIKE ?)';
            $like = '%' . $search . '%';
            $parameters = [$like, $like, $like, $like];
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'p.status = ?';
            $parameters[] = $status;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM purchases p JOIN suppliers s ON s.id = p.supplier_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    public function create(
        string $purchaseNo,
        string $purchaseKey,
        int $supplierId,
        string $currency,
        string $exchangeRateToKhr,
        ?string $expectedDeliveryDate,
        ?string $notes,
        ?string $estimatedTotal,
        int $createdBy,
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO purchases
             (purchase_no, purchase_key, supplier_id, status, currency, exchange_rate_to_khr,
              expected_delivery_date, notes, estimated_total, created_by)
             VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?)"
        );
        $statement->execute([
            $purchaseNo,
            $purchaseKey,
            $supplierId,
            $currency,
            $exchangeRateToKhr,
            $expectedDeliveryDate,
            $notes,
            $estimatedTotal,
            $createdBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateDraft(
        int $purchaseId,
        int $supplierId,
        string $currency,
        string $exchangeRateToKhr,
        ?string $expectedDeliveryDate,
        ?string $notes,
        ?string $estimatedTotal,
    ): void {
        $statement = $this->pdo->prepare(
            "UPDATE purchases
             SET supplier_id = ?, currency = ?, exchange_rate_to_khr = ?, expected_delivery_date = ?,
                 notes = ?, estimated_total = ?
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([
            $supplierId,
            $currency,
            $exchangeRateToKhr,
            $expectedDeliveryDate,
            $notes,
            $estimatedTotal,
            $purchaseId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Purchase order status changed before its draft could be updated.');
        }
    }

    public function deleteItems(int $purchaseId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM purchase_items WHERE purchase_id = ?');
        $statement->execute([$purchaseId]);
    }

    public function createItem(
        int $purchaseId,
        int $productUnitId,
        string $quantity,
        ?string $unitPurchasePrice,
        string $discountPercent,
        string $skuSnapshot,
        string $productNameSnapshot,
        string $unitCodeSnapshot,
        string $unitNameSnapshot,
        string $factorSnapshot,
        string $quantityBaseSnapshot,
        ?string $lineSubtotal,
        ?string $lineDiscountAmount,
        ?string $lineTotal,
        ?int $unitPrecisionSnapshot = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO purchase_items
             (purchase_id, product_unit_id, ordered_qty, unit_purchase_price, discount_percent,
              product_sku_snapshot, product_name_snapshot, unit_code_snapshot, unit_name_snapshot,
              unit_to_base_factor_snapshot, qty_base_snapshot, line_subtotal, line_discount_amount, line_total,
              unit_precision_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $purchaseId,
            $productUnitId,
            $quantity,
            $unitPurchasePrice,
            $discountPercent,
            $skuSnapshot,
            $productNameSnapshot,
            $unitCodeSnapshot,
            $unitNameSnapshot,
            $factorSnapshot,
            $quantityBaseSnapshot,
            $lineSubtotal,
            $lineDiscountAmount,
            $lineTotal,
            $unitPrecisionSnapshot,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function items(int $purchaseId, bool $forUpdate = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pi.id, pi.purchase_id, pi.product_unit_id, pi.ordered_qty, pi.unit_purchase_price,
                    pi.discount_percent, pi.product_sku_snapshot, pi.product_name_snapshot,
                    pi.unit_code_snapshot, pi.unit_name_snapshot, pi.unit_to_base_factor_snapshot,
                    pi.qty_base_snapshot, pi.line_subtotal, pi.line_discount_amount, pi.line_total,
                    pi.received_qty, pi.unit_precision_snapshot,
                    pu.product_id, p.status AS product_status, p.track_batches, u.status AS unit_status
             FROM purchase_items pi
             JOIN product_units pu ON pu.id = pi.product_unit_id
             JOIN products p ON p.id = pu.product_id
             JOIN units u ON u.id = pu.unit_id
             WHERE pi.purchase_id = ?
             ORDER BY pu.product_id, pi.id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$purchaseId]);
        return $statement->fetchAll();
    }

    public function markSubmitted(int $purchaseId, int $actorId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE purchases SET status = 'submitted', submitted_by = ?, submitted_at = NOW()
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([$actorId, $purchaseId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Purchase order status changed before it could be submitted.');
        }
    }

    public function markApproved(int $purchaseId, int $actorId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE purchases SET status = 'approved', approved_by = ?, approved_at = NOW()
             WHERE id = ? AND status = 'submitted'"
        );
        $statement->execute([$actorId, $purchaseId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Purchase order status changed before it could be approved.');
        }
    }

    public function markRejected(int $purchaseId, int $actorId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE purchases
             SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ?
             WHERE id = ? AND status = 'submitted'"
        );
        $statement->execute([$actorId, $reason, $purchaseId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Purchase order status changed before it could be rejected.');
        }
    }

    public function markCancelled(int $purchaseId, int $actorId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE purchases
             SET status = 'cancelled', cancelled_by = ?, cancelled_at = NOW(), cancellation_reason = ?
             WHERE id = ?"
        );
        $statement->execute([$actorId, $reason, $purchaseId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Purchase order status changed before it could be cancelled.');
        }
    }

    public function createApprovalSnapshot(
        int $purchaseId,
        string $purchaseNo,
        string $supplierCode,
        string $supplierName,
        string $currency,
        string $exchangeRateToKhr,
        string $estimatedTotal,
        int $approvedBy,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO purchase_order_approval_snapshots
             (purchase_id, purchase_no_snapshot, supplier_code_snapshot, supplier_name_snapshot,
              currency_snapshot, exchange_rate_to_khr_snapshot, estimated_total_snapshot, approved_by,
              supplier_id_snapshot)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?, supplier_id FROM purchases WHERE id = ?'
        );
        $statement->execute([
            $purchaseId,
            $purchaseNo,
            $supplierCode,
            $supplierName,
            $currency,
            $exchangeRateToKhr,
            $estimatedTotal,
            $approvedBy,
            $purchaseId,
        ]);
    }

    public function hasReceivingOrPayableReference(int $purchaseId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM goods_receipts WHERE purchase_id = ?
             UNION ALL SELECT 1 FROM payables WHERE purchase_id = ?
             LIMIT 1'
        );
        $statement->execute([$purchaseId, $purchaseId]);
        return (bool) $statement->fetchColumn();
    }

    public function purchaseNoExists(string $purchaseNo): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM purchases WHERE purchase_no = ? LIMIT 1');
        $statement->execute([$purchaseNo]);
        return (bool) $statement->fetchColumn();
    }

    /** @var list<string> */
    private const STATUSES = [
        'draft', 'submitted', 'approved', 'partially_received', 'received', 'closed', 'rejected', 'cancelled',
    ];
}
