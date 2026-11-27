#!/usr/bin/env bash
# Shared helpers of the bin/ops scripts (demo-reset.sh). Source from other bin/ops/*.sh scripts.

set -euo pipefail

ops_script_dir() {
    cd "$(dirname "${BASH_SOURCE[0]}")" && pwd
}

ops_project_root() {
    cd "$(ops_script_dir)/../.." && pwd
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

ops_log() {
    echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] $*"
}

ops_die() {
    ops_log "ERROR: $*" >&2
    exit 1
}
