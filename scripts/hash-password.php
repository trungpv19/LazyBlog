<?php
/**
 * Generate a bcrypt hash for the admin password.
 *
 * Usage:
 *   php scripts/hash-password.php "your-password"
 *   docker compose exec app php scripts/hash-password.php "your-password"
 *
 * Copy the printed line into .env as ADMIN_PASSWORD_HASH="...".
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/hash-password.php <password>\n");
    exit(1);
}

echo password_hash($argv[1], PASSWORD_BCRYPT) . "\n";
