# Analytics aggregation and retention

**Audience:** Developers and operators running usage-analytics rollups.

Related: [usage-analytics.md](usage-analytics.md), [../../02-architecture/messenger-and-scheduler.md](../../02-architecture/messenger-and-scheduler.md)

## Two-stage model

| Window | Storage |
|--------|---------|
| Last 30 calendar days plus today (default) | Detailed rows in `analytics_request` and `analytics_product_event` |
| Older completed days | Daily aggregate tables only |

A calendar **day** is always **Europe/Berlin** (`00:00` inclusive to next `00:00` exclusive), matching request collection (`TIMESTAMP WITHOUT TIME ZONE` stored as naive Berlin time).

## Daily aggregation

`AnalyticsDailyAggregationService` is idempotent per day:

1. Deletes existing aggregate rows and the run marker for that date
2. Rebuilds daily statistics from raw rows in the Berlin day window
3. Writes `analytics_aggregation_run` (including empty days with `0/0/0`)

Only **completed** days are aggregated by the scheduler (yesterday and catch-up). The current day is never rolled up automatically.

### Tables

| Table | Grain | Additive measures |
|-------|-------|-------------------|
| `analytics_aggregation_run` | date | Marker required before raw cleanup |
| `analytics_request_daily` | date + area + route + auth + role | counts, error counts, duration/query sums |
| `analytics_event_daily` | date + event + area + role | event counts |
| `analytics_filter_param_daily` | date + param | usage counts |
| `analytics_filter_area_daily` | date + area | with/without filter counts |
| `analytics_transition_daily` | date + from/to route | transition counts (within that day) |
| `analytics_session_boundary_daily` | date + route + entry/exit | session counts (within that day) |
| `analytics_uniques_daily` | date | daily distinct users/visitors/sessions (not summable across days) |

## What reporting uses

Admin Usage Analytics views keep their existing 7-/30-day windows.

| Metric | Source |
|--------|--------|
| Request counts, feature areas, top routes, auth split, role × area, event counts, filter counts | Aggregate tables for completed days with a run marker, plus raw rows for today and any unaggregated gaps |
| DAU/WAU/MAU, funnel uniques, engagement depth, time-to-first, unique users, p95, journeys | Raw tables (not reconstructable from daily counts) |

Failed aggregation never deletes the corresponding raw day, so those metrics stay available until the day is successfully rolled up.

## Scheduled job

`AnalyticsScheduleContribution` dispatches `AggregateDailyAnalyticsMessage` at **02:15 Europe/Berlin**.

`AnalyticsScheduledAggregationService`:

1. Aggregates yesterday
2. Catch-up: up to 30 older completed days that still have raw data and no marker
3. Deletes raw rows for days with a success marker older than `ANALYTICS_RAW_RETENTION_DAYS`

Requires a worker consuming `scheduler_default`. Locally: `make consume`.

## Manual command

```bash
php bin/console app:analytics:aggregate
php bin/console app:analytics:aggregate --date=2026-09-01
php bin/console app:analytics:aggregate --days=30
php bin/console app:analytics:aggregate --days=90 --no-cleanup
php bin/console app:analytics:aggregate --cleanup-only --dry-run
```

| Option | Default | Description |
|--------|---------|-------------|
| `--date` | — | Single date (`YYYY-MM-DD`, Europe/Berlin) |
| `--days` | 1 | Range ending yesterday (1–366) when `--date` is omitted |
| `--no-cleanup` | false | Skip raw deletion |
| `--cleanup-only` | false | Only retention cleanup |
| `--dry-run` | false | Preview cleanup without deleting |

First deploy: run `app:analytics:aggregate --days=…` to backfill existing history, then let the scheduler catch up and purge.

## Configuration

`ANALYTICS_RAW_RETENTION_DAYS` (default `30`) in `.env`. Not required by `app:env:check`.

## Message handler

`AggregateDailyAnalyticsMessageHandler` acquires lock `analytics-scheduled-aggregation` and delegates to `AnalyticsScheduledAggregationRunnerInterface`.

Messenger routing: `async_priority_low` (sync in `test`).
