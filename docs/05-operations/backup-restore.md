# Backup & restore

Operational guide for PostgreSQL, import files, and media uploads.

## Scope

| Asset | Default path (local) | Production (Deployer shared) |
|-------|----------------------|------------------------------|
| PostgreSQL database | from `DATABASE_URL` | from `shared/.env.local` → `DATABASE_URL` |
| Import uploads | `var/imports/` | `~/www/shared/var/imports/` (adjust to your `deploy_path`) |
| Media uploads | `public/uploads/media/` | `~/www/shared/public/uploads/media/` |
| Secrets | `.env.local` | `~/www/shared/.env.local` — **back up separately**, never inside DB dumps |

Backups are written to `var/backups/` locally (override with `BACKUP_DIR`).

## Scripts

| Script | Purpose |
|--------|---------|
| `bin/ops/backup-database.sh` | `pg_dump` custom format (`.dump`) |
| `bin/ops/backup-files.sh` | `tar.gz` of imports + media |
| `bin/ops/restore-database.sh` | `pg_restore` from `.dump` (requires `RESTORE_CONFIRM=yes`) |
| `bin/ops/verify-restore.sh` | Capture/compare row counts for restore drills |

### Make targets

```bash
make backup-db      # database only
make backup-files   # imports + media only
make backup         # both
make restore-db BACKUP_FILE=var/backups/ivena-stats-db-....dump RESTORE_CONFIRM=yes
make verify-restore-baseline   # save metrics snapshot
make verify-restore            # compare after restore
```

## Local development (Docker PostgreSQL)

### Restore drill (recommended)

Use `verify-restore.sh` to prove backup + restore round-trip without hand-written SQL.
Symfony provides `dbal:run-sql` (not `doctrine:query:sql`) if you need ad-hoc queries.

1. Start the database:

   ```bash
   docker compose up -d database
   ```

2. Ensure meaningful data exists (skip if your DB is already populated):

   ```bash
   make reset
   ```

3. Save a baseline snapshot:

   ```bash
   make verify-restore-baseline
   ```

4. Create a database backup:

   ```bash
   make backup-db
   ls -lh var/backups/ivena-stats-db-*.dump
   ```

5. Destroy data (pick one):

   ```bash
   make purge          # empty DB + runtime files
   # or only DB:
   symfony composer setup-database
   ```

6. Confirm data is gone:

   ```bash
   ./bin/ops/verify-restore.sh show
   ```

   Counts should be `0` or much lower than the baseline.

7. Restore from the dump:

   ```bash
   make restore-db BACKUP_FILE=var/backups/ivena-stats-db-YYYYMMDD-HHMMSS.dump RESTORE_CONFIRM=yes
   php bin/console cache:clear
   ```

8. Verify metrics match the baseline:

   ```bash
   make verify-restore
   ```

   Exit code `0` means all counts and migration version match.

9. Optional app smoke test: login, `/explore/allocation`, statistics dashboard.

### Quick backup only

1. Start the database:

   ```bash
   docker compose up -d database
   ```

2. Create a backup (uses `docker compose exec` when the `database` service is running):

   ```bash
   make backup-db
   ```

3. Optional file backup:

   ```bash
   make backup-files
   ```

### Ad-hoc SQL (optional)

```bash
php bin/console dbal:run-sql "SELECT COUNT(*) FROM allocation"
docker compose exec database psql -U app -d app -c 'SELECT COUNT(*) FROM "user";'
```

If `pg_dump` / `pg_restore` is installed on the host and Docker is not running, the backup scripts fall back to `DATABASE_URL` from the environment or `.env.local`.

## Production (Uberspace)

Deployer keeps persistent data outside release directories ([deployment.md](deployment.md)):

- `shared/.env.local`
- `shared/var/imports`
- `shared/public/uploads/media`

### Manual backup commands

From the project root on the server (`~/www/current` or similar):

```bash
# Database (requires pg_dump on PATH and DATABASE_URL in environment)
cd ~/www/current
set -a && source ../shared/.env.local && set +a
BACKUP_DIR=~/backups ./bin/ops/backup-database.sh

# Files (shared paths)
BACKUP_DIR=~/backups \
  IMPORTS_DIR=~/www/shared/var/imports \
  MEDIA_DIR=~/www/shared/public/uploads/media \
  ./bin/ops/backup-files.sh

# Secrets (store encrypted or offline — not in the git repo)
cp ~/www/shared/.env.local ~/backups/env-local-$(date -u +%Y%m%d).bak
chmod 600 ~/backups/env-local-*.bak
```

Store backups **outside** the Deployer `releases/` tree (for example `~/backups/`) so deploys cannot delete them.

### Cron (operator-managed)

Schedule regular backups on the server (example — adjust paths and times):

```cron
# Daily database dump at 03:00 UTC
0 3 * * * cd ~/www/current && set -a && source ../shared/.env.local && set +a && BACKUP_DIR=~/backups ./bin/ops/backup-database.sh >> ~/backups/backup.log 2>&1

# Weekly file archive on Sunday 04:00 UTC
0 4 * * 0 BACKUP_DIR=~/backups IMPORTS_DIR=~/www/shared/var/imports MEDIA_DIR=~/www/shared/public/uploads/media ~/www/current/bin/ops/backup-files.sh >> ~/backups/backup.log 2>&1

# Retention: delete dumps older than 30 days
15 5 * * * find ~/backups -name 'ivena-stats-*' -mtime +30 -delete
```

Cron is configured on Uberspace outside this repository.

### Suggested retention

| Type | Suggestion |
|------|------------|
| Daily DB dumps | keep 7 |
| Weekly DB dumps | keep 4 |
| Weekly file archives | keep 4 |
| `.env.local` copies | keep 4 (secure storage) |

## Restore runbook

### 1. Database

1. Stop the Messenger worker to avoid writes during restore:

   ```bash
   systemctl --user stop messenger
   ```

2. Restore from the latest good `.dump`:

   ```bash
   cd ~/www/current
   set -a && source ../shared/.env.local && set +a
   RESTORE_CONFIRM=yes BACKUP_FILE=~/backups/ivena-stats-db-YYYYMMDD-HHMMSS.dump ./bin/ops/restore-database.sh
   ```

3. Clear cache and restart the worker:

   ```bash
   php bin/console cache:clear --env=prod
   systemctl --user start messenger
   ```

4. Smoke test: `curl -sS https://<host>/health | jq` (expect HTTP 200, `checks.database: ok`), then login, import list, statistics dashboard.

`pg_restore --clean --if-exists` drops and recreates objects from the dump. Schema drift after restore is unlikely if the dump matches the deployed code version; if not, run `php bin/console doctrine:migrations:status`.

### 2. Import and media files

```bash
cd ~/www
tar -xzf ~/backups/ivena-stats-files-YYYYMMDD-HHMMSS.tar.gz -C ~/www/shared
# archives contain paths relative to their source roots (var/imports, public/uploads/media)
```

Verify permissions on `shared/var/imports` and `shared/public/uploads/media` (writable by the web user).

### 3. Secrets (`.env.local`)

Only if the secrets file was lost or corrupted:

```bash
cp ~/backups/env-local-YYYYMMDD.bak ~/www/shared/.env.local
chmod 600 ~/www/shared/.env.local
systemctl --user restart messenger
```

Rotate `APP_SECRET` if there is any chance the backup was exposed.

## Restore test log

Record each restore drill (recommended before beta):

| Date | Environment | Backup file | Duration | Result | Notes |
|------|-------------|-------------|----------|--------|-------|
| | local Docker | | | | |

## Related docs

- [deployment.md](deployment.md) — Deployer, shared directories, worker
- [../06-reference/configuration.md](../06-reference/configuration.md) — `DATABASE_URL` and env variables
- [troubleshooting.md](troubleshooting.md) — runtime diagnostics
