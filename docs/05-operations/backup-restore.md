# Backup & restore

Operational guide for PostgreSQL, import files, and media files.

## Scope

| Asset | Default path (local) | Production (Deployer shared) |
|-------|----------------------|------------------------------|
| PostgreSQL database | from `DATABASE_URL` | from `shared/.env.local` → `DATABASE_URL` |
| Import uploads | `var/imports/` | `<deploy_path>/shared/var/imports/` ([paths on the server](deployment.md#paths-on-the-server)) |
| Media files | `var/media/` ([media storage](../04-features/content/media-storage.md)) | `<deploy_path>/shared/var/media/`; before v0.3 `shared/public/uploads/media/`, kept until v0.4 and not backed up any more |
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
| `database.dump` | `pg_dump` custom format, schema `public` without PostGIS tables and without the rows of `lock_keys` (runtime locks, #1452) |
| `files/<component>.tar.gz` | regular files of `imports` (without `pending/`), `media` (without `variants/` and `.tmp/`), `geo`, `reference`; paths relative to the component directory |
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
cannot be read, so it can follow the backup in cron (see [scheduled backups](#scheduled-backups)). It needs
`pg_restore` (same `BACKUP_PG_TOOLS` switch as the backup) but no database connection.

## Restore command

`app:backup:restore` replaces the database and the file directories with a backup:

```bash
php bin/console app:backup:restore                                    # newest backup in BACKUP_DIR; shows the plan, asks
php bin/console app:backup:restore ivena-stats-full-20261120T030000Z  # by name or path, as for verify
php bin/console app:backup:restore <backup> --no-interaction --confirm-destructive # unattended
php bin/console app:backup:restore <backup> --keep-previous           # with a way back (needs space for a second copy)
make backup-restore                                                   # newest; BACKUP=<directory> for another
```

Before anything is replaced, the command shows the plan (backup, target database, every target directory) and
asks; unattended runs must pass `--confirm-destructive`. It then runs these steps:

| Step | What happens |
|------|--------------|
| Preflight | lock (no backup or restore at the same time), `pg_restore` available, no messages waiting in the queues (`failed` does not count), target directories writable |
| Backup verification | the complete check of `app:backup:verify`; any problem stops the restore |
| File staging | each archive is extracted next to its target directory (`.<dir>.restore-<time>`) and checked file by file against its checksum list |
| Database restore | schema `public` is dropped and recreated (with `--keep-previous`: renamed to `pre_restore_<time>`), the dump is restored into the new `public` in one transaction (PostGIS objects and the rows of `lock_keys` skipped), and the row count of every table must match the manifest (`lock_keys` is not counted) |
| File switch | each target directory is renamed to `<dir>.pre-restore-<time>`, the staged directory takes its place |
| Completion | the replaced directories are removed (with `--keep-previous`: the previous schema is dropped, the directories are kept) |

How it behaves:

- **Checks first.** Verify, queue check and file staging run before the database is touched; a failure there
  changes nothing.
- **Default: the current database is dropped before the restore.** This needs no space for a second copy of the
  database. If the restore fails after that, the database is empty (or differs from the backup) and the error
  says so: fix the cause and restore again. **Create a backup before a restore** if you may need the current
  state again.
- **`--keep-previous`: a way back.** The current schema is renamed instead and moved back on any failure, and the
  replaced directories are kept as `<dir>.pre-restore-<time>` for you to remove once the application works. This
  needs free space for a second copy of the database.
- **Files.** The staged files need space for a second copy of the file components until the switch. A failure
  while switching switches the directories back (with `--keep-previous` also the database). The error says what
  the failure left behind, for example "The database and the files were not changed."
- **Older backups** (fewer migrations than the application) are restored; the command then asks you to run
  `php bin/console doctrine:migrations:migrate`. **Newer backups** (migrations this application does not know)
  are refused: restore them with the version that made them.
- **Deployer links** are followed: the shared directory is replaced, the link in the release stays.
- A component whose directory was **missing when the backup was made** is left unchanged (warning in the plan).
- Messages that were waiting in the queues when the backup was made are restored with the database and are
  handled again once the worker runs.
- The database user must own schema `public` (as for `restore-database.sh`).
- **Media variants** are derived and not part of a backup; the restore replaces `var/media` with the backed-up
  originals.

Afterwards: run the migrations if the command asks for it, `php bin/console cache:clear`, start the Messenger
worker, and check the application (see [Restore runbook](#restore-runbook)).

### Backups from before v0.3

A backup made before the media storage (ADR 039) holds the media as flat files (`files/media.tar.gz` without
`originals/`) and a database without storage keys. After restoring it into v0.3, run the migrations as the restore
asks, then move the flat files into the storage layout:

```bash
php bin/console doctrine:migrations:migrate
php bin/console app:media:migrate-storage --legacy-dir=var/media     # on the server: --legacy-dir=<deploy_path>/shared/var/media
php bin/console app:media:rewrite-content-urls --apply
php bin/console app:media:check --full
```

`app:media:migrate-storage` removes each flat file once it is moved, because it was given the storage root itself.

## Scripts (legacy)

Not to be confused with `bin/ops/scheduled-backup.sh` and `bin/ops/pull-backups.sh`, which run the backup command
on a schedule ([scheduled backups](#scheduled-backups)). The scripts below predate the backup command. `backup-database.sh` and `backup-files.sh` write backups without
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
make backup-restore # restore the newest backup, asks first (app:backup:restore)
make backup-db      # legacy: database only
make backup-files   # legacy: imports + media only
make restore-db BACKUP_FILE=var/backups/ivena-stats-db-....dump RESTORE_CONFIRM=yes
make verify-restore-baseline   # save metrics snapshot
make verify-restore            # compare after restore
```

## Local development (Docker PostgreSQL)

### Restore drill (recommended)

With the backup commands the drill is short; the restore itself compares every table with the manifest:

```bash
docker compose up -d database
make backup                     # full backup of database and files
make backup-verify
make backup-restore             # shows the plan, asks, restores the newest backup
php bin/console cache:clear
```

Change some data between backup and restore (for example delete an import) to see it come back. To try the way
back, run the restore with `--keep-previous` and remove the `*.pre-restore-*` directories afterwards.

#### Legacy drill (scripts)

Use `verify-restore.sh` to prove a round-trip of the legacy scripts without hand-written SQL.
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
- `shared/var/media` (media storage, ADR 039; `shared/public/uploads/media` of releases before v0.3 stays until v0.4)

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

### Scheduled backups

The policy (#1568): one full backup **every Wednesday at 03:00 UTC**, kept for **90 days**, and a copy outside the
server. That gives about 13 backups on the server and 13 copies off-site; with the current size of about 300 MB
per backup this is roughly 4 GB in each place. A week of uploads can be lost when the server fails between two
backups; participants keep their export files, so imports can be repeated.

`bin/ops/scheduled-backup.sh` runs on the server. It runs `app:backup:create` and then `app:backup:verify` on the
new backup. Only after both succeed does it delete backups older than the retention, and it always keeps the
newest one. It deletes only directories named like a finished backup (`ivena-stats-full-<UTC time>`); `.partial`
directories and files of the legacy scripts stay. Any failure ends it with a non-zero exit code, so cron mails
the output.

```bash
bin/ops/scheduled-backup.sh --dry-run                   # what it would run and delete
bin/ops/scheduled-backup.sh --target-dir=$HOME/backups  # default: BACKUP_DIR, else $HOME/backups
bin/ops/scheduled-backup.sh --retention-days=90         # default 90
```

Crontab on the server (`crontab -e` on Uberspace; cron is configured there, not in this repository):

```cron
MAILTO=<operator mail address>
# Weekly full backup, Wednesdays 03:00 UTC: create, verify, then delete backups older than 90 days
0 3 * * 3 cd <deploy_path>/current && bin/ops/scheduled-backup.sh --target-dir=$HOME/backups >> $HOME/backups/backup.log 2>&1
```

Cron runs in the server's time zone; on Uberspace that is UTC. The script uses `bin/console` of the current release
with `APP_ENV=prod`, and needs `pg_dump` and `pg_restore` on cron's `PATH`.

The `.env.local` secrets are not part of the backup (see [scope](#scope)). Copy them by hand after every change,
store them encrypted or offline, and keep the last four copies.

### Off-site copy

`bin/ops/pull-backups.sh` runs on the operator's machine:

1. It copies new full backups from the server with `rsync` over SSH.
2. It checks every newly copied backup against the sizes and SHA-256 checksums in its manifest. This is the first
   part of `app:backup:verify`, and it needs `php` but no database.
3. Only if every new copy passes, it deletes local copies older than the retention, always keeping the newest one.

```bash
bin/ops/pull-backups.sh --from <user>@<host>:backups --to ~/Backups/ivena-stats --dry-run
bin/ops/pull-backups.sh --from <user>@<host>:backups --to ~/Backups/ivena-stats   # --retention-days=90 by default
```

On macOS, a `launchd` job can run it weekly, a few hours after the server backup. If the machine sleeps at that
time, `launchd` runs the job when it wakes. The repository does not install this job. Save the following as
`~/Library/LaunchAgents/io.versorgungsforschung.pull-backups.plist`, adjust the paths, then run
`launchctl load ~/Library/LaunchAgents/io.versorgungsforschung.pull-backups.plist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>io.versorgungsforschung.pull-backups</string>
    <key>ProgramArguments</key>
    <array>
        <string>/path/to/collaborative-ivena-statistics/bin/ops/pull-backups.sh</string>
        <string>--from</string>
        <string>user@host:backups</string>
        <string>--to</string>
        <string>/Users/you/Backups/ivena-stats</string>
    </array>
    <!-- Wednesdays 09:00 local time, after the server backup at 03:00 UTC -->
    <key>StartCalendarInterval</key>
    <dict>
        <key>Weekday</key>
        <integer>3</integer>
        <key>Hour</key>
        <integer>9</integer>
        <key>Minute</key>
        <integer>0</integer>
    </dict>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>
    <key>StandardOutPath</key>
    <string>/Users/you/Backups/ivena-stats/pull.log</string>
    <key>StandardErrorPath</key>
    <string>/Users/you/Backups/ivena-stats/pull.log</string>
</dict>
</plist>
```

The SSH key for the server must work without a prompt (an agent or a key without a passphrase that is limited to
this host). Check `pull.log` from time to time, or after a failed run, which `launchd` does not report.

## Restore runbook

### Backups with a manifest (app:backup:create)

1. Stop the Messenger worker to avoid writes during restore:

   ```bash
   systemctl --user stop messenger
   ```

2. If you may need the current state again (for example a restore after a bad import, not after a lost
   database), back it up first; the restore drops the current database:

   ```bash
   cd <deploy_path>/current
   php bin/console app:backup:create --no-interaction --env=prod
   ```

3. Restore database and files (shows the plan and asks before it changes anything). Add `--keep-previous` only
   when there is free space for a second copy of the database:

   ```bash
   php bin/console app:backup:restore ~/backups/ivena-stats-full-YYYYMMDDTHHMMSSZ --env=prod
   ```

4. If the command says the backup is behind the application, run the migrations:

   ```bash
   php bin/console doctrine:migrations:migrate --env=prod
   ```

5. Clear cache and restart the worker:

   ```bash
   php bin/console cache:clear --env=prod
   systemctl --user start messenger
   ```

6. Smoke test: `curl -sS https://<host>/health | jq` (expect HTTP 200, `checks.database: ok`), then login, import
   list, statistics dashboard. With `--keep-previous`, then remove the `*.pre-restore-*` directories the command
   listed.

Restore `.env.local` separately if needed ([3. Secrets](#3-secrets-envlocal)).

The manual steps below are for legacy backups and for a restore without the command.

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
tar -xzf files/media.tar.gz -C <deploy_path>/shared/var/media
(cd <deploy_path>/shared/var/media && sha256sum -c ~/backups/ivena-stats-full-YYYYMMDDTHHMMSSZ/files/media.sha256)
```

Do the same for `imports` (`shared/var/imports`), `geo` (`shared/var/geo/hospital-isochrones`) and `reference`
(`shared/var/reference`). Legacy file archives:

```bash
cd <deploy_path>
tar -xzf ~/backups/ivena-stats-files-YYYYMMDD-HHMMSS.tar.gz -C <deploy_path>/shared
# archives contain paths relative to their source roots (var/imports, and for media public/uploads/media before v0.3, var/media since)
```

Verify permissions on `shared/var/imports` and `shared/var/media` (writable by the web user). A restored media archive from before v0.3 needs the extra step in [Backups from before v0.3](#backups-from-before-v03).

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
