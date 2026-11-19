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

`KpiScheduleContribution` registers `GenerateDailyKpisMessage` every 6 hours (`0 */6 * * *`) on the shared `DefaultScheduleProvider`.

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

## Admin KPI row

The admin dashboard (`/admin`) shows four cards. The section title names the period: the last 30 Berlin calendar
days including today. Each card except "Active users" is compared with the 30 days before; this period ends with
the day before the current period starts. The comparison line reads, for example, "+38.5 % vs. previous 30 days"
and turns green or red depending on which direction is better. When there is nothing to compare with, it reads
"No previous value to compare".

| Card | Value | Secondary line | Change |
|------|-------|----------------|--------|
| Active users | MAU: distinct users in the last 30 days | DAU (today) and WAU (last 7 days) | none yet; raw rows are kept for 30 days only |
| Active hospitals | Hospitals that participate today and have a `kpi_daily` row with at least one successful import (completed or partial) in the period | Number of hospitals that participate today | Difference in hospitals; the previous period uses the same participating hospitals |
| Records imported | Rows read minus rows rejected, from final imports (global rows) | Rows read and number of imports | Relative change in percent, one decimal; none when the previous period has no imported records |
| Rejection rate | Rows rejected / rows read × 100, two decimals; "–" when no rows were read | Rows rejected of rows read | Difference in percentage points; none when one of the periods has no rows; lower is better |

- Users come from the usage analytics ([usage-analytics.md](../analytics/usage-analytics.md)), the other cards from
  the global or per-hospital `kpi_daily` rows (`KpiDailyRepository`). Days without a row count as zero.
- Imports are counted on the day they were created, so a running import shows up after the next aggregation.
- Hospitals that do not participate can still import; they count towards "Records imported" and "Rejection rate",
  but not towards "Active hospitals".

## Frontend

Admin KPI dashboard uses entrypoint `admin-kpi` in `importmap.php`.
