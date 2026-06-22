#!/usr/bin/env bash
#
# Backup the content/ directory (all markdown posts + caches).
#
# Usage:
#   scripts/backup-content.sh                                  # uses defaults
#   scripts/backup-content.sh /var/www/lazyblog/content/ user@host:/backups/
#
# Suitable for a cron entry like:
#   0 3 * * * /var/www/lazyblog/scripts/backup-content.sh >> /var/log/lazyblog-backup.log 2>&1
#
# The script is idempotent; rsync only transfers changed files.

set -euo pipefail

SRC="${1:-/var/www/lazyblog/content/}"
DEST="${2:-user@backup-host:/backups/lazyblog/}"

# Trailing slash on SRC matters — copies CONTENTS of content/, not the dir itself.
if [[ "${SRC: -1}" != "/" ]]; then
    SRC="${SRC}/"
fi

if [[ ! -d "$SRC" ]]; then
    echo "ERROR: source $SRC does not exist" >&2
    exit 1
fi

echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] backing up $SRC → $DEST"

rsync \
    --archive \
    --delete \
    --human-readable \
    --partial \
    --info=stats1 \
    "$SRC" "$DEST"

echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] backup complete"
