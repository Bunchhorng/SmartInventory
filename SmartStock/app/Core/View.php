<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    private static string $viewsPath;

    public static function setViewsPath(string $viewsPath): void
    {
        self::$viewsPath = rtrim($viewsPath, '/\\');
    }

    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        $viewPath = self::pathFor($view);
        $layoutPath = self::pathFor($layout);

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewPath;
        $content = (string) ob_get_clean();
        require $layoutPath;
    }

    private static function pathFor(string $view): string
    {
        $path = self::$viewsPath . '/' . $view . '.php';
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('View "%s" does not exist.', $view));
        }

        return $path;
    }
}
