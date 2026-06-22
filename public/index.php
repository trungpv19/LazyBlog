<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

App\Config::boot();
date_default_timezone_set(App\Config::get('TIMEZONE', 'UTC'));

$repo = new App\PostRepository();
$renderer = new App\MarkdownRenderer();
$llms = new App\LlmsBuilder($repo, __DIR__ . '/../content');

$home = new App\Controllers\HomeController($repo);
$post = new App\Controllers\PostController($repo, $renderer);
$tag = new App\Controllers\TagController($repo);
$llmsCtl = new App\Controllers\LlmsController($llms);

$router = new App\Router();

// Most-specific patterns first.
$router->get('/posts/{slug}.md', fn (array $p) => $post->raw($p));
$router->get('/posts/{slug}', fn (array $p) => $post->show($p));
$router->get('/tags/{tag}', fn (array $p) => $tag->show($p));
$router->get('/llms.txt', fn () => $llmsCtl->index());
$router->get('/llms-full.txt', fn () => $llmsCtl->full());
$router->get('/', fn () => $home->index());

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
);
