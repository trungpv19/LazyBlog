<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads + writes content/about.md, a single optional file holding the
 * site owner's profile. Kept outside content/posts/ so it never appears
 * in the post index, feeds, sitemaps, or llms.txt indexes.
 *
 * Frontmatter shape:
 *   name:      string (required — if missing the page is treated as absent)
 *   callsign:  string?
 *   location:  string?
 *   status:    string?   (free-form, e.g. "ONLINE", "AWAY", "PORTABLE")
 *   avatar:    string?   (path or absolute URL)
 *   contacts:  list of { label, value, url? }
 *   stack:     list of strings (tech / tools / topics — free-form chips)
 *   currently: string?  (1–3 sentence "what I'm doing now")
 * Body: free-form markdown.
 */
final class AboutRepository
{
    public function __construct(private readonly string $contentDir)
    {
    }

    public function path(): string
    {
        return $this->contentDir . '/about.md';
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function get(): ?AboutPage
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }

        $raw = (string) file_get_contents($path);
        [$meta, $body] = FrontmatterParser::parse($raw);

        $name = trim((string) ($meta['name'] ?? ''));
        if ($name === '') {
            // Missing the only required field — treat as absent so the
            // public page 404s instead of rendering a broken HUD shell.
            return null;
        }

        return new AboutPage(
            name: $name,
            callsign: self::nullableString($meta, 'callsign'),
            location: self::nullableString($meta, 'location'),
            status: self::nullableString($meta, 'status'),
            avatar: self::nullableString($meta, 'avatar'),
            contacts: self::parseContacts($meta['contacts'] ?? null),
            stack: self::parseStringList($meta['stack'] ?? null),
            currently: self::nullableString($meta, 'currently'),
            bodyMarkdown: $body,
        );
    }

    public function save(AboutPage $page): void
    {
        if (!is_dir($this->contentDir)) {
            mkdir($this->contentDir, 0775, true);
        }

        $meta = ['name' => $page->name];
        if ($page->callsign !== null && $page->callsign !== '') {
            $meta['callsign'] = $page->callsign;
        }
        if ($page->location !== null && $page->location !== '') {
            $meta['location'] = $page->location;
        }
        if ($page->status !== null && $page->status !== '') {
            $meta['status'] = $page->status;
        }
        if ($page->avatar !== null && $page->avatar !== '') {
            $meta['avatar'] = $page->avatar;
        }
        if ($page->contacts !== []) {
            $meta['contacts'] = array_map(
                static function (array $c): array {
                    $entry = ['label' => $c['label'], 'value' => $c['value']];
                    if (($c['url'] ?? null) !== null && $c['url'] !== '') {
                        $entry['url'] = $c['url'];
                    }
                    return $entry;
                },
                $page->contacts,
            );
        }
        if ($page->stack !== []) {
            $meta['stack'] = array_values($page->stack);
        }
        if ($page->currently !== null && $page->currently !== '') {
            $meta['currently'] = $page->currently;
        }

        $yaml = Yaml::dump($meta, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $body = rtrim($page->bodyMarkdown, "\n") . "\n";
        $contents = "---\n{$yaml}---\n\n{$body}";

        FileWriter::writeAtomic($this->path(), $contents);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function nullableString(array $meta, string $key): ?string
    {
        if (!isset($meta[$key])) {
            return null;
        }
        $v = trim((string) $meta[$key]);
        return $v === '' ? null : $v;
    }

    /**
     * Drop non-string entries + empty strings from a YAML list. Used for
     * `stack:`, which expects a clean list<string> of free-form chips.
     *
     * @return list<string>
     */
    private static function parseStringList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item) && !is_int($item) && !is_float($item)) {
                continue;
            }
            $v = trim((string) $item);
            if ($v !== '') {
                $out[] = $v;
            }
        }
        return $out;
    }

    /**
     * Coerce frontmatter into a clean list of {label, value, url?}, dropping
     * entries missing label or value. URL is optional — when absent the view
     * renders the value as plain text instead of a link.
     *
     * @return list<array{label:string,value:string,url:?string}>
     */
    private static function parseContacts(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $label = trim((string) ($entry['label'] ?? ''));
            $value = trim((string) ($entry['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            $url = trim((string) ($entry['url'] ?? ''));
            $out[] = [
                'label' => $label,
                'value' => $value,
                'url' => $url !== '' ? $url : null,
            ];
        }
        return $out;
    }
}
