# Repair CSV indication corruption

One-time repair for [issue 521](https://github.com/nplhse/collaborative-ivena-statistics/issues/521): IVENA CSV quote handling, the blind 4-character indication prefix strip, and the STEMI/`\OMI\""` leftover that hid cases from statistics.

IDs (`indication_raw.id`, `hospital.id`, `indication_normalized.id`) **differ per database**. Always resolve them with the lookup queries below. Names and PZC `332` are stable.

## What broke

Three independent defects stacked. Together they made STEMIs vanish from hospital statistics after May 2025 even though the rows were imported.

### 1. CSV quote / column shift

PHP `fgetcsv` / `str_getcsv` with enclosure `"` and a backslash escape splits fields that contain ASCII quotes (notably `STEMI / "OMI"`). Columns shift; `manv` often receives a dispatcher name; the row is classified as MCI and lands in `import_reject`.

**Going forward:** `IvenaQuotedCsvParser` uses RFC 4180 empty escape. When a line is fully quoted (`"…";"…"`), a `";"` field split recovers unescaped inner quotes without shifting columns.

### 2. Blind 4-character strip

`normalizeIndication()` always did `mb_substr($value, 4)`. When `pzc_und_text` was already a plain label, the first four letters disappeared and a stub `IndicationRaw` was created.

**Going forward:** strip only a leading `^\d{3}\s+` or `^\d{6}\s+` PZC prefix.

### 3. Mixed-quote IVENA exports (Kassel STEMIs)

Many production CSVs do **not** quote every cell. Rows start unquoted (`Leitstelle Kassel;1;…`) and only special fields are quoted. In those files the STEMI cell is already stored as `332 STEMI / \OMI\""` (backslash, then `OMI`, then `\""`).

That path never uses the lenient `";"` splitter (`startsQuoted` is false). Column count still matches the header, so the row imports as a **valid allocation**, not a reject. The repair command’s requeue step therefore **does not pick these imports up**.

`IndicationKey` originally treated `STEMI / \"OMI\"` and `STEMI / \OMI\""` as different hashes. Two raws existed:

| Role | Typical name | Review | Statistics |
|------|----------------|--------|------------|
| Intact | `STEMI / \"OMI\"` | matched → catalog `STEMI/“OMI“` (PZC 332) | visible |
| Garbled leftover | `STEMI / \OMI\""` | unreviewed, `normalized_id` NULL | **invisible** |

Dashboards join `allocation.indication_normalized_id` / `allocation_stats_projection.indication_normalized_id`. Unreviewed raws with `NULL` normalized id look like “no STEMIs”. NSTEMI (333) was unaffected, which looked like recoding.

**Going forward:** `IndicationKey::normalizeText()` maps `\OMI\""` (and the same leftover for other tokens) onto `"OMI"`, so both names share one hash. New imports attach to the matched raw instead of opening a stub.

Matching the garbled raw in the review UI is enough for the **catalog** row. Allocations are updated asynchronously (`BackfillAllocationsForIndicationRawMessage` on `async_priority_low`). Until that job runs, `allocation.indication_normalized_id` stays `NULL` and statistics stay empty.

## Why a “successful” repair command was not enough

The command did two things: merge raws that **already share a hash**, then requeue imports with **quote-shift rejects** and a readable CSV.

Kassel/Elisabeth STEMIs failed both gates:

- hashes differed (`\OMI\""` vs `\"OMI\"`) → no merge
- rows were not rejects → no requeue

Re-importing the same mixed-quote files without the hash fix would recreate the garbled stub.

## Command reference

```bash
php bin/console app:import:repair-indication-corruption --dry-run
php bin/console app:import:repair-indication-corruption
```

| Option | Default | Meaning |
|---|---|---|
| `--dry-run` | off | List merges and requeue-ready imports without writing or dispatching |
| `--since` | `2025-05-01` | Only consider quote-broken imports created on/after this date (`import.created_at`) |
| `--only-import-id` | — | Limit discovery/requeue to one import |
| `--skip-merge` | off | Skip IndicationRaw rehash/merge |
| `--skip-requeue` | off | Skip targeted requeue |
| `--skip-projection` | off | Skip projection rebuild for merge-affected imports |

### Source file gate

Before any requeue the command checks `Import.filePath`: path set, inside `var/imports`, file exists, readable, size > 0. Missing files are listed as **skipped (missing source)** and are **not** dispatched. Restore the CSV and re-run:

```bash
php bin/console app:import:repair-indication-corruption --skip-merge --only-import-id=42
```

The import handler also refuses to delete previous allocations when the CSV is missing (`is_file` before cleanup).

## Production runbook

Run this **after** deploying the release that contains `IvenaQuotedCsvParser`, the prefix-strip fix, and `IndicationKey` leftover canonicalization. Do not skip the backup.

### 0. Backup and pause

1. Database backup (and `var/imports/` if you plan to restore missing CSVs). See [../../05-operations/backup-restore.md](../../05-operations/backup-restore.md).
2. Pause Messenger workers (`async_priority_high` and `async_priority_low`) until the merge in step 3 has finished. New uploads would hash with the new normaliser against unrehashed raws.
3. Deploy the release; `cache:clear` / warmup as usual.

### 1. Resolve IDs on this database

```bash
php bin/console dbal:run-sql "SELECT id, name FROM hospital WHERE name ILIKE '%Kassel%' ORDER BY name"
```

Note Elisabeth-Krankenhaus Kassel, Klinikum Kassel, and a control hospital that imported STEMIs cleanly (locally: Diakonie Kassel).

```bash
php bin/console dbal:run-sql "SELECT id, code, name FROM indication_normalized WHERE code = 332 ORDER BY id"
```

The catalog row is typically named `STEMI/“OMI“` (typographic quotes). Call its id `:norm_id`.

```bash
php bin/console dbal:run-sql "SELECT id, name, review_status, normalized_id, hash FROM indication_raw WHERE code = '332' AND (name ILIKE '%OMI%' OR name ILIKE '%STEMI%') ORDER BY id"
```

Expect two (or more) raws until merge: one matched intact name, one garbled `\OMI\""`. Call them `:intact_raw_id` and `:garbled_raw_id`.

Sanity — cases exist, but only the intact raw is visible in stats:

```bash
php bin/console dbal:run-sql "SELECT ir.id, ir.name, ir.review_status, ir.normalized_id, a.hospital_id, h.name, COUNT(*) AS n, MIN(a.arrival_at) AS first_arr, MAX(a.arrival_at) AS last_arr FROM allocation a JOIN indication_raw ir ON ir.id = a.indication_raw_id JOIN hospital h ON h.id = a.hospital_id WHERE ir.id IN (:intact_raw_id, :garbled_raw_id) AND a.arrival_at >= '2025-05-01' GROUP BY ir.id, ir.name, ir.review_status, ir.normalized_id, a.hospital_id, h.name ORDER BY ir.id, n DESC"
```

Replace the placeholders. On a broken DB the garbled raw has `normalized_id` NULL (or allocations have `indication_normalized_id` NULL) while counts for Kassel hospitals are non-zero.

### 2. Dry-run the repair

```bash
php bin/console app:import:repair-indication-corruption --dry-run
```

Inspect:

- **Merge table:** after this release a `quote_variant` action from `:garbled_raw_id` → `:intact_raw_id` (code 332) should appear **if the garbled raw is still unreviewed**. Write down allocation counts.
- **requeue-ready vs skipped:** quote-shift rejects with a file on disk vs missing CSVs. Restore files before the requeue step if you care about those imports.

### 3. Repair the STEMI catalog (choose one path)

#### Path A — garbled raw still unreviewed (preferred)

Merge **before** anyone matches the garbled raw in the UI. Survivor scoring prefers `matched`, then occurrence count. If both raws are already matched, the garbled row can win because it has more allocations, and the readable name is deleted.

```bash
php bin/console app:import:repair-indication-corruption --skip-requeue
```

This rehashes, merges `:garbled_raw_id` into `:intact_raw_id`, moves allocations, copies `indication_normalized_id` from the survivor, deletes the loser, and rebuilds projection for affected imports.

Skip `--skip-requeue` only when you also want the quote-reject requeue in the same run (step 4).

#### Path B — garbled raw already matched in the UI

Do **not** run the merge. Both rows are `matched`; occurrence would keep the garbled name.

1. Confirm the UI match: garbled raw has `review_status = matched` and `normalized_id = :norm_id`.
2. Allocations may still have `indication_normalized_id` NULL. The match dispatched `BackfillAllocationsForIndicationRawMessage` on transport `async_priority_low` / queue `low`.

```bash
php bin/console dbal:run-sql "SELECT COUNT(*) AS queued FROM messenger_messages WHERE body ILIKE '%BackfillAllocationsForIndicationRaw%'"
php bin/console messenger:consume async_priority_low --limit=1
```

The handler copies normalized ids onto allocations **and** `allocation_stats_projection`. If the message is gone but allocations are still NULL:

```bash
php bin/console app:allocation:backfill-indications --rebuild-projection
```

Without `--rebuild-projection` the allocation table is updated and dashboards that read the projection stay wrong.

Leave the two raws in place. They both point at the same catalog; statistics are correct. Unifying names is optional later and needs a name-preserving survivor rule (not in this command today).

### 4. Requeue quote-broken imports (column-shift rejects)

Separate from STEMIs that imported as valid rows.

```bash
php bin/console app:import:repair-indication-corruption --skip-merge --dry-run
```

Restore skipped CSVs if needed, then:

```bash
php bin/console app:import:repair-indication-corruption --skip-merge
```

Start `async_priority_high` workers. Wait until batch items are **imported**, not only `queued`.

`--since` defaults to `2025-05-01`. Stub merges have no date cutoff; quote-reject discovery does.

### 5. Refresh views and audit

```bash
php bin/console app:statistics:refresh-mviews
php bin/console app:allocation:audit-indication-review
```

Do **not** run a full `app:statistics:rebuild-projection` unless something else requires it. Merge/backfill already touch affected imports; reimported files rebuild on import-completed.

### 6. Verify on this database

Normalized STEMIs since May 2025 (replace `:norm_id` and hospital names):

```bash
php bin/console dbal:run-sql "SELECT h.name, COUNT(*) AS n FROM allocation a JOIN hospital h ON h.id = a.hospital_id WHERE a.indication_normalized_id = :norm_id AND a.arrival_at >= '2025-05-01' AND h.name IN ('Elisabeth-Krankenhaus Kassel', 'Klinikum Kassel') GROUP BY h.name ORDER BY h.name"
```

Projection (what indication dashboards read):

```bash
php bin/console dbal:run-sql "SELECT h.name, COUNT(*) AS n FROM allocation_stats_projection p JOIN allocation a ON a.id = p.id JOIN hospital h ON h.id = a.hospital_id WHERE p.indication_normalized_id = :norm_id AND p.arrival_at >= '2025-05-01' AND h.name IN ('Elisabeth-Krankenhaus Kassel', 'Klinikum Kassel') GROUP BY h.name ORDER BY h.name"
```

Expect non-zero counts in the same ballpark as the pre-repair garbled raw (locally: ~109 Elisabeth, ~262 Klinikum Kassel after May 2025). Compare with a control hospital that already had matched STEMIs.

Allocations on the garbled raw must no longer have NULL normalized ids (path B) or the garbled raw must be gone (path A):

```bash
php bin/console dbal:run-sql "SELECT a.indication_raw_id, a.indication_normalized_id, COUNT(*) AS n FROM allocation a WHERE a.indication_raw_id IN (:intact_raw_id, :garbled_raw_id) GROUP BY a.indication_raw_id, a.indication_normalized_id"
```

Spot-check the indication dashboard / worklist: garbled `\OMI\""` should not remain `unreviewed`.

## Do not

- Match the garbled raw **and then** run the merge (path B + path A). Survivor scoring can delete the readable matched raw.
- Treat “repair command finished” as “STEMIs visible”. Check `indication_normalized_id` on allocations **and** on `allocation_stats_projection`.
- Use `str_getcsv` with escape `\` on mixed-quote Kassel files to “fix” `\OMI\""`. That merges adjacent columns (header count 86 vs parse count 85). Canonicalize the leftover in `IndicationKey` instead.
- Requeue every import. Only quote-shift rejects need a file-gated reimport; mixed-quote STEMIs need hash merge or a UI match plus backfill.

## Related

- Parser: `SplCsvRowReader`, `IvenaQuotedCsvParser`
- Prefix strip: `AllocationRowNormalizationTrait::normalizeIndication()`
- Quote hash: `IndicationKey::normalizeText()`
- Review match → async backfill: `IndicationRawReviewService`, `BackfillAllocationsForIndicationRawMessage` (`async_priority_low`)
- Console backfill: `app:allocation:backfill-indications` — see [../allocation/indication-normalization.md](../allocation/indication-normalization.md)
- [batch-requeue.md](batch-requeue.md)
- [../statistics/projection-and-materialized-views.md](../statistics/projection-and-materialized-views.md)
- Workers: [../../05-operations/messenger-workers.md](../../05-operations/messenger-workers.md)
