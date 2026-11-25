<?php
declare(strict_types=1);

use App\Core\HttpException;
use App\Core\Response;
use App\Core\View;
use App\Middleware\AuthMiddleware;

// When used as the router script for PHP's built-in server, let that server
// deliver files that exist under public/ (CSS, JavaScript, images, etc.).
if (PHP_SAPI === 'cli-server') {
    $requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $publicRoot = realpath(__DIR__);
    $publicFile = realpath($publicRoot . DIRECTORY_SEPARATOR . ltrim(urldecode($requestPath), '/\\'));
    if (
        $requestPath !== '/'
        && $publicFile !== false
        && str_starts_with($publicFile, $publicRoot . DIRECTORY_SEPARATOR)
        && is_file($publicFile)
    ) {
        return false;
    }
}

try {
    /** @var App\Core\App $app */
    $app = require dirname(__DIR__) . '/app/bootstrap.php';
    $router = require dirname(__DIR__) . '/routes/web.php';

    $route = (string) ($_GET['r'] ?? 'dashboard');
    if (!preg_match('/^[a-z0-9-]+$/', $route)) {
        Response::abort(404, 'Page not found.');
    }

    $auth = new AuthMiddleware($app);
    $auth->enforcePasswordChange($route);
    $router->dispatch($route, $_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (HttpException $exception) {
    http_response_code($exception->statusCode());
    View::render('errors/http', [
        'title' => 'Request error',
        'status' => $exception->statusCode(),
        'message' => $exception->getMessage(),
    ], 'layouts/guest');
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);
    View::render('errors/http', [
        'title' => 'Server error',
        'status' => 500,
        'message' => 'An unexpected error occurred. Please try again later.',
    ], 'layouts/guest');
}
