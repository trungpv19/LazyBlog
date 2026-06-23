<?php

declare(strict_types=1);

namespace App;

/**
 * Derives the operator-flex gamification stats (badges) from the
 * published-post index. Pure: takes timestamps + scalar lists in,
 * returns arrays out — no filesystem, no DB.
 *
 * Streak units are NOT global — each `longest-streak` badge in
 * content/badges.json declares its own unit (day/week/month/year).
 * `longestStreakForUnit()` computes the longest consecutive run under
 * a given unit; results are memoised per unit since one render typically
 * needs the same unit for multiple thresholds (SPARK / IRON / MARATHON
 * all on `week`, for example).
 */
final class GamificationCalculator
{
    public const UNIT_DAY = 'day';
    public const UNIT_WEEK = 'week';
    public const UNIT_MONTH = 'month';
    public const UNIT_YEAR = 'year';

    private const VALID_UNITS = [self::UNIT_DAY, self::UNIT_WEEK, self::UNIT_MONTH, self::UNIT_YEAR];

    /** @var array<string,int> cache: unit → longest run */
    private array $longestCache = [];

    public function __construct(
        private readonly \DateTimeZone $tz,
        private readonly \DateTimeImmutable $now,
        private readonly ?Badges\BadgeRegistry $badgeRegistry = null,
    ) {
    }

    /**
     * Resolve the badge registry. Single source of truth lives at
     * `content/badges.json`; tests can inject a custom registry through
     * the constructor.
     */
    private function registry(): Badges\BadgeRegistry
    {
        if ($this->badgeRegistry !== null) {
            return $this->badgeRegistry;
        }
        $rootDir = dirname(__DIR__);
        return new Badges\BadgeRegistry($rootDir . '/content/badges.json');
    }

    public static function normalizeUnit(string $unit): string
    {
        return in_array($unit, self::VALID_UNITS, true) ? $unit : self::UNIT_WEEK;
    }

    /**
     * Longest consecutive run of periods (under the given unit) that
     * contain at least one published post. `posts` should already be
     * filtered to non-draft entries on or before today; this method
     * doesn't re-filter.
     *
     * Memoised on `$unit` — subsequent calls with the same unit inside
     * one calculator instance return the cached value.
     *
     * @param list<array{date:string,...}> $publishedPosts
     */
    public function longestStreakForUnit(string $unit, array $publishedPosts): int
    {
        $unit = self::normalizeUnit($unit);
        if (array_key_exists($unit, $this->longestCache)) {
            return $this->longestCache[$unit];
        }
        if ($publishedPosts === []) {
            return $this->longestCache[$unit] = 0;
        }

        $periodSet = [];
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
            $periodSet[self::periodKey($dt, $unit)] = true;
        }
        $periods = array_keys($periodSet);
        sort($periods);

        return $this->longestCache[$unit] = $this->longestRun($periods, $unit);
    }

    /**
     * @param list<int|string> $sortedPeriods  (numeric-string keys like "2026"
     *   come back from array_keys() coerced to int — accept both shapes)
     */
    private function longestRun(array $sortedPeriods, string $unit): int
    {
        if ($sortedPeriods === []) {
            return 0;
        }
        $max = 1;
        $run = 1;
        $count = count($sortedPeriods);
        for ($i = 1; $i < $count; $i++) {
            $prev = (string) $sortedPeriods[$i - 1];
            $curr = (string) $sortedPeriods[$i];
            if (self::nextPeriodKey($prev, $unit, $this->tz) === $curr) {
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
     * Period key for a given datetime under the given unit.
     */
    public static function periodKey(\DateTimeImmutable $dt, string $unit): string
    {
        return match (self::normalizeUnit($unit)) {
            self::UNIT_DAY => $dt->format('Y-m-d'),
            self::UNIT_MONTH => $dt->format('Y-m'),
            self::UNIT_YEAR => $dt->format('Y'),
            default => $dt->format('o-\WW'),
        };
    }

    /**
     * Next period key in sequence. Internal use — only called from
     * `longestRun()` walking sorted keys.
     */
    private static function nextPeriodKey(string $key, string $unit, \DateTimeZone $tz): string
    {
        $unit = self::normalizeUnit($unit);
        return match ($unit) {
            self::UNIT_DAY => (new \DateTimeImmutable($key, $tz))
                ->modify('+1 day')->format('Y-m-d'),
            self::UNIT_MONTH => (new \DateTimeImmutable($key . '-01', $tz))
                ->modify('+1 month')->format('Y-m'),
            self::UNIT_YEAR => (string) (((int) $key) + 1),
            default => (function () use ($key, $tz): string {
                [$year, $week] = sscanf($key, '%d-W%d');
                return (new \DateTimeImmutable('now', $tz))
                    ->setISODate((int) $year, ((int) $week) + 1)
                    ->format('o-\WW');
            })(),
        };
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

        $registry = $this->registry();
        // Pre-compute longest streak for every unit referenced by a
        // `longest-streak` entry in the catalogue. This way each kind
        // invocation is a O(1) cache hit instead of a full O(n) rescan.
        $catalogue = $registry->load();
        $streakUnits = [];
        foreach (array_merge($catalogue['volume'], $catalogue['hidden']) as $entry) {
            if (($entry['kind'] ?? '') === 'longest-streak') {
                $u = self::normalizeUnit((string) ($entry['params']['unit'] ?? self::UNIT_WEEK));
                $streakUnits[$u] = true;
            }
        }
        $longestStreakByUnit = [];
        foreach (array_keys($streakUnits) as $u) {
            $longestStreakByUnit[$u] = $this->longestStreakForUnit($u, $publishedPosts);
        }

        $ctx = [
            'posts' => $postsWithBody,
            'seriesList' => $seriesList,
            'tagCounts' => $tagCounts,
            'longestStreakByUnit' => $longestStreakByUnit,
            'firstDate' => $first,
            'todayDate' => $this->now->format('Y-m-d'),
            'tz' => $this->tz,
        ];

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
