<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Yaml\Yaml;

/**
 * Splits `---\nyaml\n---\nbody` markdown files into (metadata, body) pairs.
 *
 * Tolerates missing frontmatter blocks — treats the whole file as body when
 * no `---` delimiters are present, returning an empty metadata array.
 */
final class FrontmatterParser
{
    /**
     * @return array{0:array<string,mixed>,1:string}
     */
    public static function parse(string $raw): array
    {
        if (!str_starts_with($raw, "---\n") && !str_starts_with($raw, "---\r\n")) {
            return [[], $raw];
        }

        // Normalize line endings to LF before splitting on the closing fence.
        $normalized = str_replace("\r\n", "\n", $raw);

        if (!preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $normalized, $m)) {
            return [[], $raw];
        }

        try {
            /** @var array<string,mixed>|null $meta */
            $meta = Yaml::parse($m[1]);
        } catch (\Throwable) {
            $meta = [];
        }

        return [is_array($meta) ? $meta : [], $m[2] ?? ''];
    }
}
