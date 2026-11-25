<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name('smartstock_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    public static function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function verifyCsrf(?string $submittedToken): bool
    {
        return is_string($submittedToken) && hash_equals(self::csrfToken(), $submittedToken);
    }

    public static function authenticate(int $userId, string $sessionToken): void
    {
        session_regenerate_id(true);
        $_SESSION = [
            'user_id' => $userId,
            'auth_session_token' => $sessionToken,
            'csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function authSessionToken(): ?string
    {
        $token = $_SESSION['auth_session_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    public static function clearAuthentication(): void
    {
        unset($_SESSION['user_id'], $_SESSION['auth_session_token'], $_SESSION['csrf']);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
        session_destroy();
    }

    public static function flash(string $key, string $message): void
    {
        $_SESSION['_flash'][$key] = $message;
    }

    public static function pullFlash(string $key): ?string
    {
        $message = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return is_string($message) ? $message : null;
    }
}
