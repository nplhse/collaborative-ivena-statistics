#!/usr/bin/env bash
# Scheduled backup on the server (#1568): create a full backup, verify it, then delete backups older than the
# retention. Meant for cron (weekly, see docs/05-operations/backup-restore.md#scheduled-backups); exits non-zero
# on any failure, so cron mails the output, and deletes nothing unless the new backup was verified.
#
# Usage: bin/ops/scheduled-backup.sh [--target-dir DIR] [--retention-days N] [--dry-run]
#
#   --target-dir      backup directory (default: BACKUP_DIR, else $HOME/backups)
#   --retention-days  delete backups older than this many days (default: 90); the newest backup is always kept
#   --dry-run         show what would run and what would be deleted, change nothing
#
# Runs bin/console of the checkout it lives in, with APP_ENV (default: prod).

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target="${BACKUP_DIR:-$HOME/backups}"
retention=90
dry_run=0

usage() {
    sed -n '6,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --target-dir) target="${2:?--target-dir needs a directory}"; shift 2 ;;
        --retention-days) retention="${2:?--retention-days needs a number}"; shift 2 ;;
        --dry-run) dry_run=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ "$retention" =~ ^[1-9][0-9]*$ ]] || { echo "--retention-days must be a positive number." >&2; exit 2; }

log() {
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# Oldest backup name stamp to keep, in the format of the directory names (UTC). BSD date (macOS) or GNU date.
cutoff_stamp() {
    date -u -v-"$1"d +%Y%m%dT%H%M%SZ 2>/dev/null || date -u -d "$1 days ago" +%Y%m%dT%H%M%SZ
}

# Full backups in the target directory, oldest first; the names sort by time.
backups() {
    find "$target" -mindepth 1 -maxdepth 1 -type d -name 'ivena-stats-full-*' 2>/dev/null \
        | grep -E '/ivena-stats-full-[0-9]{8}T[0-9]{6}Z$' | sort || true
}

console=(php "$root/bin/console" --env="${APP_ENV:-prod}" --no-ansi)

if [[ $dry_run -eq 1 ]]; then
    log "dry run: would run ${console[*]} app:backup:create --no-interaction --target-dir=$target"
    newest="$(backups | tail -n 1)"
    log "dry run: would verify the new backup; newest existing backup: ${newest:-none}"
else
    mkdir -p "$target"
    log "Creating a backup in $target"
    "${console[@]}" app:backup:create --no-interaction --target-dir="$target"

    newest="$(backups | tail -n 1)"
    [[ -n "$newest" ]] || { log "No backup found in $target after app:backup:create."; exit 1; }

    log "Verifying $newest"
    "${console[@]}" app:backup:verify "$newest"
fi

cutoff="$(cutoff_stamp "$retention")"
log "Keeping backups from $cutoff on ($retention days) and always the newest one"
while IFS= read -r dir; do
    [[ -n "$dir" && "$dir" != "$newest" ]] || continue
    stamp="${dir##*/ivena-stats-full-}"
    if [[ "$stamp" < "$cutoff" ]]; then
        if [[ $dry_run -eq 1 ]]; then
            log "dry run: would delete $dir"
        else
            log "Deleting $dir"
            rm -rf -- "$dir"
        fi
    fi
done < <(backups)

log "Done."
