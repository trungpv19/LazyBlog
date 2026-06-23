<?php

declare(strict_types=1);

namespace App\Badges;

/**
 * Reads the declarative badge catalogue from JSON and runs each entry
 * through the matching kind executor in BadgeKinds.
 *
 * Single source of truth: `content/badges.json`. The file ships with
 * the repo (allowlisted in .gitignore) so fresh installs get a sensible
 * default catalogue; edit in place to customise.
 *
 * Entries with an unknown `kind` are skipped silently and logged via
 * error_log so an admin can spot a typo without blowing up /about.
 * Missing file → empty catalogue (no badges panel rendered).
 */
final class BadgeRegistry
{
    /** @var array<string,\Closure> */
    private readonly array $kinds;

    public function __construct(private readonly string $configPath)
    {
        $this->kinds = BadgeKinds::executors();
    }

    /**
     * @return array{volume: list<array<string,mixed>>, hidden: list<array<string,mixed>>}
     */
    public function load(): array
    {
        if (!is_file($this->configPath)) {
            return ['volume' => [], 'hidden' => []];
        }
        $raw = @file_get_contents($this->configPath);
        if ($raw === false) {
            return ['volume' => [], 'hidden' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            error_log("LazyBlog: badge config at {$this->configPath} is not a JSON object");
            return ['volume' => [], 'hidden' => []];
        }
        return [
            'volume' => is_array($decoded['volume'] ?? null) ? array_values($decoded['volume']) : [],
            'hidden' => is_array($decoded['hidden'] ?? null) ? array_values($decoded['hidden']) : [],
        ];
    }

    /**
     * Run all volume badges; locked entries are kept for "progress" UI.
     *
     * @param array<string,mixed> $ctx
     * @return list<array<string,mixed>>
     */
    public function evaluateVolume(array $ctx): array
    {
        return $this->evaluateTier($this->load()['volume'], $ctx, 'volume', includeLocked: true);
    }

    /**
     * Run all hidden badges; only unlocked entries are returned so
     * locked codes never reach the rendered HTML.
     *
     * @param array<string,mixed> $ctx
     * @return list<array<string,mixed>>
     */
    public function evaluateHidden(array $ctx): array
    {
        return $this->evaluateTier($this->load()['hidden'], $ctx, 'hidden', includeLocked: false);
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed> $ctx
     * @return list<array<string,mixed>>
     */
    private function evaluateTier(array $entries, array $ctx, string $tier, bool $includeLocked): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $code = (string) ($entry['code'] ?? '');
            $kind = (string) ($entry['kind'] ?? '');
            if ($code === '' || $kind === '') {
                continue;
            }
            if (!isset($this->kinds[$kind])) {
                error_log("LazyBlog: badge '{$code}' has unknown kind '{$kind}'");
                continue;
            }
            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            $progress = ($this->kinds[$kind])($params, $ctx);
            if (!$includeLocked && empty($progress['unlocked'])) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'label' => (string) ($entry['label'] ?? $code),
                'description' => (string) ($entry['description'] ?? ''),
                'tier' => $tier,
            ] + $progress;
        }
        return $out;
    }
}
