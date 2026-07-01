# Console commands

Symfony console commands for operations, repair, and development. Shell scripts in `bin/ops/` are documented separately in [Backup-restore.md](Backup-restore.md).

## Conventions

### Naming

All application commands use the prefix `app:` followed by the bounded context and action:

```text
app:<bounded-context>:<action>
app:<bounded-context>:<subdomain>:<action>   # Statistics sub-features
```

Examples: `app:import:allocations`, `app:statistics:rebuild-projection`.

### Arguments and options

| Pattern | When to use | Examples |
|---|---|---|
| Positional argument | Single required entity ID for a one-entity command | `app:import:allocations <importId>` |
| `--<entity>-id` | Optional or filter scoping | `--hospital-id`, `--user-id`, `--only-id`, `--page-id` |
| `--dry-run` | Preview destructive or write operations without persisting | Backfill, requeue, deduplicate, content migration |

**Dry-run rule:** Analysis-only commands are read-only by default. Commands that write data apply changes when run without `--dry-run`. Use `--dry-run` to preview what would change.

### Output

Commands use `SymfonyStyle` with `title`, `table`, `success`, `error`, and `warning` for consistent CLI output.

### Exit codes

Most commands return `0` on success and `1` on failure. Non-standard exit codes are documented in the command description (`app:import:allocations`, `app:import:requeue-all`, `app:env:check`).

### Registration

Commands are invokable classes with `#[AsCommand]` and autoconfiguration via `config/services.yaml`. DataFixtures commands are registered only in `dev`/`test` via `config/services/foundry.yaml`.

---

## Command reference

### Install

| Command | Purpose |
|---|---|
| `app:env:check` | Validate required environment variables (beta/prod gate). See [Configuration.md](Configuration.md), [Beta-readiness-checklist.md](Beta-readiness-checklist.md). |
| `app:install` | One-time server bootstrap (initial admin user). Run after `app:env:check`. |

### Import

| Command | Purpose |
|---|---|
| `app:import:allocations <importId>` | Dispatch a single import job via Messenger. |
| `app:import:requeue-all` | Re-queue imports sequentially with resume/checkpoint support. See [Import-batch-requeue.md](Import-batch-requeue.md). |
| `app:import:analyze-rejects` | Aggregate and export import rejects for transformer planning. See [Import-reject-analysis.md](Import-reject-analysis.md). |

### Allocation

| Command | Purpose |
|---|---|
| `app:allocation:backfill-indications` | Repair tool: sync normalized indication fields (not for routine use). |
| `app:allocation:audit-indication-review` | Health check for indication raw review data consistency. |

### Statistics

| Command | Purpose |
|---|---|
| `app:statistics:rebuild-projection` | Truncate and rebuild `allocation_stats_projection` per import. See [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md). |
| `app:statistics:refresh-mviews` | Refresh materialized views manually. |
| `app:statistics:deduplicate-projection` | Detect and remove duplicate projection/allocation rows. Use `--dry-run` first. |
| `app:statistics:explorer-views:sync` | Seed or update Analysis Explorer system demo views. See [analysis-explorer-v2.md](analysis-explorer-v2.md). |
| `app:statistics:case-flow:build-geojson` | Build merged Hessen dispatch-area GeoJSON asset for Case Flow maps. |

### KPI and engagement

| Command | Purpose |
|---|---|
| `app:kpi:aggregate` | Aggregate daily KPIs into `kpi_daily`. Scheduler equivalent; see [Development-Workflow.md](Development-Workflow.md). |
| `app:reminder:preview --hospital-id=ID` | Preview or send monthly submission reminder email. |

### Content

| Command | Purpose |
|---|---|
| `app:content:analyze-page-images` | Analyze CMS page images; optional dimension backfill and layout migration. Runs on deploy via Deployer. |

### DataFixtures (dev/test only)

| Command | Purpose |
|---|---|
| `app:reference:load-indication-groups` | Load indication groups from YAML without purging the database. |
| `app:fixtures:export-patterns` | Export distribution patterns from projection data. |
| `app:fixtures:validate-patterns` | Validate committed pattern YAML files. |
| `app:fixtures:generate-csv` | Generate CSV encoding test fixtures. |

For full fixture loading, use `doctrine:fixtures:load` with groups — see [Development-fixtures.md](Development-fixtures.md).

---

## Scheduler equivalents

Some commands mirror scheduled Messenger jobs:

| Schedule | Message | CLI equivalent |
|---|---|---|
| Every 6 hours | `GenerateDailyKpisMessage` | `app:kpi:aggregate --days=2` (similar window) |
| Daily 08:00 Europe/Berlin | `SendMonthlySubmissionRemindersMessage` | `app:reminder:preview --hospital-id=ID --send` (per hospital) |
