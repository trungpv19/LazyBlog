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
            'body-pattern-count'   => self::bodyPatternCount(),
            'series-min-parts'     => self::seriesMinParts(),
            'series-count'         => self::seriesCount(),
            'tag-count'            => self::tagCount(),
            'blog-age-days'        => self::blogAgeDays(),
            // Threshold semantic depends on STREAK_UNIT env (day/week/month).
            // Old alias `longest-streak-weeks` kept so older custom configs
            // don't break after the rename.
            'longest-streak'       => self::longestStreak(),
            'longest-streak-weeks' => self::longestStreak(),
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

    /**
     * Count occurrences of a named markdown element pattern inside a
     * single post body — image, fenced code block, external link, or
     * blockquote — and unlock when any post crosses the threshold.
     *
     * `params.pattern` selects which element to count:
     *   - `image`         — `![alt](url)` figures
     *   - `code-block`    — triple-backtick fenced blocks
     *   - `external-link` — `[text](http(s)://...)` Markdown links
     *   - `blockquote`    — lines starting with `> `
     *
     * Custom patterns can be added by extending the switch below; each
     * one is a fixed regex against the raw markdown body, so the kind
     * stays predictable and JSON entries don't smuggle arbitrary code.
     */
    private static function bodyPatternCount(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 5);
            $pattern = (string) ($params['pattern'] ?? '');
            $regex = match ($pattern) {
                'image' => '/!\[[^\]]*\]\([^)\s]+/u',
                'code-block' => '/```/u',
                'external-link' => '/(?<!!)\[[^\]]+\]\(https?:\/\/[^)\s]+/u',
                'blockquote' => '/^>\s/um',
                default => null,
            };
            if ($regex === null) {
                error_log("LazyBlog: body-pattern-count unknown pattern '{$pattern}'");
                return ['current' => 0, 'target' => $threshold, 'unlocked' => false, 'unlockedAt' => null];
            }
            // Code-block fences come in pairs (open + close); divide so
            // "≥3 code blocks" actually means three real blocks.
            $divisor = $pattern === 'code-block' ? 2 : 1;
            $bestCount = 0;
            $bestDate = null;
            foreach ($ctx['posts'] as $p) {
                $body = (string) ($p['bodyForWordCount'] ?? '');
                $matches = preg_match_all($regex, $body) ?: 0;
                $count = (int) ($matches / $divisor);
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestDate = substr((string) $p['date'], 0, 10);
                }
            }
            $unlocked = $bestCount >= $threshold;
            return [
                'current' => min($bestCount, $threshold),
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

    /**
     * Distinct-series count across the catalogue. Counterpart to
     * `series-min-parts` (which inspects how many parts the deepest
     * single series has) — this kind asks "how many series have you
     * even started?", regardless of how long any one runs.
     *
     * Unlock date = newest `lastDate` among the series at the moment
     * the threshold tipped, so the badge surfaces the day the Nth
     * series became visible.
     */
    private static function seriesCount(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 3);
            $count = count($ctx['seriesList']);
            $unlocked = $count >= $threshold;
            $unlockedAt = null;
            if ($unlocked) {
                // The series listing is already sorted by lastDate desc
                // (see PostRepository::allSeries) but the Nth-most-recent
                // entry is the one that crossed the threshold — that's
                // the one at index ($threshold - 1) counting from the
                // start of the chronologically-newest run. The list is
                // newest-first, so index threshold-1 is the badge's
                // unlock date.
                $lastDates = array_column($ctx['seriesList'], 'lastDate');
                $unlockedAt = isset($lastDates[$threshold - 1])
                    ? substr((string) $lastDates[$threshold - 1], 0, 10)
                    : null;
            }
            return [
                'current' => min($count, $threshold),
                'target' => $threshold,
                'unlocked' => $unlocked,
                'unlockedAt' => $unlockedAt,
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

    private static function longestStreak(): \Closure
    {
        return static function (array $params, array $ctx): array {
            $threshold = (int) ($params['threshold'] ?? 12);
            $unit = (string) ($params['unit'] ?? 'week');
            // Calculator pre-computes longest run per unit referenced
            // anywhere in the catalogue. Lookup is O(1); missing unit
            // (typo in JSON) falls back to 0 so the badge stays locked
            // instead of crashing.
            $longest = (int) ($ctx['longestStreakByUnit'][$unit] ?? 0);
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
