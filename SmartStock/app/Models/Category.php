<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Category
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, parent_id, name, is_active FROM categories WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$id]);
        $category = $statement->fetch();

        return is_array($category) ? $category : null;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $search, string $status, int $limit, int $offset): array
    {
        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(c.name LIKE ? OR parent.name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status === 'active') {
            $where[] = 'c.is_active = 1';
        } elseif ($status === 'archived') {
            $where[] = 'c.is_active = 0';
        }

        $sql = 'SELECT c.id, c.parent_id, c.name, c.is_active, parent.name AS parent_name
                FROM categories c
                LEFT JOIN categories parent ON parent.id = c.parent_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY COALESCE(parent.name, c.name), c.parent_id IS NOT NULL, c.name, c.id
                LIMIT ? OFFSET ?';
        $statement = $this->pdo->prepare($sql);
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
            $where[] = '(c.name LIKE ? OR parent.name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if ($status === 'active') {
            $where[] = 'c.is_active = 1';
        } elseif ($status === 'archived') {
            $where[] = 'c.is_active = 0';
        }

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM categories c LEFT JOIN categories parent ON parent.id = c.parent_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array{id: int, name: string, parent_id: int|null}> */
    public function activeOptions(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, name, parent_id FROM categories WHERE is_active = 1 ORDER BY name, id'
        );
        return $statement->fetchAll();
    }

    public function siblingExists(string $name, ?int $parentId, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE name = ? AND parent_id <=> ?';
        $params = [$name, $parentId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    public function create(string $name, ?int $parentId): int
    {
        $statement = $this->pdo->prepare('INSERT INTO categories (parent_id, name) VALUES (?, ?)');
        $statement->execute([$parentId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?int $parentId): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET parent_id = ?, name = ? WHERE id = ?');
        $statement->execute([$parentId, $name, $id]);
    }

    public function archive(int $id): void
    {
        $statement = $this->pdo->prepare('UPDATE categories SET is_active = 0 WHERE id = ?');
        $statement->execute([$id]);
    }
}
