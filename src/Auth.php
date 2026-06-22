<?php

declare(strict_types=1);

namespace App;

/**
 * Single-password admin authentication.
 *
 * - Stores nothing in a DB. Verifies submitted password against the bcrypt
 *   hash in ADMIN_PASSWORD_HASH env.
 * - Session cookie hardened: HttpOnly, SameSite=Lax, Secure when configured.
 * - On successful login regenerates the session ID to prevent fixation.
 * - On failure adds a 500ms delay so brute-force is rate-limited without
 *   needing extra infrastructure for a single-writer blog.
 */
final class Auth
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $name = (string) Config::get('SESSION_NAME', 'lazyblog_sess');
        $secure = strtolower((string) Config::get('SESSION_SECURE', 'false')) === 'true';

        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        self::$started = true;
    }

    public static function attempt(string $password): bool
    {
        self::start();

        $hash = (string) Config::get('ADMIN_PASSWORD_HASH', '');
        if ($hash === '') {
            // Soft fail: no hash configured yet — still burn time so callers
            // can't distinguish "no admin set" from "wrong password".
            usleep(500_000);
            return false;
        }

        if (!password_verify($password, $hash)) {
            usleep(500_000);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        $_SESSION['admin_since'] = time();
        return true;
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['admin']);
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42_000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'],
                ],
            );
        }
        session_destroy();
    }

    /**
     * Guard an admin handler. If not authed, redirect to login preserving
     * the original destination in `?next=` so the user lands back where they
     * tried to go.
     */
    public static function requireAuth(): void
    {
        if (!self::check()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/admin';
            Http::redirect('/admin/login?next=' . rawurlencode($next));
        }
    }
}
