<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AboutRepository;
use App\Config;
use App\GamificationCalculator;
use App\Http;
use App\MarkdownRenderer;
use App\PostRepository;

/**
 * GET /about — render the operator profile page.
 *
 * 404 when content/about.md is missing or its frontmatter has no `name:` —
 * this is the explicit "page not configured" signal; the page never
 * degrades into a half-rendered HUD shell.
 *
 * Computes a small stats panel from PostRepository (post count, tag
 * count, series count, first/last TX, days online) so the about page
 * can show off activity without the admin maintaining the numbers.
 */
final class AboutController
{
    public function __construct(
        private readonly AboutRepository $repo,
        private readonly MarkdownRenderer $renderer,
        private readonly PostRepository $posts,
    ) {
    }

    public function show(): void
    {
        $about = $this->repo->get();
        if ($about === null) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $rendered = $this->renderer->render($about->bodyMarkdown);

        Http::render('about', [
            'title' => 'About — ' . $about->name,
            'about' => $about,
            'bodyHtml' => $rendered['html'],
            'stats' => $this->buildStats(),
        ]);
    }

    /**
     * Activity stats derived from the published post index + a peek at
     * `/proc/uptime` for the real server/container uptime. Cheap — runs
     * against the in-memory index cache the rest of the app uses; the
     * uptime read is a single tiny file. All null-safe.
     *
     * `streak` is computed lazily here too, keyed off the same published
     * index so we avoid a second filesystem walk.
     *
     * @return array{
     *   posts:int,tags:int,series:int,
     *   firstDate:?string,lastDate:?string,
     *   daysOnline:?int,serverUptime:?string,
     *   streak:array{current:int,longest:int,atRisk:bool,nextDeadline:string,hasAny:bool},
     *   badges:list<array{code:string,label:string,description:string,tier:string,current:int,target:int,unlocked:bool,unlockedAt:?string,isRecentUnlock:bool}>
     * }
     */
    private function buildStats(): array
    {
        $published = $this->posts->published();
        // Reduce to date-only keys so mixed `YYYY-MM-DD` / ISO datetime
        // entries display consistently and sort by calendar day, not by
        // string length.
        $dates = array_map(
            static fn (array $e): string => substr((string) $e['date'], 0, 10),
            $published,
        );
        sort($dates);
        $first = $dates[0] ?? null;
        $last = $dates === [] ? null : $dates[count($dates) - 1];
        $daysOnline = null;
        if ($first !== null) {
            $diff = (new \DateTimeImmutable('today'))
                ->diff(new \DateTimeImmutable($first))
                ->days;
            $daysOnline = $diff !== false ? (int) $diff : null;
        }

        $tz = new \DateTimeZone((string) Config::get('TIMEZONE', 'UTC'));
        $streakUnit = (string) Config::get('STREAK_UNIT', GamificationCalculator::UNIT_WEEK);
        $calc = new GamificationCalculator($tz, new \DateTimeImmutable('now', $tz), $streakUnit);
        $streak = $calc->streak($published);
        $badges = $calc->badges(
            $published,
            $this->posts->allSeries(),
            $this->posts->tagCounts(),
        );

        return [
            'posts' => count($published),
            'tags' => count($this->posts->allTags()),
            'series' => count($this->posts->allSeries()),
            'firstDate' => $first,
            'lastDate' => $last,
            'daysOnline' => $daysOnline,
            'serverUptime' => self::readServerUptime(),
            'streak' => $streak,
            'badges' => $badges,
        ];
    }

    /**
     * Format /proc/uptime as a Linux-style "Xd HH:MM" string. Returns
     * null on non-Linux hosts or when the file isn't readable (Windows,
     * sandboxed php-fpm, hardened containers, etc.) — caller falls back
     * to the blog-career uptime in that case.
     */
    private static function readServerUptime(): ?string
    {
        if (!is_readable('/proc/uptime')) {
            return null;
        }
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false || $raw === '') {
            return null;
        }
        $secs = (int) (float) strtok(trim($raw), ' ');
        if ($secs <= 0) {
            return null;
        }
        $days = intdiv($secs, 86400);
        $hours = intdiv($secs % 86400, 3600);
        $mins = intdiv($secs % 3600, 60);
        return sprintf('%dd %02d:%02d', $days, $hours, $mins);
    }
}
