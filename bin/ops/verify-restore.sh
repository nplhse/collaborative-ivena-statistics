#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=bin/ops/_lib.sh
source "$ROOT/bin/ops/_lib.sh"

BASELINE_FILE="${BASELINE_FILE:-$ROOT/var/backups/restore-baseline-latest.txt}"

usage() {
    cat <<'EOF'
Capture or verify database metrics for a restore drill.

Usage:
  ./bin/ops/verify-restore.sh show
  ./bin/ops/verify-restore.sh baseline
  ./bin/ops/verify-restore.sh verify [baseline-file]

Commands:
  show      Print current row counts and migration version
  baseline  Save current metrics to var/backups/restore-baseline-latest.txt
  verify    Compare current metrics with a saved baseline (exit 1 on mismatch)

Environment:
  BASELINE_FILE   Override baseline path (default: var/backups/restore-baseline-latest.txt)

Requires a running Docker database service or psql + DATABASE_URL.
EOF
}

ops_migration_version() {
    (
        cd "$ROOT"
        php bin/console doctrine:migrations:current --no-ansi 2>/dev/null \
            | head -1 \
            | grep -oE 'Version[0-9]+' \
            | head -1 \
            || echo "unknown"
    )
}

ops_collect_metrics() {
    local allocation_count import_count user_count hospital_count migration_version

    allocation_count="$(ops_psql_scalar "SELECT COUNT(*) FROM allocation;")"
    import_count="$(ops_psql_scalar "SELECT COUNT(*) FROM import;")"
    user_count="$(ops_psql_scalar 'SELECT COUNT(*) FROM "user";')"
    hospital_count="$(ops_psql_scalar "SELECT COUNT(*) FROM hospital;")"
    migration_version="$(ops_migration_version)"

    cat <<EOF
allocation_count=${allocation_count}
import_count=${import_count}
user_count=${user_count}
hospital_count=${hospital_count}
migration_version=${migration_version}
captured_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
EOF
}

cmd_show() {
    ops_collect_metrics
}

cmd_baseline() {
    local dir
    dir="$(ops_ensure_backup_dir)"
    BASELINE_FILE="${BASELINE_FILE:-$dir/restore-baseline-latest.txt}"

    ops_collect_metrics >"$BASELINE_FILE"
    ops_log "Baseline saved: $BASELINE_FILE"
    cat "$BASELINE_FILE"
}

cmd_verify() {
    local file="${1:-$BASELINE_FILE}"

    if [[ ! -f "$file" ]]; then
        ops_die "Baseline file not found: $file (run: ./bin/ops/verify-restore.sh baseline)"
    fi

    local exp_allocation exp_import exp_user exp_hospital exp_migration
    # shellcheck disable=SC1090
    source "$file"
    exp_allocation="${allocation_count:-}"
    exp_import="${import_count:-}"
    exp_user="${user_count:-}"
    exp_hospital="${hospital_count:-}"
    exp_migration="${migration_version:-}"

    local got_allocation got_import got_user got_hospital got_migration
    got_allocation="$(ops_psql_scalar "SELECT COUNT(*) FROM allocation;")"
    got_import="$(ops_psql_scalar "SELECT COUNT(*) FROM import;")"
    got_user="$(ops_psql_scalar 'SELECT COUNT(*) FROM "user";')"
    got_hospital="$(ops_psql_scalar "SELECT COUNT(*) FROM hospital;")"
    got_migration="$(ops_migration_version)"

    local failed=0

    ops_compare() {
        local label="$1"
        local got="$2"
        local want="$3"

        if [[ "$got" == "$want" ]]; then
            ops_log "OK   $label: $got"
        else
            ops_log "FAIL $label: expected $want, got $got"
            failed=1
        fi
    }

    ops_log "Verifying against baseline: $file"
    ops_compare "allocation_count" "$got_allocation" "$exp_allocation"
    ops_compare "import_count" "$got_import" "$exp_import"
    ops_compare "user_count" "$got_user" "$exp_user"
    ops_compare "hospital_count" "$got_hospital" "$exp_hospital"
    ops_compare "migration_version" "$got_migration" "$exp_migration"

    if [[ "$failed" -ne 0 ]]; then
        ops_die "Verification failed."
    fi

    ops_log "Verification passed."
}

COMMAND="${1:-}"
shift || true

case "$COMMAND" in
    show)
        cmd_show
        ;;
    baseline)
        cmd_baseline
        ;;
    verify)
        cmd_verify "${1:-}"
        ;;
    -h|--help|help|'')
        usage
        [[ -n "$COMMAND" ]] || exit 0
        ;;
    *)
        usage >&2
        ops_die "Unknown command: $COMMAND"
        ;;
esac
