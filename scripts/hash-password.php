<?php
/**
 * Generate a bcrypt hash for the admin password.
 *
 * Interactive (recommended — password never hits shell history or `ps`):
 *   php scripts/hash-password.php
 *
 * Non-interactive (for Docker/CI; password IS visible in process list):
 *   php scripts/hash-password.php "your-password"
 *   docker compose exec app php scripts/hash-password.php "your-password"
 *
 * The script prints a ready-to-paste line:
 *   ADMIN_PASSWORD_HASH="$2y$10$..."
 */

function read_hidden(string $prompt): string
{
    fwrite(STDERR, $prompt);

    // Hide echo on POSIX TTYs. Falls back to plain read if stty missing
    // (e.g. piped input, Windows) — caller still gets a usable hash.
    $hasStty = @shell_exec('command -v stty') !== null;
    if ($hasStty && stream_isatty(STDIN)) {
        shell_exec('stty -echo');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDERR, "\n");
    } else {
        $value = rtrim((string) fgets(STDIN), "\r\n");
    }

    return $value;
}

if ($argc >= 2) {
    $password = $argv[1];
} else {
    $password = read_hidden('New admin password: ');
    $confirm = read_hidden('Confirm password:    ');
    if ($password !== $confirm) {
        fwrite(STDERR, "ERROR: passwords do not match\n");
        exit(1);
    }
}

if ($password === '') {
    fwrite(STDERR, "ERROR: password cannot be empty\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "ERROR: password must be at least 8 characters (got " . strlen($password) . "). The login throttle helps, but a weak password is still a weak password.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);

echo 'ADMIN_PASSWORD_HASH="' . $hash . '"' . "\n";
