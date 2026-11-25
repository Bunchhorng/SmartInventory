<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        $data += [
            'appName' => (string) $this->app->config()['app_name'],
            'currentUser' => $this->app->auth()->currentUser(),
            'csrfToken' => Session::csrfToken(),
            'successMessage' => Session::pullFlash('success'),
            'errorMessage' => Session::pullFlash('error'),
        ];

        View::render($view, $data, $layout);
    }

    protected function redirect(string $route): never
    {
        Response::redirect($route);
    }
}
