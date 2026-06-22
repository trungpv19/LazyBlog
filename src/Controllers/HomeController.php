<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Http;
use App\PostRepository;

final class HomeController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $paged = PostRepository::paginate($this->repo->published(), $page, 10);

        Http::render('home', [
            'title' => (string) Config::get('SITE_TITLE'),
            'posts' => $paged['posts'],
            'tags' => $this->repo->allTags(),
            'page' => $paged['page'],
            'totalPages' => $paged['totalPages'],
            'total' => $paged['total'],
            'pageBaseUrl' => '/',
        ]);
    }
}
