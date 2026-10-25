# Sentry (beta monitoring)

This application uses `sentry/sentry-symfony` for error monitoring, structured logs, and automatic performance tracing of HTTP requests, console commands, and Doctrine queries. Messenger jobs have no transaction of their own: a worker runs inside the `messenger:consume` console transaction. Closure rebuilds record their own transaction (see below).

## Environment variables

| Variable | Purpose |
|----------|---------|
| `SENTRY_DSN` | Sentry project DSN; leave empty to disable |
| `SENTRY_ENVIRONMENT` | Optional; falls back to `APP_ENV` (`local`, `dev`, `staging`, `beta`, `prod`) |
| `SENTRY_RELEASE` | Optional; falls back to `App\Kernel::APP_VERSION` (`app.version`) |
| `SENTRY_TRACES_SAMPLE_RATE` | Share of transactions to trace (`0.0`–`1.0`); applied by `SentryTracesSampler`, closure rebuilds are always sampled |
| `SENTRY_ENABLE_LOGS` | Enable structured logs (`true` / `false`) |
| `SENTRY_CSP_REPORT_URI` | Optional Sentry CSP report endpoint; prod only — see [content-security-policy.md](content-security-policy.md) |

For beta deployments, set a DSN, `SENTRY_ENVIRONMENT=beta`, and `SENTRY_TRACES_SAMPLE_RATE` between `0.2` and `1.0`. Keep the DSN empty locally and in CI; structured logs are disabled in the `test` environment.

## Integration

- **Bundle:** [`config/packages/sentry.yaml`](../../config/packages/sentry.yaml) — errors, HTTP/Messenger/Doctrine tracing, and structured logs.
- **Monolog:** [`config/packages/monolog.yaml`](../../config/packages/monolog.yaml) — `sentry_logs_import` (`import` channel, info and above), `sentry_logs_closure_rebuild` (`closure_rebuild` channel, info and above) and `sentry_logs_main` (other channels, warning and above).
- **Sampling:** [`SentryTracesSampler`](../../src/Shared/Infrastructure/Monitoring/Sentry/SentryTracesSampler.php) — once a `traces_sampler` is set, Sentry ignores `traces_sample_rate`, so the sampler applies the global rate itself and returns `1.0` for the ops in `FULLY_SAMPLED_OPS` (`closure.rebuild`).
- **Scrubbing:** [`SentryEventScrubber`](../../src/Shared/Infrastructure/Monitoring/Sentry/SentryEventScrubber.php) and [`SentryLogScrubber`](../../src/Shared/Infrastructure/Monitoring/Sentry/SentryLogScrubber.php) filter events, breadcrumbs, and log attributes before send.
- **Request context:** [`SentryRequestContextSubscriber`](../../src/Shared/Infrastructure/Monitoring/Http/SentryRequestContextSubscriber.php) sets user (ID/username), `route`, `bounded_context`, `origin`, and `request_id`.

## CSP violation reports

CSP is configured separately via Nelmio (report-only in prod). Violations appear in Sentry under **Issues**, not Logs. Setup, policy, verification, and triage: [content-security-policy.md](content-security-policy.md).

## Issues vs. logs

- **Issues:** unhandled exceptions and PHP errors via the error listener.
- **Logs:** structured Monolog entries in Sentry Logs; domain keys live in the log message (for example `import.summary`).

## Expected HTTP exceptions

Normal 404 and 403 responses are not application defects. They are filtered by **exception type** in [`config/packages/sentry.yaml`](../../config/packages/sentry.yaml) (`ignore_exceptions`), not by HTTP status code.

| Exception | Why it is ignored |
|-----------|-------------------|
| `NotFoundHttpException` | Expected 404 for missing resources or unknown routes |
| `AccessDeniedHttpException` | HTTP 403 thrown directly (for example onboarding) or after Security wraps an access denial |
| `AccessDeniedException` | Expected authorization failure (`denyAccessUnlessGranted()`, voters, `createAccessDeniedException()`) |

Sentry’s error listener runs at priority **128**, before Symfony Security’s exception listener (priority **1**) wraps `AccessDeniedException` as `AccessDeniedHttpException`. Both types must therefore be listed; ignoring only the HTTP wrapper would still report the original security exception.

Unexpected errors inside voters, permission services, or other authorization logic (`RuntimeException`, `TypeError`, …) are **not** ignored and remain visible in Sentry. Authentication failures (`AuthenticationException`) are also not filtered.

## Import log keys in Sentry

| Message | Sent to Sentry |
|---------|----------------|
| `import.summary` | yes |
| `import.not_found` | yes |
| `import.failed` | yes |
| `import.failed.precondition` | yes |
| `import.abort.unexpected` | yes |
| `import.abort.flush_failed` | yes |
| `import.rejects.cleared` | yes |
| `import.reject_file.deleted` | yes |
| `import.source_file.deleted` | yes |
| `import.file.delete_failed` | yes |
| `import.run.summary` | yes (allowlisted attributes only) |
| `import.run.threshold_exceeded` | yes (allowlisted attributes only) |
| `import.run.retries_exhausted` | yes (allowlisted attributes only) |
| `reject.row_rejected` | no (local only) |
| `reject.row_type_unknown` | no (local only) |

Per-row reject logs stay in `var/log/import.*.log` or, in production, on stderr (JSON).

## Background job runs

Long-running background jobs share one run log and one set of telemetry rules ([ADR 018](../02-architecture/decisions/018-job-run-monitoring.md)):

- **Database:** one row per run and attempt in `job_run`: `job`, `triggered_by`, `status` (`running` / `succeeded` / `failed` / `abandoned`, imports add their own), `attempt`, import / import run / hospital ids, start and end (`TIMESTAMPTZ`), `duration_ms`, `peak_memory_bytes`, `thresholds_exceeded`, `error_class`, `error_message` (local only) and job-specific counters in `metrics` (`jsonb`). It is the long-term record for comparing runs, independent of Sentry retention. Writing it never fails the job; a failed write logs `job_run.record_failed`.
- **Sentry transaction** per run, kept off the scope, so Doctrine query spans stay with the surrounding worker or console transaction. The ops of every job are sampled at 100 % by [`SentryTracesSampler`](../../src/Shared/Infrastructure/Monitoring/Sentry/SentryTracesSampler.php); everything else keeps `SENTRY_TRACES_SAMPLE_RATE`.
- **Thresholds** per job in `config/packages/app.yaml` under `app.job_runs.<job>` (`slow_seconds`, `long_seconds`, `memory_mb`). A missing value is not checked; a job without values only records its runs.
- **Allowlist:** each job registers a [`JobRunSentryProfile`](../../src/Shared/Infrastructure/Monitoring/JobRun/JobRunSentryProfile.php) (transaction, sampled ops, span op prefix, log prefixes, allowed keys). [`SentryJobRunFilter`](../../src/Shared/Infrastructure/Monitoring/Sentry/SentryJobRunFilter.php) applies it in `before_send_transaction` and `before_send_log`: ids, counts, durations and fixed labels only. Foreign spans (for example SQL), span descriptions, unknown keys and free-text strings are dropped.

Compare runs of one job:

```sql
SELECT started_at, triggered_by, status, duration_ms / 1000 AS s,
       peak_memory_bytes / 1048576 AS mb, thresholds_exceeded, metrics
FROM job_run
WHERE job = 'closure_rebuild'
ORDER BY started_at DESC
LIMIT 20;
```

### Job runs in the admin area

Admins (`ROLE_ADMIN`) find the runs without SQL under **Admin → System → Job runs** (`/admin/job-run`). The list is read-only; nothing in the UI writes `job_run`.

- **List:** newest first, 50 per page. Columns: start, job, trigger, status, attempt, hospital, import (links to the import), duration, peak memory and exceeded thresholds. Duration and peak memory can be sorted to find the slowest or largest runs.
- **Filters:** job, status, trigger, hospital, start time (before, after, between), thresholds exceeded (yes / no), import id and import run id. The hospital filter matches `hospital_id`; closure rebuilds keep their hospitals in `metrics.hospital_ids` and are not found by it.
- **Shortcuts:** failed runs, runs with exceeded thresholds, runs of the last 24 hours.
- **Detail:** all columns, the error class and message of a failed run, and `metrics` as a table with labels and readable values (phase times of imports, rows and materialized view refresh of projection rebuilds, passes and partitions of closure rebuilds). Links lead to the other attempts of the same import run, to all runs of the import and to all runs of the job.
- **From an import:** the import's detail page in admin links to its runs ("Job runs"), which covers the import attempts and the projection rebuild of that import.

The admin list reads the table through the read-only entity `JobRun`; job, trigger and status are plain strings, so a value without a label is shown as stored.

Before relying on this in production, confirm the Sentry organisation's data region (EU) and that the DPA covers operational telemetry with hospital ids.

### Closure rebuild

Every closure volume rebuild run ([`ClosureRebuildTelemetry`](../../src/Statistics/ClosureAnalytics/Infrastructure/Monitoring/ClosureRebuildTelemetry.php)) records:

- **`job_run`** with job `closure_rebuild`, trigger `console` / `messenger` / `scheduler`. `metrics`: `full`, `hospital_ids`, `passes`, `events`, `partitions`, `hours_written`, `publish_ms`, `requests`, `queries`, `reference_window`. A row still `running` when the next run takes the lock belongs to a dead process and is marked `abandoned`.
- **Sentry transaction** `closure_rebuild` (op `closure.rebuild`) with spans `closure.rebuild.analysis`, `.pass`, `.late_claim`, `.hospital`, `.partition` and `.publish`.
- **Logs** on the `closure_rebuild` channel: `closure_rebuild.summary` (info, one per run), `closure_rebuild.threshold_exceeded` (warning), `closure_rebuild.failed` (error, class only), `closure_rebuild.run_abandoned` (warning). Locally they go to `var/log/closure_rebuild.<env>.log`, in production to stderr (JSON), and to Sentry Logs.

Thresholds (`app.job_runs.closure_rebuild`), reported in `threshold_exceeded` and as transaction tag `threshold_exceeded`:

| Threshold | Value | Label |
|-----------|-------|-------|
| Duration | > 30 min | `slow` |
| Duration above the former lock TTL | > 2 h | `long_run` |
| Peak memory (worker limit 256 MB) | > 200 MB | `memory` |
| More than one pass (in code) | > 1 | `passes` |

Its profile ([`ClosureRebuildSentryProfile`](../../src/Statistics/ClosureAnalytics/Infrastructure/Monitoring/ClosureRebuildSentryProfile.php)) covers the `closure_rebuild` transaction and `closure_rebuild.*` / `closure_volume.*` logs. No hospital or department names, no closure periods (partition spans carry no week), no error messages.

### Imports

Every attempt of an allocation or closure import run ([`ImportRunTelemetry`](../../src/Import/Infrastructure/Monitoring/ImportRunTelemetry.php)) records:

- **`job_run`** with job `allocation_import` or `closure_import`, the trigger of the import run (`upload`, `console`, `batch_requeue`, `reprocess`, `bulk_reprocess`), `import_id`, `import_run_id`, `hospital_id` and `attempt`. Status: `completed`, `partial`, `failed`, `superseded` or `retrying` (a retryable error; Messenger retries the same import run, which becomes the next attempt). A row still `running` when the next attempt of the same import run starts belongs to a dead worker and is marked `abandoned`. `metrics`: `rows_total`, `rows_ok`, `rows_rejected`, `rows_deduplicated` (allocation only, taken from the evaluated import), `chunks`, `read_ms`, `map_ms`, `persist_ms`. `import_run` stays the domain record of the run.
- **Sentry transaction** `import_run` (op `import.allocations` / `import.closures`) with spans `import.cleanup`, `import.profile` (closure hospital scan), `import.rows`, `import.deduplicate` and `import.finish`. Below `import.rows`, the spans `import.read`, `import.map` and `import.persist` carry the **summed** time of each phase (`summed: true`): reading and parsing the file, detecting and mapping rows, and flushing chunks plus writing rejected rows. They are laid out one after another, so the waterfall shows the share of each phase, not when it happened.
- **Logs** on the `import` channel: `import.run.summary` (info, one per attempt), `import.run.threshold_exceeded` (warning) and `import.run.retries_exhausted` (warning).
- **Retries exhausted:** when Messenger gives up on the message, `ImportRunRetriesExhaustedSubscriber` ends the import run as failed and the last attempt in `job_run` changes from `retrying` to `failed`. Its Sentry transaction was already sent with `retrying`, so `import.run.retries_exhausted` (run, import run and import id, job, attempt) is the signal for alerts.

Thresholds (`app.job_runs.allocation_import` / `closure_import`, see [Measured runs](#measured-runs)): `slow` above 120 s, `long_run` above 15 min (allocation) / 10 min (closure), `memory` above 200 MB. `attempts` is reported for every attempt after the first. Messages without a run id (legacy) are not recorded.

Compare the phases of the slowest allocation imports:

```sql
SELECT started_at, import_id, status, duration_ms / 1000 AS s,
       (metrics->>'rows_total')::int AS rows,
       metrics->>'read_ms' AS read_ms, metrics->>'map_ms' AS map_ms, metrics->>'persist_ms' AS persist_ms,
       peak_memory_bytes / 1048576 AS mb
FROM job_run
WHERE job = 'allocation_import'
ORDER BY duration_ms DESC NULLS LAST
LIMIT 20;
```

### Allocation stats projection rebuild

Every rebuild of the allocation stats projection for one import ([`AllocationProjectionRunTelemetry`](../../src/Statistics/Infrastructure/Monitoring/AllocationProjectionRunTelemetry.php), Messenger handler `RebuildAllocationStatsProjectionHandler`) records:

- **`job_run`** with job `allocation_projection_rebuild`, trigger `messenger`, `import_id` and `hospital_id`; status `succeeded` or `failed` (the error is rethrown, so Messenger retries it as a new row). A row still `running` for the same import when the message is redelivered belongs to a dead worker and is marked `abandoned`. `metrics`: `rows_deleted`, `rows_written`, `mv_refreshed` and the time per step (`change_detection_ms`, `delete_ms`, `insert_ms`, `closure_request_ms`, `mv_refresh_ms`).
- **Sentry transaction** `allocation_projection_rebuild` (op `projection.allocation_stats`, sampled at 100 %) with spans `projection.change_detection`, `projection.delete`, `projection.insert`, `projection.closure_request` and `projection.mv_refresh` (only when the import brings a new hospital).
- **Logs** on the `projection_rebuild` channel: `projection_rebuild.summary` (info, one per run), `projection_rebuild.threshold_exceeded` (warning), `projection_rebuild.failed` (error, class only). Locally in `var/log/projection_rebuild.<env>.log`, in production on stderr (JSON) and in Sentry Logs.

The console rebuild of every import (`app:statistics:rebuild-projection`) is one run with job `allocation_projection_full_rebuild`, trigger `console` and no import id, so it is compared and limited apart from the rebuilds per import. Its spans are `projection.truncate`, `projection.rebuild_imports` and `projection.mv_refresh`; below `projection.rebuild_imports`, `projection.delete` and `projection.insert` carry the **summed** time of all imports (`summed: true`), like the phase spans of imports. `metrics` add `imports`, `truncate_ms` and `rebuild_imports_ms`; rows are summed over all imports. A failed materialized view refresh fails the run. A full rebuild still `running` when the next one starts is marked `abandoned`.

`app:import:start` only dispatches the import message, so its runs are recorded by the worker with trigger `console` (see Imports).

Thresholds (see [Measured runs](#measured-runs)): per import (`app.job_runs.allocation_projection_rebuild`) `slow` above 30 s and `long_run` above 5 min; full rebuild (`allocation_projection_full_rebuild`) `slow` above 10 min and `long_run` above 30 min; `memory` above 200 MB for both.

### Measured runs

Thresholds were derived on 2026-10-25 from a copy of the development database (same data as production), in `prod` mode without debug, each run in a fresh worker (`messenger:consume --limit=1 --memory-limit=256M`). Durations on production hardware may differ; adjust `app.job_runs` once `job_run` holds production runs (query below).

| Job | Runs | Duration | Peak memory | Threshold `slow` / `long_run` |
|-----|------|----------|-------------|-------------------------------|
| `allocation_import` | 6 (1,882–9,772 rows) | 5–23 s; all imports since 2021: p95 32 s | 44 MB | 120 s / 15 min |
| `closure_import` | 2 (13,130 and 15,187 rows) | 15–16 s | 38–40 MB | 120 s / 10 min |
| `allocation_projection_rebuild` | 12 | 0.7–2.4 s | 26 MB | 30 s / 5 min |
| `allocation_projection_full_rebuild` | 1 (1,094 imports, 2.29 M rows) | 131 s (insert 123 s) | 22 MB | 10 min / 30 min |
| `closure_rebuild` | 22 + 1 | per hospital p95 562 s, full max 1,586 s | 106 MB | 30 min / 2 h (unchanged) |

`memory` is 200 MB for every job: a warning below the worker limit of 256 MB, far above the measured peaks.

In allocation imports, persisting takes 60–70 % of the row loop, mapping about 20 %, reading about 6 %.

**Overhead:** the same imports with the code before #693 and with telemetry, alternating, two rounds for the largest and the smallest import:

| Import | Rows | Without telemetry | With telemetry |
|--------|------|-------------------|----------------|
| 995 | 9,772 | 21.2 s / 18.7 s | 20.6 s / 18.0 s |
| 1123 | 1,882 | 4.1 s / 3.7 s | 3.9 s / 3.4 s |
| 1118 | 6,591 | 12.4 s | 12.4 s |
| 941 | 6,439 | 12.2 s | 11.7 s |
| 1116 (closure) | 13,130 | 14.2 s | 14.6 s |
| 1117 (closure) | 15,187 | 16.3 s | 16.0 s |

The differences go both ways and stay within the run-to-run variation, so the overhead is not measurable. Without a DSN the Sentry SDK does not record spans; with a DSN the cost is a few hundred spans per run, sent after the run.

Check the thresholds against production runs:

```sql
SELECT job, count(*) AS runs,
       percentile_cont(0.95) WITHIN GROUP (ORDER BY duration_ms) / 1000 AS p95_s,
       max(duration_ms) / 1000 AS max_s,
       max(peak_memory_bytes) / 1048576 AS max_mb,
       count(*) FILTER (WHERE thresholds_exceeded IS NOT NULL) AS exceeded
FROM job_run
WHERE started_at > now() - interval '30 days' AND status <> 'running'
GROUP BY job
ORDER BY job;
```

### Alert rules (Sentry UI)

Every job transaction carries the tags `status` and `threshold_exceeded` (`none` or a comma list of `slow`, `long_run`, `memory`, `passes`, `attempts`), so metric alerts on transactions cover all jobs. Configure in Sentry for environment `beta` / `prod`:

| Alert | Type | Filter | Condition |
|-------|------|--------|-----------|
| Threshold exceeded | Performance / Transactions | `transaction:[closure_rebuild,import_run,allocation_projection_rebuild] !threshold_exceeded:none` | `count()` > 0 in 1 h |
| Failed run | Performance / Transactions | `transaction:[closure_rebuild,import_run,allocation_projection_rebuild] status:failed` | `count()` > 0 in 1 h |
| Failure rate | Performance / Transactions | `transaction:import_run` | `failure_rate()` > 10 % in 1 day |
| Slow trend | Performance / Transactions | `transaction:import_run` (and per transaction) | `p95(transaction.duration)` above twice the `slow` threshold in 1 day |
| Logs (if the plan offers log alerts) | Logs | `message:*.threshold_exceeded`, `message:import.run.retries_exhausted`, `message:closure_rebuild.failed` / `message:projection_rebuild.failed` | any in 1 h |

`threshold_exceeded` containing `long_run` means a run took longer than the former lock TTL (closure rebuild) or far longer than any measured run of its job (imports and projection rebuilds, still well within the worker's `--time-limit=3600`). An import status `retrying` is not a failure. When the retries of an import are exhausted, the last attempt becomes `failed` in `job_run` and `import.run.retries_exhausted` is logged; alert on it like on a failed run (log alert `message:import.run.retries_exhausted`). Every retried attempt also reports `attempts` in `threshold_exceeded`.

Unhandled exceptions of a failed run still create a regular issue. "No completed import within the expected cycle" is a separate follow-up (scheduled check, not a run alert).

## Privacy

Request bodies and email addresses are not sent to Sentry. Log attributes that contain paths, raw data, or reject payloads are filtered or truncated before send.

## Local smoke test

In `dev` or `staging`, with a DSN configured, `GET /_debug/sentry/test` triggers a controlled exception.

## Verifying in Sentry

1. **Errors:** after the smoke test, expect a new issue with environment, release, and tags `route` / `bounded_context`.
2. **Logs:** Explore -> Logs with filters such as `message:import.summary` or `level:error`.
3. **Performance:** HTTP requests, Messenger jobs, and Doctrine queries appear as automatic transactions and spans.
4. **Privacy:** event and log details should not include request bodies or unnecessary personal data.

## Uptime monitoring

The Symfony SDK (`sentry/sentry-symfony`) sends errors, logs, and traces **from the app to Sentry**. It does **not** poll URLs. For reachability checks, configure **Sentry Uptime Monitoring** (or another external HTTP monitor) in the Sentry UI — no application code required.

### Setup (Sentry UI)

1. Open **Alerts** → **Uptime Monitors** → create monitor.
2. URL: `https://<APP_URL>/health` (same value as `APP_URL` in `shared/.env.local`).
3. Interval: e.g. every 5 minutes.
4. Expected HTTP status: **200**.

## Proactive error alerts (beta)

Uptime checks detect availability problems, but they do not alert on issue spikes. For the closed beta, define an additional error alert in the Sentry UI.

### Setup (Sentry UI)

1. Open **Alerts** → **Alert Rules** → create alert rule.
2. Filter scope: `environment=beta`.
3. Start with a simple threshold such as **more than `N` new issues in 1 hour**.
4. Add the team notification action used for beta operations.

Tune `N` after the first beta days based on normal issue volume, so noise stays manageable while true regressions still trigger quickly.

### What triggers an alert

| Condition | Uptime alert |
|-----------|--------------|
| Timeout or connection failure | yes |
| HTTP **503** (`unhealthy`, database down) | yes |
| HTTP **200** with `"status": "degraded"` (failed Messenger messages) | no (by design) |

`degraded` means the app is up but the failed queue has messages. Use `php bin/console messenger:failed:show`, the Admin ops dashboard, or [health-check.md](health-check.md) — Sentry Uptime does not parse JSON response bodies (public `/health` also omits version and messenger details).

Related: [health-check.md](health-check.md) (`GET /health` response format).
