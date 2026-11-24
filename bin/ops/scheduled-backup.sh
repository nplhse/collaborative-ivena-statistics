#!/usr/bin/env bash
# Scheduled backup on the server (#1568): create a full backup, verify it, then delete older backups so that only
# the newest ones stay. Meant for cron (weekly, see docs/05-operations/backup-restore.md#scheduled-backups); exits
# non-zero on any failure, so cron mails the output, and deletes nothing unless the new backup was verified.
#
# Usage: bin/ops/scheduled-backup.sh [--target-dir DIR] [--keep N] [--dry-run]
#
#   --target-dir  backup directory (default: BACKUP_DIR, else $HOME/backups)
#   --keep        number of backups to keep, newest first (default: 1, only the new one)
#   --dry-run     show what would run and what would be deleted, change nothing
#
# Runs bin/console of the checkout it lives in, with APP_ENV (default: prod).

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target="${BACKUP_DIR:-$HOME/backups}"
keep=1
dry_run=0

usage() {
    sed -n '6,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --target-dir) target="${2:?--target-dir needs a directory}"; shift 2 ;;
        --keep) keep="${2:?--keep needs a number}"; shift 2 ;;
        --dry-run) dry_run=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ "$keep" =~ ^[1-9][0-9]*$ ]] || { echo "--keep must be a positive number." >&2; exit 2; }

log() {
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# Full backups in the target directory, oldest first; the names sort by time.
backups() {
    find "$target" -mindepth 1 -maxdepth 1 -type d -name 'ivena-stats-full-*' 2>/dev/null \
        | grep -E '/ivena-stats-full-[0-9]{8}T[0-9]{6}Z$' | sort || true
}

console=(php "$root/bin/console" --env="${APP_ENV:-prod}" --no-ansi)

if [[ $dry_run -eq 1 ]]; then
    log "dry run: would run ${console[*]} app:backup:create --no-interaction --target-dir=$target"
    log "dry run: would verify the new backup, then keep the newest $keep backup(s) including it"
else
    mkdir -p "$target"
    log "Creating a backup in $target"
    "${console[@]}" app:backup:create --no-interaction --target-dir="$target"

    newest="$(backups | tail -n 1)"
    [[ -n "$newest" ]] || { log "No backup found in $target after app:backup:create."; exit 1; }

    log "Verifying $newest"
    "${console[@]}" app:backup:verify "$newest"
fi

# In a dry run the new backup does not exist yet, so one more existing backup would go.
delete_count=$(( $(backups | wc -l) - keep + dry_run ))
log "Keeping the newest $keep backup(s)"
if [[ $delete_count -gt 0 ]]; then
    backups | head -n "$delete_count" | while IFS= read -r dir; do
        if [[ $dry_run -eq 1 ]]; then
            log "dry run: would delete $dir"
        else
            log "Deleting $dir"
            rm -rf -- "$dir"
        fi
    done
fi

log "Done."
