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

A day that already has a run marker but no raw rows left (raw data deleted by retention) is **not**
rebuilt: its aggregates and marker stay unchanged, and the command reports it as "kept unchanged".
Re-running `app:analytics:aggregate --date/--days` over older days is therefore safe and never
replaces historical totals with zeros.

Only **completed** days are aggregated by the scheduler (yesterday and catch-up). The current day is never rolled up automatically.

### Tables

| Table | Grain | Additive measures |
|-------|-------|-------------------|
| `analytics_aggregation_run` | date | Marker required before raw cleanup |
| `analytics_request_daily` | date + area + route + auth + role | counts, error counts, duration/query sums |
| `analytics_event_daily` | date + event + area + role | event counts |
| `analytics_transition_daily` | date + from/to route | transition counts (within that day) |
| `analytics_session_boundary_daily` | date + route + entry/exit | session counts (within that day) |
| `analytics_uniques_daily` | date | daily distinct users/visitors/sessions (not summable across days) |
| `analytics_access_daily` | date + auth | counted accesses (page views, [definition](usage-analytics.md#counted-accesses)); only for counted days, see below |

### Counted accesses per day

`analytics_access_daily` holds the counted accesses of a day, one row for signed-in and one for
anonymous requests. A day has these two rows exactly when its accesses could be counted, also when
both counts are zero, so a day without rows is outside the counted period, not a day without accesses:

- A day with requests gets rows only when **every** request of the day has `counted_access` set.
  A day with unclassified requests (recorded before the flag, not yet backfilled) gets no rows; the
  command reports it.
- A day without requests gets zero rows only when an earlier day already has rows, i.e. after the
  counting started. Empty days before that (for example markers written before tracking began)
  stay outside the counted period.
- The counting starts at the first day with rows. Aggregate days in ascending order, as the
  scheduler and `--days` do, so that empty days after the start get their zero rows.

The rows follow the same idempotency and retention rules as the other tables: rebuilt with the
day while raw rows exist, kept unchanged once the raw rows are deleted, never deleted by retention.

## What reporting uses

Admin Usage Analytics views keep their existing 7-/30-day windows.

| Metric | Source |
|--------|--------|
| Request counts, feature areas, top routes, auth split, role × area, event counts | Aggregate tables for completed days with a run marker, plus raw rows for today and any unaggregated gaps |
| DAU/WAU/MAU, engagement depth, unique users, p95, journeys | Raw tables (not reconstructable from daily counts) |

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

`app:analytics:classify-accesses` marks raw requests recorded before the counted-access flag existed ([usage-analytics.md](usage-analytics.md#rows-recorded-before-the-flag)). Run it soon after the deploy that adds the flag: raw rows older than the retention period are deleted and cannot be classified later.

To count accesses for the days that still have raw rows, classify them and then aggregate those
days again (already aggregated days are rebuilt from their raw rows; days whose raw rows are gone
stay unchanged):

```bash
php bin/console app:analytics:classify-accesses --apply
php bin/console app:analytics:aggregate --days=31 --no-cleanup
```

`--days` should cover the retention period plus one day. The counting then starts at the oldest day
that still had raw rows.

## Configuration

`ANALYTICS_RAW_RETENTION_DAYS` (default `30`) in `.env`. Not required by `app:env:check`.

## Message handler

`AggregateDailyAnalyticsMessageHandler` acquires lock `analytics-scheduled-aggregation` and delegates to `AnalyticsScheduledAggregationRunnerInterface`.

Messenger routing: `async_priority_low` (sync in `test`).
