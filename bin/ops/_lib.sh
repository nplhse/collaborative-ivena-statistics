#!/usr/bin/env bash
# Shared helpers for backup/restore scripts. Source from other bin/ops/*.sh scripts.

set -euo pipefail

ops_script_dir() {
    cd "$(dirname "${BASH_SOURCE[0]}")" && pwd
}

ops_project_root() {
    cd "$(ops_script_dir)/../.." && pwd
}

ops_timestamp() {
    date -u +%Y%m%d-%H%M%S
}

ops_backup_dir() {
    local root
    root="$(ops_project_root)"
    echo "${BACKUP_DIR:-$root/var/backups}"
}

ops_ensure_backup_dir() {
    local dir
    dir="$(ops_backup_dir)"
    mkdir -p "$dir"
    echo "$dir"
}

ops_load_database_url() {
    if [[ -n "${DATABASE_URL:-}" ]]; then
        export DATABASE_URL
        return 0
    fi

    local root file line
    root="$(ops_project_root)"

    for file in "$root/.env.local" "$root/.env"; do
        [[ -f "$file" ]] || continue
        line="$(grep -E '^DATABASE_URL=' "$file" | grep -v '^#' | tail -1 || true)"
        if [[ -n "$line" ]]; then
            DATABASE_URL="${line#DATABASE_URL=}"
            DATABASE_URL="${DATABASE_URL%\"}"
            DATABASE_URL="${DATABASE_URL#\"}"
            DATABASE_URL="${DATABASE_URL%\'}"
            DATABASE_URL="${DATABASE_URL#\'}"
            export DATABASE_URL
            return 0
        fi
    done

    echo "DATABASE_URL is not set and was not found in .env.local or .env." >&2
    return 1
}

ops_database_url_without_query() {
    ops_load_database_url
    echo "${DATABASE_URL%%\?*}"
}

ops_docker_compose_available() {
    command -v docker >/dev/null 2>&1 \
        && docker compose version >/dev/null 2>&1
}

ops_docker_database_running() {
    ops_docker_compose_available || return 1
    local root
    root="$(ops_project_root)"
    (
        cd "$root"
        docker compose ps --status running --services 2>/dev/null | grep -qx 'database'
    )
}

ops_log() {
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"
}

ops_die() {
    ops_log "ERROR: $*" >&2
    exit 1
}

ops_psql_scalar() {
    local sql="$1"

    if ops_docker_database_running; then
        docker compose exec -T database \
            psql -U "${POSTGRES_USER:-app}" -d "${POSTGRES_DB:-app}" -t -A -c "$sql"
        return
    fi

    if command -v psql >/dev/null 2>&1; then
        local url
        url="$(ops_database_url_without_query)"
        psql "$url" -t -A -c "$sql"
        return
    fi

    ops_die "No running Docker database service and psql not on PATH."
}
