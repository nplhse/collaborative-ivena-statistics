# Data coverage

The import detail page (`/import/{id}`) shows which days the imported records cover and where days without records may point to missing data (#778). Days without records are *potential* gaps: depending on the dataset they can be legitimate, so they are never treated as errors and never block an import.

## Stored summary

The page does not query the imported rows. Each import stores a compact summary in `import.data_coverage` (JSONB, value object `ImportDataCoverage`):

| Field | Allocation import | Closure import |
|-------|-------------------|----------------|
| `records` | allocations | closure intervals |
| `first_at` / `last_at` | first and last `allocation.created_at` | first `starts_at`, last `ends_at` |
| `daily_counts` | allocations per `created_at` day | closure intervals active per day |

Only days with records are stored (`"2026-09-01": 412`); a year is about 365 entries. Timestamps are naive wall-clock values as IVENA delivers them. The period, the days with and without data and the gaps are derived from `daily_counts` when the page renders. An interval ending exactly at midnight does not cover the next day.

`null` means "not calculated"; an import without records stores an empty summary. The column is excluded from the audit log (`#[AuditIgnore]`), as it is derived.

### When it is calculated

`ImportDataCoverageCalculator` runs at the end of every import run, next to the export period derivation (`ImportExportPeriodDeriver`) and **before deduplication**. The summary therefore describes the imported file: it does not change when a later import replaces some of its rows, and rows discarded as duplicates of older imports still count. Calculating it on every page view would show such replaced rows as gaps.

Both queries (`DoctrineImportCoverageReader`) use the `import_id` indexes and run once per run. Reprocessing an import calculates the summary again.

## Detail page

The *Data coverage* card (`_data_coverage.html.twig`, built by `ImportCoverageViewFactory`) shows:

- period (first to last day with records) and its length in days
- earliest and latest timestamp
- days with data ("28 of 30 days"), for closures days with an active closure
- a calendar like GitHub's contribution graph: weeks as columns, Monday to Sunday, four colour steps in the primary colour relative to the busiest day, a tooltip with date and count. It is omitted for periods longer than 400 days.

Gap detection depends on the import type:

| Type | Behaviour |
|------|-----------|
| Allocation | Days without records inside the period are counted, marked yellow in the calendar and listed as consecutive intervals in a table (from, to, duration). The first 10 gaps are shown, the rest behind *Show all n gaps* (`ImportCoverageView::VISIBLE_GAPS`). |
| Closure | No gap warnings. Days without an active closure are normal and shown neutral grey. |

Fallbacks instead of the card content:

| State | Shown when |
|-------|-----------|
| Pending | the import is pending or running (an older summary is hidden) |
| Unavailable | the import failed or was cancelled |
| Not calculated | a completed or partial import has no summary yet |
| Empty | the import has no records |

## Backfill

Imports that finished before the summary existed have none. Calculate it with:

```bash
php bin/console app:import:derive-data-coverage            # dry run: lists what would change
php bin/console app:import:derive-data-coverage --apply
php bin/console app:import:derive-data-coverage --apply --hospital=12
php bin/console app:import:derive-data-coverage --apply --all   # recalculate stored summaries too
```

The command covers completed and partial imports without a summary (all of them with `--all`), in batches of 100. A backfill can only read the rows that are still stored, so rows replaced by later imports are missing from it. Reprocessing an import gives the exact file coverage.

## Tests

- `tests/Import/Unit/Domain/ValueObject/ImportDataCoverageTest.php` (gaps, period, stored format)
- `tests/Import/Integration/Query/DoctrineImportCoverageReaderTest.php` (daily counts, midnight boundary)
- `tests/Import/Unit/UI/Http/Presenter/Coverage/ImportCoverageViewFactoryTest.php` (fallbacks, calendar, gap list)
- `tests/Import/Functional/Controller/ShowImportControllerTest.php` (card, gap table, collapse)
- `tests/Import/Functional/Command/DeriveImportDataCoverageCommandTest.php` (backfill)
