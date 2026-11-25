<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Sale
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $saleId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, invoice_no, customer_id, sale_channel, settlement_type, credit_limit_override,
                    credit_override_reason, credit_override_approved_by, credit_override_approved_at,
                    status, currency, exchange_rate_to_khr,
                    subtotal_khr, discount_khr, total_khr, confirmed_at, confirmed_by,
                    voided_at, voided_by, void_reason, void_restock_confirmed, created_by, created_at
             FROM sales WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$saleId]);
        $sale = $statement->fetch();
        return is_array($sale) ? $sale : null;
    }

    public function create(
        string $invoiceNo,
        ?int $customerId,
        string $saleChannel,
        string $settlementType,
        bool $creditLimitOverride,
        ?string $creditOverrideReason,
        ?int $creditOverrideApprovedBy,
        string $subtotalKhr,
        string $discountKhr,
        string $totalKhr,
        int $createdBy,
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO sales
             (invoice_no, customer_id, sale_channel, settlement_type, credit_limit_override,
              credit_override_reason, credit_override_approved_by, credit_override_approved_at,
              status, currency, exchange_rate_to_khr, subtotal_khr, discount_khr, total_khr, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'KHR', 1, ?, ?, ?, ?)"
        );
        $statement->execute([
            $invoiceNo,
            $customerId,
            $saleChannel,
            $settlementType,
            (int) $creditLimitOverride,
            $creditOverrideReason,
            $creditOverrideApprovedBy,
            $creditLimitOverride ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            $subtotalKhr,
            $discountKhr,
            $totalKhr,
            $createdBy,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createItem(
        int $saleId,
        int $productUnitId,
        string $priceType,
        int $locationId,
        string $quantitySold,
        string $quantityBase,
        string $unitToBaseFactor,
        string $unitPriceKhr,
        bool $isPriceOverride,
        string $discountKhr,
        string $lineRevenueKhr,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO sale_items
             (sale_id, product_unit_id, price_type, location_id, qty_sold, qty_base, unit_to_base_factor,
              unit_price_khr, is_price_override, discount_khr, line_revenue_khr, cogs_khr, cogs_unit_cost_khr)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)'
        );
        $statement->execute([
            $saleId,
            $productUnitId,
            $priceType,
            $locationId,
            $quantitySold,
            $quantityBase,
            $unitToBaseFactor,
            $unitPriceKhr,
            (int) $isPriceOverride,
            $discountKhr,
            $lineRevenueKhr,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function items(int $saleId, bool $forUpdate = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT si.id, si.sale_id, si.product_unit_id, si.price_type, si.location_id,
                    si.qty_sold, si.qty_base, si.unit_to_base_factor, si.unit_price_khr, si.is_price_override,
                    si.discount_khr, si.line_revenue_khr, si.cogs_khr, si.cogs_unit_cost_khr,
                    pu.product_id, pu.unit_id, pu.to_base_factor, p.status AS product_status, p.track_batches,
                    u.precision_digits, u.status AS unit_status, sl.is_active AS location_active
             FROM sale_items si
             JOIN product_units pu ON pu.id = si.product_unit_id
             JOIN products p ON p.id = pu.product_id
             JOIN units u ON u.id = pu.unit_id
             JOIN stock_locations sl ON sl.id = si.location_id
             WHERE si.sale_id = ?
             ORDER BY pu.product_id, si.id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$saleId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function activeLocation(int $locationId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name, type, is_active FROM stock_locations
             WHERE id = ? AND is_active = 1 LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$locationId]);
        $location = $statement->fetch();
        return is_array($location) ? $location : null;
    }

    /** @return array<string, mixed>|null */
    public function productUnit(int $productUnitId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pu.id, pu.product_id, pu.unit_id, pu.to_base_factor, p.status AS product_status,
                    p.track_batches, u.precision_digits, u.status AS unit_status
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id
             JOIN units u ON u.id = pu.unit_id
             WHERE pu.id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$productUnitId]);
        $productUnit = $statement->fetch();
        return is_array($productUnit) ? $productUnit : null;
    }

    /** @return array<string, mixed>|null */
    public function currentPrice(int $productUnitId, string $priceType, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, amount FROM product_prices
             WHERE product_unit_id = ? AND price_type = ? AND currency = 'KHR' AND is_current = 1
             LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$productUnitId, $priceType]);
        $price = $statement->fetch();
        return is_array($price) ? $price : null;
    }

    /** @return list<array{id: int, code: string, name: string, type: string}> */
    public function activeLocations(): array
    {
        return $this->pdo->query(
            'SELECT id, code, name, type FROM stock_locations WHERE is_active = 1 ORDER BY name, id'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function activeProductUnits(): array
    {
        return $this->pdo->query(
            "SELECT pu.id, p.name AS product_name, p.sku, u.name AS unit_name, u.code AS unit_code
             FROM product_units pu
             JOIN products p ON p.id = pu.product_id AND p.status = 'active' AND p.track_batches = 0
             JOIN units u ON u.id = pu.unit_id AND u.status = 'active'
             ORDER BY p.name, u.name, pu.id"
        )->fetchAll();
    }

    public function updateItemCogs(int $saleItemId, string $cogsKhr, string $cogsUnitCostKhr): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sale_items SET cogs_khr = ?, cogs_unit_cost_khr = ? WHERE id = ?'
        );
        $statement->execute([$cogsKhr, $cogsUnitCostKhr, $saleItemId]);
    }

    public function markConfirmed(int $saleId, int $actorId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE sales SET status = 'confirmed', confirmed_at = NOW(), confirmed_by = ?
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([$actorId, $saleId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Sale status changed before it could be confirmed.');
        }
    }

    public function markVoided(int $saleId, int $actorId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE sales
             SET status = 'voided', voided_at = NOW(), voided_by = ?, void_reason = ?, void_restock_confirmed = 1
             WHERE id = ? AND status = 'confirmed'"
        );
        $statement->execute([$actorId, $reason, $saleId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Sale status changed before it could be voided.');
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(bool $includeCost, int $limit, int $offset): array
    {
        $costColumns = $includeCost
            ? ', COALESCE((SELECT SUM(cost_items.cogs_khr) FROM sale_items cost_items WHERE cost_items.sale_id = s.id), 0) AS cogs_khr,
                 s.total_khr - COALESCE((SELECT SUM(profit_items.cogs_khr) FROM sale_items profit_items WHERE profit_items.sale_id = s.id), 0) AS gross_profit_khr'
            : '';
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.invoice_no, s.sale_channel, s.settlement_type, s.status, s.subtotal_khr, s.discount_khr, s.total_khr,
                    s.created_at, s.confirmed_at, r.due_date, c.name AS customer_name,
                    CASE
                        WHEN r.id IS NULL THEN \'not_established\'
                        WHEN r.voided_at IS NOT NULL THEN \'voided\'
                        WHEN COALESCE((SELECT SUM(ra.amount_khr) FROM receivable_allocations ra JOIN payments p ON p.id = ra.payment_id
                                       WHERE ra.receivable_id = r.id AND ra.reversed_at IS NULL
                                         AND p.direction = \'in\' AND p.verification_status = \'verified\'), 0)
                             + COALESCE((SELECT SUM(cnra.amount_khr) FROM credit_note_receivable_applications cnra
                                         JOIN credit_notes cn ON cn.id = cnra.credit_note_id
                                         WHERE cnra.receivable_id = r.id AND cn.status = \'posted\'), 0) >= r.original_khr THEN \'paid\'
                        WHEN COALESCE((SELECT SUM(ra2.amount_khr) FROM receivable_allocations ra2 JOIN payments p2 ON p2.id = ra2.payment_id
                                       WHERE ra2.receivable_id = r.id AND ra2.reversed_at IS NULL
                                         AND p2.direction = \'in\' AND p2.verification_status = \'verified\'), 0)
                             + COALESCE((SELECT SUM(cnra2.amount_khr) FROM credit_note_receivable_applications cnra2
                                         JOIN credit_notes cn2 ON cn2.id = cnra2.credit_note_id
                                         WHERE cnra2.receivable_id = r.id AND cn2.status = \'posted\'), 0) > 0 THEN \'partial\'
                        WHEN r.due_date IS NOT NULL AND r.due_date < CURDATE() THEN \'overdue\'
                        ELSE \'unpaid\'
                    END AS payment_status,
                    creator.full_name AS created_by_name,
                    confirmer.full_name AS confirmed_by_name'
                    . $costColumns .
             ' FROM sales s
               JOIN users creator ON creator.id = s.created_by
               LEFT JOIN users confirmer ON confirmer.id = s.confirmed_by
               LEFT JOIN receivables r ON r.sale_id = s.id
               LEFT JOIN customers c ON c.id = s.customer_id
               ORDER BY s.created_at DESC, s.id DESC LIMIT ? OFFSET ?'
        );
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->bindValue(2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** @return array{revenue: string, cogs: string} */
    public function confirmedTotals(): array
    {
        $statement = $this->pdo->query(
            "SELECT
                (SELECT COALESCE(SUM(confirmed_sales.total_khr), 0)
                 FROM sales confirmed_sales WHERE confirmed_sales.status = 'confirmed')
                 - (SELECT COALESCE(SUM(cn.amount_khr), 0) FROM credit_notes cn WHERE cn.status = 'posted') AS revenue,
                (SELECT COALESCE(SUM(si.cogs_khr), 0)
                 FROM sale_items si JOIN sales confirmed_sales ON confirmed_sales.id = si.sale_id
                 WHERE confirmed_sales.status = 'confirmed')
                 - (SELECT COALESCE(SUM(sri.cost_basis_khr), 0)
                    FROM sale_return_items sri
                    JOIN sale_returns sr ON sr.id = sri.sale_return_id
                    WHERE sr.status = 'confirmed' AND sri.condition_code = 'resellable') AS cogs"
        );
        $totals = $statement->fetch();
        return [
            'revenue' => is_array($totals) ? (string) $totals['revenue'] : '0.0000',
            'cogs' => is_array($totals) ? (string) $totals['cogs'] : '0.0000',
        ];
    }
}
