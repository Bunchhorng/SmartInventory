<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string}>> */
    private array $routes = [];

    public function __construct(private App $app)
    {
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function get(string $route, array $handler): void
    {
        $this->add('GET', $route, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    public function post(string $route, array $handler): void
    {
        $this->add('POST', $route, $handler);
    }

    /** @param array{0: class-string, 1: string} $handler */
    private function add(string $method, string $route, array $handler): void
    {
        $this->routes[$route][$method] = $handler;
    }

    public function dispatch(string $route, string $method): void
    {
        $method = strtoupper($method);
        if (!isset($this->routes[$route])) {
            Response::abort(404, 'Page not found.');
        }
        if (!isset($this->routes[$route][$method])) {
            Response::abort(405, 'Method not allowed.');
        }

        [$controllerClass, $action] = $this->routes[$route][$method];
        $controller = new $controllerClass($this->app);
        $controller->{$action}();
    }
}
