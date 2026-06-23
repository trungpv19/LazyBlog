<?php

declare(strict_types=1);

/**
 * Lightweight fixture-driven smoke test for GamificationCalculator.
 * Run: php scripts/test-gamification.php
 *
 * No PHPUnit dependency — the project doesn't ship a test framework yet.
 * Exit code 0 on success, 1 on any failed case.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\GamificationCalculator;

$tz = new DateTimeZone('Asia/Saigon');

/** @return list<array{date:string}> */
function posts(array $dates): array
{
    return array_map(static fn (string $d): array => ['date' => $d], $dates);
}

$cases = [
    [
        'name' => 'empty dataset',
        'now' => '2026-06-23 12:00:00',
        'posts' => [],
        'expect' => ['current' => 0, 'longest' => 0, 'atRisk' => false, 'hasAny' => false],
    ],
    [
        'name' => 'single post this week',
        'now' => '2026-06-23 12:00:00', // Tuesday, week 26
        'posts' => posts(['2026-06-22']),
        'expect' => ['current' => 1, 'longest' => 1, 'atRisk' => false, 'hasAny' => true],
    ],
    [
        'name' => '3 consecutive weeks ending this week',
        'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-08', '2026-06-15', '2026-06-22']),
        'expect' => ['current' => 3, 'longest' => 3, 'atRisk' => false],
    ],
    [
        'name' => 'streak ended last week, current week empty mid-week (at risk)',
        'now' => '2026-06-24 12:00:00', // Wednesday
        'posts' => posts(['2026-06-01', '2026-06-08', '2026-06-15']),
        'expect' => ['current' => 3, 'longest' => 3, 'atRisk' => true],
    ],
    [
        'name' => 'streak ended last week, current week empty, Sunday (broken)',
        'now' => '2026-06-28 23:00:00', // Sunday of current week
        'posts' => posts(['2026-06-01', '2026-06-08', '2026-06-15']),
        'expect' => ['current' => 0, 'longest' => 3, 'atRisk' => false],
    ],
    [
        'name' => 'two streaks 4 then 2 (with gap)',
        'now' => '2026-06-23 12:00:00',
        'posts' => posts([
            // 4-week streak: weeks 14, 15, 16, 17 (Apr)
            '2026-03-30', '2026-04-06', '2026-04-13', '2026-04-20',
            // gap
            // 2-week streak: weeks 25, 26 (Jun)
            '2026-06-15', '2026-06-22',
        ]),
        'expect' => ['current' => 2, 'longest' => 4],
    ],
    [
        'name' => 'year boundary (W52 of 2025 + W1 of 2026 consecutive)',
        'now' => '2026-01-10 12:00:00',
        'posts' => posts(['2025-12-22', '2025-12-29', '2026-01-05']),
        // W52 of 2025, W1 of 2026, W2 of 2026 all consecutive — but 2025 has 53 weeks per ISO calendar
        // Actually 2025: Dec 22 = W52, Dec 29 = W1 of 2026; 2026 starts at Mon Dec 29.
        'expect' => ['longest' => 3],
    ],
];

$pass = 0;
$fail = 0;

foreach ($cases as $case) {
    $now = new DateTimeImmutable($case['now'], $tz);
    $calc = new GamificationCalculator($tz, $now);
    $got = $calc->streak($case['posts']);

    $ok = true;
    $diffs = [];
    foreach ($case['expect'] as $key => $want) {
        if ($got[$key] !== $want) {
            $ok = false;
            $diffs[] = sprintf("%s: want %s, got %s", $key, var_export($want, true), var_export($got[$key], true));
        }
    }

    if ($ok) {
        $pass++;
        echo "  PASS: {$case['name']}\n";
    } else {
        $fail++;
        echo "  FAIL: {$case['name']}\n";
        foreach ($diffs as $d) {
            echo "        - {$d}\n";
        }
        echo "        full got: " . json_encode($got) . "\n";
    }
}

echo "\n", $pass, " passed, ", $fail, " failed\n";
exit($fail === 0 ? 0 : 1);
