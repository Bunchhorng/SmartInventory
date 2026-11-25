<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Supplier
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $supplierId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, supplier_code, name, contact_person, phone, address, email,
                    status, payment_terms_days, created_at, updated_at
             FROM suppliers WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$supplierId]);
        $supplier = $statement->fetch();

        return is_array($supplier) ? $supplier : null;
    }

    public function codeExists(string $supplierCode, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM suppliers WHERE supplier_code = ?';
        $parameters = [$supplierCode];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $exceptId;
        }
        $statement = $this->pdo->prepare($sql . ' LIMIT 1');
        $statement->execute($parameters);
        return (bool) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function list(string $search, string $status, int $limit, int $offset): array
    {
        [$where, $parameters] = $this->listWhere($search, $status);
        $statement = $this->pdo->prepare(
            'SELECT id, supplier_code, name, contact_person, phone, address, email,
                    status, payment_terms_days, created_at, updated_at
             FROM suppliers'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY name, id LIMIT ? OFFSET ?'
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
        [$where, $parameters] = $this->listWhere($search, $status);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM suppliers' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        );
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @return list<array{id: int, supplier_code: string, name: string}> */
    public function activeForSelection(): array
    {
        return $this->pdo->query(
            "SELECT id, supplier_code, name FROM suppliers WHERE status = 'active' ORDER BY name, id"
        )->fetchAll();
    }

    public function create(
        string $supplierCode,
        string $name,
        ?string $contactPerson,
        ?string $phone,
        ?string $address,
        ?string $email,
        int $paymentTermsDays,
    ): int {
        $statement = $this->pdo->prepare(
            "INSERT INTO suppliers
             (supplier_code, name, contact_person, phone, address, email, status, payment_terms_days)
             VALUES (?, ?, ?, ?, ?, ?, 'active', ?)"
        );
        $statement->execute([
            $supplierCode,
            $name,
            $contactPerson,
            $phone,
            $address,
            $email,
            $paymentTermsDays,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(
        int $supplierId,
        string $supplierCode,
        string $name,
        ?string $contactPerson,
        ?string $phone,
        ?string $address,
        ?string $email,
        int $paymentTermsDays,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE suppliers
             SET supplier_code = ?, name = ?, contact_person = ?, phone = ?, address = ?, email = ?,
                 payment_terms_days = ?
             WHERE id = ?'
        );
        $statement->execute([
            $supplierCode,
            $name,
            $contactPerson,
            $phone,
            $address,
            $email,
            $paymentTermsDays,
            $supplierId,
        ]);
    }

    public function setStatus(int $supplierId, string $status): void
    {
        $statement = $this->pdo->prepare('UPDATE suppliers SET status = ? WHERE id = ?');
        $statement->execute([$status, $supplierId]);
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function listWhere(string $search, string $status): array
    {
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(supplier_code LIKE ? OR name LIKE ? OR contact_person LIKE ? OR phone LIKE ?)';
            $like = '%' . $search . '%';
            $parameters = [$like, $like, $like, $like];
        }
        if (in_array($status, ['active', 'inactive', 'archived'], true)) {
            $where[] = 'status = ?';
            $parameters[] = $status;
        }

        return [$where, $parameters];
    }
}
