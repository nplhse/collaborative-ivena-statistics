# Backup & restore

Operational guide for PostgreSQL, import files, and media files.

## Scope

| Asset | Default path (local) | Production (Deployer shared) |
|-------|----------------------|------------------------------|
| PostgreSQL database | from `DATABASE_URL` | from `shared/.env.local` → `DATABASE_URL` |
| Import uploads | `var/imports/` | `<deploy_path>/shared/var/imports/` ([paths on the server](deployment.md#paths-on-the-server)) |
| Media files | `var/media/` ([media storage](../04-features/content/media-storage.md)) | `<deploy_path>/shared/var/media/`; before v0.3 `shared/public/uploads/media/`, not read or backed up since v0.4-beta |
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
- The database user must own schema `public`.
- **Media variants** are derived and not part of a backup; the restore replaces `var/media` with the backed-up
  originals.

Afterwards: run the migrations if the command asks for it, `php bin/console cache:clear`, start the Messenger
worker, and check the application (see [Restore runbook](#restore-runbook)).

### Backups from before v0.3

A backup made before the media storage (ADR 039) holds the media as flat files (`files/media.tar.gz` without
`originals/`) and a database without storage keys. Releases from v0.4-beta on can no longer move such files
(#1450): restore the backup with a **v0.3 release first**, then follow its guide (this section of
`docs/05-operations/backup-restore.md` in v0.3: migrations, `app:media:migrate-storage --legacy-dir=<storage root>`,
`app:media:rewrite-content-urls --apply`, `app:media:check --full`). Once every media item is in the storage, upgrade
to the current release. Its migrations stop with a message while a media item has no storage key.

## Make targets

```bash
make backup         # full backup with manifest (app:backup:create)
make backup-verify  # verify the newest backup (app:backup:verify)
make backup-restore # restore the newest backup, asks first (app:backup:restore)
```

`bin/ops/scheduled-backup.sh` and `bin/ops/pull-backups.sh` run the backup command on a schedule
([scheduled backups](#scheduled-backups)). The older scripts `backup-database.sh`, `backup-files.sh`,
`restore-database.sh` and `verify-restore.sh` were removed in v0.4-beta (#1449): the backup command covers them, and
the manifest holds the row count of every table that `verify-restore.sh` compared. Their backups have no manifest;
restore them with the scripts of a v0.3 release.

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

### Quick backup only

1. Start the database:

   ```bash
   docker compose up -d database
   ```

2. Create a backup of database and files (uses the Docker database when the `database` service is running):

   ```bash
   make backup
   ```

### Ad-hoc SQL (optional)

```bash
php bin/console dbal:run-sql "SELECT COUNT(*) FROM allocation"
docker compose exec database psql -U app -d app -c 'SELECT COUNT(*) FROM "user";'
```

If Docker is not running, the backup targets use `pg_dump` / `pg_restore` of the host (`BACKUP_PG_TOOLS=local`) with `DATABASE_URL`.

## Production (Uberspace)

Deployer keeps persistent data outside release directories ([deployment.md](deployment.md)):

- `shared/.env.local`
- `shared/var/imports`
- `shared/var/reference` (reference catalog packages, ADR 020)
- `shared/var/media` (media storage, ADR 039; `shared/public/uploads/media` of releases before v0.3 is no longer read since v0.4-beta)

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

The policy (#1568):

- **Server:** one full backup **every Wednesday at 03:00 UTC**. The server keeps only the newest backup, and the
  off-site copy deletes it there once it is copied and checked. So the server holds at most one backup, for a
  few hours.
- **Off-site:** the copies are kept for at most **90 days**, about 13 weekly backups. With the current size of
  about 300 MB per backup this is roughly 4 GB.
- **Data loss:** a week of uploads can be lost when the server fails between two backups. Participants keep their
  export files, so imports can be repeated.

`bin/ops/scheduled-backup.sh` runs on the server:

1. It runs `app:backup:create`, then `app:backup:verify` on the new backup.
2. Only after both succeed does it delete older backups, so that only the newest `--keep` backups stay (default 1:
   the new one).
3. It deletes only directories named like a finished backup (`ivena-stats-full-<UTC time>`). `.partial`
   directories and other files stay.
4. Any failure ends it with a non-zero exit code. Cron mails only what a job prints, not its exit code, so with
   `--log FILE` the script appends its whole output to the file, prints nothing on success and, on a failure, the
   last 40 lines of the run: that is what cron mails (#1709).

```bash
bin/ops/scheduled-backup.sh --dry-run                  # what it would run and delete
bin/ops/scheduled-backup.sh --target-dir $HOME/backups # default: BACKUP_DIR, else $HOME/backups
bin/ops/scheduled-backup.sh --keep 2                   # keep the two newest backups instead of one
bin/ops/scheduled-backup.sh --log $HOME/backups/backup.log # output into the log, printed only on failure
```

Crontab on the server (`crontab -e` on Uberspace; cron is configured there, not in this repository):

```cron
MAILTO=<operator mail address>
# Weekly full backup, Wednesdays 03:00 UTC: create, verify, then delete all older backups; mails only on failure
0 3 * * 3 <deploy_path>/current/bin/ops/scheduled-backup.sh --log $HOME/backups/backup.log
```

Keep the entry on one line when you paste it into `crontab -e`; a line broken by the editor or the terminal fails
with `bad minute`. Do not redirect the output (`>> … 2>&1`): cron would then have nothing to mail. To check that
failures arrive, run the script once with a target directory it cannot write to, for example
`bin/ops/scheduled-backup.sh --target-dir /nonexistent/backups --log /tmp/backup-test.log`, and check the mail of
the crontab's `MAILTO` (or run it from cron with that option for one night).

Cron runs in the server's time zone; on Uberspace that is UTC. The script uses `bin/console` of the current release
with `APP_ENV=prod`, and needs `pg_dump` and `pg_restore` on cron's `PATH`.

The `.env.local` secrets are not part of the backup (see [scope](#scope)). Copy them by hand after every change,
store them encrypted or offline, and keep the last four copies.

### Off-site copy

`bin/ops/pull-backups.sh` runs on the operator's machine:

1. It copies the finished full backups from the server with `rsync` over SSH.
2. It checks every new copy against the sizes and SHA-256 checksums in its manifest. This is the first part of
   `app:backup:verify`, and it needs `php` but no database. With `--delete-from-server` it also checks every
   backup that is still on the server, including one copied in an earlier run.
3. Only if all these checks pass does it delete anything. With `--delete-from-server` it first deletes those
   backups on the server.
4. Then it deletes local copies older than `--retention-days` (default 90) and, with `--keep N`, all but the newest
   N copies. The newest local copy always stays.

```bash
bin/ops/pull-backups.sh --from <user>@<host>:backups --to ~/Backups/ivena-stats --delete-from-server --dry-run
bin/ops/pull-backups.sh --from <user>@<host>:backups --to ~/Backups/ivena-stats --delete-from-server
bin/ops/pull-backups.sh --from <user>@<host>:backups --to ~/Backups/ivena-stats --keep 4   # at most four local copies
```

Write the server path relative to the home directory (`backups`) or absolute, not with `~`.

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
        <string>--delete-from-server</string>
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

Restore `.env.local` separately if needed ([Secrets](#secrets-envlocal)).

Restore the database only with the command: it checks the dump and the row counts against the manifest. The
manual steps below restore single file components, for example after a lost directory.

### Import and media files

A backup holds one archive per component, with paths relative to the component directory.
Check the archive against the manifest first (`sha256sum`), then extract it into the shared directory:

```bash
cd ~/backups/ivena-stats-full-YYYYMMDDTHHMMSSZ
sha256sum files/media.tar.gz          # compare with payloads in manifest.json
tar -xzf files/media.tar.gz -C <deploy_path>/shared/var/media
(cd <deploy_path>/shared/var/media && sha256sum -c ~/backups/ivena-stats-full-YYYYMMDDTHHMMSSZ/files/media.sha256)
```

Do the same for `imports` (`shared/var/imports`), `geo` (`shared/var/geo/hospital-isochrones`) and `reference`
(`shared/var/reference`).

Verify permissions on `shared/var/imports` and `shared/var/media` (writable by the web user). A media archive of the backup command from before v0.3 needs the extra step in [Backups from before v0.3](#backups-from-before-v03).

### Secrets (`.env.local`)

Only if the secrets file was lost or corrupted:

```bash
cp ~/backups/env-local-YYYYMMDD.bak <deploy_path>/shared/.env.local
chmod 600 <deploy_path>/shared/.env.local
systemctl --user restart messenger
```

Rotate `APP_SECRET` if there is any chance the backup was exposed, as described in
[secrets and rotation](../06-reference/configuration.md#secrets-and-rotation). Never change `APP_HASH_KEY`.

## Restore test log

Record each restore drill (recommended before beta):

| Date | Environment | Backup file | Duration | Result | Notes |
|------|-------------|-------------|----------|--------|-------|
| | local Docker | | | | |

## Related docs

- [deployment.md](deployment.md) — Deployer, shared directories, worker
- [../06-reference/configuration.md](../06-reference/configuration.md) — `DATABASE_URL` and env variables
- [troubleshooting.md](troubleshooting.md) — runtime diagnostics
