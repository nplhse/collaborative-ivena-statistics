# Explore public ID backfill

One-time (or resumable) backfill of `public_id` UUID v4 (RFC 4122) columns for explore detail resources.

## Prerequisites

- Migration `Version20260708183000` applied (`public_id` nullable `VARCHAR(36)` + partial unique indexes)
- Sufficient DB time budget for `allocation` (~2M rows: plan for multiple runs or use the wrapper script)

## Commands

Dry-run:

```bash
php bin/console app:explore:backfill-public-ids --dry-run
```

Single table:

```bash
php bin/console app:explore:backfill-public-ids --table=allocation --batch-size=5000
```

Time-limited chunk (resume-safe):

```bash
php bin/console app:explore:backfill-public-ids --max-runtime=300
```

Automatic restart until complete:

```bash
./bin/backfill-public-ids-until-done
```

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Backfill complete (or dry-run finished) |
| `1` | More rows need `public_id` (e.g. `--max-runtime` reached) |
| `2` | Critical error |

## Resume behaviour

No checkpoint table is required. Each run continues with:

```sql
SELECT id FROM <table> WHERE public_id IS NULL ORDER BY id ASC LIMIT <batch-size>
```

Interrupted runs (SIGINT/SIGTERM, timeout, OOM) can be restarted safely.

Each backfilled ID is an independent `Uuid::v4()` — no sequential predictability within batches.

## Deploy sequence

1. `doctrine:migrations:migrate` (adds nullable `public_id`)
2. Deploy application code (routes expect UUID v4; rows without `public_id` return 404 on detail pages)
3. `./bin/backfill-public-ids-until-done`

New rows created via import or ORM receive `public_id` immediately via `HasPublicId` `PrePersist`.

## Security note

UUID v4 improves opacity versus integer IDs but does not replace authentication and rate limiting. Treat `public_id` as an opaque handle, not a secret.

## Follow-up (separate PR)

After backfill is complete in all environments, a follow-up migration should:

- Set `public_id` to `NOT NULL` on all five tables
- Replace partial unique indexes with full unique indexes

This step is intentionally deferred from the initial rollout so alpha can run backfill and validate UUID routing before locking the schema.
