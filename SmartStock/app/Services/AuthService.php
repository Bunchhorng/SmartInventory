<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Models\AuditLog;
use App\Models\User;
use PDO;

final class AuthService
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private PDO $pdo,
        private User $users,
        private AuditLog $auditLog,
        private array $config,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $userId = Session::userId();
        $sessionToken = Session::authSessionToken();
        if ($userId === null || $sessionToken === null) {
            return null;
        }

        $tokenHash = hash('sha256', $sessionToken);
        if (!$this->users->hasValidAuthSession($userId, $tokenHash)) {
            Session::clearAuthentication();
            return null;
        }

        $user = $this->users->findActiveById($userId);
        if ($user === null) {
            $this->users->revokeAuthSession($tokenHash);
            Session::clearAuthentication();
        }

        return $user;
    }

    /** @return array<string, mixed>|null */
    public function attemptLogin(string $username, string $password): ?array
    {
        $user = $this->users->findByUsername($username);
        if (
            $user === null
            || $user['status'] !== 'active'
            || $this->isLocked($user)
            || !password_verify($password, (string) $user['password_hash'])
        ) {
            if ($user !== null && $user['status'] === 'active' && !$this->isLocked($user)) {
                $this->users->recordFailedLogin($user);
            }
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->users->markLoginSucceeded((int) $user['id']);
            $sessionToken = $this->createAuthSession((int) $user['id']);
            $this->auditLog->record((int) $user['id'], 'auth.login', 'users', (int) $user['id']);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        Session::authenticate((int) $user['id'], $sessionToken);

        return $this->users->findActiveById((int) $user['id']);
    }

    public function logout(int $userId): void
    {
        $token = Session::authSessionToken();
        if ($token !== null) {
            $this->users->revokeAuthSession(hash('sha256', $token));
        }
        $this->auditLog->record($userId, 'auth.logout', 'users', $userId);
        Session::destroy();
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): ?string
    {
        $currentHash = $this->users->passwordHashFor($userId);
        if ($currentHash === null || !password_verify($currentPassword, $currentHash)) {
            return 'The current password is incorrect.';
        }
        if ($currentPassword === $newPassword) {
            return 'The new password must be different from the current password.';
        }
        $policyError = $this->validatePassword($newPassword);
        if ($policyError !== null) {
            return $policyError;
        }

        $this->pdo->beginTransaction();
        try {
            $this->users->updatePassword($userId, $this->hashPassword($newPassword));
            $this->users->revokeAllAuthSessions($userId);
            $this->auditLog->record($userId, 'auth.password_changed', 'users', $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $this->startAuthenticatedSession($userId);
        return null;
    }

    public function createStaff(
        int $actorId,
        string $username,
        string $fullName,
        string $temporaryPassword,
    ): ?string {
        $validationError = $this->validateNewUser($username, $fullName, $temporaryPassword);
        if ($validationError !== null) {
            return $validationError;
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->users->createUser(
                $username,
                $fullName,
                $this->hashPassword($temporaryPassword),
                'staff',
            );
            $this->auditLog->record($actorId, 'user.staff_created', 'users', $userId);
            $this->pdo->commit();
        } catch (\PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return 'The staff account could not be created. The username may already be in use.';
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return null;
    }

    public function changeStaffStatus(int $actorId, int $staffId, string $status): ?string
    {
        $allowedStatuses = ['active', 'suspended', 'disabled'];
        if (!in_array($status, $allowedStatuses, true)) {
            return 'The requested account status is invalid.';
        }
        if (!$this->users->isOwner($actorId)) {
            return 'Only an Owner can manage staff accounts.';
        }

        $staff = $this->users->findStaffById($staffId);
        if ($staff === null || $this->users->isOwner($staffId)) {
            return 'The requested Staff account was not found.';
        }
        if ($staff['status'] === $status) {
            return sprintf('This Staff account is already %s.', $status);
        }

        $this->pdo->beginTransaction();
        try {
            $this->users->updateStaffStatus($staffId, $status);
            $this->users->revokeAllAuthSessions($staffId);
            $this->auditLog->record(
                $actorId,
                sprintf('user.staff_%s', $status),
                'users',
                $staffId,
                sprintf('Changed Staff account from %s to %s.', $staff['status'], $status),
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return null;
    }

    public function createInitialOwner(
        string $username,
        string $fullName,
        string $password,
    ): ?string {
        $validationError = $this->validateNewUser($username, $fullName, $password);
        if ($validationError !== null) {
            return $validationError;
        }
        if ($this->users->countOwners() > 0) {
            return 'An Owner account already exists. Refusing to create another.';
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->users->createUser($username, $fullName, $this->hashPassword($password), 'owner');
            $this->auditLog->record($userId, 'auth.owner_created', 'users', $userId);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return null;
    }

    private function startAuthenticatedSession(int $userId): void
    {
        Session::authenticate($userId, $this->createAuthSession($userId));
    }

    private function createAuthSession(int $userId): string
    {
        $minutes = max(15, min(1440, (int) $this->config['session_lifetime_minutes']));
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable(sprintf('+%d minutes', $minutes)))->format('Y-m-d H:i:s');
        $this->users->createAuthSession($userId, hash('sha256', $rawToken), $expiresAt);
        return $rawToken;
    }

    /** @param array<string, mixed> $user */
    private function isLocked(array $user): bool
    {
        if (empty($user['locked_until'])) {
            return false;
        }

        return strtotime((string) $user['locked_until']) > time();
    }

    private function validateNewUser(string $username, string $fullName, string $password): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,60}$/', $username)) {
            return 'Username must be 3–60 characters and use letters, numbers, dots, dashes, or underscores.';
        }
        $nameLength = function_exists('mb_strlen') ? mb_strlen($fullName) : strlen($fullName);
        if (trim($fullName) === '' || $nameLength > 180) {
            return 'Full name is required and must be 180 characters or fewer.';
        }

        return $this->validatePassword($password);
    }

    private function validatePassword(string $password): ?string
    {
        $length = strlen($password);
        if ($length < 12 || $length > 128) {
            return 'Password must contain 12–128 characters.';
        }

        return null;
    }

    private function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algorithm);
    }
}
