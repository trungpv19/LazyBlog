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
    // ───── weekly unit (default) ─────
    [
        'name' => '[week] empty dataset',
        'unit' => 'week',
        'now' => '2026-06-23 12:00:00',
        'posts' => [],
        'expect' => ['current' => 0, 'longest' => 0, 'atRisk' => false, 'hasAny' => false],
    ],
    [
        'name' => '[week] single post this week',
        'unit' => 'week',
        'now' => '2026-06-23 12:00:00', // Tuesday, week 26
        'posts' => posts(['2026-06-22']),
        'expect' => ['current' => 1, 'longest' => 1, 'atRisk' => false, 'hasAny' => true],
    ],
    [
        'name' => '[week] 3 consecutive weeks ending this week',
        'unit' => 'week',
        'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-08', '2026-06-15', '2026-06-22']),
        'expect' => ['current' => 3, 'longest' => 3, 'atRisk' => false],
    ],
    [
        'name' => '[week] streak ended last week, current week empty mid-week (at risk)',
        'unit' => 'week',
        'now' => '2026-06-24 12:00:00', // Wednesday
        'posts' => posts(['2026-06-01', '2026-06-08', '2026-06-15']),
        'expect' => ['current' => 3, 'longest' => 3, 'atRisk' => true],
    ],
    [
        'name' => '[week] streak ended last week, current week empty, Sunday (broken)',
        'unit' => 'week',
        'now' => '2026-06-28 23:00:00', // Sunday of current week
        'posts' => posts(['2026-06-01', '2026-06-08', '2026-06-15']),
        'expect' => ['current' => 0, 'longest' => 3, 'atRisk' => false],
    ],
    [
        'name' => '[week] two streaks 4 then 2 (with gap)',
        'unit' => 'week',
        'now' => '2026-06-23 12:00:00',
        'posts' => posts([
            '2026-03-30', '2026-04-06', '2026-04-13', '2026-04-20',
            '2026-06-15', '2026-06-22',
        ]),
        'expect' => ['current' => 2, 'longest' => 4],
    ],
    [
        'name' => '[week] year boundary (W52 of 2025 + W1 of 2026 consecutive)',
        'unit' => 'week',
        'now' => '2026-01-10 12:00:00',
        'posts' => posts(['2025-12-22', '2025-12-29', '2026-01-05']),
        'expect' => ['longest' => 3],
    ],

    // ───── daily unit ─────
    [
        'name' => '[day] empty dataset',
        'unit' => 'day',
        'now' => '2026-06-23 12:00:00',
        'posts' => [],
        'expect' => ['current' => 0, 'longest' => 0, 'hasAny' => false],
    ],
    [
        'name' => '[day] 4 consecutive days ending today',
        'unit' => 'day',
        'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-20', '2026-06-21', '2026-06-22', '2026-06-23']),
        'expect' => ['current' => 4, 'longest' => 4, 'atRisk' => false],
    ],
    [
        'name' => '[day] streak ended yesterday, today empty (at risk grace)',
        'unit' => 'day',
        'now' => '2026-06-23 18:00:00',
        'posts' => posts(['2026-06-20', '2026-06-21', '2026-06-22']),
        'expect' => ['current' => 3, 'longest' => 3, 'atRisk' => true],
    ],
    [
        'name' => '[day] missed 2 days ago, broken',
        'unit' => 'day',
        'now' => '2026-06-23 12:00:00',
        'posts' => posts(['2026-06-20', '2026-06-21']),
        'expect' => ['current' => 0, 'longest' => 2],
    ],

    // ───── monthly unit ─────
    [
        'name' => '[month] empty dataset',
        'unit' => 'month',
        'now' => '2026-06-15 12:00:00',
        'posts' => [],
        'expect' => ['current' => 0, 'longest' => 0, 'hasAny' => false],
    ],
    [
        'name' => '[month] 4 consecutive months ending this month',
        'unit' => 'month',
        'now' => '2026-06-15 12:00:00',
        'posts' => posts(['2026-03-10', '2026-04-15', '2026-05-20', '2026-06-05']),
        'expect' => ['current' => 4, 'longest' => 4, 'atRisk' => false],
    ],
    [
        'name' => '[month] streak ended last month, current empty mid-month (at risk)',
        'unit' => 'month',
        'now' => '2026-06-15 12:00:00', // 15th of June, plenty of time left
        'posts' => posts(['2026-04-10', '2026-05-15']),
        'expect' => ['current' => 2, 'longest' => 2, 'atRisk' => true],
    ],
    [
        'name' => '[month] last day of month with no post → broken',
        'unit' => 'month',
        'now' => '2026-06-30 23:00:00', // 30 June = end of month
        'posts' => posts(['2026-04-10', '2026-05-15']),
        'expect' => ['current' => 0, 'longest' => 2, 'atRisk' => false],
    ],
    [
        'name' => '[month] year boundary (Nov/Dec/Jan consecutive)',
        'unit' => 'month',
        'now' => '2026-02-15 12:00:00',
        'posts' => posts(['2025-11-10', '2025-12-10', '2026-01-10']),
        'expect' => ['longest' => 3],
    ],
];

$pass = 0;
$fail = 0;

foreach ($cases as $case) {
    $now = new DateTimeImmutable($case['now'], $tz);
    $calc = new GamificationCalculator($tz, $now, $case['unit'] ?? null);
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
