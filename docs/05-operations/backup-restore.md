# Backup & restore

Operational guide for PostgreSQL, import files, and media uploads.

## Scope

| Asset | Default path (local) | Production (Deployer shared) |
|-------|----------------------|------------------------------|
| PostgreSQL database | from `DATABASE_URL` | from `shared/.env.local` → `DATABASE_URL` |
| Import uploads | `var/imports/` | `<deploy_path>/shared/var/imports/` ([paths on the server](deployment.md#paths-on-the-server)) |
| Media uploads | `public/uploads/media/` | `<deploy_path>/shared/public/uploads/media/` |
| Hospital isochrones | `var/geo/hospital-isochrones/` | `<deploy_path>/shared/var/geo/hospital-isochrones/` |
| Reference catalog packages | `var/reference/` | `<deploy_path>/shared/var/reference/` — private; keep copies in the private data repository, not in these backups only ([ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md)) |
| Encounter curation packages | `var/encounter/` | copied in by hand for an import; not a backup of the catalog or the plausibility table, which are in the database dump ([curation package](../04-features/encounter/curation-package.md)) |
| Secrets | `.env.local` | `<deploy_path>/shared/.env.local` — **back up separately**, never inside DB dumps |

Backups are written to `var/backups/` locally (override with `BACKUP_DIR`).

## Backup command

`app:backup:create` writes a full backup (database and all file components) with a manifest and checksums
([ADR 038](../02-architecture/decisions/038-verified-backup-format.md)):

```bash
php bin/console app:backup:create                        # shows the plan, asks, then runs
php bin/console app:backup:create --no-interaction       # unattended (cron)
php bin/console app:backup:create --target-dir=$HOME/backups # other directory than BACKUP_DIR
make backup                                              # unattended, Docker database when it runs
```

Each run creates one directory, for example `var/backups/ivena-stats-full-20261120T030000Z/`:

| File | Content |
|------|---------|
| `manifest.json` | format version, time (UTC), app version, executed migrations, rows per table, files and bytes per component, size and SHA-256 of every payload |
| `database.dump` | `pg_dump` custom format, schema `public` without PostGIS tables |
| `files/<component>.tar.gz` | regular files of `imports` (without `pending/`), `media`, `geo`, `reference`; paths relative to the component directory |
| `files/<component>.sha256` | SHA-256 of every file in that archive (`sha256sum -c` format) |

How it behaves:

- **Consistent while the app runs.** Dump, row counts and migration list come from one exported database
  snapshot. A file that changes while it is packed fails the run; start it again when imports are done.
- **Complete or nothing.** The backup is written as `.<name>.partial` and renamed at the end. A failed run removes
  its partial directory and exits with `1`; existing backups are never overwritten. A `.partial` directory left
  over from a killed run is not a backup: remove it.
- **Private.** Directories `0700`, files `0600`. Targets below `public/` are refused; a target inside a Deployer
  release gets a warning, because releases are deleted.
- **No secrets.** `.env.local` is not included (see the table above). The manifest holds counts and
  checksums only.
- **Tools.** Needs `tar` and the PostgreSQL client tools of the server's major version or newer.
  `BACKUP_PG_TOOLS=local` (default) uses `pg_dump` on `PATH`; `BACKUP_PG_TOOLS=docker` runs it in the Compose
  service `database` (from a git worktree also set `COMPOSE_PROJECT_NAME` to the main checkout's project).
  `make backup` picks `docker` when that service is running.

## Verify command

`app:backup:verify` checks a backup without restoring it and without changing anything:

```bash
php bin/console app:backup:verify                                     # newest backup in BACKUP_DIR
php bin/console app:backup:verify ivena-stats-full-20261120T030000Z   # by name in BACKUP_DIR
php bin/console app:backup:verify ~/backups/ivena-stats-full-20261120T030000Z # by path, e.g. a copied backup
make backup-verify                                                    # newest; BACKUP=<directory> for another
```

| Step | Checks |
|------|--------|
| Manifest | present, a known format version (a backup of a newer format is refused), every field typed |
| Payload checksums | every listed file exists with the recorded size and SHA-256; files that are not part of the backup are shown as warnings |
| Database dump | `pg_restore --list` reads the dump, and every table of the manifest has data in it |
| File archives | every entry is a regular file with a relative path (no `..`, links or devices); each file matches its line in `<component>.sha256`; number of files and bytes match the manifest |

The archives are read and hashed in PHP while they are streamed; nothing is extracted. All checks run even when
one fails, and the command lists every problem at the end. It exits with `1` when the backup has a problem or
cannot be read, so it can follow the backup in cron (see [Cron](#cron-operator-managed)). It needs
`pg_restore` (same `BACKUP_PG_TOOLS` switch as the backup) but no database connection.

Restoring these backups with `app:backup:restore` follows in the last step of #949. Until then, restore them by
hand as in the [restore runbook](#restore-runbook), after a successful verify.

## Scripts (legacy)

The scripts below predate the backup command. `backup-database.sh` and `backup-files.sh` write backups without
a manifest; `restore-database.sh` restores any custom-format dump, including `database.dump` of the new format.

| Script | Purpose |
|--------|---------|
| `bin/ops/backup-database.sh` | `pg_dump` custom format (`.dump`), schema `public` only (skips PostGIS schemas `tiger`/`topology` and PostGIS tables in `public`) |
| `bin/ops/backup-files.sh` | `tar.gz` of imports + media |
| `bin/ops/restore-database.sh` | Reset schema `public` (CASCADE), then `pg_restore` (requires `RESTORE_CONFIRM=yes`; skips PostGIS extensions/tables in the dump TOC) |
| `bin/ops/verify-restore.sh` | Capture/compare row counts for restore drills |

### Make targets

```bash
make backup         # full backup with manifest (app:backup:create)
make backup-verify  # verify the newest backup (app:backup:verify)
make backup-db      # legacy: database only
make backup-files   # legacy: imports + media only
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
- `shared/var/reference` (reference catalog packages, ADR 020)
- `shared/public/uploads/media`

### Manual backup commands

From the current release on the server (`<deploy_path>/current`):

```bash
# Database and files with manifest (requires pg_dump and tar on PATH; the paths follow the shared links)
cd <deploy_path>/current
php bin/console app:backup:create --target-dir=$HOME/backups --env=prod

# Secrets (store encrypted or offline — not in the git repo)
cp <deploy_path>/shared/.env.local ~/backups/env-local-$(date -u +%Y%m%d).bak
chmod 600 ~/backups/env-local-*.bak
```

Store backups **outside** the Deployer `releases/` tree (for example `~/backups/`) so deploys cannot delete them.

### Cron (operator-managed)

Schedule regular backups on the server (example — adjust paths and times):

```cron
# Daily full backup at 03:00 UTC (set BACKUP_DIR=$HOME/backups in shared/.env.local, or pass --target-dir)
0 3 * * * cd <deploy_path>/current && php bin/console app:backup:create --no-interaction --env=prod >> ~/backups/backup.log 2>&1 && php bin/console app:backup:verify --env=prod >> ~/backups/backup.log 2>&1

# Retention: delete backups older than 30 days (directories of the new format, files of the legacy scripts)
15 5 * * * find ~/backups -maxdepth 1 -name 'ivena-stats-*' -mtime +30 -exec rm -rf {} +
```

Cron is configured on Uberspace outside this repository.

### Suggested retention

| Type | Suggestion |
|------|------------|
| Daily full backups | keep 7 |
| Weekly full backups | keep 4 |
| `.env.local` copies | keep 4 (secure storage) |

## Restore runbook

### 1. Database

1. Stop the Messenger worker to avoid writes during restore:

   ```bash
   systemctl --user stop messenger
   ```

2. Restore from the latest good `.dump`:

   ```bash
   cd <deploy_path>/current
   set -a && source ../shared/.env.local && set +a
   RESTORE_CONFIRM=yes BACKUP_FILE=~/backups/ivena-stats-db-YYYYMMDD-HHMMSS.dump ./bin/ops/restore-database.sh
   ```

3. Clear cache and restart the worker:

   ```bash
   php bin/console cache:clear --env=prod
   systemctl --user start messenger
   ```

4. Smoke test: `curl -sS https://<host>/health | jq` (expect HTTP 200, `checks.database: ok`), then login, import list, statistics dashboard.

`restore-database.sh` drops and recreates schema `public` (`DROP SCHEMA public CASCADE`), then restores from the dump without `--clean`. The `SCHEMA - public` TOC entry is skipped because the script already created that schema. PostGIS extensions and tables (`spatial_ref_sys`, …) listed in the dump TOC are skipped so restores work on plain Postgres (e.g. local Docker) even when the dump came from a host with PostGIS. Schema drift after restore is unlikely if the dump matches the deployed code version; if not, run `php bin/console doctrine:migrations:status`.

For a backup of the new format run `app:backup:verify` on it first, then use its `database.dump` as `BACKUP_FILE`. Afterwards compare the row counts
with `table_rows` in its `manifest.json`.

### 2. Import and media files

Backups of the new format hold one archive per component, with paths relative to the component directory.
Check the archive against the manifest first (`sha256sum`), then extract it into the shared directory:

```bash
cd ~/backups/ivena-stats-full-YYYYMMDDTHHMMSSZ
sha256sum files/media.tar.gz          # compare with payloads in manifest.json
tar -xzf files/media.tar.gz -C <deploy_path>/shared/public/uploads/media
(cd <deploy_path>/shared/public/uploads/media && sha256sum -c ~/backups/ivena-stats-full-YYYYMMDDTHHMMSSZ/files/media.sha256)
```

Do the same for `imports` (`shared/var/imports`), `geo` (`shared/var/geo/hospital-isochrones`) and `reference`
(`shared/var/reference`). Legacy file archives:

```bash
cd <deploy_path>
tar -xzf ~/backups/ivena-stats-files-YYYYMMDD-HHMMSS.tar.gz -C <deploy_path>/shared
# archives contain paths relative to their source roots (var/imports, public/uploads/media)
```

Verify permissions on `shared/var/imports` and `shared/public/uploads/media` (writable by the web user).

### 3. Secrets (`.env.local`)

Only if the secrets file was lost or corrupted:

```bash
cp ~/backups/env-local-YYYYMMDD.bak <deploy_path>/shared/.env.local
chmod 600 <deploy_path>/shared/.env.local
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
