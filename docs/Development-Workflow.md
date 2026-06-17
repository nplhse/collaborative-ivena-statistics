# Development workflow

## Daily tasks

### Initialize the environment

```bash
make setup-dev    # greenfield with fixtures (alias: make install)
make upgrade-dev  # update deps/schema; keeps existing DB (e.g. mirror DB)
```

### Reset local runtime data

```bash
make purge   # clear assets, uploads, imports, logs; empty DB; no fixtures
make reset   # like purge, then load demo fixtures (see Development-fixtures.md)
```

### Start the local worker

Required for async import jobs and scheduled KPI aggregation (`scheduler_default`):

```bash
make consume
```

### Run tests

```bash
make test
make coverage
```

### Code checks / coding standards

```bash
make lint
make static-analysis
make cs
```

### Aggregate admin KPIs

Daily metrics for the EasyAdmin dashboard are written to `kpi_daily` by a console command (not computed on each page load):

```bash
php bin/console app:kpi:aggregate              # last 30 days ending yesterday (dashboard window)
php bin/console app:kpi:aggregate --days=1     # yesterday only
php bin/console app:kpi:aggregate --date=2026-06-01
```

Only imports with final status (Completed, Partial, Failed, Cancelled) are counted; Pending/Running are ignored.

#### Scheduled aggregation (Symfony Scheduler)

During the alpha phase, KPIs are refreshed automatically every 6 hours (00:00, 06:00, 12:00, 18:00 Europe/Berlin) via Symfony Scheduler. Each run aggregates **yesterday and today** so same-day issues appear on the dashboard without waiting until the next calendar day.

The schedule is defined in [`KpiScheduleProvider`](../src/Kpi/Infrastructure/Scheduler/KpiScheduleProvider.php) and dispatches `GenerateDailyKpisMessage` to the `scheduler_default` transport.

**Local:** the worker must consume the scheduler transport (included in `make consume`):

```bash
make consume
# or scheduler only:
php bin/console messenger:consume scheduler_default -vv
```

**Diagnostics:**

```bash
php bin/console debug:scheduler
php bin/console messenger:stats
```

On a new environment, run `app:kpi:aggregate` once (default covers the same 30-day window as the dashboard) before relying on the scheduler for historical days.

### Refresh cache and assets

```bash
make warmup   # does not touch the database
```

For schema changes on an existing database, prefer `make upgrade-dev` over `purge` or `reset`.

### Migrations

```bash
make upgrade-dev
# or manually:
php bin/console doctrine:migrations:migrate --no-interaction
```

## Tips

- In `dev`, mail is processed synchronously; import jobs stay asynchronous.
- Local mail UI: start Mailpit with `docker compose up -d mailer`, then open http://127.0.0.1:8025 (`dev` uses `smtp://127.0.0.1:1025`).
- Preview/send monthly reminder: `php bin/console app:reminder:preview --hospital=ID --send` (add `--ignore-opt-out` if the owner disabled reminders).
- For reproducible import issues, always note the same input file and import ID.
- For queue problems, run `messenger:stats` first, then `messenger:failed:show`.

## Related documentation

- Setup: [Setup.md](Setup.md)
- Testing: [Testing.md](Testing.md)
- Import: [Import-workflow.md](Import-workflow.md)
- Fixtures: [Development-fixtures.md](Development-fixtures.md)
- Troubleshooting: [Troubleshooting.md](Troubleshooting.md)
