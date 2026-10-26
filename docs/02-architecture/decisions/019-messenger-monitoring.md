# ADR 019: Messenger monitoring with hourly metrics, scheduler jobs in the run log, and bounded retention

**Status:** accepted

## Context

More and more work runs in Messenger workers: imports, projection rebuilds, KPI and analytics aggregation, notification delivery and reconciliation, storage measurement, monthly reminders (issue #738). What the admin area shows about it today:

- `MessengerStatsRepository`: pending messages per queue and the list of the `failed` transport (operations dashboard).
- Health check `messenger_failed`: degraded as soon as the `failed` transport holds a message.
- `job_run` (ADR 018): one row per run for the long-running jobs (imports, projection rebuilds, closure rebuild), with duration, memory, thresholds and counters.
- Domain records: `import_run`, `notification_delivery`, `monthly_reminder_dispatch`.

What is missing:

- How many messages of which type were handled, how many failed for good, how many needed retries, how long handling took, and how that changes over time.
- Whether a scheduled job ran at all. Most scheduler jobs only write a log line; a stopped scheduler or worker leaves no failed message behind, so the existing health check stays green.

The monthly reminder also tracks delivery on a path of its own: `MonthlyReminderEmailCustomizer` adds the dispatch and attempt id as mail headers and `MonthlyReminderDeliverySubscriber` reads them on `SentMessageEvent`. Since ADR 016 `notification_delivery` tracks the same state, and failures already reach Engagement through `NotificationDeliveryFailed`.

`job_run` is the wrong place for every message: notification and mail messages are frequent and short, a row per message would grow the table quickly and answer nothing that a counter does not.

## Decision

Monitoring has three levels with one question each:

| Level | Question | Storage |
|-------|----------|---------|
| Messenger | Is message handling working? (all messages, aggregated) | `messenger_metric`, `messenger_failure` |
| Job | Did the job run, how long, with what result? (scheduler jobs and long-running jobs) | `job_run` (ADR 018) |
| Domain | What happened to the import, the notification, the reminder? | `import_run`, `notification_delivery`, `monthly_reminder_dispatch` |

Sentry stays the place for exceptions and traces. Message payloads are never stored.

### Messenger level: hourly counters per message type and transport

A subscriber on the worker events (`WorkerMessageReceivedEvent`, `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent`) counts every message a worker handles. It measures the time from "received" to "handled" or "failed".

`messenger_metric` holds one row per bucket, message class and transport:

- `granularity` (`hour` or `day`), `bucket_start` (`TIMESTAMPTZ`), `message_class`, `transport`
- `attempts`: handling attempts
- `handled`: attempts that succeeded
- `handled_after_retry`: of these, attempts with a `RedeliveryStamp` (succeeded only after a retry)
- `retried`: failed attempts that Messenger retries (`willRetry()`)
- `failed`: failed attempts without a retry (the message is given up or moved to the `failed` transport)
- `duration_ms_sum`, `duration_ms_max`
- `last_activity_at`

The counters keep logical messages and attempts apart: `handled + failed` are final outcomes of logical messages, `attempts` and `retried` count attempts. The success rate is `handled / (handled + failed)`; the retry rate is `retried / attempts`.

The subscriber writes one `INSERT … ON CONFLICT DO UPDATE` per attempt. Like `JobRunRecorder`, it catches its own errors and logs `messenger_metric.record_failed`; a monitoring error never fails or retries a message.

`messenger_failure` holds one row per failed attempt: message class, transport, attempt number, whether a retry follows, exception class, time. It holds no exception message: messages of final failures stay readable in the `failed` transport, everything else is in Sentry. It answers which exceptions keep failing the same message type.

Only messages handled by a worker are counted. Messages handled synchronously (`sync://`, development routing of mail and notification delivery) are not.

Queue state is read live from `messenger_messages` (Doctrine transport, ADR 003): pending messages, messages waiting for a retry (`available_at` in the future), the age of the oldest pending message, messages in `failed`. The Symfony Scheduler transport has no queue; the page shows its queue values as "not measurable" and relies on the job level instead.

### Job level: every scheduler job writes `job_run`

Every job started by the Symfony Scheduler writes a `job_run` row, also short ones (KPI, analytics aggregation, notification cleanup and reconciliation, storage measurement, monthly reminder).

- Jobs with their own telemetry class (ADR 018: closure rebuild, imports, projection rebuilds) keep it.
- Every other job is recorded by a Messenger middleware. Each context registers the message classes of its jobs with a job name in its Infrastructure layer; the message classes stay unchanged.
- The trigger is `scheduler` for messages with a `ScheduledStamp`, `console` or `messenger` otherwise.
- Handlers can add counters to `metrics` through a port in `Shared/Application/Contract` (for example `sent` and `skipped` of the monthly reminder). Handlers without counters need no change.
- New status `skipped`: the job ran but had nothing to do (monthly reminder outside the first working day, lock held by another run). A skipped run still proves that the scheduler and the worker are alive.
- Each scheduler job has an expected interval in `app.job_runs.<job>.overdue_after_minutes`. A job without a finished run within that time is overdue.

### Monthly reminder: one delivery path

- Notification dispatches `NotificationDeliverySent` when a delivery moves to `sent`, like `NotificationDeliveryFailed` for failures.
- Engagement updates its dispatch from that event, with the same attempt check as for failures.
- The reminder's mail headers and `MonthlyReminderDeliverySubscriber` are removed.
- `monthly_reminder_dispatch` keeps its columns (`status`, `delivered_at`, `failure_reason`, `attempt_id`). It prevents a second scheduled reminder per hospital and period and keeps the reminder history after the notification retention (ADR 016) removed the notification and its deliveries.
- The scheduler batch becomes the job `monthly_reminder`.

### Health

The checks become contributors (`HealthCheckContributorInterface`); none of them is public.

- `messenger_failed`: moves from `HealthCheckService` into a contributor, unchanged (degraded while `failed` holds messages).
- `messenger_backlog`: degraded or unhealthy when the oldest pending message of a queue is older than the configured limit.
- `messenger_stalled`: unhealthy when messages are pending and no attempt was recorded within the configured time. An empty queue or an idle worker is never a failure.
- `messenger_retries`: degraded when the retry rate of the last 24 hours is above the configured limit and enough attempts were recorded.
- `scheduled_jobs`: degraded when a job is overdue, unhealthy when it is overdue by more than twice its interval.

Limits live in `app.messenger_monitoring` in `config/packages/app.yaml`.

### Retention

A daily scheduler job (itself recorded in `job_run`):

- folds hourly rows of `messenger_metric` older than 90 days into daily rows and deletes the hourly rows; daily rows are kept;
- deletes rows of `messenger_failure` older than 30 days;
- deletes `job_run` rows older than 180 days with status `succeeded` or `skipped` and no exceeded threshold; every other row is kept for 730 days.

The values live in `app.messenger_monitoring.retention` and `app.job_runs_retention`.

## Consequences

- The admin area gets a Messenger page (overview, transports, message types, failures, history over 24 hours, 7 days and 30 days) reading `messenger_metric`, `messenger_failure` and `messenger_messages`.
- Charts read hourly rows for up to 30 days and daily rows beyond.
- Every handled message costs one additional upsert in the worker, outside the handler's transaction.
- `job_run` grows by about 10,000 rows per year from scheduler jobs, mostly the hourly reconciliation; the retention keeps it bounded.
- `JobRunCatalog` learns the new jobs, their metrics keys and the status `skipped`.
- The open retention question of ADR 018 is answered here.
- Symfony 8.2's Messenger logging middleware (issue #652) can add per-message log lines later; it does not replace these counters.

## Related

- ADR 003 (Doctrine Messenger transport)
- ADR 016 (notification system, retention)
- ADR 017 (storage monitoring, health contributors)
- ADR 018 (job run log)
- Issues #738, #652
