<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Environment configuration loader.
 *
 * Reads from $_ENV (populated by vlucas/phpdotenv) and validates that
 * required keys are present at boot. Throws fast if anything required
 * is missing — better to fail at startup than mid-request.
 */
final class Config
{
    /**
     * Env keys that must be set for the app to boot.
     *
     * @var list<string>
     */
    private const REQUIRED = [
        'SITE_TITLE',
        'SITE_URL',
    ];

    public static function boot(): void
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($_ENV[$key]) || $_ENV[$key] === '') {
                throw new RuntimeException("Missing required env: {$key}");
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $default;
    }
}
