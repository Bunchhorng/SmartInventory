<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Read-only report for deciding whether a separately approved historical
 * valuation backfill is needed. It intentionally performs no repair or write.
 */
final class ValuationPreflightReport
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, int> */
    public function report(): array
    {
        $tables = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ('inventory_receipts', 'inventory_cost_entries', 'product_costs')"
        )->fetchAll(PDO::FETCH_COLUMN);
        $hasReceiptTables = in_array('inventory_receipts', $tables, true);
        $hasCostTables = in_array('inventory_cost_entries', $tables, true)
            && in_array('product_costs', $tables, true);

        $columns = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'inventory_cost_entries'
               AND column_name = 'stock_movement_id'"
        )->fetchColumn();

        $movementSummary = $this->pdo->query(
            "SELECT COUNT(*) AS receipt_movements,
                    COALESCE(SUM(ice.id IS NULL), 0) AS movements_without_valuation
             FROM stock_movements sm
             LEFT JOIN inventory_cost_entries ice
               ON ice.product_id = sm.product_id
              AND ice.source_type = sm.source_type
              AND ice.source_id = sm.source_id
              AND ice.source_line_id = sm.source_line_id
             WHERE sm.source_type IN ('inventory_receipt', 'inventory_receipt_reversal')"
        )->fetch();
        $mismatch = $this->pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT p.id
                FROM products p
                LEFT JOIN (
                    SELECT product_id, SUM(qty_base) AS physical_qty
                    FROM stock_balances WHERE batch_id IS NULL GROUP BY product_id
                ) b ON b.product_id = p.id
                LEFT JOIN product_costs pc ON pc.product_id = p.id
                WHERE COALESCE(b.physical_qty, 0) <> COALESCE(pc.qty_valued, 0)
             ) AS product_mismatches'
        )->fetchColumn();

        return [
            'has_phase31_receipts' => (int) $hasReceiptTables,
            'has_costing_tables' => (int) $hasCostTables,
            'has_phase32_movement_link' => (int) $columns,
            'receipt_movements' => is_array($movementSummary) ? (int) $movementSummary['receipt_movements'] : 0,
            'movements_without_valuation' => is_array($movementSummary) ? (int) $movementSummary['movements_without_valuation'] : 0,
            'products_with_qty_mismatch' => (int) $mismatch,
        ];
    }
}
