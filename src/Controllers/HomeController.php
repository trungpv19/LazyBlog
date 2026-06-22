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
        Http::render('home', [
            'title' => (string) Config::get('SITE_TITLE'),
            'posts' => $this->repo->published(),
            'tags' => $this->repo->allTags(),
        ]);
    }
}
