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
    ) {
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
}
