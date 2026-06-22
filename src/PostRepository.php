<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Reads/writes posts as markdown files under content/posts/.
 *
 * Maintains a JSON index (content/.index.json) of frontmatter-only entries
 * so listing pages can avoid touching every body. Index is rebuilt lazily
 * when missing or when any `.md` mtime is newer than the index mtime.
 */
final class PostRepository
{
    private string $contentDir;
    private string $postsDir;
    private string $indexPath;

    public function __construct(?string $contentDir = null)
    {
        $this->contentDir = $contentDir ?? (__DIR__ . '/../content');
        $this->postsDir = $this->contentDir . '/posts';
        $this->indexPath = $this->contentDir . '/.index.json';
    }

    public function postsDir(): string
    {
        return $this->postsDir;
    }

    /**
     * @return list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}>
     */
    public function all(): array
    {
        if ($this->indexStale()) {
            $this->rebuildIndex();
        }

        $raw = @file_get_contents($this->indexPath);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}>
     */
    public function published(): array
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        return array_values(array_filter(
            $this->all(),
            static fn (array $e): bool => !$e['draft'] && $e['date'] <= $today,
        ));
    }

    /**
     * @return list<array{slug:string,title:string,date:string,tags:list<string>,draft:bool,icon:?string,summary:?string,file:string,mtime:int}>
     */
    public function byTag(string $tag): array
    {
        return array_values(array_filter(
            $this->published(),
            static fn (array $e): bool => in_array($tag, $e['tags'], true),
        ));
    }

    public function bySlug(string $slug): ?Post
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                return $this->loadFromFile($entry['file']);
            }
        }
        return null;
    }

    public function rawMarkdownBySlug(string $slug): ?string
    {
        foreach ($this->all() as $entry) {
            if ($entry['slug'] === $slug) {
                $raw = @file_get_contents($entry['file']);
                return $raw === false ? null : $raw;
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    public function allTags(): array
    {
        $tags = [];
        foreach ($this->published() as $entry) {
            foreach ($entry['tags'] as $t) {
                $tags[$t] = true;
            }
        }
        $list = array_keys($tags);
        sort($list);
        return $list;
    }

    public function invalidateCaches(): void
    {
        @unlink($this->indexPath);
        @unlink($this->contentDir . '/.llms.txt');
        @unlink($this->contentDir . '/.llms-full.txt');
        @unlink($this->contentDir . '/.feed.xml');
    }

    private function indexStale(): bool
    {
        if (!is_file($this->indexPath)) {
            return true;
        }
        $indexMtime = (int) filemtime($this->indexPath);
        foreach ($this->scanPostFiles() as $file) {
            if (filemtime($file) > $indexMtime) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private function scanPostFiles(): array
    {
        if (!is_dir($this->postsDir)) {
            return [];
        }
        $files = glob($this->postsDir . '/*.md') ?: [];
        sort($files);
        return $files;
    }

    public function rebuildIndex(): void
    {
        $entries = [];
        foreach ($this->scanPostFiles() as $file) {
            $raw = file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            [$meta, $body] = FrontmatterParser::parse($raw);

            $basename = basename($file, '.md');
            // Filename pattern: YYYY-MM-DD-slug
            if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $basename, $m)) {
                $dateFromName = $m[1];
                $slugFromName = $m[2];
            } else {
                $dateFromName = '';
                $slugFromName = $basename;
            }

            /** @var list<string> $tags */
            $tags = [];
            if (isset($meta['tags']) && is_array($meta['tags'])) {
                $tags = array_values(array_filter(array_map(
                    static fn (mixed $t): string => is_string($t) ? strtolower(trim($t)) : '',
                    $meta['tags'],
                )));
            }

            $entries[] = [
                'slug' => is_string($meta['slug'] ?? null) && $meta['slug'] !== ''
                    ? (string) $meta['slug']
                    : $slugFromName,
                'title' => (string) ($meta['title'] ?? $slugFromName),
                'date' => self::normalizeDate($meta['date'] ?? null, $dateFromName),
                'tags' => $tags,
                'draft' => (bool) ($meta['draft'] ?? false),
                'icon' => isset($meta['icon']) ? (string) $meta['icon'] : null,
                'summary' => isset($meta['summary']) ? (string) $meta['summary'] : null,
                'author' => self::resolveAuthor($meta['author'] ?? null),
                'file' => $file,
                'mtime' => (int) filemtime($file),
            ];

            // Mark $body as used — body is intentionally NOT cached in the
            // index file. Listing pages only need frontmatter.
            unset($body);
        }

        // Sort by date desc, title asc as tiebreaker.
        usort($entries, static function (array $a, array $b): int {
            $c = strcmp($b['date'], $a['date']);
            return $c !== 0 ? $c : strcmp($a['title'], $b['title']);
        });

        if (!is_dir($this->contentDir)) {
            mkdir($this->contentDir, 0775, true);
        }

        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode index');
        }
        file_put_contents($this->indexPath, $json, LOCK_EX);
    }

    private function loadFromFile(string $file): ?Post
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        [$meta, $body] = FrontmatterParser::parse($raw);

        $basename = basename($file, '.md');
        if (preg_match('/^(\d{4}-\d{2}-\d{2})-(.+)$/', $basename, $m)) {
            $dateFromName = $m[1];
            $slugFromName = $m[2];
        } else {
            $dateFromName = '';
            $slugFromName = $basename;
        }

        /** @var list<string> $tags */
        $tags = [];
        if (isset($meta['tags']) && is_array($meta['tags'])) {
            $tags = array_values(array_filter(array_map(
                static fn (mixed $t): string => is_string($t) ? strtolower(trim($t)) : '',
                $meta['tags'],
            )));
        }

        return new Post(
            slug: is_string($meta['slug'] ?? null) && $meta['slug'] !== '' ? (string) $meta['slug'] : $slugFromName,
            title: (string) ($meta['title'] ?? $slugFromName),
            date: self::normalizeDate($meta['date'] ?? null, $dateFromName),
            tags: $tags,
            draft: (bool) ($meta['draft'] ?? false),
            bodyMarkdown: $body,
            icon: isset($meta['icon']) ? (string) $meta['icon'] : null,
            summary: isset($meta['summary']) ? (string) $meta['summary'] : null,
            author: self::resolveAuthor($meta['author'] ?? null),
        );
    }

    /**
     * Per-post `author:` wins; otherwise fall back to env DEFAULT_AUTHOR;
     * otherwise null (view will hide the field).
     */
    private static function resolveAuthor(mixed $raw): ?string
    {
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        $default = Config::get('DEFAULT_AUTHOR', '');
        return ($default !== null && $default !== '') ? $default : null;
    }

    /**
     * Symfony YAML parses unquoted `2026-06-22` as a Unix int timestamp.
     * Coerce any sensible representation back to `YYYY-MM-DD`.
     */
    private static function normalizeDate(mixed $raw, string $fallback): string
    {
        if (is_int($raw)) {
            return date('Y-m-d', $raw);
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        if (is_string($raw) && $raw !== '') {
            return $raw;
        }
        return $fallback;
    }
}
