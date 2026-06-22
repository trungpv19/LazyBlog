<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http;
use App\Searcher;

final class SearchController
{
    public function __construct(private readonly Searcher $searcher)
    {
    }

    public function show(): void
    {
        // Cap query at 256 chars — past that it's never a real search query,
        // and unbounded length lets an attacker burn CPU in fold()/strtr().
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 256);
        $hits = $q !== '' ? $this->searcher->run($q) : [];

        Http::render('search', [
            'title' => $q !== '' ? 'Search // ' . $q : 'Search // Transmission Index',
            'q' => $q,
            'hits' => $hits,
            'fold' => fn (string $s): string => $this->searcher->fold($s),
        ]);
    }
}
