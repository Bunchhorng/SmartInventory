<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class User
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, full_name, password_hash, status, failed_attempts, locked_until,
                    must_change_password
             FROM users WHERE username = ? LIMIT 1'
        );
        $statement->execute([$username]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null */
    public function findActiveById(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, full_name, status, must_change_password
             FROM users WHERE id = ? AND status = ? LIMIT 1'
        );
        $statement->execute([$userId, 'active']);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function passwordHashFor(int $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $statement->execute([$userId]);
        $hash = $statement->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function markLoginSucceeded(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW()
             WHERE id = ?'
        );
        $statement->execute([$userId]);
    }

    /** @param array<string, mixed> $user */
    public function recordFailedLogin(array $user): void
    {
        $lockExpired = !empty($user['locked_until'])
            && strtotime((string) $user['locked_until']) <= time();
        $attempts = $lockExpired ? 1 : ((int) $user['failed_attempts'] + 1);
        $lockedUntil = $attempts >= 5
            ? (new \DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s')
            : null;

        $statement = $this->pdo->prepare(
            'UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?'
        );
        $statement->execute([$attempts, $lockedUntil, $user['id']]);
    }

    public function updatePassword(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET password_hash = ?, must_change_password = 0, password_changed_at = NOW()
             WHERE id = ?'
        );
        $statement->execute([$passwordHash, $userId]);
    }

    public function countOwners(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'owner'"
        )->fetchColumn();
    }

    public function createUser(string $username, string $fullName, string $passwordHash, string $roleCode): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO users (username, full_name, password_hash, status, must_change_password)
             VALUES (?, ?, ?, 'active', 1)"
        );
        $statement->execute([$username, $fullName, $passwordHash]);
        $userId = (int) $this->pdo->lastInsertId();

        $roleStatement = $this->pdo->prepare(
            'INSERT INTO user_roles (user_id, role_id)
             SELECT ?, id FROM roles WHERE code = ?'
        );
        $roleStatement->execute([$userId, $roleCode]);
        if ($roleStatement->rowCount() !== 1) {
            throw new \RuntimeException(sprintf('The "%s" role is missing.', $roleCode));
        }

        return $userId;
    }

    /** @return list<array<string, mixed>> */
    public function listStaff(): array
    {
        $statement = $this->pdo->query(
            "SELECT u.id, u.username, u.full_name, u.status, u.must_change_password,
                    u.last_login_at, u.disabled_at, u.created_at
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.code = 'staff'
             ORDER BY u.full_name ASC, u.id ASC"
        );

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findStaffById(int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT u.id, u.username, u.full_name, u.status, u.must_change_password,
                    u.last_login_at, u.disabled_at
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE u.id = ? AND r.code = 'staff'
             LIMIT 1"
        );
        $statement->execute([$userId]);
        $user = $statement->fetch();

        return is_array($user) ? $user : null;
    }

    public function updateStaffStatus(int $userId, string $status): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE users
             SET status = ?,
                 disabled_at = CASE WHEN ? = 'disabled' THEN NOW() ELSE NULL END,
                 failed_attempts = CASE WHEN ? = 'active' THEN 0 ELSE failed_attempts END,
                 locked_until = CASE WHEN ? = 'active' THEN NULL ELSE locked_until END
             WHERE id = ?"
        );
        $statement->execute([$status, $status, $status, $status, $userId]);
    }

    public function isOwner(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ? AND r.code = 'owner'
             LIMIT 1"
        );
        $statement->execute([$userId]);

        return (bool) $statement->fetchColumn();
    }

    public function hasPermission(int $userId, string $permission): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ? AND (r.code = 'owner' OR p.code = ?)
             LIMIT 1"
        );
        $statement->execute([$userId, $permission]);

        return (bool) $statement->fetchColumn();
    }

    public function createAuthSession(int $userId, string $tokenHash, string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_sessions (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$userId, $tokenHash, $expiresAt]);
    }

    public function hasValidAuthSession(int $userId, string $tokenHash): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM auth_sessions
             WHERE user_id = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute([$userId, $tokenHash]);

        return (bool) $statement->fetchColumn();
    }

    public function revokeAuthSession(string $tokenHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE auth_sessions SET revoked_at = NOW()
             WHERE token_hash = ? AND revoked_at IS NULL'
        );
        $statement->execute([$tokenHash]);
    }

    public function revokeAllAuthSessions(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE auth_sessions SET revoked_at = NOW()
             WHERE user_id = ? AND revoked_at IS NULL'
        );
        $statement->execute([$userId]);
    }
}
