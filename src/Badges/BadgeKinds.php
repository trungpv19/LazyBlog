<?php

declare(strict_types=1);

namespace App\Badges;

/**
 * Registry of badge "kinds" — reusable compute templates that take a
 * params dict from JSON config and return the standard progress shape.
 *
 * Adding a brand-new pattern means writing one new kind executor here,
 * then anyone can declare badges of that pattern in `badges.json` by
 * setting `"kind": "your-new-kind"`. Existing kinds are intentionally
 * tight in scope so the params dict stays predictable.
 *
 * Executor signature:
 *   fn (array $params, array $ctx): array{current:int,target:int,unlocked:bool,unlockedAt:?string}
 *
 * `$ctx` is what GamificationCalculator passes in — see that class for
 * the full key list (posts, seriesList, tagCounts, longestStreakWeeks,
 * firstDate, todayDate, tz).
 */
final class BadgeKinds
{
    /**
     * @return array<string,\Closure>
     */
    public static function executors(): array
    {
        return [
            'post-count'           => self::postCount(),
            'longest-post-words'   => self::longestPostWords(),
            'series-min-parts'     => self::seriesMinParts(),
            'tag-count'            => self::tagCount(),
            'blog-age-days'        => self::blogAgeDays(),
            'longest-streak-weeks' => self::longestStreakWeeks(),
            'time-window'          => self::timeWindow(),
            'gap-days'             => self::gapDays(),
            'same-day-count'       => self::sameDayCount(),
            'anniversary'          => self::anniversary(),
            'date-suffix'          => self::dateSuffix(),
        ];
    }

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::executors());
    }

    /* ────────── executors ────────── */

    private static function postCount(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 1);
            $count = count($ctx['posts']);
            $unlocked = $count >= $threshold;
            $unlockedAt = null;
            if ($unlocked) {
                $dates = array_map(
                    static fn (array $p): string => substr((string) $p['date'], 0, 10),
                    $ctx['posts'],
                );
                sort($dates);
                $unlockedAt = $dates[$threshold - 1] ?? null;
            }
            return [
                'current' => min($count, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => $unlockedAt,
            ];
        };
    }

    private static function longestPostWords(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 5000);
            $bestWords = 0;
            $bestDate = null;
            foreach ($ctx['posts'] as $p) {
                $body = (string) ($p['bodyForWordCount'] ?? '');
                // `\S+` counts whitespace-separated tokens — locale safe
                // (works for Vietnamese, CJK with spaces, etc.) unlike
                // PHP's locale-bound str_word_count.
                $words = preg_match_all('/\S+/u', $body) ?: 0;
                if ($words > $bestWords) {
                    $bestWords = $words;
                    $bestDate = substr((string) $p['date'], 0, 10);
                }
            }
            $unlocked = $bestWords >= $threshold;
            return [
                'current' => min($bestWords, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => $unlocked ? $bestDate : null,
            ];
        };
    }

    private static function seriesMinParts(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 3);
            $best = 0;
            $bestUnlockDate = null;
            foreach ($ctx['seriesList'] as $s) {
                $count = (int) ($s['count'] ?? 0);
                if ($count > $best) {
                    $best = $count;
                    if ($count >= $threshold) {
                        $bestUnlockDate = substr((string) ($s['lastDate'] ?? ''), 0, 10);
                    }
                }
            }
            $unlocked = $best >= $threshold;
            return [
                'current' => min($best, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => $unlocked ? $bestUnlockDate : null,
            ];
        };
    }

    private static function tagCount(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 10);
            $max = 0;
            foreach ($ctx['tagCounts'] as $n) {
                if ((int) $n > $max) {
                    $max = (int) $n;
                }
            }
            $unlocked = $max >= $threshold;
            return [
                'current' => min($max, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => null,
            ];
        };
    }

    private static function blogAgeDays(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 365);
            if (empty($ctx['firstDate'])) {
                return ['current' => 0, 'target' => $threshold, 'unlocked' => false, 'unlockedAt' => null];
            }
            try {
                $first = new \DateTimeImmutable((string) $ctx['firstDate']);
                $today = new \DateTimeImmutable((string) $ctx['todayDate']);
            } catch (\Throwable) {
                return ['current' => 0, 'target' => $threshold, 'unlocked' => false, 'unlockedAt' => null];
            }
            $days = (int) $first->diff($today)->days;
            $unlocked = $days >= $threshold;
            return [
                'current' => min($days, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => $unlocked ? $first->modify("+{$threshold} days")->format('Y-m-d') : null,
            ];
        };
    }

    private static function longestStreakWeeks(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 12);
            $longest = (int) ($ctx['longestStreakWeeks'] ?? 0);
            $unlocked = $longest >= $threshold;
            return [
                'current' => min($longest, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => null,
            ];
        };
    }

    /**
     * Match posts published within an hour window [hourFrom, hourTo)
     * optionally restricted to a specific ISO day-of-week (1=Mon..7=Sun).
     * Only posts with an explicit time in frontmatter qualify, so the
     * midnight fallback for date-only posts can't trigger e.g. NIGHT-OWL.
     */
    private static function timeWindow(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $hourFrom = (int) ($params['hourFrom'] ?? 0);
            $hourTo = (int) ($params['hourTo'] ?? 24);
            $dow = isset($params['dayOfWeek']) ? (int) $params['dayOfWeek'] : null;
            $unlockedAt = null;
            foreach ($ctx['posts'] as $p) {
                if (empty($p['hasTime'])) {
                    continue;
                }
                try {
                    $dt = new \DateTimeImmutable((string) $p['date'], $ctx['tz']);
                } catch (\Throwable) {
                    continue;
                }
                if ($dow !== null && (int) $dt->format('N') !== $dow) {
                    continue;
                }
                $h = (int) $dt->format('G');
                if ($h >= $hourFrom && $h < $hourTo) {
                    $unlockedAt = $dt->format('Y-m-d');
                    break;
                }
            }
            return [
                'current' => $unlockedAt !== null ? 1 : 0,
                'target' => 1,
                'unlocked' => $unlockedAt !== null,
                'unlockedAt' => $unlockedAt,
            ];
        };
    }

    private static function gapDays(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 30);
            $days = [];
            foreach ($ctx['posts'] as $p) {
                $days[substr((string) $p['date'], 0, 10)] = true;
            }
            $days = array_keys($days);
            sort($days);
            $unlockedAt = null;
            $count = count($days);
            for ($i = 1; $i < $count; $i++) {
                try {
                    $prev = new \DateTimeImmutable($days[$i - 1]);
                    $curr = new \DateTimeImmutable($days[$i]);
                } catch (\Throwable) {
                    continue;
                }
                if ((int) $prev->diff($curr)->days >= $threshold) {
                    $unlockedAt = $days[$i];
                    break;
                }
            }
            return [
                'current' => $unlockedAt !== null ? 1 : 0,
                'target' => 1,
                'unlocked' => $unlockedAt !== null,
                'unlockedAt' => $unlockedAt,
            ];
        };
    }

    private static function sameDayCount(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 2);
            $counts = [];
            foreach ($ctx['posts'] as $p) {
                $d = substr((string) $p['date'], 0, 10);
                $counts[$d] = ($counts[$d] ?? 0) + 1;
            }
            $unlockedAt = null;
            foreach ($counts as $d => $n) {
                if ($n >= $threshold) {
                    if ($unlockedAt === null || $d < $unlockedAt) {
                        $unlockedAt = (string) $d;
                    }
                }
            }
            return [
                'current' => $unlockedAt !== null ? $threshold : 0,
                'target' => $threshold,
                'unlocked' => $unlockedAt !== null,
                'unlockedAt' => $unlockedAt,
            ];
        };
    }

    private static function anniversary(): \Closure
    {
        return static function (array $params, array $ctx): array {
            if (empty($ctx['firstDate'])) {
                return ['current' => 0, 'target' => 1, 'unlocked' => false, 'unlockedAt' => null];
            }
            try {
                $first = new \DateTimeImmutable((string) $ctx['firstDate']);
            } catch (\Throwable) {
                return ['current' => 0, 'target' => 1, 'unlocked' => false, 'unlockedAt' => null];
            }
            $birthdayMd = $first->format('m-d');
            $unlockedAt = null;
            foreach ($ctx['posts'] as $p) {
                $d = substr((string) $p['date'], 0, 10);
                if ($d === $ctx['firstDate']) {
                    continue;
                }
                try {
                    $dt = new \DateTimeImmutable($d);
                } catch (\Throwable) {
                    continue;
                }
                if ($dt->format('m-d') === $birthdayMd) {
                    $unlockedAt = $d;
                    break;
                }
            }
            return [
                'current' => $unlockedAt !== null ? 1 : 0,
                'target' => 1,
                'unlocked' => $unlockedAt !== null,
                'unlockedAt' => $unlockedAt,
            ];
        };
    }

    /**
     * Match posts whose date string ends with a fixed `-MM-DD` suffix.
     * Handles year-agnostic dates (Jan 1, Feb 29, etc.).
     */
    private static function dateSuffix(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $mmDd = (string) ($params['mmDd'] ?? '');
            if (!preg_match('/^-\d{2}-\d{2}$/', $mmDd)) {
                return ['current' => 0, 'target' => 1, 'unlocked' => false, 'unlockedAt' => null];
            }
            $unlockedAt = null;
            foreach ($ctx['posts'] as $p) {
                $d = substr((string) $p['date'], 0, 10);
                if (str_ends_with($d, $mmDd)) {
                    $unlockedAt = $d;
                    break;
                }
            }
            return [
                'current' => $unlockedAt !== null ? 1 : 0,
                'target' => 1,
                'unlocked' => $unlockedAt !== null,
                'unlockedAt' => $unlockedAt,
            ];
        };
    }
}
