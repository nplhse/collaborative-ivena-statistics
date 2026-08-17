# Sentry (beta monitoring)

This application uses `sentry/sentry-symfony` for error monitoring, structured logs, and automatic performance tracing across HTTP, Messenger, and Doctrine.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `SENTRY_DSN` | Sentry project DSN; leave empty to disable |
| `SENTRY_ENVIRONMENT` | Optional; falls back to `APP_ENV` (`local`, `dev`, `staging`, `beta`, `prod`) |
| `SENTRY_RELEASE` | Optional; falls back to `App\Kernel::APP_VERSION` (`app.version`) |
| `SENTRY_TRACES_SAMPLE_RATE` | Share of transactions to trace (`0.0`–`1.0`) |
| `SENTRY_ENABLE_LOGS` | Enable structured logs (`true` / `false`) |
| `SENTRY_CSP_REPORT_URI` | Optional Sentry CSP report endpoint; prod only — see [content-security-policy.md](content-security-policy.md) |

For beta deployments, set a DSN, `SENTRY_ENVIRONMENT=beta`, and `SENTRY_TRACES_SAMPLE_RATE` between `0.2` and `1.0`. Keep the DSN empty locally and in CI; structured logs are disabled in the `test` environment.

## Integration

- **Bundle:** [`config/packages/sentry.yaml`](../../config/packages/sentry.yaml) — errors, HTTP/Messenger/Doctrine tracing, and structured logs.
- **Monolog:** [`config/packages/monolog.yaml`](../../config/packages/monolog.yaml) — `sentry_logs_import` (`import` channel, info and above) and `sentry_logs_main` (other channels, warning and above).
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
| `reject.row_rejected` | no (local only) |
| `reject.row_type_unknown` | no (local only) |

Per-row reject logs stay in `var/log/import.*.log` or, in production, on stderr (JSON).

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
