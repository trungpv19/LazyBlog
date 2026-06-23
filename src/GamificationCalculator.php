<?php

declare(strict_types=1);

namespace App;

/**
 * Derives the operator-flex gamification stats (writing streak, badges)
 * from the published-post index. Pure: takes timestamps + scalar lists
 * in, returns arrays out — no filesystem, no DB, no caching.
 *
 * Streak unit is configurable via the `STREAK_UNIT` env (`day`, `week`,
 * `month`; default `week`). One published post per period keeps the
 * streak alive. The current period gets a grace window — empty current
 * period flags as "at risk" until the period actually ends — so a
 * mid-day or mid-week surface doesn't zero the streak out prematurely.
 */
final class GamificationCalculator
{
    public const UNIT_DAY = 'day';
    public const UNIT_WEEK = 'week';
    public const UNIT_MONTH = 'month';

    private const VALID_UNITS = [self::UNIT_DAY, self::UNIT_WEEK, self::UNIT_MONTH];

    private readonly string $unit;

    public function __construct(
        private readonly \DateTimeZone $tz,
        private readonly \DateTimeImmutable $now,
        ?string $unit = null,
        private readonly ?Badges\BadgeRegistry $badgeRegistry = null,
    ) {
        $resolved = $unit ?? self::UNIT_WEEK;
        $this->unit = in_array($resolved, self::VALID_UNITS, true) ? $resolved : self::UNIT_WEEK;
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

    public function unit(): string
    {
        return $this->unit;
    }

    /**
     * @param list<array{date:string,...}> $publishedPosts
     * @return array{current:int,longest:int,atRisk:bool,nextDeadline:string,hasAny:bool,unit:string}
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
                'unit' => $this->unit,
            ];
        }

        // Deduplicate posts by period key — multiple posts in one period
        // count as a single streak tick. The key format depends on unit
        // (day/week/month).
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
            $periodSet[$this->periodKey($dt)] = true;
        }
        $periods = array_keys($periodSet);
        sort($periods);

        $longest = $this->longestRun($periods);
        [$current, $atRisk] = $this->currentRun($periodSet);

        return [
            'current' => $current,
            'longest' => $longest,
            'atRisk' => $atRisk,
            'nextDeadline' => $this->endOfCurrentPeriod(),
            'hasAny' => true,
            'unit' => $this->unit,
        ];
    }

    /**
     * @param list<string> $sortedPeriods
     */
    private function longestRun(array $sortedPeriods): int
    {
        if ($sortedPeriods === []) {
            return 0;
        }
        $max = 1;
        $run = 1;
        $count = count($sortedPeriods);
        for ($i = 1; $i < $count; $i++) {
            if ($this->nextPeriodKey($sortedPeriods[$i - 1]) === $sortedPeriods[$i]) {
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
     * @param array<string,true> $periodSet
     * @return array{0:int,1:bool}  [current, atRisk]
     */
    private function currentRun(array $periodSet): array
    {
        $thisPeriod = $this->periodKey($this->now);
        $prevPeriod = $this->prevPeriodKey($thisPeriod);

        $hasThis = isset($periodSet[$thisPeriod]);
        $hasPrev = isset($periodSet[$prevPeriod]);

        if ($hasThis) {
            $anchor = $thisPeriod;
            $atRisk = false;
        } elseif ($hasPrev && !$this->currentPeriodFullyElapsed()) {
            // Current period empty but it isn't over yet — streak still
            // alive, just at risk until the operator publishes.
            $anchor = $prevPeriod;
            $atRisk = true;
        } else {
            return [0, false];
        }

        $count = 0;
        $cursor = $anchor;
        while (isset($periodSet[$cursor])) {
            $count++;
            $cursor = $this->prevPeriodKey($cursor);
        }
        return [$count, $atRisk];
    }

    /**
     * Map a datetime into its period key under the active unit.
     */
    private function periodKey(\DateTimeImmutable $dt): string
    {
        return match ($this->unit) {
            self::UNIT_DAY => $dt->format('Y-m-d'),
            self::UNIT_MONTH => $dt->format('Y-m'),
            default => $dt->format('o-\WW'),
        };
    }

    private function nextPeriodKey(string $key): string
    {
        return $this->shiftPeriodKey($key, +1);
    }

    private function prevPeriodKey(string $key): string
    {
        return $this->shiftPeriodKey($key, -1);
    }

    private function shiftPeriodKey(string $key, int $delta): string
    {
        $now = new \DateTimeImmutable('now', $this->tz);
        return match ($this->unit) {
            self::UNIT_DAY => (new \DateTimeImmutable($key, $this->tz))
                ->modify(($delta >= 0 ? '+' : '') . $delta . ' day')
                ->format('Y-m-d'),
            self::UNIT_MONTH => (new \DateTimeImmutable($key . '-01', $this->tz))
                ->modify(($delta >= 0 ? '+' : '') . $delta . ' month')
                ->format('Y-m'),
            default => (function () use ($key, $delta, $now): string {
                [$year, $week] = sscanf($key, '%d-W%d');
                return $now
                    ->setISODate((int) $year, ((int) $week) + $delta)
                    ->format('o-\WW');
            })(),
        };
    }

    /**
     * The "must publish by" deadline shown to the operator — last day of
     * the current period under the active unit.
     */
    private function endOfCurrentPeriod(): string
    {
        return match ($this->unit) {
            self::UNIT_DAY => $this->now->format('Y-m-d'),
            self::UNIT_MONTH => $this->now->format('Y-m-t'),
            default => $this->now
                ->modify('+' . (7 - (int) $this->now->format('N')) . ' days')
                ->format('Y-m-d'),
        };
    }

    /**
     * True only when the current period is past — i.e. the operator can
     * no longer rescue the streak by publishing now. Day units treat the
     * period as "always still live" because a calendar day only ends at
     * the moment we'd be re-evaluated for the next day anyway.
     */
    private function currentPeriodFullyElapsed(): bool
    {
        return match ($this->unit) {
            // Day: there's always still time within the calendar day
            // until midnight, at which point the period key itself moves.
            self::UNIT_DAY => false,
            // Week: ISO day 7 = Sunday — no future day in this week.
            self::UNIT_WEEK => (int) $this->now->format('N') >= 7,
            // Month: today is the last day-of-month.
            self::UNIT_MONTH => (int) $this->now->format('j') >= (int) $this->now->format('t'),
            default => false,
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

        $streak = $this->streak($publishedPosts);

        $ctx = [
            'posts' => $postsWithBody,
            'seriesList' => $seriesList,
            'tagCounts' => $tagCounts,
            // Unit-agnostic key (preferred). `longestStreakWeeks` kept as
            // an alias so existing `longest-streak-weeks` kind reads still
            // resolve under the new name.
            'longestStreak' => $streak['longest'],
            'longestStreakWeeks' => $streak['longest'],
            'streakUnit' => $this->unit,
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
