<?php

declare(strict_types=1);

namespace App;

/**
 * Immutable post value object — single markdown file mapped to typed fields.
 *
 * Body is kept as raw markdown; rendering happens lazily via MarkdownRenderer
 * so list views can skip the expensive parse step entirely.
 */
final class Post
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $date,
        public readonly array $tags,
        public readonly bool $draft,
        public readonly string $bodyMarkdown,
        public readonly ?string $icon = null,
        public readonly ?string $summary = null,
        public readonly ?string $author = null,
        public readonly ?string $image = null,
    ) {
    }

    /**
     * Pull the first `![alt](url)` URL out of the body. Used as a fallback
     * og:image source when the post has no frontmatter `image:` and no
     * site-wide SITE_OG_IMAGE — so social previews still get a thumbnail
     * the moment the post has any image at all.
     */
    public function firstBodyImage(): ?string
    {
        if (preg_match('/!\[[^\]]*\]\(([^)\s]+)/u', $this->bodyMarkdown, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * First ~200 chars of body with markdown punctuation stripped, used for
     * listings, RSS descriptions, and llms.txt entries.
     */
    public function excerpt(int $max = 200): string
    {
        if ($this->summary !== null && $this->summary !== '') {
            return $this->summary;
        }

        $plain = (string) preg_replace('/[`*_>#\-\[\]()]/', '', $this->bodyMarkdown);
        $plain = (string) preg_replace('/\s+/', ' ', $plain);
        $plain = trim($plain);

        if (mb_strlen($plain) <= $max) {
            return $plain;
        }

        return mb_substr($plain, 0, $max) . '…';
    }

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }

    public function rawUrl(): string
    {
        return '/posts/' . $this->slug . '.md';
    }

    public function pubDateRfc2822(): string
    {
        $tz = Config::get('TIMEZONE', 'UTC');
        try {
            $dt = new \DateTimeImmutable($this->date, new \DateTimeZone((string) $tz));
            return $dt->format(\DateTimeInterface::RFC2822);
        } catch (\Throwable) {
            return $this->date;
        }
    }
}
