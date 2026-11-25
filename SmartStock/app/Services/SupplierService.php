<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\User;
use DomainException;
use PDO;

final class SupplierService
{
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private Supplier $suppliers,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(int $actorId, array $input): int
    {
        $this->assertCanManage($actorId);
        $data = $this->validatedInput($input);

        return $this->transaction(function () use ($actorId, $data): int {
            if ($this->suppliers->codeExists($data['supplier_code'])) {
                throw new DomainException('The supplier code is already in use.');
            }
            $supplierId = $this->suppliers->create(
                $data['supplier_code'],
                $data['name'],
                $data['contact_person'],
                $data['phone'],
                $data['address'],
                $data['email'],
                $data['payment_terms_days'],
            );
            $this->auditLog->record($actorId, 'supplier.created', 'suppliers', $supplierId);
            return $supplierId;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(int $actorId, int $supplierId, array $input): void
    {
        $this->assertCanManage($actorId);
        $data = $this->validatedInput($input);

        $this->transaction(function () use ($actorId, $supplierId, $data): void {
            if ($this->suppliers->find($supplierId, true) === null) {
                throw new DomainException('The supplier was not found.');
            }
            if ($this->suppliers->codeExists($data['supplier_code'], $supplierId)) {
                throw new DomainException('The supplier code is already in use.');
            }
            $this->suppliers->update(
                $supplierId,
                $data['supplier_code'],
                $data['name'],
                $data['contact_person'],
                $data['phone'],
                $data['address'],
                $data['email'],
                $data['payment_terms_days'],
            );
            $this->auditLog->record($actorId, 'supplier.updated', 'suppliers', $supplierId);
        });
    }

    public function setStatus(int $actorId, int $supplierId, string $status): void
    {
        $this->assertCanManage($actorId);
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new DomainException('A supplier can only be activated or deactivated through this workflow.');
        }

        $this->transaction(function () use ($actorId, $supplierId, $status): void {
            $supplier = $this->suppliers->find($supplierId, true);
            if ($supplier === null) {
                throw new DomainException('The supplier was not found.');
            }
            if ($supplier['status'] === $status) {
                throw new DomainException('The supplier already has that status.');
            }
            if ($supplier['status'] === 'archived') {
                throw new DomainException('Archived historical suppliers cannot be reactivated through this workflow.');
            }
            $this->suppliers->setStatus($supplierId, $status);
            $this->auditLog->record($actorId, 'supplier.status_changed', 'suppliers', $supplierId, $status);
        });
    }

    public function delete(int $actorId, int $supplierId): never
    {
        $this->assertCanManage($actorId);
        if ($this->suppliers->find($supplierId) === null) {
            throw new DomainException('The supplier was not found.');
        }
        throw new DomainException('Suppliers are not deleted because transaction history must be preserved. Deactivate the supplier instead.');
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function listForViewer(int $actorId, string $search, string $status, int $page, int $perPage): array
    {
        $this->assertCanView($actorId);
        if (!$this->users->isOwner($actorId) && $status !== 'active') {
            $status = 'active';
        }
        $search = $this->search($search);
        $status = in_array($status, ['active', 'inactive', 'archived'], true) ? $status : 'all';
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $items = $this->suppliers->list($search, $status, $perPage, ($page - 1) * $perPage);

        if (!$this->users->isOwner($actorId)) {
            $items = array_map(static fn (array $supplier): array => [
                'id' => $supplier['id'],
                'supplier_code' => $supplier['supplier_code'],
                'name' => $supplier['name'],
                'contact_person' => $supplier['contact_person'],
                'phone' => $supplier['phone'],
                'address' => $supplier['address'],
                'status' => $supplier['status'],
            ], $items);
        }

        return ['items' => $items, 'total' => $this->suppliers->count($search, $status)];
    }

    /** @return list<array{id: int, supplier_code: string, name: string}> */
    public function activeForPurchase(int $actorId): array
    {
        $this->assertCanView($actorId);
        return $this->suppliers->activeForSelection();
    }

    private function assertCanView(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'suppliers.view')) {
            throw new DomainException('You do not have permission to view suppliers.');
        }
    }

    private function assertCanManage(int $actorId): void
    {
        if (!$this->users->hasPermission($actorId, 'suppliers.manage')) {
            throw new DomainException('You do not have permission to manage suppliers.');
        }
    }

    /** @param array<string, mixed> $input @return array{supplier_code: string, name: string, contact_person: string, phone: string, address: string, email: ?string, payment_terms_days: int} */
    private function validatedInput(array $input): array
    {
        $supplierCode = strtoupper(trim((string) ($input['supplier_code'] ?? '')));
        if (!preg_match('/^[A-Z0-9][A-Z0-9-]{1,48}$/', $supplierCode)) {
            throw new DomainException('Supplier code must use 2-49 uppercase letters, numbers, or hyphens.');
        }
        $name = $this->requiredText($input['name'] ?? null, 'Supplier name', 2, 180);
        $contactPerson = $this->requiredText($input['contact_person'] ?? null, 'Contact person', 2, 180);
        $phone = $this->requiredText($input['phone'] ?? null, 'Phone number', 5, 50);
        if (!preg_match('/^[0-9+(). -]+$/', $phone)) {
            throw new DomainException('Phone number contains unsupported characters.');
        }
        $address = $this->requiredText($input['address'] ?? null, 'Address', 2, 1000);
        $email = $this->optionalText($input['email'] ?? null, 'Email address', 3, 190);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('A valid email address is required when an email is provided.');
        }
        $terms = filter_var($input['payment_terms_days'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 3650],
        ]);
        if ($terms === false) {
            throw new DomainException('Payment terms must be a whole number from 0 to 3650 days.');
        }

        return [
            'supplier_code' => $supplierCode,
            'name' => $name,
            'contact_person' => $contactPerson,
            'phone' => $phone,
            'address' => $address,
            'email' => $email,
            'payment_terms_days' => (int) $terms,
        ];
    }

    private function requiredText(mixed $value, string $label, int $minimum, int $maximum): string
    {
        $text = trim((string) $value);
        if ($this->length($text) < $minimum || $this->length($text) > $maximum) {
            throw new DomainException(sprintf('%s must contain %d-%d characters.', $label, $minimum, $maximum));
        }
        return $text;
    }

    private function optionalText(mixed $value, string $label, int $minimum, int $maximum): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if ($this->length($text) < $minimum || $this->length($text) > $maximum) {
            throw new DomainException(sprintf('%s must contain %d-%d characters when provided.', $label, $minimum, $maximum));
        }
        return $text;
    }

    private function search(string $search): string
    {
        $search = trim($search);
        return $this->length($search) > 100 ? substr($search, 0, 100) : $search;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @template T @param callable():T $operation @return T */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new DomainException('The supplier could not be saved because it conflicts with an existing record.', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
