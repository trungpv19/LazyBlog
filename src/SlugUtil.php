<?php

declare(strict_types=1);

namespace App;

/**
 * Slug helpers: validation + Vietnamese-aware diacritic stripping.
 *
 * Files on disk follow `YYYY-MM-DD-{slug}.md`. Slugs must round-trip safely
 * through URLs and filesystem paths, so we limit to `[a-z0-9-]+`.
 */
final class SlugUtil
{
    public static function valid(string $slug): bool
    {
        return $slug !== ''
            && mb_strlen($slug) <= 80
            && preg_match('/^[a-z0-9-]+$/', $slug) === 1;
    }

    public static function fromTitle(string $title): string
    {
        $title = self::stripDiacritics($title);
        $title = strtolower($title);
        $title = (string) preg_replace('/[^a-z0-9]+/', '-', $title);
        $title = trim($title, '-');
        return mb_substr($title, 0, 80);
    }

    /**
     * Best-effort transliteration: Vietnamese + common Latin diacritics → ASCII.
     */
    private static function stripDiacritics(string $s): string
    {
        $map = [
            'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
            'ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
            'ỳ','ý','ỵ','ỷ','ỹ',
            'đ',
        ];
        $rep = [
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e',
            'i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u',
            'y','y','y','y','y',
            'd',
        ];
        $s = str_replace($map, $rep, $s);
        $s = str_replace(array_map('mb_strtoupper', $map), $rep, $s);
        return $s;
    }
}
