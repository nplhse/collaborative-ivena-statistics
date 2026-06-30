#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=bin/ops/_lib.sh
source "$ROOT/bin/ops/_lib.sh"

cd "$ROOT"

IMPORTS_DIR="${IMPORTS_DIR:-$ROOT/var/imports}"
MEDIA_DIR="${MEDIA_DIR:-$ROOT/public/uploads/media}"
BACKUP_DIR="$(ops_ensure_backup_dir)"
TIMESTAMP="$(ops_timestamp)"
OUTPUT_FILE="${BACKUP_FILE:-$BACKUP_DIR/ivena-stats-files-$TIMESTAMP.tar.gz}"

ops_log "File backup starting → $OUTPUT_FILE"
ops_log "  imports: $IMPORTS_DIR"
ops_log "  media:   $MEDIA_DIR"

TAR_PATHS=()

ops_add_directory_to_tar() {
    local path="$1"
    if [[ ! -d "$path" ]]; then
        ops_log "WARN: directory missing, skipping: $path"
        return 0
    fi

    local parent base
    parent="$(cd "$(dirname "$path")" && pwd)"
    base="$(basename "$path")"
    TAR_PATHS+=(-C "$parent" "$base")
}

ops_add_directory_to_tar "$IMPORTS_DIR"
ops_add_directory_to_tar "$MEDIA_DIR"

if [[ ${#TAR_PATHS[@]} -eq 0 ]]; then
    ops_die "No directories to back up (imports and media both missing)."
fi

tar -czf "$OUTPUT_FILE" "${TAR_PATHS[@]}"

BYTES="$(wc -c <"$OUTPUT_FILE" | tr -d ' ')"
ops_log "File backup completed ($BYTES bytes): $OUTPUT_FILE"
