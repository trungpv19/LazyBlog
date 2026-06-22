<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Atomic file write helper.
 *
 * Strategy: write to a temp file in the SAME directory, then `rename()`.
 * `rename()` is atomic on POSIX filesystems as long as src + dst are on the
 * same filesystem (guaranteed by `tempnam($dir, ...)`). On a crash mid-write
 * the partial file is the temp file and can be cleaned up; the real path
 * still holds the previous version.
 */
final class FileWriter
{
    public static function writeAtomic(string $path, string $contents, int $mode = 0644): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            throw new RuntimeException("Directory does not exist: {$dir}");
        }
        if (!is_writable($dir)) {
            throw new RuntimeException("Directory not writable: {$dir}");
        }

        $tmp = tempnam($dir, '.lazyblog-');
        if ($tmp === false) {
            throw new RuntimeException("tempnam() failed in {$dir}");
        }

        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new RuntimeException("write to temp file failed: {$tmp}");
            }
            @chmod($tmp, $mode);
            if (!rename($tmp, $path)) {
                throw new RuntimeException("rename({$tmp}, {$path}) failed");
            }
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }
    }
}
