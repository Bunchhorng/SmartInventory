<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class SaleReturn
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $returnId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, return_no, return_key, sale_id, credit_note_id, status, reason, requested_by, created_at,
                    approved_by, approved_at, confirmed_at, confirmed_by, financial_amount_khr
             FROM sale_returns WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$returnId]);
        $return = $statement->fetch();
        return is_array($return) ? $return : null;
    }

    /** @return array<string, mixed>|null */
    public function findByKey(string $returnKey, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, return_no, return_key, sale_id, credit_note_id, status, reason, requested_by, created_at,
                    approved_by, approved_at, confirmed_at, confirmed_by, financial_amount_khr
             FROM sale_returns WHERE return_key = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$returnKey]);
        $return = $statement->fetch();
        return is_array($return) ? $return : null;
    }

    public function create(string $number, string $key, int $saleId, int $creditNoteId, string $reason, int $requestedBy): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO sale_returns
             (return_no, return_key, sale_id, credit_note_id, status, reason, requested_by)
             VALUES (?, ?, ?, ?, 'draft', ?, ?)"
        );
        $statement->execute([$number, $key, $saleId, $creditNoteId, $reason, $requestedBy]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createItem(int $returnId, int $saleItemId, string $requestedQtyBase, string $condition): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sale_return_items
             (sale_return_id, sale_item_id, qty_base, approved_qty_base, condition_code, restock)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $statement->execute([$returnId, $saleItemId, $requestedQtyBase, $condition, $condition === 'resellable' ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function items(int $returnId, bool $forUpdate = false): array
    {
        $statement = $this->pdo->prepare(
            'SELECT sri.id, sri.sale_return_id, sri.sale_item_id, sri.qty_base AS requested_qty_base,
                    sri.approved_qty_base, sri.condition_code, sri.restock, sri.financial_amount_khr, sri.cost_basis_khr,
                    si.sale_id, si.product_unit_id, si.location_id, si.qty_sold, si.qty_base AS sale_qty_base,
                    si.unit_to_base_factor, si.line_revenue_khr, si.cogs_khr, si.cogs_unit_cost_khr,
                    pu.product_id, pu.unit_id, u.name AS unit_name, u.code AS unit_code, u.precision_digits
             FROM sale_return_items sri
             JOIN sale_items si ON si.id = sri.sale_item_id
             JOIN product_units pu ON pu.id = si.product_unit_id
             JOIN units u ON u.id = pu.unit_id
             WHERE sri.sale_return_id = ?
             ORDER BY pu.product_id, sri.sale_item_id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$returnId]);
        return $statement->fetchAll();
    }

    public function updateApprovedQuantity(int $returnItemId, string $approvedQtyBase): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sale_return_items SET approved_qty_base = ? WHERE id = ?'
        );
        $statement->execute([$approvedQtyBase, $returnItemId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Return item approval state was not updated.');
        }
    }

    public function markApproved(int $returnId, int $actorId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE sale_returns SET status = 'approved', approved_by = ?, approved_at = NOW()
             WHERE id = ? AND status = 'draft'"
        );
        $statement->execute([$actorId, $returnId]);
        return $statement->rowCount() === 1;
    }

    public function postItemAmounts(int $returnItemId, string $financialAmountKhr, string $costBasisKhr): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sale_return_items SET financial_amount_khr = ?, cost_basis_khr = ? WHERE id = ?'
        );
        $statement->execute([$financialAmountKhr, $costBasisKhr, $returnItemId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Return item posting values were not updated.');
        }
    }

    public function markConfirmed(int $returnId, int $actorId, string $financialAmountKhr): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE sale_returns
             SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW(), financial_amount_khr = ?
             WHERE id = ? AND status = 'approved'"
        );
        $statement->execute([$actorId, $financialAmountKhr, $returnId]);
        return $statement->rowCount() === 1;
    }

    /** @return array{qty_base: string, financial_amount_khr: string, cost_basis_khr: string} */
    public function confirmedTotalsForSaleItem(int $saleItemId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(sri.approved_qty_base), 0) AS qty_base,
                    COALESCE(SUM(sri.financial_amount_khr), 0) AS financial_amount_khr,
                    COALESCE(SUM(sri.cost_basis_khr), 0) AS cost_basis_khr
             FROM sale_return_items sri
             JOIN sale_returns sr ON sr.id = sri.sale_return_id
             WHERE sri.sale_item_id = ? AND sr.status = 'confirmed'"
        );
        $statement->execute([$saleItemId]);
        $totals = $statement->fetch();
        return [
            'qty_base' => is_array($totals) ? (string) $totals['qty_base'] : '0.000',
            'financial_amount_khr' => is_array($totals) ? (string) $totals['financial_amount_khr'] : '0.0000',
            'cost_basis_khr' => is_array($totals) ? (string) $totals['cost_basis_khr'] : '0.0000',
        ];
    }

    public function confirmedFinancialTotalForCreditNote(int $creditNoteId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(financial_amount_khr), 0) FROM sale_returns
             WHERE credit_note_id = ? AND status = 'confirmed'"
        );
        $statement->execute([$creditNoteId]);
        return (string) $statement->fetchColumn();
    }

    public function hasConfirmedForSale(int $saleId, bool $forUpdate = false): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM sale_returns WHERE sale_id = ? AND status = 'confirmed' LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$saleId]);
        return $statement->fetch() !== false;
    }

    public function hasConfirmedForCreditNote(int $creditNoteId, bool $forUpdate = false): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM sale_returns WHERE credit_note_id = ? AND status = 'confirmed' LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$creditNoteId]);
        return $statement->fetch() !== false;
    }

    public function createWriteoff(int $returnItemId, string $condition, string $amountKhr, string $reason, int $actorId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO sale_return_writeoffs (sale_return_item_id, condition_code, amount_khr, reason, recorded_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$returnItemId, $condition, $amountKhr, $reason, $actorId]);
    }

    public function createQuarantineHold(
        int $returnItemId,
        int $productId,
        int $originLocationId,
        string $quantityBase,
        string $costBasisKhr,
        int $actorId,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO sale_return_quarantine_holds
             (sale_return_item_id, product_id, origin_location_id, qty_base, cost_basis_khr, held_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$returnItemId, $productId, $originLocationId, $quantityBase, $costBasisKhr, $actorId]);
    }

    /** @return list<array<string, mixed>> */
    public function listReturns(bool $includeCost): array
    {
        $costColumns = $includeCost
            ? ', sri.cost_basis_khr, wo.amount_khr AS writeoff_khr, qh.status AS quarantine_status'
            : '';
        $statement = $this->pdo->query(
            'SELECT sr.id, sr.return_no, sr.return_key, sr.status, sr.reason, sr.financial_amount_khr,
                    sr.created_at, sr.approved_at, sr.confirmed_at, s.invoice_no, cn.credit_note_no,
                    requester.full_name AS requested_by_name, approver.full_name AS approved_by_name,
                    sri.id AS return_item_id, sri.sale_item_id, sri.qty_base AS requested_qty_base,
                    sri.approved_qty_base, sri.condition_code, si.qty_sold, u.code AS unit_code,
                    p.name AS product_name, p.sku'
                    . $costColumns .
             ' FROM sale_returns sr
               JOIN sales s ON s.id = sr.sale_id
               JOIN credit_notes cn ON cn.id = sr.credit_note_id
               JOIN users requester ON requester.id = sr.requested_by
               LEFT JOIN users approver ON approver.id = sr.approved_by
               JOIN sale_return_items sri ON sri.sale_return_id = sr.id
               JOIN sale_items si ON si.id = sri.sale_item_id
               JOIN product_units pu ON pu.id = si.product_unit_id
               JOIN products p ON p.id = pu.product_id
               JOIN units u ON u.id = pu.unit_id
               LEFT JOIN sale_return_writeoffs wo ON wo.sale_return_item_id = sri.id
               LEFT JOIN sale_return_quarantine_holds qh ON qh.sale_return_item_id = sri.id
               ORDER BY sr.created_at DESC, sr.id DESC, sri.id ASC
               LIMIT 300'
        );
        return $statement->fetchAll();
    }
}
