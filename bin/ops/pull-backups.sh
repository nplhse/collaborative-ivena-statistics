#!/usr/bin/env bash
# Off-site copy of the server backups (#1568): copy the finished full backups with rsync over SSH, check every copy
# against the checksums of its manifest, optionally delete the copied backups on the server, then delete old local
# copies. Runs on the operator's machine (for example weekly with launchd, see
# docs/05-operations/backup-restore.md#off-site-copy); exits non-zero on any failure and deletes nothing, on the
# server or locally, unless every copy it handled passed the check.
#
# Usage: bin/ops/pull-backups.sh --from SOURCE --to DIR [--keep N] [--retention-days N] [--delete-from-server] [--dry-run]
#
#   --from                rsync source of the server's backup directory, e.g. user@host:backups (or a local directory)
#   --to                  local directory for the copies
#   --keep                keep at most this many local copies, newest first (default: no limit)
#   --retention-days      delete local copies older than this many days (default: 90)
#   --delete-from-server  delete each server backup once its local copy passed the check
#   --dry-run             show what would be copied and deleted, change nothing
#
# The newest local copy is always kept. Needs rsync, ssh and php (for the checksum check) on PATH.

set -euo pipefail

from=""
to=""
keep=0
retention=90
delete_from_server=0
dry_run=0

usage() {
    sed -n '8,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --from) from="${2:?--from needs a source}"; shift 2 ;;
        --to) to="${2:?--to needs a directory}"; shift 2 ;;
        --keep) keep="${2:?--keep needs a number}"; shift 2 ;;
        --retention-days) retention="${2:?--retention-days needs a number}"; shift 2 ;;
        --delete-from-server) delete_from_server=1; shift ;;
        --dry-run) dry_run=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ -n "$from" && -n "$to" ]] || { usage >&2; exit 2; }
[[ "$keep" =~ ^[0-9]+$ ]] || { echo "--keep must be a number." >&2; exit 2; }
[[ "$retention" =~ ^[1-9][0-9]*$ ]] || { echo "--retention-days must be a positive number." >&2; exit 2; }
from="${from%/}"
to="${to%/}"

name_pattern='^ivena-stats-full-[0-9]{8}T[0-9]{6}Z$'

log() {
    printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

# Oldest backup name stamp to keep, in the format of the directory names (UTC). BSD date (macOS) or GNU date.
cutoff_stamp() {
    date -u -v-"$1"d +%Y%m%dT%H%M%SZ 2>/dev/null || date -u -d "$1 days ago" +%Y%m%dT%H%M%SZ
}

# Names of the finished full backups in the local directory, oldest first; the names sort by time.
local_names() {
    find "$to" -mindepth 1 -maxdepth 1 -type d -name 'ivena-stats-full-*' 2>/dev/null \
        | sed 's#.*/##' | grep -E "$name_pattern" | sort || true
}

# Names of the finished full backups on the server.
server_names() {
    rsync --list-only "$from/" | awk '{print $NF}' | grep -E "$name_pattern" | sort || true
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

# Deletes one backup on the server: over SSH for host:path, else in the local source directory.
delete_on_server() {
    [[ "$1" =~ $name_pattern ]] || { log "Refusing to delete $1 on the server."; return 1; }
    if [[ "$from" == *:* && "$from" != /* ]]; then
        local host="${from%%:*}" path="${from#*:}"
        path="${path#\~/}"
        ssh "$host" "rm -rf -- $(printf '%q' "${path:-.}/$1")"
    else
        rm -rf -- "${from:?}/$1"
    fi
}

# Only finished full backups: the name format of app:backup:create, not the hidden `.partial` directories.
name='ivena-stats-full-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z'
rsync_options=(-a --chmod=Du=rwx,Dgo=,Fu=rw,Fgo= --include="/$name/" --include="/$name/**" --exclude='*')
[[ $dry_run -eq 1 ]] && rsync_options+=(--dry-run --itemize-changes)

on_server="$(server_names)"
before="$(local_names)"
[[ $dry_run -eq 1 ]] || mkdir -p "$to"
log "Copying backups from $from to $to"
rsync "${rsync_options[@]}" "$from/" "$to/"

if [[ $dry_run -eq 1 ]]; then
    [[ $delete_from_server -eq 0 || -z "$on_server" ]] || log "dry run: would delete on the server after the check: $(tr '\n' ' ' <<<"$on_server")"
else
    # Check what was copied now and, before deleting it there, everything that is still on the server.
    failed=0
    while IFS= read -r backup; do
        [[ -n "$backup" ]] || continue
        log "Checking $to/$backup"
        check_backup "$to/$backup" || { log "Check failed: $to/$backup"; failed=1; }
    done < <({ comm -13 <(printf '%s\n' "$before") <(local_names); [[ $delete_from_server -eq 1 ]] && printf '%s\n' "$on_server"; } | grep -v '^$' | sort -u)
    if [[ $failed -eq 1 ]]; then
        log "At least one copy failed the check; nothing deleted."
        exit 1
    fi

    if [[ $delete_from_server -eq 1 ]]; then
        while IFS= read -r backup; do
            [[ -n "$backup" ]] || continue
            log "Deleting $backup on the server"
            delete_on_server "$backup"
        done <<<"$on_server"
    fi
fi

names="$(local_names)"
count="$(grep -c . <<<"$names" || true)"
newest="$(tail -n 1 <<<"$names")"
cutoff="$(cutoff_stamp "$retention")"
log "Keeping local copies from $cutoff on ($retention days)$([[ $keep -gt 0 ]] && echo ", at most $keep") and always the newest one"
index=0
while IFS= read -r backup; do
    [[ -n "$backup" && "$backup" != "$newest" ]] || continue
    index=$(( index + 1 ))
    stamp="${backup#ivena-stats-full-}"
    if [[ "$stamp" < "$cutoff" ]] || [[ $keep -gt 0 && $(( count - index )) -ge $keep ]]; then
        if [[ $dry_run -eq 1 ]]; then
            log "dry run: would delete $to/$backup"
        else
            log "Deleting $to/$backup"
            rm -rf -- "${to:?}/$backup"
        fi
    fi
done <<<"$names"

log "Done."
