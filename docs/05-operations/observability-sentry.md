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
- **Logs** on the `import` channel: `import.run.summary` (info, one per attempt) and `import.run.threshold_exceeded` (warning).

Thresholds: not set yet (`app.job_runs.allocation_import` / `closure_import`), so imports only record their runs; they follow from the measured runs (#693, step 5). `attempts` is reported for every attempt after the first. Messages without a run id (legacy) are not recorded.

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

Thresholds: not set yet (`app.job_runs.allocation_projection_rebuild`), so the rebuild only records its runs until step 5 of #693.

### Alert rules (Sentry UI)

The transaction carries the tags `status` (`succeeded` / `failed`), `passes` and `threshold_exceeded` (`none` or a comma list), so metric alerts on transactions cover all cases:

1. **Alerts → Create alert → Performance / Transactions:** filter `transaction:closure_rebuild !threshold_exceeded:none`, `count()` above 0 in 1 hour, environment `beta` / `prod`.
2. Same with filter `transaction:closure_rebuild status:failed`.
3. If the Sentry plan offers alerts on logs, `message:closure_rebuild.threshold_exceeded` and `message:closure_rebuild.failed` work as well.

Unhandled exceptions of a failed rebuild still create a regular issue.

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
