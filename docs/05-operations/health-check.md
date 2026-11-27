# Health check

`GET /health` is a public endpoint (no login) that returns slim JSON for uptime monitoring. The route is stateless
(#1685): it uses no session and sets no cookie, and no cookie consent or analytics is read before it, so with the
database down it still answers `unhealthy` with 503.

Related: [observability-sentry.md](observability-sentry.md), [deployment.md](deployment.md), [troubleshooting.md](troubleshooting.md)

## Response statuses

| `status` | HTTP | Meaning |
|--------|------|---------|
| `healthy` | 200 | Database reachable, no failed Messenger messages, queues and scheduler jobs fine, storage fine |
| `degraded` | 200 | Database OK, but the failed queue has messages, a queue falls behind, many messages are retried, a scheduler job is overdue or its last run failed, or storage is at `warning`, `stale`, `unavailable` or `incomplete`, or the maintenance mode is on. Check the Admin ops dashboard |
| `unhealthy` | 503 | Database unreachable, processing stalled, a queue far behind, a scheduler job overdue by more than twice its interval, or storage `critical` / `exceeded` |

The overall status is the worst of all checks. Public JSON includes only `status`, `checks.database` and `checks.storage` (no app `version`, no Messenger or scheduler details, no sizes or paths). Full details remain available in the Admin ops dashboard.

Example:

```bash
curl -sS https://<host>/health | jq
# { "status": "healthy", "checks": { "database": "ok", "storage": "ok" } }
```

## Checks

| Check | Public | Source |
|-------|--------|--------|
| `database` | yes | `SELECT 1`. If it fails, no other check runs (`messenger_failed` shows `skipped`) |
| `messenger_failed` | no | Rows in the `failed` Messenger queue |
| `storage` | yes | Latest stored storage measurement (ADR 017), one indexed query, no filesystem scan |
| `messenger_backlog` | no | Oldest message ready to be handled (ADR 019) |
| `messenger_stalled` | no | Ready messages without any handling attempt (ADR 019) |
| `messenger_retries` | no | Retry rate of the last 24 hours (ADR 019) |
| `scheduled_jobs` | no | Last finished run of every scheduler job (ADR 019) |
| `media` | no | Latest stored media integrity check (ADR 039), one query: `ok`, `unchecked` before the first run (healthy), or `missing=<n> damaged=<n>` (degraded). Details: `php bin/console app:media:check` ([media storage](../04-features/content/media-storage.md#integrity-check)) |

| `two_factor` | no | Two-factor key (ADR 040), at most two queries: `ok`, `disabled` without `APP_TWO_FACTOR_KEY` and without users of it (healthy), `missing_key users=<n>` or `key_mismatch` when accounts use two-factor authentication but their secrets cannot be decrypted (degraded; their codes fail, backup codes work) |
| `maintenance` | no | Maintenance mode flag (#1680, [ADR 046](../02-architecture/decisions/046-maintenance-mode-by-flag-file.md)), no query: `off` (healthy) or `on` (degraded), so a mode left on after a deploy shows up. `/health` stays reachable during the maintenance ([maintenance mode](deployment.md#maintenance-mode)) |
| `research_export` | no | Export connection of data extract requests (ADR 045), one query: `read_only` or `disabled` without `RESEARCH_DATABASE_URL` (healthy), `writable` when its role could change data or `unreachable` when it fails (degraded). Details: [data extract requests](../04-features/research/README.md#export-connection) |

Further checks come from services implementing `App\Shared\Application\Health\HealthCheckContributorInterface` (autoconfigured tag `app.health_check_contributor`). They run after the database check, in container order, and only mark themselves public when their value is a plain status string.

### Storage states

The storage check reads the latest row of `storage_measurement`, written daily at 03:30 Europe/Berlin by the scheduler or by hand with `php bin/console app:storage:analyze --persist`.

| `checks.storage` | Health | Meaning |
|------------------|--------|---------|
| `ok` | healthy | Level `normal` (below 80 % of the capacity reference) |
| `notice` | healthy | Level `notice` (from 80 %) |
| `not_configured` | healthy | Measured, but there is no capacity reference (no budget, no filesystem total) |
| `warning` | degraded | Level `warning` (from 90 %) |
| `incomplete` | degraded | Latest measurement has no total because the file walk or the database size failed |
| `stale` | degraded | Latest measurement is older than `app.storage.measurement_max_age_hours` (default 36). An old measurement is never reported as healthy, whatever its level |
| `unavailable` | degraded | No measurement at all (fresh install, scheduler not running) |
| `critical` | unhealthy (503) | Level `critical` (from 99 %) |
| `exceeded` | unhealthy (503) | Level `exceeded` (100 % or more) |

Thresholds are configured in `config/packages/app.yaml` (`app.storage.thresholds.*`). The Admin health card shows the storage item with usage, capacity and its source, remaining percentage, level and measurement time, and links to the storage details.

### Messenger and scheduler checks

Limits are configured under `app.messenger_monitoring.health` and `app.job_runs.<job>.overdue_after_minutes`; the values below are the defaults. "Ready" means in a queue other than `failed`, not delayed and not in progress. An empty queue or an idle worker is never a failure.

| Check | `degraded` | `unhealthy` |
|-------|------------|-------------|
| `messenger_backlog` | oldest ready message waits more than 30 min | more than 120 min |
| `messenger_stalled` | – | ready messages older than 15 min and no handling attempt for 15 min (worker stopped) |
| `messenger_retries` | more than 10 % of the attempts of the last 24 h were retried (from 20 attempts on) | – |
| `scheduled_jobs` | a job has no finished run within its interval, or its last run failed | a job has no finished run within twice its interval |

The value is `ok` or a short reason, for example `oldest message waits 45 min (low)` or `overdue: kpi_daily (9 h)`. A skipped run counts as a run. A job that never ran counts from the oldest `job_run` row or from the last Doctrine migration (`doctrine_migration_versions.executed_at`), whichever is later, so a job that a release adds is not overdue right after its deploy (#1562). A release that adds a scheduled job therefore also ships a migration, or `scheduled_jobs` may be `degraded` or `unhealthy` until the new job first runs. Runbooks: [messenger-monitoring.md](messenger-monitoring.md#runbooks).

## Uptime monitoring

For external uptime monitoring (Sentry Uptime or similar), point the monitor at `https://<APP_URL>/health` and expect HTTP **200**.

| Condition | Uptime alert |
|-----------|--------------|
| Timeout or connection failure | yes |
| HTTP **503** (`unhealthy`: database down, processing stalled, backlog or scheduler job far behind, or storage `critical` / `exceeded`) | yes |
| HTTP **200** with `"status": "degraded"` | no (by design) |

`degraded` means the app is up but the failed queue has messages, background processing falls behind or storage needs attention. Investigate with `php bin/console messenger:failed:show` or the Admin ops panel. Sentry Uptime does not parse JSON response bodies.

## Runbook: `degraded` or failed queue entries

Use this when `/health` returns `degraded` or when routine checks show failed messages.

1. Inspect failed messages from the current release ([paths on the server](deployment.md#paths-on-the-server)):
   ```bash
   cd <deploy_path>/current
   php bin/console messenger:failed:show
   ```
2. Identify the failing message class and error details.
3. Fix the underlying cause (for example env/config mismatch, temporary dependency outage, or bad payload source).
4. Retry safe messages:
   ```bash
   php bin/console messenger:failed:retry
   ```
5. Re-check queue and health status:
   ```bash
   php bin/console messenger:failed:show
   curl -sS https://<host>/health | jq
   ```

If failures keep reappearing, escalate as an incident and keep the failed queue under active observation until stable.

## Runbook: storage `stale`, `unavailable` or `incomplete`

1. Check that the scheduler worker runs (the daily measurement is a scheduled message).
2. Measure by hand and look at the output for failed parts:
   ```bash
   cd <deploy_path>/current
   php bin/console app:storage:analyze --persist
   ```
3. Re-check `curl -sS https://<host>/health | jq`.

For `warning`, `critical` or `exceeded`, free space (old releases, leftover imports or media) or raise the budget (`STORAGE_BUDGET_BYTES`).

### Sentry Uptime setup

1. Open **Alerts** → **Uptime Monitors** → create monitor.
2. URL: `https://<APP_URL>/health` (same value as `APP_URL` in `shared/.env.local`).
3. Interval: e.g. every 5 minutes.
4. Expected HTTP status: **200**.

Details: [observability-sentry.md](observability-sentry.md#uptime-monitoring)
