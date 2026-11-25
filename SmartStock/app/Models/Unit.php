<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Unit
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, name, precision_digits, status FROM units WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id]);
        $unit = $statement->fetch();

        return is_array($unit) ? $unit : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $search, string $status, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(code LIKE ? OR name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (in_array($status, ['active', 'archived'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, code, name, precision_digits, status FROM units'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY name, id LIMIT ? OFFSET ?'
        );
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function count(string $search, string $status): int
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(code LIKE ? OR name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (in_array($status, ['active', 'archived'], true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM units' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array{id: int, code: string, name: string, precision_digits: int}> */
    public function activeOptions(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, code, name, precision_digits FROM units WHERE status = 'active' ORDER BY name, id"
        );
        return $statement->fetchAll();
    }

    public function duplicateExists(string $code, string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM units WHERE (code = ? OR name = ?)';
        $params = [$code, $name];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    public function create(string $code, string $name, int $precision): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO units (code, name, precision_digits, status) VALUES (?, ?, ?, 'active')"
        );
        $statement->execute([$code, $name, $precision]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $code, string $name, int $precision): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE units SET code = ?, name = ?, precision_digits = ? WHERE id = ?'
        );
        $statement->execute([$code, $name, $precision, $id]);
    }

    public function isReferencedByActiveProduct(int $unitId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM products p
             LEFT JOIN product_units pu ON pu.product_id = p.id
             WHERE p.status = 'active' AND (p.base_unit_id = ? OR pu.unit_id = ?)
             LIMIT 1"
        );
        $statement->execute([$unitId, $unitId]);
        return (bool) $statement->fetchColumn();
    }

    public function archive(int $id): void
    {
        $statement = $this->pdo->prepare("UPDATE units SET status = 'archived' WHERE id = ?");
        $statement->execute([$id]);
    }
}
