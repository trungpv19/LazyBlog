<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

App\Config::boot();
date_default_timezone_set(App\Config::get('TIMEZONE', 'UTC'));

// -----------------------------------------------------------------------------
// Global defense-in-depth headers — Caddy sets these in prod via Caddyfile,
// but this catches dev `php -S`, Apache via .htaccess, and any future
// nginx setups too. Cheap to set on every request.
// -----------------------------------------------------------------------------
header_remove('X-Powered-By');                                  // hide PHP version
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    // 'unsafe-inline' is required by the pre-paint theme script in layout.php
    // and the inline scrollspy/back-to-top/copy-button scripts. EasyMDE loads
    // from jsdelivr. No external script CDNs beyond that are allowed.
    . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
    . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
    . "font-src 'self' https://fonts.gstatic.com data:; "
    . "img-src 'self' data: https:; "
    . "connect-src 'self'; "
    . "frame-ancestors 'self'; "
    . "base-uri 'self'; "
    . "form-action 'self';"
);

// Start the session BEFORE any output so layout.php's Auth::check() (used by
// the `[ ADMIN ]` header button, edit-link on posts, etc.) doesn't trigger
// "headers already sent" warnings on public routes.
App\Auth::start();

$repo = new App\PostRepository();
$renderer = new App\MarkdownRenderer();
$llms = new App\LlmsBuilder($repo, __DIR__ . '/../content');
$feed = new App\FeedBuilder($repo, $renderer, __DIR__ . '/../content');

$home = new App\Controllers\HomeController($repo);
$post = new App\Controllers\PostController($repo, $renderer);
$tag = new App\Controllers\TagController($repo);
$llmsCtl = new App\Controllers\LlmsController($llms);
$feedCtl = new App\Controllers\FeedController($feed);
$admin = new App\Controllers\AdminController($repo);

$router = new App\Router();

// Most-specific patterns first.
// Admin (auth-gated inside the controller).
$router->get('/admin/login', fn () => $admin->loginForm());
$router->post('/admin/login', fn () => $admin->loginSubmit());
$router->post('/admin/logout', fn () => $admin->logout());
$router->get('/admin/new', fn () => $admin->newForm());
$router->get('/admin/edit/{slug}', fn (array $p) => $admin->editForm($p));
$router->post('/admin/save', fn () => $admin->save());
$router->post('/admin/delete/{slug}', fn (array $p) => $admin->delete($p));
$router->post('/admin/preview', fn () => $admin->preview());
$router->get('/admin', fn () => $admin->index());

// Public.
$router->get('/posts/{slug}.md', fn (array $p) => $post->raw($p));
$router->get('/posts/{slug}', fn (array $p) => $post->show($p));
$router->get('/tags/{tag}', fn (array $p) => $tag->show($p));
$router->get('/llms.txt', fn () => $llmsCtl->index());
$router->get('/llms-full.txt', fn () => $llmsCtl->full());
$router->get('/feed.xml', fn () => $feedCtl->show());
$router->get('/', fn () => $home->index());

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $_SERVER['REQUEST_URI'] ?? '/',
);
