<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Config;
use App\Csrf;
use App\Http;
use App\Post;
use App\PostRepository;
use App\SlugUtil;

/**
 * Admin CRUD: login, list, new, edit, save, delete, logout.
 *
 * Every state-changing handler is POST + CSRF-checked.
 * Every authed handler calls Auth::requireAuth() first.
 */
final class AdminController
{
    public function __construct(private readonly PostRepository $repo)
    {
    }

    // ----- Auth -----

    public function loginForm(): void
    {
        if (Auth::check()) {
            Http::redirect('/admin');
        }
        Http::render('admin/login', [
            'title' => 'Admin Login',
            'next' => isset($_GET['next']) ? (string) $_GET['next'] : '/admin',
            'error' => null,
        ]);
    }

    public function loginSubmit(): void
    {
        Csrf::requireValid();
        $password = (string) ($_POST['password'] ?? '');
        $next = (string) ($_POST['next'] ?? '/admin');

        if (!self::safeRedirectTarget($next)) {
            $next = '/admin';
        }

        if (Auth::attempt($password)) {
            Http::redirect($next);
        }

        http_response_code(401);
        Http::render('admin/login', [
            'title' => 'Admin Login',
            'next' => $next,
            'error' => 'Wrong password.',
        ]);
    }

    public function logout(): void
    {
        Csrf::requireValid();
        Auth::logout();
        Http::redirect('/admin/login');
    }

    // ----- List -----

    public function index(): void
    {
        Auth::requireAuth();

        Http::render('admin/list', [
            'title' => 'Admin',
            'posts' => $this->repo->all(),
            'flash' => self::consumeFlash(),
        ]);
    }

    // ----- New / Edit form -----

    public function newForm(): void
    {
        Auth::requireAuth();

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        Http::render('admin/edit', [
            'title' => 'New Post',
            'mode' => 'new',
            'post' => null,
            'originalFilename' => '',
            'formError' => null,
            'formValues' => [
                'date' => $today,
                'slug' => '',
                'title' => '',
                'author' => (string) Config::get('DEFAULT_AUTHOR', ''),
                'tags' => '',
                'draft' => false,
                'icon' => '',
                'summary' => '',
                'body' => '',
            ],
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    public function editForm(array $params): void
    {
        Auth::requireAuth();
        $slug = $params['slug'] ?? '';
        $post = $this->repo->bySlug($slug);
        if ($post === null) {
            http_response_code(404);
            Http::render('not-found', ['title' => '404 // NO SIGNAL']);
            return;
        }

        $originalFilename = $post->date . '-' . $post->slug . '.md';

        Http::render('admin/edit', [
            'title' => 'Edit: ' . $post->title,
            'mode' => 'edit',
            'post' => $post,
            'originalFilename' => $originalFilename,
            'formError' => null,
            'formValues' => [
                'date' => $post->date,
                'slug' => $post->slug,
                'title' => $post->title,
                'author' => $post->author ?? '',
                'tags' => implode(', ', $post->tags),
                'draft' => $post->draft,
                'icon' => $post->icon ?? '',
                'summary' => $post->summary ?? '',
                'body' => $post->bodyMarkdown,
            ],
        ]);
    }

    // ----- Save -----

    public function save(): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $values = self::readFormValues();
        $originalFilename = (string) ($_POST['original_filename'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'new');

        // Default slug from title when empty.
        if ($values['slug'] === '' && $values['title'] !== '') {
            $values['slug'] = SlugUtil::fromTitle($values['title']);
        }

        try {
            $post = self::buildPostFromForm($values);
            $this->repo->save($post, $originalFilename !== '' ? $originalFilename : null);
        } catch (\Throwable $e) {
            http_response_code(400);
            Http::render('admin/edit', [
                'title' => $mode === 'edit' ? 'Edit Post' : 'New Post',
                'mode' => $mode,
                'post' => null,
                'originalFilename' => $originalFilename,
                'formError' => $e->getMessage(),
                'formValues' => $values,
            ]);
            return;
        }

        self::setFlash("Saved: {$post->slug}");
        Http::redirect('/admin');
    }

    // ----- Delete -----

    /**
     * @param array<string,string> $params
     */
    public function delete(array $params): void
    {
        Auth::requireAuth();
        Csrf::requireValid();

        $slug = $params['slug'] ?? '';
        $ok = $this->repo->delete($slug);
        self::setFlash($ok ? "Deleted: {$slug}" : "Delete failed: {$slug}");
        Http::redirect('/admin');
    }

    // ----- Helpers -----

    /**
     * @return array{date:string,slug:string,title:string,author:string,tags:string,draft:bool,icon:string,summary:string,body:string}
     */
    private static function readFormValues(): array
    {
        return [
            'date' => trim((string) ($_POST['date'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'author' => trim((string) ($_POST['author'] ?? '')),
            'tags' => trim((string) ($_POST['tags'] ?? '')),
            'draft' => !empty($_POST['draft']),
            'icon' => trim((string) ($_POST['icon'] ?? '')),
            'summary' => trim((string) ($_POST['summary'] ?? '')),
            'body' => (string) ($_POST['body'] ?? ''),
        ];
    }

    /**
     * @param array{date:string,slug:string,title:string,author:string,tags:string,draft:bool,icon:string,summary:string,body:string} $v
     */
    private static function buildPostFromForm(array $v): Post
    {
        if ($v['title'] === '') {
            throw new \RuntimeException('Title is required.');
        }
        if ($v['slug'] === '') {
            throw new \RuntimeException('Slug is required.');
        }
        if (!SlugUtil::valid($v['slug'])) {
            throw new \RuntimeException("Invalid slug: only [a-z0-9-], max 80 chars.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['date'])) {
            throw new \RuntimeException('Date must be YYYY-MM-DD.');
        }

        /** @var list<string> $tags */
        $tags = [];
        foreach (explode(',', $v['tags']) as $raw) {
            $t = strtolower(trim($raw));
            $t = (string) preg_replace('/[^a-z0-9-]/', '', $t);
            if ($t !== '' && !in_array($t, $tags, true)) {
                $tags[] = $t;
            }
        }

        return new Post(
            slug: $v['slug'],
            title: $v['title'],
            date: $v['date'],
            tags: $tags,
            draft: $v['draft'],
            bodyMarkdown: $v['body'],
            icon: $v['icon'] !== '' ? $v['icon'] : null,
            summary: $v['summary'] !== '' ? $v['summary'] : null,
            author: $v['author'] !== '' ? $v['author'] : null,
        );
    }

    /**
     * Reject open-redirect targets — only allow relative paths starting with "/".
     */
    private static function safeRedirectTarget(string $url): bool
    {
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return false;
        }
        return true;
    }

    private static function setFlash(string $msg): void
    {
        Auth::start();
        $_SESSION['_flash'] = $msg;
    }

    private static function consumeFlash(): ?string
    {
        Auth::start();
        if (!empty($_SESSION['_flash'])) {
            $msg = (string) $_SESSION['_flash'];
            unset($_SESSION['_flash']);
            return $msg;
        }
        return null;
    }
}
