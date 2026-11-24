#!/usr/bin/env bash
# Off-site copy of the server backups (#1568): copy new full backups with rsync over SSH, check every newly copied
# backup against the checksums of its manifest, then delete local copies older than the retention. Runs on the
# operator's machine (for example weekly with launchd, see docs/05-operations/backup-restore.md#off-site-copy);
# exits non-zero on any failure and deletes nothing unless every new copy passed the check.
#
# Usage: bin/ops/pull-backups.sh --from SOURCE --to DIR [--retention-days N] [--dry-run]
#
#   --from            rsync source of the server's backup directory, e.g. user@host:backups (or a local directory)
#   --to              local directory for the copies
#   --retention-days  delete local copies older than this many days (default: 90); the newest copy is always kept
#   --dry-run         show what would be copied and deleted, change nothing
#
# Needs rsync, ssh and php (for the checksum check) on PATH.

set -euo pipefail

from=""
to=""
retention=90
dry_run=0

usage() {
    sed -n '7,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --from) from="${2:?--from needs a source}"; shift 2 ;;
        --to) to="${2:?--to needs a directory}"; shift 2 ;;
        --retention-days) retention="${2:?--retention-days needs a number}"; shift 2 ;;
        --dry-run) dry_run=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ -n "$from" && -n "$to" ]] || { usage >&2; exit 2; }
[[ "$retention" =~ ^[1-9][0-9]*$ ]] || { echo "--retention-days must be a positive number." >&2; exit 2; }

log() {
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# Oldest backup name stamp to keep, in the format of the directory names (UTC). BSD date (macOS) or GNU date.
cutoff_stamp() {
    date -u -v-"$1"d +%Y%m%dT%H%M%SZ 2>/dev/null || date -u -d "$1 days ago" +%Y%m%dT%H%M%SZ
}

# Full backups in the local directory, oldest first; the names sort by time.
backups() {
    find "$to" -mindepth 1 -maxdepth 1 -type d -name 'ivena-stats-full-*' 2>/dev/null \
        | grep -E '/ivena-stats-full-[0-9]{8}T[0-9]{6}Z$' | sort || true
}

# Every payload of the manifest exists with the recorded size and SHA-256 (the first steps of app:backup:verify).
check_backup() {
    php -r '
        $dir = $argv[1];
        $manifest = json_decode((string) @file_get_contents($dir."/manifest.json"), true);
        if (!is_array($manifest) || "ivena-stats-backup" !== ($manifest["format"] ?? null) || !is_array($manifest["payloads"] ?? null)) {
            fwrite(STDERR, "  no readable manifest\n");
            exit(1);
        }
        $failed = 0;
        foreach ($manifest["payloads"] as $file => $payload) {
            $path = $dir."/".$file;
            if (!is_file($path) || filesize($path) !== $payload["bytes"] || !hash_equals((string) $payload["sha256"], (string) hash_file("sha256", $path))) {
                fwrite(STDERR, "  size or checksum differs: ".$file."\n");
                $failed = 1;
            }
        }
        exit($failed);
    ' "$1"
}

# Only finished full backups: the name format of app:backup:create, not the hidden `.partial` directories.
name='ivena-stats-full-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z'
rsync_options=(-a --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= --include="/$name/" --include="/$name/**" --exclude='*')
[[ $dry_run -eq 1 ]] && rsync_options+=(--dry-run --itemize-changes)

before="$(backups)"
[[ $dry_run -eq 1 ]] || mkdir -p "$to"
log "Copying backups from $from to $to"
rsync "${rsync_options[@]}" "${from%/}/" "${to%/}/"

if [[ $dry_run -eq 0 ]]; then
    failed=0
    while IFS= read -r dir; do
        [[ -n "$dir" ]] || continue
        if grep -qxF "$dir" <<<"$before"; then
            continue
        fi
        log "Checking $dir"
        check_backup "$dir" || { log "Check failed: $dir"; failed=1; }
    done < <(backups)
    if [[ $failed -eq 1 ]]; then
        log "At least one copy failed the check; nothing deleted."
        exit 1
    fi
fi

newest="$(backups | tail -n 1)"
cutoff="$(cutoff_stamp "$retention")"
log "Keeping copies from $cutoff on ($retention days) and always the newest one"
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
