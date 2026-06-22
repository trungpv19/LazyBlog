<?php

declare(strict_types=1);

namespace App;

/**
 * Per-session CSRF token.
 *
 * Token is generated lazily on first call to token(), stored in $_SESSION,
 * and verified with hash_equals to avoid timing leaks. Single token per
 * session is fine for an admin used by one writer.
 */
final class Csrf
{
    public const SESSION_KEY = '_csrf';

    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    public static function verify(?string $posted): bool
    {
        Auth::start();
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($stored) || $stored === '' || !is_string($posted) || $posted === '') {
            return false;
        }
        return hash_equals($stored, $posted);
    }

    /**
     * Verify or 403. Use at the top of every state-changing admin POST handler.
     * Accepts the token from either `$_POST['_csrf']` (form-encoded requests)
     * or the `X-CSRF-Token` header (raw-body requests like /admin/preview).
     */
    public static function requireValid(): void
    {
        $posted = $_POST['_csrf'] ?? null;
        if (!is_string($posted) || $posted === '') {
            $posted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        if (!self::verify(is_string($posted) ? $posted : null)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 CSRF token mismatch\n";
            exit;
        }
    }
}
