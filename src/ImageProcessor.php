<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Decode an uploaded image, downscale if oversized, and re-encode as WebP.
 *
 * Privacy: re-encoding through GD strips ALL metadata (EXIF, GPS, color
 * profile, comments, vendor blobs). The output bytes are derived purely
 * from the pixel buffer — anything the source carried alongside is gone.
 *
 * Sizing: the body column is ~960px wide in the public theme, so we cap
 * the output at MAX_WIDTH (1600px) to give 2× retina headroom without
 * shipping huge files.
 */
final class ImageProcessor
{
    public const MAX_WIDTH = 1600;
    public const WEBP_QUALITY = 82;

    /**
     * Hard pixel-count ceiling. A truecolor GD buffer uses ~4 bytes/pixel,
     * so 40MP ≈ 160MB RAM during decode. Reject anything bigger upfront
     * rather than OOM-ing the FPM worker.
     */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Decode `$srcPath` (any GD-supported format), resize if wider than
     * MAX_WIDTH, and write a WebP file to `$destPath`. Returns the final
     * dimensions for the caller to log/return.
     *
     * @return array{width:int, height:int}
     * @throws RuntimeException if the source can't be decoded or the
     *                          destination can't be written.
     */
    public static function processToWebp(string $srcPath, string $destPath): array
    {
        $info = @getimagesize($srcPath);
        if ($info === false) {
            throw new RuntimeException('Not a recognized image file.');
        }
        [$srcW, $srcH, $type] = $info;

        if ($srcW * $srcH > self::MAX_PIXELS) {
            throw new RuntimeException(
                'Image too large (' . $srcW . '×' . $srcH . ' = '
                . number_format($srcW * $srcH) . ' pixels; max '
                . number_format(self::MAX_PIXELS) . ').'
            );
        }

        $img = self::decode($srcPath, $type);
        if ($img === null) {
            throw new RuntimeException('Unsupported image type.');
        }

        try {
            // Preserve transparency for PNG / WebP sources.
            imagepalettetotruecolor($img);
            imagealphablending($img, true);
            imagesavealpha($img, true);

            if ($srcW > self::MAX_WIDTH) {
                $scale = self::MAX_WIDTH / $srcW;
                $newW = self::MAX_WIDTH;
                $newH = (int) round($srcH * $scale);
                $resized = imagecreatetruecolor($newW, $newH);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
                imagedestroy($img);
                $img = $resized;
            } else {
                $newW = $srcW;
                $newH = $srcH;
            }

            if (!imagewebp($img, $destPath, self::WEBP_QUALITY)) {
                throw new RuntimeException('Failed to encode WebP output.');
            }
        } finally {
            imagedestroy($img);
        }

        return ['width' => $newW, 'height' => $newH];
    }

    /**
     * @return \GdImage|null
     */
    private static function decode(string $path, int $type): ?\GdImage
    {
        return match ($type) {
            IMAGETYPE_JPEG => @\imagecreatefromjpeg($path) ?: null,
            IMAGETYPE_PNG  => @\imagecreatefrompng($path)  ?: null,
            IMAGETYPE_WEBP => @\imagecreatefromwebp($path) ?: null,
            IMAGETYPE_GIF  => @\imagecreatefromgif($path)  ?: null,
            default        => null,
        };
    }
}
