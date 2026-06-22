<?php

declare(strict_types=1);

namespace App;

/**
 * Generates llmstxt.org-style indexes for AI agent consumption.
 *
 * - llms.txt: site description + one-line summary per published post + tags index
 * - llms-full.txt: every published post concatenated as raw markdown
 *
 * Both files are cached under content/ and invalidated by PostRepository
 * whenever any post is saved or deleted.
 */
final class LlmsBuilder
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly string $contentDir,
    ) {
    }

    public function indexPath(): string
    {
        return $this->contentDir . '/.llms.txt';
    }

    public function fullPath(): string
    {
        return $this->contentDir . '/.llms-full.txt';
    }

    public function buildIndex(): string
    {
        $siteTitle = (string) Config::get('SITE_TITLE');
        $siteUrl = rtrim((string) Config::get('SITE_URL'), '/');
        $siteDesc = (string) Config::get('SITE_DESCRIPTION', '');

        $out = "# {$siteTitle}\n\n";
        if ($siteDesc !== '') {
            $out .= "> {$siteDesc}\n\n";
        }

        $out .= "## Posts\n\n";
        foreach ($this->repo->published() as $entry) {
            $summary = $entry['summary'] ?? '';
            if ($summary === '') {
                // Lightweight excerpt: read body and trim. Acceptable cost here
                // — this builder runs only on save, not per request.
                $post = $this->repo->bySlug($entry['slug']);
                $summary = $post ? $post->excerpt(120) : '';
            }
            $url = "{$siteUrl}/posts/{$entry['slug']}.md";
            $out .= "- [{$entry['title']}]({$url}): {$summary}\n";
        }

        $tags = $this->repo->allTags();
        if ($tags !== []) {
            $out .= "\n## Tags\n\n";
            foreach ($tags as $tag) {
                $out .= "- {$tag}: {$siteUrl}/tags/{$tag}\n";
            }
        }

        return $out;
    }

    public function buildFull(): string
    {
        $siteTitle = (string) Config::get('SITE_TITLE');
        $out = "# {$siteTitle}\n\n";

        $parts = [];
        foreach ($this->repo->published() as $entry) {
            $raw = $this->repo->rawMarkdownBySlug($entry['slug']);
            if ($raw === null) {
                continue;
            }
            $parts[] = $raw;
        }

        return $out . implode("\n\n---\n\n", $parts) . "\n";
    }

    public function readOrBuildIndex(): string
    {
        $path = $this->indexPath();
        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }
        $built = $this->buildIndex();
        @file_put_contents($path, $built, LOCK_EX);
        return $built;
    }

    public function readOrBuildFull(): string
    {
        $path = $this->fullPath();
        if (is_file($path)) {
            $cached = @file_get_contents($path);
            if ($cached !== false) {
                return $cached;
            }
        }
        $built = $this->buildFull();
        @file_put_contents($path, $built, LOCK_EX);
        return $built;
    }
}
