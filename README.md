# LazyBlog

A simple personal blog. Posts are markdown files on disk, rendered by ~3000 lines
of plain PHP across a dozen classes, served by Caddy + php-fpm. CRT phosphor
terminal aesthetic. AI-friendly by design — every post is also available as raw
`.md`, plus `llms.txt` / `llms-full.txt` indexes and a valid RSS 2.0 feed.

No database. No framework. No build step. Backup with `rsync`.

```
┌─────────────────────────────────────────────────────┐
│  CRT terminal layout  ·  green ↔ amber theme       │
│  Phosphor vignette    ·  chromatic-aberration heads  │
│  Markdown files       ·  llms.txt + raw .md + RSS    │
│  TOC + scrollspy      ·  code-block copy buttons    │
│  Browser admin UI     ·  EasyMDE + server-side prev │
│  Image full-bleed     ·  theme-tint multiply blend  │
│  SEO + JSON-LD        ·  Open Graph + Twitter Card  │
│  CSP + session hard.  ·  CSRF + atomic file writes  │
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
draft: false              # true → hidden publicly + from llms.txt, shown in admin only
icon: "🟢"                 # optional emoji shown next to title
summary: "One-line description used in lists, meta tags, llms.txt."
---

Body in **markdown**. Standard CommonMark, plus...
```

Or write it from the browser at `/admin/edit/...` — see [Admin section](#admin)
below.

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

### Public

| Path | Purpose |
|------|---------|
| `/` | Home — paginated post list (`?page=N`) |
| `/posts/{slug}` | Rendered HTML post |
| `/posts/{slug}.md` | Raw markdown of the post (`text/markdown`) |
| `/tags/{tag}` | Posts filtered by tag (`?page=N`) |
| `/feed.xml` | RSS 2.0 of latest 20 posts (ETag + 304) |
| `/llms.txt` | Site index per [llmstxt.org](https://llmstxt.org) |
| `/llms-full.txt` | Every published post concatenated for LLM training |
| `/robots.txt` | `Disallow: /admin/` + `Disallow: /llms-full.txt` |

Each rendered post page includes `<link rel="alternate" type="text/markdown">`
in `<head>` so AI agents auto-discover the raw source. Plus
`<link rel="alternate" type="application/rss+xml" href="/feed.xml">` so RSS
readers find the feed without a URL hint.

### Admin (auth required)

| Path | Purpose |
|------|---------|
| `GET /admin/login` · `POST /admin/login` | Single-password login (bcrypt) |
| `POST /admin/logout` | CSRF-protected session destroy |
| `GET /admin` | List every post — live / draft / scheduled |
| `GET /admin/new` | Blank edit form |
| `GET /admin/edit/{slug}` | Prefilled edit form |
| `POST /admin/save` | Validates + atomic write + cache invalidation |
| `POST /admin/delete/{slug}` | CSRF-protected unlink |
| `POST /admin/preview` | Server-side markdown render for EasyMDE preview pane |

## Reading experience

- **Theme toggle**: Green (phosphor terminal) ↔ Amber (warmer reader mode). Persists in `localStorage`.
- **CRT effects**: static 2px scanlines on every page, soft phosphor vignette darkening corners, subtle 8s flicker on home, chromatic-aberration RGB split on headings. All respect `prefers-reduced-motion`.
- **Auto TOC** for posts with ≥3 headings. Inline on mobile; floats to left rail on desktop after scrolling. Scrollspy highlights the matching TOC link as you scroll past each H2.
- **Image figures**: any `![alt](url)` on its own line auto-wraps in `<figure>`, breaks out to full viewport width, takes the theme color via `mix-blend-mode: multiply` overlay, and shows the alt text as a centered `<figcaption>`. Mobile keeps images inside the column. Blank lines around the image are inserted for you — `text\n![alt](url)\n## Next` works without manual spacing.
- **Back-to-top** button after ~400px of scroll.
- **Code blocks** show a language label + COPY button.
- **Dashed underlines** site-wide for a softer terminal feel.
- **Retro ASCII 404** with phosphor glow on missing routes.
- **`[ HOME ]`** + `[ ADMIN ]` (when logged in) + theme toggle in the centered header actions row.

## SEO & social

Every page emits:

- `<title>`, `<meta name="description">`, `<meta name="author">`, `<link rel="canonical">`
- Open Graph (`og:title`, `og:description`, `og:url`, `og:type`, `og:site_name`, `og:locale`)
- Twitter Card (`summary` variant)
- JSON-LD structured data (`Blog` on home, `BlogPosting` on posts — headline,
  datePublished, author, publisher, keywords)
- For posts: `article:published_time`, `article:author`, one `article:tag` per tag
- `<link rel="alternate" type="application/rss+xml" href="/feed.xml">` so feed readers auto-discover
- Inline SVG favicon (no `/favicon.ico` 404)
- `Content-Security-Policy` allowing only `self` + Google Fonts + jsdelivr (EasyMDE)
- `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Content-Type-Options: nosniff`
- `X-Powered-By` header removed so PHP version doesn't leak

## Project layout

```
LazyBlog/
├── public/                  # web root (Caddy serves from here)
│   ├── index.php            # front controller, security headers, session
│   ├── .htaccess            # Apache rewrite fallback
│   ├── robots.txt
│   └── assets/
│       ├── site.css         # public theme + layout (loaded on every page)
│       ├── admin.css        # admin-only (loaded only on /admin/*)
│       └── admin-editor.js  # EasyMDE setup + tag chips + unsaved guard
├── src/
│   ├── Config.php           # env loader + required-key validation
│   ├── Router.php           # tiny pattern-based router
│   ├── Http.php             # render(), redirect() (CRLF strip), e() escape
│   ├── Auth.php             # session + password verify + strict_mode
│   ├── Csrf.php             # per-session token, hash_equals verify
│   ├── FileWriter.php       # atomic tempnam + rename writes
│   ├── Post.php             # immutable post value object
│   ├── PostRepository.php   # read/write .md files + index cache
│   ├── FrontmatterParser.php
│   ├── MarkdownRenderer.php # admonitions → placeholder → CommonMark → reinject
│   ├── SlugUtil.php         # diacritic-stripping slug helpers
│   ├── LlmsBuilder.php      # llms.txt + llms-full.txt generators
│   ├── FeedBuilder.php      # RSS 2.0 via DOMDocument
│   └── Controllers/
│       ├── HomeController.php
│       ├── PostController.php   # show + raw .md
│       ├── TagController.php
│       ├── LlmsController.php
│       ├── FeedController.php   # /feed.xml with ETag + 304
│       └── AdminController.php  # login, list, edit, save, delete, preview
├── views/
│   ├── layout.php           # base HTML, SEO, header, footer
│   ├── home.php
│   ├── post.php
│   ├── tag.php
│   ├── not-found.php        # retro ASCII 404
│   ├── _pagination.php      # shared prev/next + page indicator partial
│   └── admin/
│       ├── login.php
│       ├── list.php
│       └── edit.php         # EasyMDE + tag chip + server-side preview
├── content/                 # gitignored
│   └── posts/               # YYYY-MM-DD-slug.md files
├── scripts/
│   ├── hash-password.php    # bcrypt helper for ADMIN_PASSWORD_HASH
│   └── backup-content.sh    # local tarball by default; --remote for rsync
├── docs/
│   ├── deployment-guide.md  # step-by-step VPS playbook
│   └── system-architecture.md  # diagrams, request lifecycle, threat model
├── plans/                   # gitignored (private design + phase plans)
├── docker/
│   └── Caddyfile.dev
├── Caddyfile.example        # production HTTPS site block
├── docker-compose.yml       # dev: Caddy + php-fpm bind-mount
├── Dockerfile               # dev image
├── Dockerfile.prod          # production: non-root, opcache, no bind mount
├── composer.json
└── .env.example
```

## Configuration (`.env`)

| Key | Purpose |
|-----|---------|
| `SITE_TITLE` | Brand shown in header + `<title>` + Open Graph |
| `SITE_URL` | Canonical URLs, og:url, llms.txt links, RSS guid |
| `SITE_DESCRIPTION` | Default meta description (overridden by post summary on post pages) |
| `TIMEZONE` | PHP `date_default_timezone_set` value |
| `CALLSIGN` | Small line above the site title (e.g. `XV5HP // STATION // CITY`). Empty hides it. |
| `DEFAULT_AUTHOR` | Author shown when a post has no `author:` frontmatter |
| `FOOTER_SIGNOFF` | Copyright line at the bottom of every page. Supports `{year}` token. Empty hides the line. |
| `POSTS_PER_PAGE` | Page size on home + tag listings. Default `10`. |
| `ADMIN_PASSWORD_HASH` | bcrypt hash for the admin. Empty = login disabled. |
| `SESSION_NAME` | Cookie name. Default `lazyblog_sess`. |
| `SESSION_SECURE` | `true` in production (HTTPS only); `false` for local dev. |

## Build & ship as Docker image

The repo ships with two Dockerfiles:

| File | Purpose | Code source |
|------|---------|-------------|
| `Dockerfile` | Local dev | Bind-mounted at runtime |
| `Dockerfile.prod` | Production | Copied into the image at build time |

### Build the production image

```bash
docker build -f Dockerfile.prod -t lazyblog:latest .
# Result: ~80MB image with PHP 8.2-fpm + composer deps + your app code,
# running as non-root user `lazyblog`. Opcache enabled, validate_timestamps=0.
```

### Run on the VPS

Clone the repo on the VPS, build the image in place, then run it. No
registry needed.

```bash
# On the VPS
cd /srv/lazyblog
git clone https://github.com/hieuha/LazyBlog.git src
cd src
docker build -f Dockerfile.prod -t lazyblog:latest .

# Run, bind-mounting content/ + .env from the host so they survive image rebuilds
docker run -d \
    --name lazyblog \
    --restart unless-stopped \
    -v /srv/lazyblog/content:/var/www/html/content \
    -v /srv/lazyblog/.env:/var/www/html/.env:ro \
    -p 127.0.0.1:9000:9000 \
    lazyblog:latest
```

Point Caddy at `127.0.0.1:9000` via `php_fastcgi 127.0.0.1:9000` in your
`Caddyfile`. The root in Caddy stays `/srv/lazyblog/src/public` — Caddy serves
static files (assets/, robots.txt, favicon) directly without going through PHP.

Update flow:

```bash
cd /srv/lazyblog/src
git pull
docker build -f Dockerfile.prod -t lazyblog:latest .
docker stop lazyblog && docker rm lazyblog
docker run -d ... lazyblog:latest    # same flags as above
```

Wrap the four lines in a systemd unit or shell script for one-shot updates.

## Backup

The entire blog state lives under `content/`. Use the helper script:

```bash
# Local timestamped tarball under ./backups/ (default)
scripts/backup-content.sh

# Or push to a remote host (idempotent rsync)
scripts/backup-content.sh --remote user@backup-host:/backups/lazyblog/
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
- **CSRF**: every state-changing POST (login, logout, save, delete) is gated
  by `Csrf::requireValid()` (random_bytes(32), hash_equals).
- **Open redirect**: `safeRedirectTarget()` validates `?next=` (must start with
  `/`, not `//`, no CRLF/tab/NUL). `Http::redirect()` strips control chars too.
- **Session hardening**: `session.use_strict_mode = 1`, `session.use_only_cookies = 1`,
  `HttpOnly`, `SameSite=Lax`, `Secure` when `SESSION_SECURE=true`, session ID
  regenerated on login, 500ms delay on failed password attempts.
- **Preview DoS**: `/admin/preview` reads at most 256KB from the request body.
- **Response headers** set globally at boot: `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Content-Security-Policy` allow-listing only self + Google Fonts + jsdelivr.
  `X-Powered-By` removed.
- **Path traversal blocked**: URL slugs are never used as filesystem paths;
  PostRepository looks up entries via the index cache (closed set from `glob()`).
- **Atomic writes**: FileWriter uses `tempnam` + `LOCK_EX` + `rename`. Crash
  mid-write leaves the previous file intact.
- **RSS XML safety**: FeedBuilder uses DOMDocument; never string-concatenates.

### Hardening checklist for production

Items done in code (need verifying on your specific deploy):

- [x] Non-root user in `Dockerfile.prod` (`lazyblog` UID 1000)
- [x] `Content-Security-Policy` set at PHP layer (and at Caddy layer too)
- [x] PHP `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `opcache.validate_timestamps=0` baked into `Dockerfile.prod`
- [x] Session strict-mode + cookie-only + HttpOnly + SameSite=Lax

Items still on the human-operator (covered in `docs/deployment-guide.md`):

- [ ] `Strict-Transport-Security` uncommented in Caddyfile (after TLS proves stable for a week)
- [ ] `SESSION_SECURE=true` in `.env`
- [ ] `.env` mode `640`, owner `lazyblog:www-data`
- [ ] `content/posts/` writable by php-fpm; rest read-only
- [ ] Backup cron via `scripts/backup-content.sh` + at least one restore test
- [ ] Fail2ban or Caddy rate-limit plugin on `/admin/login` if exposed publicly
- [ ] DNS CAA record locking certs to Let's Encrypt

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
- **`scripts/backup-content.sh`** — local timestamped tarballs by default (`backups/content-YYYYMMDD-HHMMSS.tar.gz`); `--remote user@host:/path/` for idempotent rsync. Old archives are never auto-pruned — remove them manually. Auto-resolves project root so cron works regardless of CWD.
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
# 1. Generate a bcrypt hash for your admin password (interactive — recommended)
docker compose exec -it app php scripts/hash-password.php
#    Or non-interactive (password visible in shell history / ps):
#    docker compose exec app php scripts/hash-password.php "your-real-password"

# 2. Paste the printed line into .env:
#    ADMIN_PASSWORD_HASH="$2y$12$..."

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
