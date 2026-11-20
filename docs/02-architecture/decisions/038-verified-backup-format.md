# ADR 038: Verified backups as one directory with a manifest

**Status:** accepted

## Context

Backups were made by shell scripts in `bin/ops/`: a `pg_dump` file and a `tar.gz` of imports and media,
written independently, with no description of what they contain (#949). An operator could not tell whether a
dump and an archive belonged together, whether a file was damaged, or whether a restore brought back what was
backed up. Row counts for a restore drill had to be captured by hand at another time than the dump.

Issue #949 asks for Symfony commands that create, verify and restore backups with a versioned, self-describing
format. The triage of 2026-11-18 limits v0.3 to a full backup with manifest, a verify command and a guided
restore; partial backups follow later. Two later issues must fit the same format: #489 moves media out of
`public/` into managed storage, #727 adds archiving and checksums for import source files.

Constraints:

- Production runs on Uberspace with Deployer (`shared/` holds `var/imports`, `var/reference`,
  `var/geo/hospital-isochrones`, `public/uploads/media`) and PostgreSQL in the same account. Local development
  runs PostgreSQL in Docker Compose and usually has no client tools on the host.
- The application keeps running during a backup; imports and the worker may write.
- No new encryption, signing or remote storage (out of scope of #949).

## Decision

### Format

A backup is one directory named `ivena-stats-<type>-<YYYYMMDDTHHMMSSZ>` (UTC; type `full` for now):

```
manifest.json            written last
database.dump            pg_dump custom format, schema public without PostGIS tables
files/<component>.tar.gz regular files of one component
files/<component>.sha256 SHA-256 per file, sha256sum format
```

- It is written below `.<name>.partial` and renamed when everything, including the manifest, is written. A
  directory with the final name is therefore always complete; a failed run removes its own partial directory
  and nothing else. Existing backups are never overwritten.
- Directories are created with mode `0700`, files with `0600`. Targets below `public/` are refused; a target
  inside a Deployer release gets a warning, because the release is deleted later.

### Manifest

`manifest.json` holds `format` (`ivena-stats-backup`) and `format_version` (1), the id, the type, `created_at`
with an explicit offset, the application version and environment, the database server and dump tool versions,
the **full list of executed migrations** (in timestamp order, ADR 037) and the **row count of every table** in
schema `public`. Per file component it holds the number of files, their bytes, skipped symbolic links and
whether the source directory was missing. Per payload file it holds size and SHA-256.

It contains no records, no personal data, no credentials and no absolute paths. File lists with names stay in
the `.sha256` payloads.

The full migration list lets a restore tell an older backup (a subset of the migrations the code knows; it may
be restored and then migrated) from a newer one (migrations the code does not know; refused).

### Components

Components have logical names, independent of where their files are today:

| Component | Source | Notes |
|---|---|---|
| `database` | schema `public` | PostGIS tables (`spatial_ref_sys`, …) excluded, as before |
| `imports` | `app.imports_base_dir` | without `pending/` (temporary uploads, #977) |
| `media` | `app.media_upload_dir` | keeps its name when #489 moves the files |
| `geo` | `app.hospital_isochrones_dir` | prepared locations are derived and not included |
| `reference` | `var/reference` | a copy in the private data repository is still required (ADR 020) |

`.env.local` is not part of a backup; it is backed up separately. A component without files writes no archive.
Symbolic links are never followed or packed; they are counted in the manifest.

### Consistency

- The database is read in one read-only `REPEATABLE READ` transaction that exports its snapshot
  (`pg_export_snapshot()`). `pg_dump --snapshot` reads exactly that snapshot, and the row counts and executed
  migrations are read in the same transaction. Dump and manifest describe the same state while the application
  keeps running.
- Files are listed and hashed first; tar packs exactly that list. Afterwards size and modification time of every
  file are compared again: a file that changed during the backup fails the run instead of producing an
  inconsistent archive.
- Backup and restore share the lock `backup-maintenance`, so they never run at the same time.

### PostgreSQL tools

`BACKUP_PG_TOOLS=local` (default, the server) runs `pg_dump` from `PATH` against the configured connection,
with the password in `PGPASSWORD` and never as an argument. `BACKUP_PG_TOOLS=docker` (local development) runs
the tools inside the Compose service `database` and copies the files with `docker compose cp`. All external
commands are argument lists without a shell.

### Commands

`app:backup:create` creates a backup (this decision, #949 step 1). `app:backup:verify` and `app:backup:restore`
follow in the next steps of #949 and read this format. Make targets are thin wrappers; the scripts in
`bin/ops/` remain for backups made before this format.

## Alternatives considered

- **One archive file per backup** (a single `tar` with everything inside). One file is easier to copy, but
  checking a checksum or reading the manifest means reading or unpacking the whole archive, and `pg_restore`
  cannot read a dump nested in an archive without extracting it first. Rejected; a directory can still be
  packed by the operator for transport.
- **Keep the shell scripts and add a manifest script.** Bash would have to parse `DATABASE_URL`, JSON and
  migration state; the restore guidance (#949) needs interactive selection and checks that Symfony Console and
  Doctrine already provide. Rejected.
- **Counts taken separately from the dump** (as `verify-restore.sh` did). Simpler, but counts and dump can then
  describe different states while the application writes. Rejected in favour of the exported snapshot.
- **ZIP via ext-zip instead of tar.** Would avoid an external tool, but adds a new platform requirement;
  `tar` is present on every target host. Rejected.
- **Per-file checksums inside the manifest.** Would make the manifest grow with every import file. They live in
  the `.sha256` payloads instead, which are covered by the manifest checksums.

## Consequences

- One format for #949, #489 and #727; partial backups can add components and types without a new format.
- Backups made with the old scripts have no manifest; they are restored with `bin/ops/restore-database.sh` and
  `tar` as documented in [backup-restore.md](../../05-operations/backup-restore.md).
- The server needs `pg_dump` of the server's major version or newer, and `tar`.
- Checksums detect damage, not tampering: whoever can replace a backup can replace its manifest too.
