# KPI aggregation

The `Kpi` bounded context aggregates daily metrics into the `kpi_daily` table for the admin KPI dashboard.

Related: [../../02-architecture/messenger-and-scheduler.md](../../02-architecture/messenger-and-scheduler.md), [../../05-operations/messenger-workers.md](../../05-operations/messenger-workers.md)

## Data model

- Entity: `KpiDaily` (`src/Kpi/Domain/Entity/KpiDaily.php`)
- Repository: `KpiDailyRepository`

## Aggregation service

`KpiAggregationService` is idempotent per day:

1. Deletes existing `kpi_daily` rows for the target date(s)
2. Aggregates via `KpiDayAggregationQuery` (per hospital + global)
3. Writes only final imports (pending/running imports are excluded)

## Scheduled aggregation

`KpiScheduleProvider` dispatches `GenerateDailyKpisMessage` every 6 hours (`0 */6 * * *`).

`KpiScheduledAggregationService` aggregates **yesterday and today** (Europe/Berlin timezone).

Requires a running worker consuming `scheduler_default`. Locally: `make consume`.

## Manual command

```bash
php bin/console app:kpi:aggregate
php bin/console app:kpi:aggregate --date=2025-06-01
php bin/console app:kpi:aggregate --days=30
```

| Option | Default | Description |
|--------|---------|-------------|
| `--date` | — | Single date (`YYYY-MM-DD`) |
| `--days` | 30 | Range ending yesterday (1–366) |

## Message handler

`GenerateDailyKpisMessageHandler` acquires lock `kpi-scheduled-aggregation` and delegates to `KpiScheduledAggregationRunnerInterface`.

Messenger routing: `async_priority_low` (sync in `test`).

## Frontend

Admin KPI dashboard uses entrypoint `admin-kpi` in `importmap.php`.
