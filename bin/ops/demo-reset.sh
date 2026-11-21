#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Reset a demo installation on a server (staging, ADR 023) to its snapshot. Meant for a nightly cron job.

Usage:
  ~/www/current/bin/ops/demo-reset.sh

The first run after a deploy (the release differs from the one of the snapshot) rebuilds the demo
data with `make demo-install` and takes a new snapshot: database dump, var/demo/imports and
var/demo/media. Every other run restores that snapshot. Both stop the Messenger worker,
empty the demo log and clear the cache pools.

Environment:
  DEMO_PRESET         Fixture preset for a rebuild (default: realistic)
  DEMO_SNAPSHOT_DIR   Where the snapshot is kept (default: ~/demo-snapshot)
  MESSENGER_SERVICE   systemd user unit of the demo worker (default: messenger.service)
  DEMO_REBUILD        "yes" rebuilds even when the release did not change

Refuses to run unless the database the demo environment resolves ends in "_demo".
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

# Resolve the "current" symlink, so the snapshot records the release it belongs to.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
# shellcheck source=bin/ops/_lib.sh
source "$ROOT/bin/ops/_lib.sh"

PRESET="${DEMO_PRESET:-realistic}"
SNAPSHOT_DIR="${DEMO_SNAPSHOT_DIR:-$HOME/demo-snapshot}"
SERVICE="${MESSENGER_SERVICE:-messenger.service}"
RELEASE="$(basename "$ROOT")"

cd "$ROOT"
export APP_ENV=demo

# The same DATABASE_URL Symfony uses in the demo environment (.env, .env.local, .env.demo, .env.demo.local).
DATABASE_URL="$(php <<'PHP'
<?php
require 'vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
echo $_SERVER['DATABASE_URL'];
PHP
)"
export DATABASE_URL
database_name="$(ops_database_url_without_query)"
database_name="${database_name##*/}"
if [[ "$database_name" != *_demo ]]; then
    ops_die "Refusing to reset database \"$database_name\": the demo database name must end in \"_demo\"."
fi

console() {
    php bin/console --no-interaction "$@"
}

# What visitors add during the day; the demo's geodata under var/demo/geo stays as it is.
FILE_DIRS=(var/demo/imports var/demo/media)

systemctl --user stop "$SERVICE"
trap 'systemctl --user start "$SERVICE"' EXIT

mkdir -p "$SNAPSHOT_DIR"
if [[ "${DEMO_REBUILD:-}" == "yes" || ! -f "$SNAPSHOT_DIR/release" || "$(cat "$SNAPSHOT_DIR/release")" != "$RELEASE" ]]; then
    ops_log "Rebuilding the demo data of release $RELEASE (preset $PRESET)"
    rm -f "$SNAPSHOT_DIR/release"
    make --no-print-directory demo-install DEMO_PRESET="$PRESET" DEMO_CONFIRM=yes

    ops_log "Taking a new snapshot in $SNAPSHOT_DIR"
    BACKUP_DIR="$SNAPSHOT_DIR" BACKUP_FILE="$SNAPSHOT_DIR/database.dump" "$ROOT/bin/ops/backup-database.sh"
    tar -czf "$SNAPSHOT_DIR/files.tar.gz" "${FILE_DIRS[@]}"
    echo "$RELEASE" > "$SNAPSHOT_DIR/release"
else
    ops_log "Restoring the demo snapshot of release $RELEASE"
    RESTORE_CONFIRM=yes BACKUP_FILE="$SNAPSHOT_DIR/database.dump" "$ROOT/bin/ops/restore-database.sh"
    rm -rf "${FILE_DIRS[@]}"
    tar -xzf "$SNAPSHOT_DIR/files.tar.gz"
fi

if [[ -f var/log/demo.log ]]; then
    : > var/log/demo.log
fi
console cache:pool:clear cache.global_clearer

ops_log "Demo reset finished"
