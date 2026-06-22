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

        Http::render('tag', [
            'title' => '#' . $tag,
            'tag' => $tag,
            'posts' => $this->repo->byTag($tag),
        ]);
    }
}
