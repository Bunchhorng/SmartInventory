<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Response;
use App\Core\Session;

final class AuthMiddleware
{
    public function __construct(private App $app)
    {
    }

    /** @return array<string, mixed> */
    public function requireUser(): array
    {
        $user = $this->app->auth()->currentUser();
        if ($user === null) {
            Session::flash('error', 'Please sign in to continue.');
            Response::redirect('login');
        }

        return $user;
    }

    public function enforcePasswordChange(string $route): void
    {
        $user = $this->app->auth()->currentUser();
        if (
            $user !== null
            && (bool) $user['must_change_password']
            && !in_array($route, ['change-password', 'logout'], true)
        ) {
            Response::redirect('change-password');
        }
    }
}
