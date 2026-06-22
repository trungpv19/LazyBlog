# LazyBlog

A simple personal blog. Posts are markdown files on disk, rendered by ~500 lines of
plain PHP, served by Caddy + php-fpm. CRT phosphor terminal aesthetic. AI-friendly
by design — every post is also available as raw `.md`, plus an `llms.txt` index.

No database. No framework. No build step. Backup with `rsync`.

```
┌─────────────────────────────────────────────────────┐
│  CRT terminal layout  ·  green ↔ amber theme       │
│  Markdown files       ·  llms.txt + raw .md         │
│  TOC + scrollspy      ·  code-block copy buttons    │
│  SEO + JSON-LD        ·  Open Graph + Twitter Card  │
└─────────────────────────────────────────────────────┘
```

## Stack

- PHP 8.2+ (no framework)
- Composer: `league/commonmark`, `symfony/yaml`, `vlucas/phpdotenv`
- Caddy + php-fpm
- Fonts via Google Fonts: VT323, Share Tech Mono, Play
- Docker Compose for local dev

## Quick start (Docker)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
open http://localhost:8080
```

Hot-reload works via bind-mount: edit `.php` / `.css` / `.md` files and refresh.

### Without Docker

```bash
cp .env.example .env
composer install
php -S localhost:8000 -t public
```

## Writing a post

Drop a file in `content/posts/` named `YYYY-MM-DD-slug.md` with YAML frontmatter:

```yaml
---
title: "My First Post"
date: 2026-06-22
author: XV5HP            # optional, falls back to DEFAULT_AUTHOR env
tags: [hello, intro]
draft: false              # true → only visible in admin (Phase 3)
icon: "🟢"                 # optional emoji shown next to title
summary: "One-line description used in lists, meta tags, llms.txt."
---

Body in **markdown**. Standard CommonMark, plus...
```

### Markdown extensions

Beyond standard markdown, three custom patterns map to the CRT design system:

**Highlight block** — for specs, callouts, key facts:

````markdown
::: highlight
TỐC ĐỘ TRUYỀN: 8 giây → 73 giây

ĐỘ PHÂN GIẢI: 320×240 đến 640×496 pixels
:::
````

**Story card** — for sidebars, anecdotes, deep-dive boxes:

````markdown
::: story icon="🌕" title="Apollo 11 Color Loss"
Body of the card. Supports full markdown including **bold**, links, etc.
:::
````

**Freq tag** — inline code with a unit pattern (`Hz`, `kHz`, `MHz`, `GHz`, `MB`,
`GB`, `TB`, `ms`, `s`, `min`, `km`, `m`, `cm`, `mm`) auto-renders as a colored
chip:

```markdown
The ISS broadcasts on `145.8 MHz` in PD120 mode.
```

Plain inline code (no unit pattern) stays as plain `<code>`.

## URLs

| Path | Purpose |
|------|---------|
| `/` | Home — paginated post list |
| `/posts/{slug}` | Rendered HTML post |
| `/posts/{slug}.md` | Raw markdown of the post (text/markdown) |
| `/tags/{tag}` | Posts filtered by tag |
| `/llms.txt` | Site index + post summaries, per [llmstxt.org](https://llmstxt.org) |
| `/llms-full.txt` | Every published post concatenated — for LLM training/embedding |

Each rendered post page also includes a `<link rel="alternate" type="text/markdown">`
in `<head>` so tools auto-discover the raw source.

## Reading experience

- **Theme toggle**: Green (phosphor terminal) ↔ Amber (warmer reader mode). Persists in `localStorage`.
- **CRT scanlines** + subtle flicker on home (respects `prefers-reduced-motion`).
- **Auto TOC** for posts with ≥3 headings. Inline on mobile; floats to left rail on desktop after scrolling.
- **Scrollspy**: matching TOC link highlights as you scroll past each H2.
- **Back-to-top** button appears after ~400px of scroll.
- **Code blocks** show a language label + COPY button.
- **`[ HOME ]`** + theme toggle live in centered header actions row.

## SEO & social

Every page emits:

- `<title>`, `<meta name="description">`, `<meta name="author">`, `<link rel="canonical">`
- Open Graph (`og:title`, `og:description`, `og:url`, `og:type`, `og:site_name`, `og:locale`)
- Twitter Card (`summary` variant)
- JSON-LD structured data (`Blog` on home, `BlogPosting` on posts — headline,
  datePublished, author, publisher, keywords)
- For posts: `article:published_time`, `article:author`, one `article:tag` per tag
- `<link rel="alternate" type="application/rss+xml" href="/feed.xml">` placeholder
  (RSS itself ships in the upcoming Phase 5)
- Inline SVG favicon (no `/favicon.ico` 404)

## Project layout

```
LazyBlog/
├── public/                  # web root (Caddy serves from here)
│   ├── index.php            # front controller / router
│   ├── .htaccess            # Apache rewrite fallback
│   └── assets/
│       └── site.css         # single CSS file — theme tokens, layout, components
├── src/
│   ├── Config.php           # env loader + required-key validation
│   ├── Router.php           # tiny pattern-based router
│   ├── Http.php             # render(), redirect(), e() escape helper
│   ├── Post.php             # immutable post value object
│   ├── PostRepository.php   # reads/writes markdown files + index cache
│   ├── FrontmatterParser.php
│   ├── MarkdownRenderer.php # admonitions → placeholder → CommonMark → reinject
│   ├── SlugUtil.php         # diacritic-stripping slug helpers
│   ├── LlmsBuilder.php      # llms.txt + llms-full.txt generators
│   └── Controllers/         # HomeController, PostController, TagController, LlmsController
├── views/
│   ├── layout.php           # base HTML, SEO, header, footer
│   ├── home.php
│   ├── post.php
│   ├── tag.php
│   └── not-found.php
├── content/
│   └── posts/               # YYYY-MM-DD-slug.md files (gitignored)
├── docker/
│   └── Caddyfile.dev
├── docker-compose.yml
├── Dockerfile
├── composer.json
└── .env.example
```

## Configuration (`.env`)

| Key | Purpose |
|-----|---------|
| `SITE_TITLE` | Brand shown in header + `<title>` + Open Graph |
| `SITE_URL` | Used for canonical URLs, og:url, llms.txt, RSS |
| `SITE_DESCRIPTION` | Default meta description (overridden by post summary on post pages) |
| `TIMEZONE` | PHP `date_default_timezone_set` value |
| `DEFAULT_AUTHOR` | Author shown when a post has no `author:` frontmatter |
| `ADMIN_PASSWORD_HASH` | bcrypt hash for the admin (Phase 3, not yet active) |
| `SESSION_*` | Session cookie settings (Phase 3) |

## Backup

The entire blog state lives under `content/`. Backup is:

```bash
rsync -avh /var/www/lazyblog/content/ user@backup-host:/backups/lazyblog/
```

Optionally `git init content/` for per-post version history independent of the
app's git repo.

## Security notes

- `content/` is fully gitignored — posts stay on the host, not in the repo.
- `.env` is never committed. Web root is `public/`; `.env` lives one dir up.
- Caddy blocks `/.env*`, `/Dockerfile`, `/docker-compose.yml`, dotfiles → 404.
- CommonMark `allow_unsafe_links: false` blocks `javascript:` URLs.
- **Trust assumption**: `html_input: 'allow'` is set so the admonition placeholder
  bridge works. This means **raw HTML inside `.md` files is rendered as-is**.
  Acceptable because posts are author-only. If multi-author writing is added
  later, switch to `'escape'` and re-implement admonition reinjection on the
  escaped form.
- All variables from frontmatter or URL params reach views via `Http::e()` /
  `htmlspecialchars(ENT_QUOTES, 'UTF-8')`.
- Open redirect: `Http::redirect()` does no URL validation; only called internally.
  When Phase 3 admin lands, validate `?next=` parameters before redirecting.

### Hardening checklist for production (Phase 6)

- [ ] `Strict-Transport-Security` header in Caddyfile
- [ ] `Content-Security-Policy` (`default-src 'self'; font-src fonts.gstatic.com; style-src 'self' 'unsafe-inline' fonts.googleapis.com`)
- [ ] Non-root user in production Dockerfile (php-fpm currently runs as root in dev)
- [ ] `.env` mode `640`, owned by `lazyblog:www-data`
- [ ] `content/posts/` writable by php-fpm; everything else read-only
- [ ] Backup cron + integrity check
- [ ] PHP `display_errors=Off`, `log_errors=On` in prod `php.ini`

## Plan & roadmap

See `plans/260622-1036-personal-blog-php-markdown/plan.md` for the full multi-phase plan:

1. ✅ Bootstrap (Docker, composer, router, env)
2. ✅ PostRepository + public read paths + raw `.md` + `llms.txt`
3. ✅ Admin auth + CRUD (single-password, CSRF, atomic file writes)
4. ✅ Editor UX (EasyMDE, tag chips, server-side preview, autosave, unsaved-changes guard)
5. ✅ RSS feed `/feed.xml`
6. ✅ Deployment (Caddyfile.example, Dockerfile.prod, backup script, `docs/deployment-guide.md`)

**All six phases complete.** The plan in `plans/260622-1036-personal-blog-php-markdown/` is fully implemented.

### Phase 6 — Deployment

- **`Caddyfile.example`** — production HTTPS config with security headers, asset caching, dotfile blocking, optional rate-limit on `/admin/login`
- **`Dockerfile.prod`** — non-root `lazyblog` user, opcache enabled with `validate_timestamps=0`, production php.ini (display_errors off, expose_php off, session.use_strict_mode=1), composer `--no-dev --optimize-autoloader` baked in
- **`scripts/backup-content.sh`** — idempotent rsync of `content/` to a remote host; designed for cron
- **`docs/deployment-guide.md`** — step-by-step Ubuntu/Debian VPS playbook covering: VPS provisioning + firewall, runtime install, app deploy as a dedicated `lazyblog` user, Caddy setup, DNS + TLS, admin password, backup cron, zero-downtime update flow, troubleshooting matrix, production-hardening checklist

### Phase 4 — Editor UX details

When editing a post at `/admin/edit/{slug}` you get:

- **EasyMDE markdown editor** (CDN, pinned to 2.18.0)
  - Bold / italic / heading / quote / list / code / link / image toolbar
  - Custom buttons for the LazyBlog admonitions: `!` inserts `::: highlight`, `💬` inserts `::: story icon="..." title="..."`
  - `Cmd-P` toggle preview, `F9` side-by-side, `F11` fullscreen
  - **Server-side preview**: the preview pane POSTs to `/admin/preview` and renders through the SAME `MarkdownRenderer` used for public pages — so `::: highlight`, `::: story`, and freq-tag chips render correctly (EasyMDE's default marked.js can't parse them). Debounced 300ms.
  - **Autosave** to `localStorage` keyed by slug, 1500ms delay — restored if you reopen the tab after an accidental close
  - Theme-aware: editor surfaces use `--bg`, `--primary`, etc. and swap with the green ↔ amber toggle
- **Tag chip input** — comma-separated input becomes clickable pills. Type a tag + `Enter` or `,` to add. Backspace on empty entry removes the last chip. Click `×` on a chip to delete it. Hidden `<input>` keeps the comma-joined value for form submit.
- **Unsaved-changes guard** — `window.beforeunload` prompts if you try to navigate away with edits. Cleared on actual SAVE so it doesn't fire on submit.

### Phase 5 — RSS feed

`GET /feed.xml` returns a valid RSS 2.0 feed of the latest 20 published posts:
- Channel: site title, description, language `vi`, `lastBuildDate`, `atom:link rel="self"`
- Per item: title, link, GUID (permalink), `pubDate` (RFC 2822), description (excerpt), `content:encoded` (full rendered HTML, CDATA-wrapped), one `<category>` per tag
- Cached to `content/.feed.xml`; invalidated automatically when any post is saved/deleted (same hook as `.index.json` and `.llms.txt`)
- Sends `ETag` header; honors `If-None-Match` with 304
- `<link rel="alternate" type="application/rss+xml">` already in every page's `<head>` so reader apps auto-discover

### CSS architecture (security + perf hygiene)

- `public/assets/site.css` — public-only (theme tokens, header/footer, post-list, post-body, TOC, code-copy, scrollspy). **Loaded on every page.**
- `public/assets/admin.css` — admin-only (`.admin-*`, `.tag-chip-*`, EasyMDE/CodeMirror overrides, `body.is-admin` widths). **Only loaded when path starts with `/admin`.**
- Public visitors save ~10KB and don't see admin class names in view-source
- Admin pages load both — theme tokens cascade from `site.css` so `admin.css` inherits CRT palette without redeclaring

### Phase 3 details — admin lives at `/admin`

| Route | Purpose |
|-------|---------|
| `GET /admin/login` · `POST /admin/login` | Single-password login (bcrypt) |
| `POST /admin/logout` | CSRF-protected session destroy |
| `GET /admin` | List every post (live · draft · scheduled) |
| `GET /admin/new` | Blank edit form |
| `GET /admin/edit/{slug}` | Prefilled edit form |
| `POST /admin/save` | Validates + atomic write + cache invalidation |
| `POST /admin/delete/{slug}` | CSRF-protected unlink |

Set up:
```bash
# 1. Generate a bcrypt hash for your admin password
docker compose exec app php scripts/hash-password.php "your-real-password"

# 2. Paste the output into .env:
#    ADMIN_PASSWORD_HASH="$2y$10$..."

# 3. Restart
docker compose restart app

# 4. Log in at http://localhost:8080/admin/login
```

When logged in, a `[ ADMIN ]` link appears in the header on every page,
plus an `[ EDIT ]` button next to `[ VIEW SOURCE .md ]` on each post.
Public visitors see nothing different (gated server-side, not just CSS).

Empty `ADMIN_PASSWORD_HASH` = login disabled. No default password.

### New config keys (since v1)

| Env | Default | Purpose |
|-----|---------|---------|
| `POSTS_PER_PAGE` | `10` | Posts per page on home + tag listings (`?page=N`) |
| `CALLSIGN` | empty | Small line above the site title (e.g. `XV5HP // STATION // CITY`). Empty hides the line. |
| `DEFAULT_AUTHOR` | empty | Author shown in `§ TRANSMISSION — DATE — AUTHOR` when a post has no `author:` frontmatter |

### Recent polish

- Status badges → colored dots (green = live, gray = draft, amber = scheduled), with `title=""` tooltips for a11y
- Pagination: prev/next + page indicator; canonical URL includes `?page=N` so each page is its own canonical
- Long titles ellipsis-truncate in the admin list so rows stay single-line
- Admin canvas widens to 1180–1480px depending on viewport, separate from the narrower (960–1020px) reading width on public pages
- `[ HOME ]` + `[ ADMIN ]` + theme toggle in centered header actions row

## License

MIT.
