# System Architecture

LazyBlog is a flat-file PHP blog. No database, no message queue, no
build step. Everything runs in a single Caddy + php-fpm container pair.

## High-level diagram

```
                          ┌──────────────────────────────────────┐
                          │            Caddy (TLS, 443)          │
                          │  ─ asset cache headers                │
                          │  ─ security headers                   │
                          │  ─ dotfile blocking                   │
                          │  ─ optional rate-limit /admin/login   │
                          └──────────────┬───────────────────────┘
                                         │ fastcgi unix socket
                                         ▼
                          ┌──────────────────────────────────────┐
                          │            php-fpm 8.2                │
                          │            (lazyblog user)            │
                          └──────────────┬───────────────────────┘
                                         │ index.php
                                         ▼
                          ┌──────────────────────────────────────┐
                          │   Router   →   Controllers           │
                          │                                       │
                          │   home  ─┐                            │
                          │   post  ─┤   ┌──────────────────┐    │
                          │   tag   ─┼──►│ PostRepository   │    │
                          │   admin ─┘   │ MarkdownRenderer │    │
                          │   feed       │ FeedBuilder      │    │
                          │   llms       │ LlmsBuilder      │    │
                          └──────────────┴──────────┬───────┘    │
                                                    │
                                                    ▼
                          ┌──────────────────────────────────────┐
                          │      content/  (flat files)          │
                          │                                       │
                          │   posts/2026-06-22-slug.md  ← source  │
                          │   .index.json               ← cache   │
                          │   .llms.txt                 ← cache   │
                          │   .llms-full.txt            ← cache   │
                          │   .feed.xml                 ← cache   │
                          └──────────────────────────────────────┘
```

## Request lifecycle

### Public read (`GET /posts/{slug}`)

```
1. Caddy receives request → strips proxy headers → fastcgi to php-fpm
2. public/index.php:
   - Composer autoload
   - Dotenv loads .env
   - Config::boot()  asserts required env vars
   - Auth::start()   opens session (cookie-only, strict-mode)
   - Global security headers + CSP emitted
   - Router::dispatch
3. PostController::show($slug)
   - PostRepository::bySlug($slug)
     ├─ indexStale()?  rebuildIndex() → writes .index.json + invalidates .llms*.txt + .feed.xml
     └─ file_get_contents on the matched .md
   - MarkdownRenderer::render($body)
     ├─ preprocessStandaloneImages → insert blank lines around ![alt](url)-only lines
     ├─ preprocessAdmonitions  → ::: highlight / ::: story → <!--LAZY-INJ-N-->
     ├─ CommonMark convert     → HTML
     ├─ reinjectStashed        → restore admonition divs
     ├─ postprocessFreqTags    → <code>2.3 kHz</code> → <span class="freq-tag">
     ├─ postprocessFigures     → <p><img alt></p> → <figure><img><figcaption>
     ├─ injectHeadingIds       → <h2 id="slug">
     └─ extractToc             → list of {level,id,text}
   - Http::render('post', […])
     ├─ ob_start, require views/post.php
     ├─ ob_get_clean → $body
     └─ require views/layout.php  emits HTML with SEO / OG / JSON-LD
4. Caddy applies asset cache + compression and returns the response
```

### Admin save (`POST /admin/save`)

```
1. Same boot as above
2. AdminController::save
   - Auth::requireAuth  redirects to login if !$_SESSION['admin']
   - Csrf::requireValid hash_equals against $_SESSION['_csrf']
   - readFormValues + buildPostFromForm
     ├─ SlugUtil::valid  enforces [a-z0-9-]+ max 80
     └─ date regex \d{4}-\d{2}-\d{2}
   - PostRepository::save($post, $previousFilename)
     ├─ symfony/yaml dumps frontmatter
     ├─ FileWriter::writeAtomic  tempnam + LOCK_EX + rename
     ├─ if rename (slug or date change) → unlink old file
     └─ invalidateCaches() unlinks .index.json + .llms*.txt + .feed.xml
   - setFlash + Http::redirect('/admin')
3. Next request rebuilds the index lazily (mtime check) →
   chain invalidates .llms*.txt + .feed.xml again so /llms.txt and
   /feed.xml regenerate on their next read
```

## Module responsibilities

| Module | Responsibility | Key invariant |
|--------|----------------|---------------|
| `Config` | Read $_ENV, assert required keys at boot | Throws RuntimeException if a required var is missing |
| `Auth` | Session lifecycle, password verify, requireAuth gate | Session ID regenerated on login; strict_mode rejects unknown SIDs |
| `Csrf` | Per-session random_bytes(32) token | hash_equals comparison; one token per session |
| `Router` | Pattern → handler dispatch | Most-specific patterns first; 404 fallback renders `not-found.php` |
| `Http` | render() with layout wrap; redirect() with CRLF strip; e() escape | Output buffering for body capture; layout always rendered last |
| `Post` | Immutable value object | Body stays as raw markdown; rendering happens lazily |
| `PostRepository` | Read/write `.md` files, maintain index cache | Filename = `YYYY-MM-DD-{slug}.md`; rebuilds on mtime drift |
| `FrontmatterParser` | YAML frontmatter ⇄ body split | Tolerates missing frontmatter |
| `SlugUtil` | Slug validation + Vietnamese-aware diacritic strip | `^[a-z0-9-]+$` max 80 chars |
| `MarkdownRenderer` | Markdown → HTML with LazyBlog extensions | Admonitions go through placeholder bridge to bypass CommonMark's block parser |
| `FileWriter` | Atomic write helper | tempnam in target dir + rename — never corrupts on crash |
| `LlmsBuilder` | Generate llms.txt + llms-full.txt | Reads index, lazily reads bodies; outputs follow llmstxt.org |
| `FeedBuilder` | Generate RSS 2.0 XML | DOMDocument (not string concat); 20-item limit; full HTML in content:encoded |

## Cache pyramid

```
       /llms.txt   /llms-full.txt   /feed.xml
            │              │            │
            └──────┬───────┴────────────┘
                   │ derives from
                   ▼
            content/.index.json         ← rebuilt when ANY post .md mtime > index mtime
                   │
                   ▼
            content/posts/*.md          ← source of truth
```

All four cache files are invalidated together. The index rebuild trigger
is mtime-based, so manual `.md` drops (without admin UI) are picked up
automatically on the next HTTP request that touches `PostRepository::all()`.

Public visitors transparently warm the caches. No cron, no service.

## Threat model

| Asset | Threat | Defense |
|-------|--------|---------|
| Markdown files on disk | Path traversal via URL slug | Slugs never used as filesystem paths; only `$entry['file']` from index |
| Markdown files on disk | Concurrent write corruption | `FileWriter::writeAtomic` — LOCK_EX + rename |
| Admin session | Fixation | `session.use_strict_mode` + regenerate_id on login |
| Admin session | Cookie theft | HttpOnly, SameSite=Lax, Secure (when SESSION_SECURE=true) |
| Admin login | Brute force | 500ms delay per failed attempt + optional Caddy rate-limit |
| State-changing admin endpoints | CSRF | Csrf::requireValid on every POST |
| `?next=` parameter | Open redirect / header injection | `safeRedirectTarget` allow-list + CRLF reject |
| Preview endpoint | DoS via huge payload | 256KB read cap in AdminController::preview |
| Markdown body | XSS via raw HTML | Single-author trust model; documented in README |
| Markdown links | `javascript:` URL | `allow_unsafe_links: false` in CommonMark |
| RSS XML | Malformed XML when post has < > & | DOMDocument auto-escaping (never string concat) |
| Public pages | Banner disclosure (PHP version) | `header_remove('X-Powered-By')` at boot |
| Public pages | Clickjacking | `X-Frame-Options: SAMEORIGIN` + CSP `frame-ancestors 'self'` |
| Public pages | Foreign script injection | CSP `script-src 'self' jsdelivr` |
| `.env`, `Dockerfile` | Filesystem disclosure via HTTP | Caddy dotfile block + web root = `public/` |

## File system layout (runtime)

```
/var/www/lazyblog/                        owner: lazyblog:lazyblog
├── public/                               ← Caddy root (read-only to web)
│   ├── index.php
│   ├── .htaccess                         (Apache fallback)
│   ├── robots.txt
│   └── assets/
│       ├── site.css
│       ├── admin.css                     (admin-only)
│       └── admin-editor.js               (admin-only)
├── src/                                  ← outside web root
├── views/                                ← outside web root
├── scripts/
│   ├── hash-password.php
│   └── backup-content.sh
├── vendor/                               ← composer install --no-dev
├── .env                                  ← mode 640, owner lazyblog:www-data
├── composer.json + composer.lock
└── content/                              ← writable by www-data (php-fpm group)
    └── posts/
        ├── 2026-06-22-slug.md
        ├── .index.json                   ← gitignored caches
        ├── .llms.txt
        ├── .llms-full.txt
        └── .feed.xml
```
