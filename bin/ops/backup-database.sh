#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=bin/ops/_lib.sh
source "$ROOT/bin/ops/_lib.sh"

cd "$ROOT"

BACKUP_DIR="$(ops_ensure_backup_dir)"
TIMESTAMP="$(ops_timestamp)"
OUTPUT_FILE="${BACKUP_FILE:-$BACKUP_DIR/ivena-stats-db-$TIMESTAMP.dump}"

ops_log "Database backup starting → $OUTPUT_FILE"

if ops_docker_database_running; then
    ops_log "Using Docker Compose service 'database' (pg_dump inside container)"
    docker compose exec -T database \
        pg_dump -U "${POSTGRES_USER:-app}" -d "${POSTGRES_DB:-app}" -Fc \
        >"$OUTPUT_FILE"
elif command -v pg_dump >/dev/null 2>&1; then
    DATABASE_URL_CLEAN="$(ops_database_url_without_query)"
    ops_log "Using local pg_dump with DATABASE_URL"
    pg_dump "$DATABASE_URL_CLEAN" -Fc -f "$OUTPUT_FILE"
else
    ops_die "Neither a running Docker database service nor pg_dump on PATH is available."
fi

if [[ ! -s "$OUTPUT_FILE" ]]; then
    rm -f "$OUTPUT_FILE"
    ops_die "Backup file is empty: $OUTPUT_FILE"
fi

BYTES="$(wc -c <"$OUTPUT_FILE" | tr -d ' ')"
ops_log "Database backup completed ($BYTES bytes): $OUTPUT_FILE"
