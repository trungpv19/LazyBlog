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
        $q = trim((string) ($_GET['q'] ?? ''));
        $hits = $q !== '' ? $this->searcher->run($q) : [];

        Http::render('search', [
            'title' => $q !== '' ? 'Search // ' . $q : 'Search // Transmission Index',
            'q' => $q,
            'hits' => $hits,
            'fold' => fn (string $s): string => $this->searcher->fold($s),
        ]);
    }
}
