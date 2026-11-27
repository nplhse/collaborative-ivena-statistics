# ADR 039: Managed media storage outside the web root with checksums and integrity checks

**Status:** accepted

## Context

Media of the CMS and the blog (images and PDFs, entity `Media` in the Content context) are uploaded with
VichUploader into `public/uploads/media/`. Apache serves them directly; only a `.htaccess` in that directory keeps
uploaded scripts from running. All files lie in one flat directory, named after the original file with a unique
suffix (`SmartUniqueNamer`); `media.filename` holds only that name. The path `/uploads/media` is written in
several places (`MediaPublicUrlResolver`, `LocalMediaFileLocator`, `MediaCrudController`, `MediaFactory`,
`StoragePathClassifier`, Makefile, `.gitignore`, the demo overrides in `vich_uploader.yaml` and `services.yaml`).

`MediaSnippetGenerator` and `MediaImageFigureHtmlBuilder` write `/uploads/media/<file>` as literal HTML into blog
posts (`post.content`) and text blocks of pages (`page_translation.content` and its draft, ADR 036). Image blocks
store a `mediaId` and get their URL when they are rendered.

Issue #489 asks to move media out of `public/` into managed storage, to allow restricted media later (#580), to
prepare derived variants such as thumbnails, to migrate existing files safely and to keep them in the backups of
ADR 038. The review of the plan (2026-11-21) added:

- a flat directory will grow too large; files are to be partitioned;
- an integrity check that finds damaged and missing files and removes files no database row refers to any more,
  for example uploads of a demo installation that was reset;
- a command that rewrites the old URLs in existing content, so the old URLs can be dropped in v0.4 while content
  keeps working unchanged;
- no separate solution for the demo or other parts of the site.

Constraints:

- Production runs on Uberspace with Apache and Deployer; persistent data lives in `shared/` (ADR 038).
  `public/.htaccess` sends every request that is not an existing file to `index.php`.
- VichUploader is used only by `Media`.
- The application keeps running during a deploy, a backup and the integrity check.

## Decision

### Storage root and layout

Media live in one storage root, the parameter `app.media_storage_dir` (`var/media`). The demo environment keeps
its own root (`var/demo/media`) through the same parameter, as it already does for imports (ADR 023); code,
layout and URLs are the same in every environment. `make demo-uninstall` removes the demo with its root, so media of a demo that is no longer
used do not stay behind. Deployer keeps `var/media` in `shared_dirs`.

```
var/media/
  originals/<YYYY>/<MM>/<uuid>.<ext>            the uploaded file, never changed
  variants/<YYYY>/<MM>/<uuid>/<variant>.<ext>   derived files (later), regenerable
  .tmp/                                         files being written
```

- **Partition by year and month of the upload**, read from `ClockInterface`. Media of a CMS grow by tens to a few
  hundred files per month, so a month directory stays small, and operators can read the layout. Migrated files use
  the month of `Media::createdAt`.
- **The file name is a UUIDv7 and an extension derived from the detected MIME type** (`jpg`, `png`, `webp`,
  `pdf`), independent of the original name, which stays in `originalFilename`.
- **The storage key is the path below the root** (`originals/2026/11/0193….webp`) and is stored in
  `media.storage_key` (unique). The layout can later get another level (for example a hash prefix) for new files
  without touching existing ones, because every key is stored in full.
- A key never changes and its file is never overwritten. Replacing the file of a `Media` writes a new key and
  removes the old file after the commit.
- `.tmp/` is on the same filesystem as the files, so a finished file is moved into place with one `rename`.

### Own storage component instead of VichUploader

`MediaStorage` (Content, Infrastructure) is the only class that touches media files: store, open, delete (with
variants), resolve the path of a key and hash. The admin form uses a plain file field and an upload handler;
`vich/uploader-bundle` and its form theme are removed.

- **Store:** write to `.tmp/`, compute size and SHA-256 while writing, `rename` into `originals/…`, then set
  `storage_key`, `size` and `checksum_sha256` on the entity. If the database transaction then fails, the file stays
  as an orphan, and the integrity check removes it after the grace period.
- **Delete:** after the commit that removes the row (or replaces the file). A failed delete leaves an orphan for the
  integrity check, never a row without a file. A media item not yet moved takes its file in the old directory along,
  as VichUploader did.

New columns on `media`: `storage_key`, `checksum_sha256`, `verified_at` (last full hash check), `legacy_filename`
(the old name, for the transition) and `visibility` (`public`, `restricted`; default `public`). `filename` stays
until v0.4.

### Delivery

- `GET /media/<YYYY>/<MM>/<uuid>.<ext>` (the key without `originals/`) looks the key up in `media` and serves the file with `BinaryFileResponse` (range requests
  for PDFs, `ETag`, `Last-Modified`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline` with the
  original name). Only keys of existing rows are served, so no path from the request reaches the filesystem.
- Public media get `Cache-Control: public, max-age=31536000, immutable`, which is safe because the content of a
  key never changes. Restricted media are not delivered at all until #580 decides who may see them.
- The routes are stateless: no session, no consent cookie, no usage tracking. A file request therefore never reads
  the logged-in user (the Sentry request context skips the user on stateless routes as well).
- **Legacy route** `GET /uploads/media/{filename}`: a migrated file is redirected permanently (301) to its new
  URL; a file not yet migrated is served from the old directory, looked up by `media.filename` (cached for an hour),
  so the time between deploy and migration has no broken links. The route is removed in v0.4.

### Migration of existing files

`app:media:migrate-storage [--dry-run] [--legacy-dir=<dir>]` moves every row without `storage_key`:

1. The source is `<legacy-dir>/<filename>` (default: the old upload directory). A missing source is reported and
   the row stays as it is.
2. The file is **copied**, not moved, through `.tmp/` into `originals/<YYYY>/<MM>/` of `createdAt`; size and
   SHA-256 of the copy are compared with the source.
3. Only then `storage_key`, `checksum_sha256`, `verified_at` and `legacy_filename` are set, in one flush per
   row; a failed flush removes the copy again. A run that stops halfway leaves the remaining rows untouched; a second run skips migrated rows.
4. Files in the old directory without a row are listed, not copied.

The old directory is not changed. The previous release keeps working with it (rollback), and the files stay
available until v0.4.

### Rewriting old URLs in content

`app:media:rewrite-content-urls [--apply]` replaces `/uploads/media/<legacy_filename>` with the new URL in
`post.content` and in `page_translation.content` and its draft. It replaces only URLs whose `legacy_filename`
belongs to a migrated row and lists all others; a second run changes nothing. Without `--apply` it only previews.
The rewrite is a technical migration, not an edit: it changes neither `updated_at` nor the audit log, whose entries
are history.

### Integrity check and cleanup

`app:media:check` compares the storage root with `media`:

| Finding | Detection | Action |
|---|---|---|
| missing | a row whose file does not exist; until v0.4 also a row not yet moved whose old file is gone | reported, admins notified |
| damaged | size or SHA-256 differs from the row | reported, admins notified; the file stays for analysis and restore |
| orphan | a file below `originals/` or `variants/` that no row refers to | **deleted** |
| leftover | a file in `.tmp/` | **deleted** |
| outside the layout | any other file below the root | listed only |

- **Quick check** (every run): existence and size of every row, and the orphans. It reads no file contents.
- **Rolling full check:** each run also hashes the files with the oldest `verified_at` up to a byte budget, so every
  file is hashed about every 30 days without reading all of them each time. `--full` hashes every file at once.
- The scheduler runs the check daily (a schedule contribution, ADR 019); `--dry-run` reports what would be deleted.
- The result of the last run is stored and read by a health check contributor `media` (one query, no filesystem
  access in `/health`). Missing or damaged files make it `degraded`. Admins are notified through the notification
  system (ADR 016), once per set of affected files. Results are kept for 90 days.

**Deleting orphans** is automatic only when all of these hold:

- the file is older than a grace period (24 hours), so an upload whose row is not committed yet is safe;
- no backup or restore runs (the check takes the lock `backup-maintenance` of ADR 038);
- `media` is not empty, and the orphans are at most 10 or at most 20 % of the files; more points to a wrong
  database or an incomplete restore, so the run deletes nothing and reports instead. A demo reset restores database
  and files from one snapshot, so it leaves no orphans behind.

Every deletion is logged with key, size and modification time. Missing and damaged files are never fixed
automatically: the way back is a restore from a backup.

### Backups

The backup component `media` (ADR 038) reads `app.media_storage_dir`; archive paths become `originals/…`. The format
stays at version 1, because paths were already relative to the component directory. `variants/` and `.tmp/` are
excluded: variants are derived. A backup made before this change has flat files and a database without
`storage_key`; after its restore, the restore asks to migrate, and `app:media:migrate-storage
--legacy-dir=<storage root>` moves the flat files into the layout.

### Deploy

`shared_dirs` and `writable_dirs` get `var/media` instead of `public/uploads/media`. After `database:migrate` and
before `deploy:symlink`, Deployer runs `app:media:migrate-storage --legacy-dir={{deploy_path}}/shared/public/uploads/media`.
It does nothing when nothing is left. A failure stops the deploy before the switch, and the previous release keeps
serving the old directory, so `dep rollback` keeps working.

`app:media:rewrite-content-urls` is not part of the deploy: once content points to `/media/…`, a release before this
change cannot show those images. It runs once after the release is accepted (Deployer task `media:rewrite-content-urls`,
upgrade guide); without `--apply` it only previews. The old task `media:migrate-to-shared` is removed.

### Layers

The console commands and the delivery route depend on ports in `Content/Application/Contract`
(`MediaFileLookupInterface`, `MediaStorageMigratorInterface`, `MediaIntegrityCheckerInterface`,
`MediaUrlRewriterInterface`), implemented in `Content/Infrastructure/Media`, so UI and Application do not depend on
Infrastructure (ADR 007). The admin notification lives in `Content/Infrastructure/Notification`; Content's
infrastructure may depend on `Notification` for it, as Encounter's does (ADR 027). The integrity check is scheduled
from Content (job `media_integrity_check`).

### Removed in v0.4

The legacy route, the columns `filename` and `legacy_filename`, the old directory on the server and its entry in the
storage monitoring. Content no longer needs them once the rewrite has run.

**Removed in v0.4-beta (#1450).** v0.3 is a mandatory upgrade stop, so every installation has moved its files before
it reaches v0.4. Removed are the route `/uploads/media/{filename}` (it answers 404 now), the parameter
`app.media_legacy_dir` and every code path that read the old directory or the old file names, the commands
`app:media:migrate-storage` (including `--legacy-dir`) and `app:media:rewrite-content-urls` with their Deployer tasks
and ports (`MediaStorageMigratorInterface`, `MediaUrlRewriterInterface`), and the storage monitoring rules for
`public/uploads/media` and `public/uploads/demo`. A migration drops `media.filename` and `media.legacy_filename` with
their indexes and makes `storage_key` required; it stops while a row has no storage key. The lock name
`backup-maintenance` moved from the removed migrator to `MediaIntegrityChecker::LOCK`. A backup from before v0.3
(flat files, no `storage_key`) is restored with a v0.3 release first, then upgraded. The sections above describe the
v0.3 transition as it was decided.

## Alternatives considered

- **Keep VichUploader with a date directory namer.** Less code, but Vich computes the path from the object at runtime
  and stores only a file name; the key would not be stored, and checksums, atomic writes and the integrity check
  would sit next to Vich instead of in one place. Rejected; the user chose an own component.
- **Partition by hash prefix** (`ab/cd/<name>`). Spreads files evenly at any volume, but the layout tells an operator
  nothing, and the volume of a CMS does not need it. Rejected for now; a stored key allows it later for new files.
- **Keep files in `public/` and restrict access with `.htaccess`.** No PHP per request, but restricted media need an
  authorization decision of the application, and the old directory listing and script rules stay a risk. Rejected.
- **Move files instead of copying them during the migration.** Needs no space for a copy, but a failed or rolled-back
  deploy would leave the previous release without its files. Rejected; the old directory is removed in v0.4.
- **Only redirect the old URLs, never rewrite content.** Simpler, but the redirect would have to stay forever.
  Rejected; the redirect is a fallback for external links during the transition.
- **Move orphans to a quarantine instead of deleting them.** Safer against a wrong decision, but a quarantine needs its
  own retention and would keep uploads of reset demos. Rejected; the safety rules above prevent mass deletion, and the
  backups hold the files.
- **Mark a storage root with the installation it belongs to.** Would stop the cleanup of one installation from deleting
  files of another one that shares the directory. Not done: installations do not share a storage root (the demo has its
  own), and the orphan rule stops a mass deletion.

## Consequences

- Every media request runs PHP. Long cache lifetimes keep repeated requests cheap; the volume of a CMS allows it.
- Restricted media (#580) and variants can be added without moving files again.
- Damaged or missing files show up in `/health` and as admin notifications, at the latest after about 30 days for
  damage inside a file and after one day for missing files.
- Files without a row disappear one day after the row; files of a deleted `Media` are no longer kept anywhere but in
  the backups.
- For the transition the server holds the media twice (old directory and new storage) until the cleanup in v0.4.
- Restoring a backup from before this change needs the extra migration step described above.
- After the content rewrite, a rollback to a release before this change shows broken images in the rewritten content;
  the rewrite therefore runs only after the release is accepted.
