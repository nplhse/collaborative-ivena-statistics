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

This replaces objects in the target database. Use only on dev/staging or after
a confirmed incident on production.
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

ops_log "Database restore starting from $BACKUP_FILE"

if ops_docker_database_running; then
    ops_log "Using Docker Compose service 'database' (pg_restore inside container)"
    docker compose exec -T database \
        pg_restore --clean --if-exists --no-owner --no-acl \
        -U "${POSTGRES_USER:-app}" -d "${POSTGRES_DB:-app}" \
        <"$BACKUP_FILE"
elif command -v pg_restore >/dev/null 2>&1; then
    DATABASE_URL_CLEAN="$(ops_database_url_without_query)"
    ops_log "Using local pg_restore with DATABASE_URL"
    pg_restore --clean --if-exists --no-owner --no-acl -d "$DATABASE_URL_CLEAN" "$BACKUP_FILE"
else
    ops_die "Neither a running Docker database service nor pg_restore on PATH is available."
fi

ops_log "Database restore finished. Run: php bin/console cache:clear"
