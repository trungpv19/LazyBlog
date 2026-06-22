#!/usr/bin/env bash
#
# Backup the content/ directory (all markdown posts + caches).
#
# Default: create a timestamped local tarball under ./backups/
#   scripts/backup-content.sh
#     → backups/content-20260622-173045.tar.gz
#
# Custom local destination:
#   scripts/backup-content.sh --src /var/www/lazyblog/content --out /var/backups/lazyblog
#
# Remote rsync (idempotent — only transfers changed files):
#   scripts/backup-content.sh --src /var/www/lazyblog/content --remote user@host:/backups/lazyblog/
#
# Cron-friendly:
#   0 3 * * * /var/www/lazyblog/scripts/backup-content.sh \
#       --src /var/www/lazyblog/content \
#       --out /var/backups/lazyblog \
#       >> /var/log/lazyblog-backup.log 2>&1
#
# This script NEVER deletes old archives — prune them manually when needed.

set -euo pipefail

SRC=""
OUT=""
REMOTE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --src)    SRC="$2";    shift 2 ;;
        --out)    OUT="$2";    shift 2 ;;
        --remote) REMOTE="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "ERROR: unknown arg $1" >&2
            exit 1
            ;;
    esac
done

# Resolve project root from the script location so defaults work regardless
# of CWD (cron jobs, docker exec, etc.).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

SRC="${SRC:-$PROJECT_ROOT/content}"
OUT="${OUT:-$PROJECT_ROOT/backups}"

if [[ ! -d "$SRC" ]]; then
    echo "ERROR: source $SRC does not exist" >&2
    exit 1
fi

TS="$(date -u +'%Y%m%d-%H%M%S')"
LOG_TS="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

if [[ -n "$REMOTE" ]]; then
    # Trailing slash on SRC matters — copies CONTENTS of content/, not the dir itself.
    SRC_RSYNC="${SRC%/}/"
    echo "[$LOG_TS] rsync $SRC_RSYNC → $REMOTE"
    rsync \
        --archive \
        --delete \
        --human-readable \
        --partial \
        --info=stats1 \
        "$SRC_RSYNC" "$REMOTE"
    echo "[$LOG_TS] remote backup complete"
    exit 0
fi

mkdir -p "$OUT"
ARCHIVE="$OUT/content-$TS.tar.gz"

echo "[$LOG_TS] archiving $SRC → $ARCHIVE"
# -C to parent so the tarball contains a clean `content/` at the root,
# not the full absolute path.
tar -czf "$ARCHIVE" -C "$(dirname "$SRC")" "$(basename "$SRC")"

SIZE="$(du -h "$ARCHIVE" | cut -f1)"
echo "[$LOG_TS] wrote $ARCHIVE ($SIZE)"
