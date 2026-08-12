#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=bin/ops/_lib.sh
source "$ROOT/bin/ops/_lib.sh"

usage() {
    cat <<'EOF'
Restore a PostgreSQL backup created by bin/ops/backup-database.sh.

Usage:
  RESTORE_CONFIRM=yes BACKUP_FILE=var/backups/ivena-stats-db-....dump ./bin/ops/restore-database.sh
  RESTORE_CONFIRM=yes ./bin/ops/restore-database.sh var/backups/ivena-stats-db-....dump

Environment:
  BACKUP_FILE       Path to .dump file (custom format from pg_dump -Fc)
  RESTORE_CONFIRM   Must be "yes" to proceed (safety gate)
  DATABASE_URL      Target database (from env or .env.local / .env)

Replaces schema public (DROP … CASCADE + recreate), then restores from the dump.
PostGIS extensions and tables (spatial_ref_sys, …) in the dump TOC are skipped.
Use only on dev/staging or after a confirmed incident on production.
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

BACKUP_FILE="${BACKUP_FILE:-${1:-}}"
if [[ -z "$BACKUP_FILE" ]]; then
    usage >&2
    ops_die "BACKUP_FILE is required."
fi

if [[ ! -f "$BACKUP_FILE" ]]; then
    ops_die "Backup file not found: $BACKUP_FILE"
fi

if [[ "${RESTORE_CONFIRM:-}" != "yes" ]]; then
    ops_die 'Set RESTORE_CONFIRM=yes to run restore (this overwrites database objects).'
fi

cd "$ROOT"

# Resolve to an absolute path for docker cp / host tooling.
BACKUP_FILE="$(cd "$(dirname "$BACKUP_FILE")" && pwd)/$(basename "$BACKUP_FILE")"

ops_log "Database restore starting from $BACKUP_FILE"

ops_reset_public_schema_docker() {
    local user db
    user="${POSTGRES_USER:-app}"
    db="${POSTGRES_DB:-app}"
    ops_log "Resetting schema public (Docker)"
    docker compose exec -T database psql -U "$user" -d "$db" -v ON_ERROR_STOP=1 -c 'DROP SCHEMA public CASCADE;'
    docker compose exec -T database psql -U "$user" -d "$db" -v ON_ERROR_STOP=1 -c \
        "CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO \"${user}\"; GRANT ALL ON SCHEMA public TO public;"
}

ops_reset_public_schema_host() {
    local url user
    url="$(ops_database_url_without_query)"
    user="$(ops_database_user_from_url)"
    ops_log "Resetting schema public (host psql)"
    psql "$url" -v ON_ERROR_STOP=1 -c 'DROP SCHEMA public CASCADE;'
    psql "$url" -v ON_ERROR_STOP=1 -c \
        "CREATE SCHEMA public; GRANT ALL ON SCHEMA public TO \"${user}\"; GRANT ALL ON SCHEMA public TO public;"
}

if ops_docker_database_running; then
    ops_log "Using Docker Compose service 'database' (pg_restore inside container)"
    user="${POSTGRES_USER:-app}"
    db="${POSTGRES_DB:-app}"
    container="$(docker compose ps -q database)"
    if [[ -z "$container" ]]; then
        ops_die "Could not resolve Docker container for service 'database'."
    fi

    ops_reset_public_schema_docker

    docker cp "$BACKUP_FILE" "$container:/tmp/restore.dump"
    docker compose exec -T database sh -c \
        "pg_restore -l /tmp/restore.dump | grep -v -E '$(ops_pg_restore_toc_exclude_ere)' > /tmp/restore.list || true"
    docker compose exec -T database \
        pg_restore --no-owner --no-acl -U "$user" -d "$db" -L /tmp/restore.list /tmp/restore.dump
    docker compose exec -T database rm -f /tmp/restore.dump /tmp/restore.list
elif command -v pg_restore >/dev/null 2>&1 && command -v psql >/dev/null 2>&1; then
    DATABASE_URL_CLEAN="$(ops_database_url_without_query)"
    ops_log "Using local pg_restore with DATABASE_URL"
    LIST_FILE="$(mktemp)"
    trap 'rm -f "$LIST_FILE"' EXIT

    ops_reset_public_schema_host
    ops_filter_pg_restore_toc "$BACKUP_FILE" "$LIST_FILE"
    pg_restore --no-owner --no-acl -d "$DATABASE_URL_CLEAN" -L "$LIST_FILE" "$BACKUP_FILE"
else
    ops_die "Neither a running Docker database service nor pg_restore+psql on PATH is available."
fi

ops_log "Database restore finished. Run: php bin/console cache:clear"
