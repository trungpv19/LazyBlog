<?php

declare(strict_types=1);

namespace App\Badges;

/**
 * Static catalogue of operator-flex badges. Each entry is a small assoc
 * array with a `compute` closure so the unlock + progress logic lives
 * next to the definition — easier to read than a giant switch.
 *
 * Compute signature:
 *   fn (array $ctx): array{current:int, target:int, unlocked:bool, unlockedAt:?string}
 *
 * `$ctx` shape passed by GamificationCalculator::badges():
 *   posts              list<array{date:string,bodyForWordCount:string,hasTime:bool,tags:list<string>,...}>
 *   seriesList         list<array{slug:string,count:int,firstDate:string,lastDate:string,title:string}>
 *   tagCounts          array<string,int>
 *   longestStreakWeeks int
 *   firstDate          ?string  (date-only)
 *   todayDate          string   (date-only)
 *   tz                 \DateTimeZone
 */
final class BadgeCatalog
{
    /**
     * Volume tier — always rendered, locked entries show progress.
     *
     * @return list<array{code:string,label:string,description:string,compute:\Closure}>
     */
    public static function all(): array
    {
        return [
            self::firstTx(),
            self::broadcast(10),
            self::broadcast(50),
            self::broadcast(100),
            self::longForm(),
            self::seriesInit(),
            self::tagSpecialist(),
            self::oneYearOnline(),
            self::streakMilestone(12, 'IRON-STREAK', 'IRON STREAK'),
            self::streakMilestone(26, 'MARATHON', 'MARATHON'),
        ];
    }

    private static function firstTx(): array
    {
        return [
            'code' => 'FIRST-TX',
            'label' => 'FIRST TX',
            'description' => 'Send the first transmission',
            'compute' => static function (array $ctx): array {
                $count = count($ctx['posts']);
                $unlocked = $count >= 1;
                $unlockedAt = null;
                if ($unlocked) {
                    $dates = array_map(static fn (array $p): string => substr((string) $p['date'], 0, 10), $ctx['posts']);
                    sort($dates);
                    $unlockedAt = $dates[0];
                }
                return [
                    'current' => min($count, 1),
                    'target' => 1,
                    'unlocked' => $unlocked,
                    'unlockedAt' => $unlockedAt,
                ];
            },
        ];
    }

    private static function broadcast(int $threshold): array
    {
        return [
            'code' => "BROADCAST-X{$threshold}",
            'label' => "BROADCAST ×{$threshold}",
            'description' => "{$threshold} published transmissions",
            'compute' => static function (array $ctx) use ($threshold): array {
                $count = count($ctx['posts']);
                $unlocked = $count >= $threshold;
                $unlockedAt = null;
                if ($unlocked) {
                    $dates = array_map(static fn (array $p): string => substr((string) $p['date'], 0, 10), $ctx['posts']);
                    sort($dates);
                    $unlockedAt = $dates[$threshold - 1] ?? null;
                }
                return [
                    'current' => min($count, $threshold),
                    'target' => $threshold,
                    'unlocked' => $unlocked,
                    'unlockedAt' => $unlockedAt,
                ];
            },
        ];
    }

    private static function longForm(): array
    {
        return [
            'code' => 'LONG-FORM',
            'label' => 'LONG-FORM OPERATOR',
            'description' => '1 transmission ≥ 5000 words',
            'compute' => static function (array $ctx): array {
                $target = 5000;
                $bestWords = 0;
                $bestDate = null;
                foreach ($ctx['posts'] as $p) {
                    $body = (string) ($p['bodyForWordCount'] ?? '');
                    // `\S+` over the body counts whitespace-separated tokens.
                    // Works for Vietnamese (no breakable letters lost) and
                    // is independent of locale, unlike str_word_count.
                    $words = preg_match_all('/\S+/u', $body) ?: 0;
                    if ($words > $bestWords) {
                        $bestWords = $words;
                        $bestDate = substr((string) $p['date'], 0, 10);
                    }
                }
                $unlocked = $bestWords >= $target;
                return [
                    'current' => min($bestWords, $target),
                    'target' => $target,
                    'unlocked' => $unlocked,
                    'unlockedAt' => $unlocked ? $bestDate : null,
                ];
            },
        ];
    }

    private static function seriesInit(): array
    {
        return [
            'code' => 'SERIES-INIT',
            'label' => 'SERIES INITIATED',
            'description' => 'First series with ≥3 published parts',
            'compute' => static function (array $ctx): array {
                $target = 3;
                $best = 0;
                $bestUnlockDate = null;
                foreach ($ctx['seriesList'] as $s) {
                    $count = (int) ($s['count'] ?? 0);
                    if ($count > $best) {
                        $best = $count;
                        if ($count >= $target) {
                            $bestUnlockDate = substr((string) ($s['lastDate'] ?? ''), 0, 10);
                        }
                    }
                }
                $unlocked = $best >= $target;
                return [
                    'current' => min($best, $target),
                    'target' => $target,
                    'unlocked' => $unlocked,
                    'unlockedAt' => $unlocked ? $bestUnlockDate : null,
                ];
            },
        ];
    }

    private static function tagSpecialist(): array
    {
        return [
            'code' => 'TAG-SPECIALIST',
            'label' => 'TAG SPECIALIST',
            'description' => '≥10 posts in a single tag',
            'compute' => static function (array $ctx): array {
                $target = 10;
                $max = 0;
                foreach ($ctx['tagCounts'] as $n) {
                    if ((int) $n > $max) {
                        $max = (int) $n;
                    }
                }
                $unlocked = $max >= $target;
                return [
                    'current' => min($max, $target),
                    'target' => $target,
                    'unlocked' => $unlocked,
                    'unlockedAt' => null,
                ];
            },
        ];
    }

    private static function oneYearOnline(): array
    {
        return [
            'code' => 'ONE-YEAR-ONLINE',
            'label' => 'ONE YEAR ONLINE',
            'description' => 'Blog age ≥ 365 days',
            'compute' => static function (array $ctx): array {
                $target = 365;
                if (empty($ctx['firstDate'])) {
                    return ['current' => 0, 'target' => $target, 'unlocked' => false, 'unlockedAt' => null];
                }
                try {
                    $first = new \DateTimeImmutable((string) $ctx['firstDate']);
                    $today = new \DateTimeImmutable((string) $ctx['todayDate']);
                } catch (\Throwable) {
                    return ['current' => 0, 'target' => $target, 'unlocked' => false, 'unlockedAt' => null];
                }
                $days = (int) $first->diff($today)->days;
                $unlocked = $days >= $target;
                $unlockedAt = $unlocked ? $first->modify("+{$target} days")->format('Y-m-d') : null;
                return [
                    'current' => min($days, $target),
                    'target' => $target,
                    'unlocked' => $unlocked,
                    'unlockedAt' => $unlockedAt,
                ];
            },
        ];
    }

    private static function streakMilestone(int $threshold, string $code, string $label): array
    {
        return [
            'code' => $code,
            'label' => $label . " ({$threshold}w)",
            'description' => "Longest weekly streak ≥ {$threshold} weeks",
            'compute' => static function (array $ctx) use ($threshold): array {
                $longest = (int) ($ctx['longestStreakWeeks'] ?? 0);
                $unlocked = $longest >= $threshold;
                return [
                    'current' => min($longest, $threshold),
                    'target' => $threshold,
                    'unlocked' => $unlocked,
                    'unlockedAt' => null,
                ];
            },
        ];
    }
}
