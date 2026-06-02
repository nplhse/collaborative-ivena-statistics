# Sentry (alpha monitoring)

This application uses `sentry/sentry-symfony` for error monitoring, structured logs, and automatic performance tracing across HTTP, Messenger, and Doctrine.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `SENTRY_DSN` | Sentry project DSN; leave empty to disable |
| `SENTRY_ENVIRONMENT` | Optional; falls back to `APP_ENV` (`local`, `dev`, `staging`, `alpha`, `prod`) |
| `SENTRY_RELEASE` | Optional; falls back to `APP_VERSION` |
| `SENTRY_TRACES_SAMPLE_RATE` | Share of transactions to trace (`0.0`–`1.0`) |
| `SENTRY_ENABLE_LOGS` | Enable structured logs (`true` / `false`) |

For alpha deployments, set a DSN, `SENTRY_ENVIRONMENT=alpha`, and `SENTRY_TRACES_SAMPLE_RATE` between `0.2` and `1.0`. Keep the DSN empty locally and in CI; structured logs are disabled in the `test` environment.

## Integration

- **Bundle:** [`config/packages/sentry.yaml`](../config/packages/sentry.yaml) — errors, HTTP/Messenger/Doctrine tracing, and structured logs.
- **Monolog:** [`config/packages/monolog.yaml`](../config/packages/monolog.yaml) — `sentry_logs_import` (`import` channel, info and above) and `sentry_logs_main` (other channels, warning and above).
- **Scrubbing:** [`SentryEventScrubber`](../src/Shared/Infrastructure/Monitoring/Sentry/SentryEventScrubber.php) and [`SentryLogScrubber`](../src/Shared/Infrastructure/Monitoring/Sentry/SentryLogScrubber.php) filter events, breadcrumbs, and log attributes before send.
- **Request context:** [`SentryRequestContextSubscriber`](../src/Shared/Infrastructure/Monitoring/Http/SentryRequestContextSubscriber.php) sets user (ID/username), `route`, `bounded_context`, `origin`, and `request_id`.

## Issues vs. logs

- **Issues:** unhandled exceptions and PHP errors via the error listener.
- **Logs:** structured Monolog entries in Sentry Logs; domain keys live in the log message (for example `import.summary`).

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
