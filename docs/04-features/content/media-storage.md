# Media storage

How the media library stores, delivers and checks its files (#489,
[ADR 039](../../02-architecture/decisions/039-managed-media-storage.md)). Operations: [deployment.md](../../05-operations/deployment.md#media-files-shared-across-releases),
[backup-restore.md](../../05-operations/backup-restore.md).

## Storage

Media items (`Media`, admin area *Media library*) are images (JPEG, PNG, WebP) and PDFs of at most 10 MB. Their files
live below the storage root `app.media_storage_dir` (`var/media`; demo: `var/demo/media`):

| Path | Content |
|---|---|
| `originals/<YYYY>/<MM>/<uuid>.<ext>` | the uploaded file, in the month of the upload; never changed |
| `variants/<YYYY>/<MM>/<uuid>/` | derived files such as thumbnails (prepared, none yet); not backed up |
| `.tmp/` | files being written; not backed up |

`media.storage_key` holds the path below the root, `checksum_sha256` and `size` describe the file, `verified_at` is the
last full hash check. The name and extension come from a UUIDv7 and the MIME type detected from the content, not from the
uploaded name, which stays in `original_filename`. Only `MediaStorage` (Content, Infrastructure) touches these files:
it writes through `.tmp/` and one `rename`, so a key never points at a partly written file. Replacing the file of a
media item gives it a new key; the old file and the files of a deleted media item are removed after the commit
(`MediaFileRemovalListener`).

`media.visibility` is `public` for every media item. Restricted media are not delivered until #580 decides who may see
them.

## URLs

| URL | Answer |
|---|---|
| `/media/<YYYY>/<MM>/<uuid>.<ext>` | the file of a public media item, `Cache-Control: public, max-age=31536000, immutable`, `ETag`, inline with the original name |
| `/uploads/media/<filename>` (until v0.4) | `301` to the new URL once the media item has moved; the file from the old directory before that |

Both routes are stateless (no session, no cookie, no usage tracking) and answer `404` for anything that is not a public
media item with an existing file. `MediaPublicUrlResolver` builds the URL of a media item and reads a storage key or an
old file name back from a URL found in content; the snippets of the media library and the page image analysis
(`app:content:analyze-page-images`) use it for both URL forms.

## Moving files from `public/uploads/media` (v0.3)

| Command | What it does |
|---|---|
| `app:media:migrate-storage [--dry-run] [--legacy-dir=<dir>]` | Copies every media item that still has a `filename` into the storage, checks the copy by SHA-256, then sets its key and keeps the old name in `legacy_filename`. Missing files and files without media item are listed; "Every media item is in the media storage." appears only when no media item is left outside it. A relative `--legacy-dir` is relative to the working directory. Exit code 1 only if a copy failed. Deployer runs it on every deploy. |
| `app:media:rewrite-content-urls [--apply]` | Replaces `/uploads/media/<filename>` of moved media items in post content and in page content and drafts. Previews without `--apply`; leaves `updated_at` and the audit log alone. |

The old directory is never changed by these commands. When `--legacy-dir` is the storage root itself (flat files of a
backup from before v0.3, see [restore](../../05-operations/backup-restore.md#backups-from-before-v03)), each moved
source is removed instead.

## Integrity check

`app:media:check [--full] [--dry-run]` runs daily at 05:10 (Europe/Berlin, job `media_integrity_check`):

| Finding | Detection | Action |
|---|---|---|
| missing | a media item without its file; until v0.4 also a media item not yet moved whose file is not in the old directory (listed as `uploads/media/<filename>`) | reported: admin dashboard and `/health` (`media`, degraded), notification `admin.media_integrity_problem` |
| damaged | size, or SHA-256 of a hashed file, differs from the media item | reported like missing; the file stays |
| orphan | a file below `originals/` or a directory below `variants/` that no media item refers to | deleted when older than 24 hours (`app.media_check_grace_hours`) |
| leftover | a file in `.tmp/` | deleted when older than 24 hours |
| outside the layout | any other file below the root | listed by the command only |

- Each run checks existence and size of every file, and hashes the files verified longest ago up to 256 MB
  (`app.media_check_hash_budget_bytes`); every file is therefore hashed about once a month. `--full` hashes all.
- No orphan is deleted when the media library is empty, or when the orphans are more than 10 **and** more than 20 % of
  the files: that points to a wrong database or an incomplete restore. The run reports instead
  (`deletion_blocked_reason`). Every deletion is logged.
- The check takes the lock `backup-maintenance` of backup and restore; a scheduled run that meets it is skipped.
- Results go to `media_integrity_check` (kept 90 days); the health check reads only the latest row. Administrators get
  one notification per set of affected files. The command exits with 1 when files are missing or damaged.

Missing and damaged files are restored from a backup ([backup-restore.md](../../05-operations/backup-restore.md)).
