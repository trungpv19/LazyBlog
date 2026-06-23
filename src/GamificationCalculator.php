<?php

declare(strict_types=1);

namespace App;

/**
 * Derives the operator-flex gamification stats (writing streak, badges)
 * from the published-post index. Pure: takes timestamps + scalar lists
 * in, returns arrays out — no filesystem, no DB, no caching.
 *
 * Streak rule is weekly (ISO week, ≥1 published post per week). A
 * Monday-to-Sunday week with no post breaks the streak; the current
 * week is given a grace period until Sunday so an empty mid-week
 * surface flags as "at risk" rather than zeroing out.
 */
final class GamificationCalculator
{
    public function __construct(
        private readonly \DateTimeZone $tz,
        private readonly \DateTimeImmutable $now,
        private readonly ?Badges\BadgeRegistry $badgeRegistry = null,
    ) {
    }

    /**
     * Resolve the badge registry, defaulting to the bundled JSON config
     * + the optional user override at `content/badges.json`. Allows tests
     * to inject a custom registry without forcing every caller to wire
     * it explicitly.
     */
    private function registry(): Badges\BadgeRegistry
    {
        if ($this->badgeRegistry !== null) {
            return $this->badgeRegistry;
        }
        $rootDir = dirname(__DIR__);
        return new Badges\BadgeRegistry(
            userPath: $rootDir . '/content/badges.json',
            defaultsPath: $rootDir . '/src/Badges/badges.json',
        );
    }

    /**
     * @param list<array{date:string,...}> $publishedPosts
     * @return array{current:int,longest:int,atRisk:bool,nextDeadline:string,hasAny:bool}
     */
    public function streak(array $publishedPosts): array
    {
        if ($publishedPosts === []) {
            return [
                'current' => 0,
                'longest' => 0,
                'atRisk' => false,
                'nextDeadline' => '',
                'hasAny' => false,
            ];
        }

        // Deduplicate posts by ISO week key — multiple posts in one week
        // count as a single streak tick.
        $weekSet = [];
        foreach ($publishedPosts as $p) {
            $dateOnly = substr((string) $p['date'], 0, 10);
            if ($dateOnly === '') {
                continue;
            }
            try {
                $dt = new \DateTimeImmutable($dateOnly, $this->tz);
            } catch (\Throwable) {
                continue;
            }
            $weekSet[$dt->format('o-\WW')] = true;
        }
        $weeks = array_keys($weekSet);
        sort($weeks);

        $longest = $this->longestRun($weeks);
        [$current, $atRisk] = $this->currentRun($weekSet);

        return [
            'current' => $current,
            'longest' => $longest,
            'atRisk' => $atRisk,
            'nextDeadline' => $this->endOfCurrentWeek(),
            'hasAny' => true,
        ];
    }

    /**
     * @param list<string> $sortedWeeks
     */
    private function longestRun(array $sortedWeeks): int
    {
        if ($sortedWeeks === []) {
            return 0;
        }
        $max = 1;
        $run = 1;
        $count = count($sortedWeeks);
        for ($i = 1; $i < $count; $i++) {
            if ($this->nextWeekKey($sortedWeeks[$i - 1]) === $sortedWeeks[$i]) {
                $run++;
                if ($run > $max) {
                    $max = $run;
                }
            } else {
                $run = 1;
            }
        }
        return $max;
    }

    /**
     * @param array<string,true> $weekSet
     * @return array{0:int,1:bool}  [current, atRisk]
     */
    private function currentRun(array $weekSet): array
    {
        $thisWeek = $this->now->format('o-\WW');
        $prevWeek = $this->prevWeekKey($thisWeek);

        $hasThisWeek = isset($weekSet[$thisWeek]);
        $hasPrevWeek = isset($weekSet[$prevWeek]);

        if ($hasThisWeek) {
            $anchor = $thisWeek;
            $atRisk = false;
        } elseif ($hasPrevWeek && (int) $this->now->format('N') < 7) {
            // Current week empty but Sunday hasn't passed yet —
            // streak still alive, just at risk.
            $anchor = $prevWeek;
            $atRisk = true;
        } else {
            return [0, false];
        }

        $count = 0;
        $cursor = $anchor;
        while (isset($weekSet[$cursor])) {
            $count++;
            $cursor = $this->prevWeekKey($cursor);
        }
        return [$count, $atRisk];
    }

    private function nextWeekKey(string $key): string
    {
        [$year, $week] = sscanf($key, '%d-W%d');
        return (new \DateTimeImmutable('now', $this->tz))
            ->setISODate((int) $year, ((int) $week) + 1)
            ->format('o-\WW');
    }

    private function prevWeekKey(string $key): string
    {
        [$year, $week] = sscanf($key, '%d-W%d');
        return (new \DateTimeImmutable('now', $this->tz))
            ->setISODate((int) $year, ((int) $week) - 1)
            ->format('o-\WW');
    }

    /**
     * Sunday (ISO day 7) of the current week — the deadline the operator
     * must publish by to keep the streak alive.
     */
    private function endOfCurrentWeek(): string
    {
        $daysToSunday = 7 - (int) $this->now->format('N');
        return $this->now->modify("+{$daysToSunday} days")->format('Y-m-d');
    }

    /**
     * Run the volume badge catalogue against the published-post set and
     * supporting indices. Each badge entry comes back enriched with
     * current/target progress + an `unlocked` flag + the date it became
     * available, sorted unlocked-first (newest first), then locked
     * closest-to-target first.
     *
     * @param list<array{date:string,tags:list<string>,file:string,...}> $publishedPosts
     * @param list<array{slug:string,count:int,firstDate:string,lastDate:string,title:string}> $seriesList
     * @param array<string,int> $tagCounts
     * @return list<array{
     *   code:string,label:string,description:string,tier:string,
     *   current:int,target:int,unlocked:bool,unlockedAt:?string,
     *   isRecentUnlock:bool
     * }>
     */
    public function badges(array $publishedPosts, array $seriesList, array $tagCounts): array
    {
        // Lazy body load for LONG-FORM word count. The index doesn't carry
        // post bodies (listings don't need them), so we re-read here. For a
        // 200-post blog this is ~5–10ms — acceptable for a one-shot page.
        $postsWithBody = array_map(static function (array $p): array {
            $path = (string) ($p['file'] ?? '');
            $body = '';
            if ($path !== '' && is_file($path)) {
                $raw = (string) @file_get_contents($path);
                if ($raw !== '') {
                    [, $body] = FrontmatterParser::parse($raw);
                }
            }
            $hasTime = preg_match('/\d{2}:\d{2}/', (string) $p['date']) === 1;
            return $p + ['bodyForWordCount' => $body, 'hasTime' => $hasTime];
        }, $publishedPosts);

        $first = null;
        if ($publishedPosts !== []) {
            $dates = array_map(
                static fn (array $p): string => substr((string) $p['date'], 0, 10),
                $publishedPosts,
            );
            sort($dates);
            $first = $dates[0];
        }

        $streak = $this->streak($publishedPosts);

        $ctx = [
            'posts' => $postsWithBody,
            'seriesList' => $seriesList,
            'tagCounts' => $tagCounts,
            'longestStreakWeeks' => $streak['longest'],
            'firstDate' => $first,
            'todayDate' => $this->now->format('Y-m-d'),
            'tz' => $this->tz,
        ];

        $registry = $this->registry();
        // Volume tier always rendered (locked entries surface N/M progress).
        // Hidden tier filters to unlocked entries inside the registry so
        // locked codes never make it into the HTML response.
        $results = array_merge(
            $registry->evaluateVolume($ctx),
            $registry->evaluateHidden($ctx),
        );

        // Sort: unlocked first (newest unlockedAt first), then locked by
        // smallest remaining gap so "almost there" entries surface up.
        usort($results, static function (array $a, array $b): int {
            if ($a['unlocked'] !== $b['unlocked']) {
                return $b['unlocked'] <=> $a['unlocked'];
            }
            if ($a['unlocked']) {
                return strcmp((string) $b['unlockedAt'], (string) $a['unlockedAt']);
            }
            $aGap = $a['target'] - $a['current'];
            $bGap = $b['target'] - $b['current'];
            return $aGap <=> $bGap;
        });

        // Flag the single most-recently-unlocked entry so the view can
        // pulse a subtle phosphor glow on it. Tier-agnostic — picks the
        // freshest unlock across volume + hidden.
        $maxDate = '';
        $maxIdx = null;
        foreach ($results as $idx => $b) {
            if (empty($b['unlocked']) || empty($b['unlockedAt'])) {
                continue;
            }
            if ((string) $b['unlockedAt'] > $maxDate) {
                $maxDate = (string) $b['unlockedAt'];
                $maxIdx = $idx;
            }
        }
        foreach ($results as $idx => &$entry) {
            $entry['isRecentUnlock'] = ($idx === $maxIdx);
        }
        unset($entry);

        return $results;
    }
}
