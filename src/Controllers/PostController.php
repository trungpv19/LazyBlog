<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http;
use App\MarkdownRenderer;
use App\PostRepository;

final class PostController
{
    public function __construct(
        private readonly PostRepository $repo,
        private readonly MarkdownRenderer $renderer,
    ) {
    }

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);

        if ($post === null || $post->draft || $post->date > date('Y-m-d')) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $rendered = $this->renderer->render($post->bodyMarkdown);

        Http::render('post', [
            'title' => $post->title,
            'post' => $post,
            'body_html' => $rendered['html'],
            'toc' => $rendered['toc'],
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function raw(array $params): void
    {
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);

        if ($post === null || $post->draft || $post->date > date('Y-m-d')) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "404 not found\n";
            return;
        }

        $raw = $this->repo->rawMarkdownBySlug($slug);
        if ($raw === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "404 not found\n";
            return;
        }

        header('Content-Type: text/markdown; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $raw;
    }
}
