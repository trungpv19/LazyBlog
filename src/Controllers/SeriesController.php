<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http;
use App\PostRepository;

/**
 * GET /series/{slug} — index page for a series. Lists all posts with
 * `series: {slug}` in their frontmatter, ordered by `part:` then date.
 * 404 if no posts use the slug.
 */
final class SeriesController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $slug = strtolower(trim($params['slug'] ?? ''));
        if ($slug === '') {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $posts = $this->repo->bySeries($slug);
        if ($posts === []) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        // Pull a human-readable title from the first matching post — most
        // writers don't set a separate series title, so derive from the
        // slug as a fallback (kebab → Title Case).
        $displayTitle = self::titleFromSlug($slug);

        Http::render('series', [
            'title' => 'Series // ' . $displayTitle,
            'seriesSlug' => $slug,
            'seriesTitle' => $displayTitle,
            'posts' => $posts,
        ]);
    }

    private static function titleFromSlug(string $slug): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }
}
