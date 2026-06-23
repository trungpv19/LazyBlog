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
        // Strip CRLF + control chars to defeat HTTP response splitting.
        // PHP 8 mostly already rejects these in header() but defense-in-depth.
        $location = (string) preg_replace('/[\r\n\t\0]/', '', $location);
        if ($location === '') {
            $location = '/';
        }
        http_response_code($code);
        header('Location: ' . $location);
        exit;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Return an asset URL with a cache-busting ?v=<mtime> query string.
     *
     * Takes a path relative to /public (e.g. "assets/base.css") and resolves
     * mtime against the on-disk file so deploys invalidate browser caches
     * automatically. Falls back to a static "0" when the file is missing so
     * a typo doesn't crash the page render.
     */
    public static function asset(string $path): string
    {
        $relative = '/' . ltrim($path, '/');
        $fsPath = __DIR__ . '/../public' . $relative;
        $version = is_file($fsPath) ? (string) filemtime($fsPath) : '0';
        return $relative . '?v=' . $version;
    }
}
