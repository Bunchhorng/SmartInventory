<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function redirect(string $route): never
    {
        header('Location: ?r=' . rawurlencode($route), true, 302);
        exit;
    }

    public static function abort(int $statusCode, string $message): never
    {
        throw new HttpException($statusCode, $message);
    }
}
