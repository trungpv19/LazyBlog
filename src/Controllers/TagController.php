<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http;
use App\PostRepository;

final class TagController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $tag = strtolower(trim($params['tag'] ?? ''));
        if ($tag === '') {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $paged = \App\PostRepository::paginate($this->repo->byTag($tag), $page, 10);

        Http::render('tag', [
            'title' => '#' . $tag,
            'tag' => $tag,
            'posts' => $paged['posts'],
            'page' => $paged['page'],
            'totalPages' => $paged['totalPages'],
            'total' => $paged['total'],
            'pageBaseUrl' => '/tags/' . rawurlencode($tag),
        ]);
    }
}
