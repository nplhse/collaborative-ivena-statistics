# Console commands

Symfony console commands for operations, repair, and development. Shell scripts in `bin/ops/` are documented separately in [../05-operations/backup-restore.md](../05-operations/backup-restore.md).

## Conventions

### Naming

All application commands use the prefix `app:` followed by the bounded context and action:

```text
app:<bounded-context>:<action>
app:<bounded-context>:<subdomain>:<action>   # Statistics sub-features
```

Examples: `app:import:start`, `app:statistics:rebuild-projection`. Geographic enrichment uses the operator group `app:geo:*` (Allocation commands).

### Arguments and options

| Pattern | When to use | Examples |
|---|---|---|
| Positional argument | Single required entity ID for a one-entity command | `app:import:start <importId>` |
| `--<entity>-id` | Optional or filter scoping | `--hospital-id`, `--user-id`, `--only-id`, `--page-id` |
| `--dry-run` | Preview destructive or write operations without persisting | Backfill, requeue, deduplicate, content migration |

**Dry-run rule:** Analysis-only commands are read-only by default. Commands that write data apply changes when run without `--dry-run`. Use `--dry-run` to preview what would change. Exceptions that preview by default: `app:audit:purge-import-assessments` (`--execute` to delete), `app:user:backfill-created-at`, `app:user-activity:backfill`, `app:hospital:backfill-participating-since`, `app:geo:geocode-hospitals`, `app:geo:fetch-isochrones`, `app:geo:prepare-location` and `app:geo:apply-prepared-location` (`--apply` to write).

### Output

Commands use `SymfonyStyle` with `title`, `table`, `success`, `error`, and `warning` for consistent CLI output.

### Exit codes

Most commands return `0` on success and `1` on failure. Non-standard exit codes are documented in the command description (`app:import:start`, `app:import:requeue-all`, `app:env:check`).

### Registration

Commands are invokable classes with `#[AsCommand]` and autoconfiguration via `config/services.yaml`. DataFixtures commands are registered only in `dev`/`test` via `config/services/foundry.yaml`.

---

## Command reference

### Install

| Command | Purpose |
|---|---|
| `app:env:check` | Validate required environment variables. See [configuration.md](configuration.md), [../05-operations/deployment.md](../05-operations/deployment.md). |

### Import

| Command | Purpose |
|---|---|
| `app:import:start <importId>` | Dispatch a single import job via Messenger. The import type is resolved from the Import. The worker records the run in `job_run` with trigger `console`. |
| `app:import:requeue-all` | Re-queue imports sequentially with resume/checkpoint support. See [../04-features/import/batch-requeue.md](../04-features/import/batch-requeue.md). `--only-ids` limits to a comma-separated ID list. |
| `app:import:derive-export-periods` | Derive `import.export_starts_at` / `export_ends_at` from imported rows (allocations first, then closures). Dry run by default; `--apply` writes and requests a closure rebuild per changed hospital. `--hospital=<id>` limits the scope, `--show-unchanged` lists every import. Manual periods are kept. See [../04-features/statistics/closure-architecture.md](../04-features/statistics/closure-architecture.md). |
| `app:import:analyze-rejects` | Aggregate and export import rejects for transformer planning. See [../04-features/import/reject-analysis.md](../04-features/import/reject-analysis.md). |
| `app:import:repair-indication-corruption` | One-time issue 521 repair: merge quote/stub/`\OMI\""` IndicationRaws, then requeue quote-broken imports whose source CSV still exists. Production runbook: [../04-features/import/repair-indication-corruption.md](../04-features/import/repair-indication-corruption.md). |

### Reference catalog

| Command | Purpose |
|---|---|
| `app:reference:import` | Import a JSON reference catalog package (`--source=`, default `var/reference/catalog.json`). `--mode=add` (default) validates it, matches rows by id then natural key, creates missing rows with the package ids and never changes existing ones; one conflict stops it before any write. `--mode=replace` restores the whole catalog from a complete package in one transaction; it refuses while operational data, permissions, projections or saved views refer to the catalog, while queues hold messages, or while another restore or reset runs. `--dry-run` prints the plan; `--user=` sets `createdBy`. Clears the reference data cache. See [../04-features/import/reference-catalog.md](../04-features/import/reference-catalog.md). |
| `app:reference:export` | Write the current DB catalog into one JSON package (`--output=`, default `var/reference/catalog-<timestamp>.json`; optional `--types=`, `--notes=`). Rows carry their public ids and refer to each other by id. The package is checked like `app:reference:validate` before it is written; nothing is written when the data has problems. See [ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md). |
| `app:reference:validate` | Check a JSON reference catalog package (`--source=`, default `var/reference/catalog.json`) without importing it: format and version, types, field rules, ids, references, counts and checksum. Lists every problem with its path in the file. See [ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md). |
| `app:reference:propose-from-rejects` | Read-only on rejects: write a report directory (`report.md`, requeue ID list) with catalog values that rejects miss. Add them through the admin pages, then requeue. |

### Allocation

| Command | Purpose |
|---|---|
| `app:allocation:backfill-indications` | Repair tool: sync normalized indication fields (not for routine use). After matching a raw in the UI, prefer the `async_priority_low` backfill job; use `--rebuild-projection` if updating the projection too. See [../04-features/allocation/indication-normalization.md](../04-features/allocation/indication-normalization.md) and the STEMI path-B steps in [../04-features/import/repair-indication-corruption.md](../04-features/import/repair-indication-corruption.md). |
| `app:allocation:audit-indication-review` | Health check for indication raw review data consistency. |
| `app:explore:backfill-public-ids` | Backfill `public_id` UUID v4 values for explore detail resources. See [../04-features/allocation/explore-public-ids.md](../04-features/allocation/explore-public-ids.md). |
| `app:hospital:backfill-participating-since` | One-time tool: fill `Hospital.participatingSince` for currently participating hospitals that have no timestamp. Prefer the earliest `audit_log` evidence of becoming participating, otherwise the first successful import (`Completed`/`Partial`). Does **not** fall back to `created_at`. Default: dry-run preview. Writes only with `--apply`. See [../04-features/content/dashboard-overview.md](../04-features/content/dashboard-overview.md). |

### Geographic data

| Command | Purpose |
|---|---|
| `app:geo:geocode-hospitals` | Geocode hospital street addresses via OpenRouteService. Scope: exactly one of `--hospital-id`, `--dispatch-area-id`, `--state-id`. Optional `--participating-only` for area/state. Default: dry-run. Writes only with `--apply`. Existing coordinates are skipped unless `--force`. Runbook: [../05-operations/hospital-geodata.md](../05-operations/hospital-geodata.md). |
| `app:geo:fetch-isochrones` | Fetch destination isochrones (5–50 minutes) and store GeoJSON under `var/geo/hospital-isochrones/{stateId}/{publicId}.geojson`. Same scope options as geocode. Skips an existing file only when its stored origin still matches the hospital coordinates. Rate-limit abort is resumable. Runbook: [../05-operations/hospital-geodata.md](../05-operations/hospital-geodata.md). |
| `app:geo:prepare-location <slug>` | Prepare a location for offline use (demo): coordinates from an address (`--street`, `--postal-code`, `--city`; geocoded), a manual pin (`--lat`, `--lng`) or an existing hospital (`--from-hospital-id`), plus destination isochrones. Stores `var/geo/prepared-locations/<slug>.json`. Default: dry-run; `--apply` calls OpenRouteService when needed (at most 2 requests) and writes; `--force` replaces an existing slug. Runbook: [../05-operations/hospital-geodata.md](../05-operations/hospital-geodata.md#prepared-locations-offline-use). |
| `app:geo:list-prepared-locations` | List prepared locations (slug, label, address, coordinates, bands, date). Read-only, offline. |
| `app:geo:apply-prepared-location <slug> --hospital-id=` | Apply a prepared location to a hospital without network access: sets coordinates, fills an empty address, writes the isochrone file. Default: dry-run; `--apply` writes; different existing coordinates need `--force`. |

### Statistics

| Command | Purpose |
|---|---|
| `app:statistics:rebuild-projection` | Truncate and rebuild `allocation_stats_projection` per import. Recorded as one `job_run` (job `allocation_projection_full_rebuild`, trigger `console`). See [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md). |
| `app:statistics:rebuild-closure-volume-projection` | Rebuild closure analysis and `closure_volume_hour` for every hospital, or only `--hospital=<id>` (repeatable). `--reference-window=backward` uses all reference weeks before the event instead of the symmetric window, for comparison runs only. Prints duration, the retained partition heap and the process peak memory; `job_run.metrics` records the window. See [../04-features/statistics/closure-architecture.md](../04-features/statistics/closure-architecture.md). |
| `app:statistics:closure-volume-snapshot <action> [name]` | `save`, `compare`, `list` or `drop` named copies of `closure_volume_hour` (table `closure_volume_snapshot_<name>`). `compare` reports missing, extra and changed rows per column (`--tolerance`, default 0.0001) and exits 1 on differences. `--hospital=<id>` limits save and compare. A snapshot taken before ADR 015 (urgency × stratum) is compared on department rows of the urgency metrics the event closed. Used to verify refactorings against ADR 015. |
| `app:statistics:closure-rebuild-requests` | List unconsumed closure rebuild requests with hospital, changed range (or whole hospital) and state. `--hospital=<id>` filters, `--retry-failed` re-queues parked requests, `--run` processes the open requests now as the worker does (incremental where they carry a range) and prints the step profile. |
| `app:statistics:closure-volume-diagnostics` | Read-only checks for ADR 015: reference quality per scope, derived coverage and gaps, closures at export boundaries, and events that would be provisional with a symmetric window (`--weeks`, default 4). `--hospital=<id>` limits the scope. |
| `app:statistics:refresh-mviews` | Refresh materialized views manually. |
| `app:statistics:deduplicate-projection` | Detect and remove duplicate projection/allocation rows. Use `--dry-run` first. |
| `app:statistics:explorer-views:sync` | Seed or update Analysis Explorer system demo views. See [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md). |
| `app:statistics:case-flow:build-geojson` | Build merged Hessen dispatch-area GeoJSON asset for Case Flow maps. |

### KPI, analytics, and engagement

| Command | Purpose |
|---|---|
| `app:kpi:aggregate` | Aggregate daily KPIs into `kpi_daily`. Scheduler equivalent; see [../04-features/kpi/kpi-aggregation.md](../04-features/kpi/kpi-aggregation.md). |
| `app:analytics:aggregate` | Aggregate completed usage-analytics days and purge expired raw events. Scheduler equivalent; see [../04-features/analytics/aggregation-and-retention.md](../04-features/analytics/aggregation-and-retention.md). |
| `app:reminder:preview --hospital-id=ID` | Preview or send monthly submission reminder email. |

### Storage

| Command | Purpose |
|---|---|
| `app:storage:analyze` | Measure storage usage now and print it by category and location. Dry run by default; `--persist` stores today's measurement like the scheduled job and triggers storage alerts and the daily report unless `--no-notify` is given. `--format=json`, `-v` lists every contributor. Exit code `1` when files or database could not be measured or another measurement is running. See [../04-features/storage/README.md](../04-features/storage/README.md). |

### Content

| Command | Purpose |
|---|---|
| `app:content:analyze-page-images` | Analyze CMS page images; optional dimension backfill and layout migration. Runs on deploy via Deployer. |

### User

| Command | Purpose |
|---|---|
| `app:user:create` | Interactively create an enabled, email-verified user. Optionally grant `ROLE_ADMIN`. No default credentials. See [../05-operations/deployment.md](../05-operations/deployment.md). |
| `app:user:promote-admin` | Grant `ROLE_ADMIN` to an existing user identified by **username**. Asks for confirmation. |
| `app:user:demote-admin` | Remove `ROLE_ADMIN` from an existing user identified by **username**. Asks for confirmation. Refuses to demote the last remaining administrator. |
| `app:user:delete` | Delete an existing user identified by **username**. Asks for confirmation. Refuses when the user is the last administrator, still owns hospitals, or other records still reference the account. |
| `app:user:backfill-created-at` | One-time legacy tool: reconstruct `User.createdAt` from the earliest successful own import, otherwise the earliest successful import of an **owned** hospital. Access-grant users are not backfilled from hospital imports. Default: dry-run preview. Writes only with `--apply`. Removable after the production run. |
| `app:user-activity:backfill` | One-time backfill of the `user_activity` profile projection from historical imports, posts, comments, and hospital relations. Default: dry-run preview. Writes only with `--apply`. Idempotent via unique deduplication keys. See [ADR 014](../02-architecture/decisions/014-persisted-user-activity-projection.md). |

### Audit

| Command | Purpose |
|---|---|
| `app:audit:purge-import-assessments` | One-time cleanup of import-generated `Assessment` `create` entries in `audit_log`. Default: dry-run preview. See [../05-operations/audit-log-maintenance.md](../05-operations/audit-log-maintenance.md). |

### Doctrine / ad-hoc SQL

| Command | Purpose |
|---|---|
| `dbal:run-sql '…'` | Run a single SQL statement (ops one-offs). Examples: reset cookie consents after introducing the `analytics` preference; delete historical admin UI rows from `analytics_request` — see [../04-features/analytics/usage-analytics.md](../04-features/analytics/usage-analytics.md). |

### DataFixtures (dev/test only)

| Command | Purpose |
|---|---|
| `app:dev:reset-operational-data` | Delete everything that blocks a catalog restore: allocations, MCI cases, imports and rejects, closures, projections, KPIs, reminder dispatches, access grants, hospital owners, hospital job runs and notifications, saved user views. Users and the reference catalog stay. One transaction, then materialized views are refreshed. Refuses outside `dev`/`test`, while queues hold messages, or while a restore runs (shared lock). `--dry-run` lists the rows; asks before deleting unless `--force`. See [ADR 020](../02-architecture/decisions/020-portable-reference-catalog.md). |
| `app:generate:check-catalog` | dev/test, read-only: check that the installed reference catalog has every name and PZC code the generator writes. Lists per type what resolves, what is missing and what is ambiguous, with the fix; `--mapping=<file>` instead of `FIXTURES_CATALOG_MAPPING`, `--all` lists every resolved name. The fixtures and the other `app:generate:` commands run the same check first. See [fixtures.md](../03-development/fixtures.md#generator-on-another-catalog). |
| `app:generate:encoding-csv` | Generate CSV encoding test fixtures. |
| `app:generate:imports` | Add a synthetic allocation import and closure import for one month: `--hospital=<public id>` (repeatable), `--month=YYYY-MM`, optional `--seed`, `--dry-run` shows the planned counts. Refuses a month after the last day (`FIXTURES_UNTIL` or yesterday) and a month that already has allocations, closures or a synthetic import for the hospital. Runs the new imports through the pipeline: allocation projection, closure analysis and closure volume projection of the hospitals, materialized views. `--async` tracks a generation run and leaves this to a Messenger worker, `--notify` (with `--async`) notifies the hospital owners when it is done. See [ADR 022](../02-architecture/decisions/022-synthetic-data-generation.md). |
| `app:generate:run:list` | dev/test/demo: recent generation runs with status, stage and hospital results. See [fixtures.md](../03-development/fixtures.md#generation-runs). |
| `app:generate:run:show` | dev/test/demo: one generation run (newest without an argument): settings, hospitals with counts, imports and failures, stale runs, and the `job_run` rows of its background stages. |
| `app:generate:run:retry` | dev/test/demo: dispatch the background projections again for hospitals of a run that failed after generating data or were left open; writes no data. |
| `app:demo:csv` | Demo (also dev and test): write one synthetic month of a hospital as two IVENA CSV files, allocations and closures, for the normal upload: `--hospital=<public id>`, optional `--month=YYYY-MM` (default: the month after the hospital's newest allocation or closure), `--seed`, `--dir` (default `var/demo/csv`). The rows are the ones `app:generate:imports` would write. Refuses a month that is not complete by the last day (`FIXTURES_UNTIL` or yesterday) or already has data for the hospital. Writes nothing into the database. See [demo-environment.md](../05-operations/demo-environment.md#upload-a-month-during-the-presentation). |
| `app:generate:scenario` | Lay a scenario over existing synthetic data: `mci`, `icy-road`, `flu-wave` or `heat`; without a name it lists them. `--dispatch-area=<name>` or `--hospital=<public id>` (repeatable), `--at=YYYY-MM-DD`, optional `--seed`, `--dry-run`. Writes one import per hospital (and closures for the flu wave), refuses a second application for the same hospital and start day, then runs the processing pipeline. Delete the imports to remove a scenario. |

For full fixture loading, use `doctrine:fixtures:load` with groups — see [../03-development/fixtures.md](../03-development/fixtures.md).

---

## Scheduler equivalents

Some commands mirror scheduled Messenger jobs:

| Schedule | Message | CLI equivalent |
|---|---|---|
| Every 6 hours | `GenerateDailyKpisMessage` | `app:kpi:aggregate --days=2` (similar window) |
| Daily 08:00 Europe/Berlin | `SendMonthlySubmissionRemindersMessage` | `app:reminder:preview --hospital-id=ID --send` (per hospital) |
| Daily 02:15 Europe/Berlin | `AggregateDailyAnalyticsMessage` | `app:analytics:aggregate` (yesterday + catch-up + cleanup) |
| Daily 03:30 Europe/Berlin | `MeasureStorageMessage` | `app:storage:analyze --persist` |
