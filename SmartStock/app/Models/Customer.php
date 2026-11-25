<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class Customer
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $customerId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, phone, credit_limit, credit_enabled, payment_terms_days, status, created_at, updated_at
             FROM customers WHERE id = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$customerId]);
        $customer = $statement->fetch();
        return is_array($customer) ? $customer : null;
    }

    /** @return array<string, mixed>|null */
    public function active(int $customerId, bool $forUpdate = false): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, name, phone, credit_limit, credit_enabled, payment_terms_days, status
             FROM customers WHERE id = ? AND status = 'active' LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$customerId]);
        $customer = $statement->fetch();
        return is_array($customer) ? $customer : null;
    }

    public function create(string $name, ?string $phone, string $creditLimit, bool $creditEnabled, int $termsDays): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO customers (name, phone, credit_limit, credit_enabled, payment_terms_days, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );
        $statement->execute([$name, $phone, $creditLimit, (int) $creditEnabled, $termsDays]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateCreditProfile(int $customerId, string $creditLimit, bool $creditEnabled, int $termsDays): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE customers SET credit_limit = ?, credit_enabled = ?, payment_terms_days = ? WHERE id = ? AND status = \'active\''
        );
        $statement->execute([$creditLimit, (int) $creditEnabled, $termsDays, $customerId]);
    }

    /** @return list<array<string, mixed>> */
    public function activeForSales(): array
    {
        return $this->pdo->query(
            "SELECT id, name, phone FROM customers WHERE status = 'active' ORDER BY name, id"
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function listWithCreditExposure(): array
    {
        return $this->pdo->query(
            "SELECT c.id, c.name, c.phone, c.credit_limit, c.credit_enabled, c.payment_terms_days, c.status,
                    COALESCE((
                        SELECT SUM(r.original_khr - COALESCE((
                            SELECT SUM(ra.amount_khr)
                            FROM receivable_allocations ra
                            JOIN payments p ON p.id = ra.payment_id
                            WHERE ra.receivable_id = r.id
                              AND ra.reversed_at IS NULL
                              AND p.direction = 'in'
                              AND p.verification_status = 'verified'
                        ), 0))
                        FROM receivables r
                        WHERE r.customer_id = c.id AND r.voided_at IS NULL
                    ), 0) AS outstanding_khr
             FROM customers c
             ORDER BY c.status = 'active' DESC, c.name, c.id"
        )->fetchAll();
    }
}
