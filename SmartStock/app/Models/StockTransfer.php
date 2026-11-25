<?php
declare(strict_types=1);

namespace App\Models;

use DomainException;
use PDO;

/** Persistence-only gateway for Phase 6.1 immutable internal transfers. */
final class StockTransfer
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $transferId, bool $lock = false): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM stock_transfers WHERE id = ? LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$transferId]);
        $result = $query->fetch();
        return is_array($result) ? $result : null;
    }

    /** @return array<string, mixed>|null */
    public function byKey(string $documentKey): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM stock_transfers WHERE document_key = ? LIMIT 1');
        $query->execute([$documentKey]);
        $result = $query->fetch();
        return is_array($result) ? $result : null;
    }

    public function transferNoExists(string $transferNo): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM stock_transfers WHERE transfer_no = ? LIMIT 1');
        $query->execute([$transferNo]);
        return (bool) $query->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function items(int $transferId, bool $lock = false): array
    {
        $query = $this->pdo->prepare(
            'SELECT sti.*, p.status AS product_status, p.track_batches, pu.product_id AS current_product_id,
                    u.status AS unit_status
             FROM stock_transfer_items sti
             JOIN products p ON p.id = sti.product_id
             JOIN product_units pu ON pu.id = sti.product_unit_id
             JOIN units u ON u.id = pu.unit_id
             WHERE sti.transfer_id = ?
             ORDER BY sti.product_id, sti.id' . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$transferId]);
        return $query->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function list(string $status, int $limit, int $offset): array
    {
        $params = [];
        $where = '';
        if (in_array($status, ['draft', 'submitted', 'approved', 'confirmed', 'rejected', 'cancelled'], true)) {
            $where = ' WHERE st.status = ?';
            $params[] = $status;
        }
        $query = $this->pdo->prepare(
            'SELECT st.id, st.transfer_no, st.from_location_id, st.to_location_id, st.status, st.created_by,
                    st.created_at, st.submitted_at, st.approved_at, st.confirmed_at,
                    source.code AS source_location_code, source.name AS source_location_name,
                    destination.code AS destination_location_code, destination.name AS destination_location_name
             FROM stock_transfers st
             JOIN stock_locations source ON source.id = st.from_location_id
             JOIN stock_locations destination ON destination.id = st.to_location_id'
             . $where . ' ORDER BY st.id DESC LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) {
            $query->bindValue($index + 1, $value, PDO::PARAM_STR);
        }
        $query->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $query->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function locations(int $sourceId, int $destinationId, bool $lock = false): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, code, name, type, is_active FROM stock_locations WHERE id IN (?, ?) ORDER BY id'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$sourceId, $destinationId]);
        return $query->fetchAll();
    }

    public function create(array $data): int
    {
        return $this->insert('stock_transfers', $data);
    }

    public function createItem(array $data): int
    {
        return $this->insert('stock_transfer_items', $data);
    }

    public function createApproval(int $transferId, string $contentHash, int $actorId): void
    {
        $this->insert('stock_transfer_approvals', [
            'transfer_id' => $transferId,
            'content_hash' => $contentHash,
            'approved_by' => $actorId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function approval(int $transferId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM stock_transfer_approvals WHERE transfer_id = ? LIMIT 1');
        $query->execute([$transferId]);
        $result = $query->fetch();
        return is_array($result) ? $result : null;
    }

    public function transition(array $document, string $status, int $actorId, ?string $reason = null): void
    {
        if (!in_array($status, ['submitted', 'approved', 'rejected', 'cancelled'], true)) {
            throw new \LogicException('Invalid stock-transfer transition.');
        }
        $field = in_array($status, ['rejected', 'cancelled'], true) ? 'terminated' : $status;
        $sql = 'UPDATE stock_transfers SET status = ?, ' . $field . '_by = ?, ' . $field . '_at = NOW()';
        $params = [$status, $actorId];
        if ($reason !== null) {
            $sql .= ', termination_reason = ?';
            $params[] = $reason;
        }
        $sql .= ' WHERE id = ? AND status = ?';
        $params[] = $document['id'];
        $params[] = $document['status'];
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        if ($query->rowCount() !== 1) {
            throw new DomainException('Transfer state changed. Reload and retry.');
        }
    }

    public function markConfirmed(int $transferId, int $actorId): void
    {
        $query = $this->pdo->prepare(
            "UPDATE stock_transfers SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW()
             WHERE id = ? AND status = 'approved'"
        );
        $query->execute([$actorId, $transferId]);
        if ($query->rowCount() !== 1) {
            throw new DomainException('Transfer is not approved for confirmation.');
        }
    }

    public function createPosting(array $data): int
    {
        return $this->insert('stock_transfer_postings', $data);
    }

    /** @return list<array<string, mixed>> */
    public function postings(int $transferId): array
    {
        $query = $this->pdo->prepare(
            'SELECT stp.* FROM stock_transfer_postings stp
             JOIN stock_transfer_items sti ON sti.id = stp.transfer_item_id
             WHERE sti.transfer_id = ? ORDER BY stp.id'
        );
        $query->execute([$transferId]);
        return $query->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function evidence(int $transferId): array
    {
        $query = $this->pdo->prepare(
            'SELECT sti.id AS transfer_item_id, sti.product_id, sti.qty_base, stp.id AS posting_id,
                    stp.source_stock_movement_id, stp.destination_stock_movement_id,
                    stp.source_cost_entry_id, stp.destination_cost_entry_id, stp.unit_cost_khr,
                    stp.carrying_value_khr, source_movement.product_id AS source_product_id,
                    source_movement.location_id AS source_location_id, source_movement.qty_delta AS source_qty_delta,
                    source_movement.source_type AS source_type, source_movement.source_id AS source_id,
                    source_movement.source_line_id AS source_line_id,
                    destination_movement.product_id AS destination_product_id,
                    destination_movement.location_id AS destination_location_id,
                    destination_movement.qty_delta AS destination_qty_delta,
                    destination_movement.source_type AS destination_type,
                    destination_movement.source_id AS destination_id,
                    destination_movement.source_line_id AS destination_line_id,
                    source_cost.qty_delta AS source_cost_qty, source_cost.value_delta_khr AS source_value_delta,
                    source_cost.costing_type AS source_costing_type,
                    destination_cost.qty_delta AS destination_cost_qty,
                    destination_cost.value_delta_khr AS destination_value_delta,
                    destination_cost.costing_type AS destination_costing_type
             FROM stock_transfer_items sti
             LEFT JOIN stock_transfer_postings stp ON stp.transfer_item_id = sti.id
             LEFT JOIN stock_movements source_movement ON source_movement.id = stp.source_stock_movement_id
             LEFT JOIN stock_movements destination_movement ON destination_movement.id = stp.destination_stock_movement_id
             LEFT JOIN inventory_cost_entries source_cost ON source_cost.id = stp.source_cost_entry_id
             LEFT JOIN inventory_cost_entries destination_cost ON destination_cost.id = stp.destination_cost_entry_id
             WHERE sti.transfer_id = ? ORDER BY sti.product_id, sti.id'
        );
        $query->execute([$transferId]);
        return $query->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function audits(int $transferId): array
    {
        $query = $this->pdo->prepare(
            "SELECT action, actor_id, created_at FROM audit_logs
             WHERE entity_type = 'stock_transfers' AND entity_id = ? ORDER BY id"
        );
        $query->execute([$transferId]);
        return $query->fetchAll();
    }

    private function insert(string $table, array $data): int
    {
        $query = $this->pdo->prepare(
            'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES ('
            . implode(',', array_fill(0, count($data), '?')) . ')'
        );
        $query->execute(array_values($data));
        return (int) $this->pdo->lastInsertId();
    }
}
