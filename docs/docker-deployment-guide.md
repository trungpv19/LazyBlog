# Docker Deployment Guide

For bare-metal (no Docker) on a VPS, see `bare-metal-deployment-guide.md`.

LazyBlog ships two Dockerfiles:

| File | Purpose | Code source |
|------|---------|-------------|
| `Dockerfile` | Local dev | Bind-mounted at runtime |
| `Dockerfile.prod` | Production | Copied into the image at build time |

## Local development

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
open http://localhost:8080
```

Hot-reload via bind-mount: edit `.php` / `.css` / `.md` files and refresh.
EasyMDE editor loads from CDN, so no JS build step.

## Production image

```bash
docker build -f Dockerfile.prod -t lazyblog:latest .
```

Result: ~80MB image with PHP 8.2-fpm + composer deps + your app code, running
as non-root `lazyblog` user. Opcache enabled with `validate_timestamps=0`,
production php.ini (`display_errors=Off`, `expose_php=Off`, strict session).

## Run on the VPS

Clone the repo on the VPS, build the image in place, run it.

```bash
# On the VPS
mkdir -p /srv/lazyblog
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
`Caddyfile`. Root in Caddy stays `/srv/lazyblog/src/public` — Caddy serves
static assets directly without going through PHP.

See `Caddyfile.example` for a production-ready site block (security headers,
asset caching, dotfile blocking, optional `/admin/login` rate-limit).

## Update flow

```bash
cd /srv/lazyblog/src
git pull
docker build -f Dockerfile.prod -t lazyblog:latest .
docker stop lazyblog && docker rm lazyblog
docker run -d ... lazyblog:latest    # same flags as above
```

Wrap the four lines in a systemd unit or shell script for one-shot updates.

## Bare metal vs Docker

|  | Bare-metal (`install-vps.sh`) | Docker |
|--|-------------------------------|--------|
| Setup time | ~3 min one-shot | ~5 min (build + run) |
| Disk | ~100 MB (PHP + Caddy + composer deps) | ~80 MB image + Caddy on host |
| Updates | `git pull` + reload services | `git pull` + rebuild + restart container |
| PHP version control | Pinned by installer (sury/ondrej PPA if needed) | Pinned by Dockerfile base image |
| Cold start | None | Container restart ~1s |
| Best for | Single-tenant VPS, ARM (Pi), minimal RAM | Hosts already running Docker for other apps |
