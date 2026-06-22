<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * HTTP helpers: view rendering with layout wrapping, redirects, escaping.
 */
final class Http
{
    /**
     * Render a view inside the base layout.
     *
     * The view file echoes its content (or builds it via string return) —
     * we capture stdout into $body, then include the layout, which uses
     * $title and $body.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $view, array $data = []): void
    {
        $viewPath = __DIR__ . '/../views/' . $view . '.php';
        if (!is_file($viewPath)) {
            throw new RuntimeException("View not found: {$view}");
        }

        $title = (string) ($data['title'] ?? Config::get('SITE_TITLE'));

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        /** @var string $body */
        $body = ob_get_clean();

        require __DIR__ . '/../views/layout.php';
    }

    public static function redirect(string $location, int $code = 302): never
    {
        http_response_code($code);
        header('Location: ' . $location);
        exit;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
