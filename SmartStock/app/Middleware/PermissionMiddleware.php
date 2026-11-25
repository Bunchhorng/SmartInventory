<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Response;

final class PermissionMiddleware
{
    public function __construct(private App $app)
    {
    }

    /** @param array<string, mixed> $user */
    public function requirePermission(array $user, string $permission): void
    {
        if (!$this->app->users()->hasPermission((int) $user['id'], $permission)) {
            Response::abort(403, 'You do not have permission to access this page.');
        }
    }
}
