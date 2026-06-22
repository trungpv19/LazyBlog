# Backup & Restore

The entire blog state lives under `content/`. Everything else (PHP source,
CSS, etc.) is in git — recoverable from there. Back up `content/`, lose
nothing.

## What's in `content/`

```
content/
├── posts/                  # your authored markdown files
├── uploads/                # admin-UI image uploads, year/month subdirs
├── .index.json             # frontmatter cache — regenerated on demand
├── .llms.txt               # derived cache — regenerated on demand
├── .llms-full.txt          # derived cache — regenerated on demand
└── .feed.xml               # derived cache — regenerated on demand
```

The `.*` dotfiles are caches that rebuild automatically when posts change,
so the only state you really need to preserve is `content/posts/` and
`content/uploads/`. The script archives the whole `content/` directory
which is simpler — caches add a few KB, uploads add whatever you've
uploaded (typically a few MB per post with images).

## Helper script

`scripts/backup-content.sh` covers both local and remote modes:

```bash
# Local timestamped tarball under ./backups/ (default)
scripts/backup-content.sh
# → backups/content-20260622-104017.tar.gz

# Custom local destination
scripts/backup-content.sh --src /var/www/lazyblog/src/content \
                         --out /var/backups/lazyblog

# Push to a remote host (idempotent rsync — only changed files transferred)
scripts/backup-content.sh --src /var/www/lazyblog/src/content \
                         --remote user@backup-host:/backups/lazyblog/
```

The script never auto-prunes old archives — `ls -lt backups/` and `rm`
the ones you don't need.

## Daily cron (set up by `install-vps.sh`)

If you used the bare-metal installer, you already have:

```
# /etc/cron.d/lazyblog-backup
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
0 3 * * * lazyblog /var/www/lazyblog/src/scripts/backup-content.sh \
    --src /var/www/lazyblog/src/content \
    --out /var/backups/lazyblog \
    >> /var/log/lazyblog-backup.log 2>&1
```

Runs daily at 03:00 server-local time. Verify it once manually:

```bash
sudo -u lazyblog /var/www/lazyblog/src/scripts/backup-content.sh \
    --src /var/www/lazyblog/src/content \
    --out /var/backups/lazyblog

ls -la /var/backups/lazyblog/
tail /var/log/lazyblog-backup.log
```

## Restore

A backup tarball contains the whole `content/` directory at its root, so
restore is "extract over the existing location":

```bash
# Stop accepting writes briefly (skip if you're sure no admin saves are in flight)
sudo systemctl stop caddy

# Extract the tarball — replaces existing content/posts/ files
cd /var/www/lazyblog/src
sudo -u lazyblog tar -xzf /var/backups/lazyblog/content-20260622-104017.tar.gz

# Drop the stale cache files so they regenerate from the restored posts
sudo -u lazyblog rm -f content/.index.json content/.llms*.txt content/.feed.xml

sudo systemctl start caddy
curl -I http://127.0.0.1/      # smoke-test
```

Test the restore flow at least once before you actually need it. A backup
you've never restored is more wishful thinking than insurance.

## Optional: git-track `content/`

For per-post version history independent of the app repo:

```bash
cd /var/www/lazyblog/src/content
sudo -u lazyblog git init
sudo -u lazyblog git add posts/
sudo -u lazyblog git commit -m "snapshot"
```

Then `git push` to a private repo or `git remote add backup ...` for an
offsite mirror. Combines well with the tarball cron — git for granular
history, tarballs for fast full-state recovery.
