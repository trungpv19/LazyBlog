<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http;
use App\PostRepository;
use DateTimeImmutable;

/**
 * Archive page — GitHub-style heatmap of posting activity + chronological
 * list grouped by year. Range auto-compresses to (first post month → today)
 * so a new blog doesn't render months of empty cells.
 */
final class ArchiveController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    public function show(): void
    {
        $posts = $this->repo->published();
        // Index posts by date for O(1) lookup while walking the calendar.
        // Key on the date part only so ISO datetime posts still bucket
        // alongside same-day legacy date-only posts.
        $byDate = [];
        foreach ($posts as $p) {
            $key = substr((string) $p['date'], 0, 10);
            $byDate[$key][] = $p;
        }

        $heatmap = $this->buildHeatmap($byDate);

        // Reverse-chronological list grouped by year for the section below
        // the heatmap. Posts are already sorted newest-first by the repo.
        $byYear = [];
        foreach ($posts as $p) {
            $byYear[substr($p['date'], 0, 4)][] = $p;
        }

        Http::render('archive', [
            'title' => 'Archive // Transmission Log',
            'posts' => $posts,
            'byYear' => $byYear,
            'heatmap' => $heatmap,
            'total' => count($posts),
            'siteTitle' => (string) Config::get('SITE_TITLE'),
        ]);
    }

    /**
     * Build a week-column heatmap from the first post's Monday to this week's
     * Sunday. Each week is a list of 7 cells (Mon..Sun).
     *
     * @param array<string,list<array<string,mixed>>> $byDate posts keyed by 'YYYY-MM-DD'
     * @return array{
     *   weeks: list<list<array{date:string,count:int,posts:list<array<string,mixed>>,inRange:bool}>>,
     *   monthLabels: array<int,string>,
     *   maxCount: int,
     *   firstDate: string,
     *   lastDate: string
     * }
     */
    private function buildHeatmap(array $byDate): array
    {
        $today = new DateTimeImmutable('today');

        if (empty($byDate)) {
            // No posts yet — render an empty 4-week grid so the layout still
            // shows something instead of collapsing.
            $firstDate = $today->modify('-3 weeks');
        } else {
            $earliest = min(array_keys($byDate));
            $firstDate = new DateTimeImmutable($earliest);
        }

        // Snap to the Monday of the first post's week so each week-column is
        // aligned. PHP 'N' returns 1=Mon..7=Sun.
        $startMonday = $firstDate->modify('-' . ((int) $firstDate->format('N') - 1) . ' days');
        // Snap end to the Sunday of this week so the last column is complete.
        $endSunday = $today->modify('+' . (7 - (int) $today->format('N')) . ' days');

        $weeks = [];
        $monthLabels = [];
        $maxCount = 0;
        $cursor = $startMonday;
        $weekIndex = 0;
        $lastMonth = '';

        while ($cursor <= $endSunday) {
            $week = [];
            $weekStartMonth = $cursor->format('M');
            // Only label the column when the month changes — avoids one label
            // per week and matches the GitHub pattern.
            if ($weekStartMonth !== $lastMonth && $cursor->format('j') <= 7) {
                $monthLabels[$weekIndex] = $weekStartMonth;
                $lastMonth = $weekStartMonth;
            }

            for ($d = 0; $d < 7; $d++) {
                $date = $cursor->format('Y-m-d');
                $cellPosts = $byDate[$date] ?? [];
                $count = count($cellPosts);
                $maxCount = max($maxCount, $count);
                $week[] = [
                    'date' => $date,
                    'count' => $count,
                    'posts' => $cellPosts,
                    'inRange' => $cursor >= $firstDate && $cursor <= $today,
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
            $weekIndex++;
        }

        return [
            'weeks' => $weeks,
            'monthLabels' => $monthLabels,
            'maxCount' => $maxCount,
            'firstDate' => $firstDate->format('Y-m-d'),
            'lastDate' => $today->format('Y-m-d'),
        ];
    }
}
