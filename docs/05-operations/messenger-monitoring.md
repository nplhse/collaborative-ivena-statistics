# Messenger monitoring

How to see whether background processing works: the admin Messenger page, the run log of scheduler jobs, the health checks and the retention of the monitoring data ([ADR 019](../02-architecture/decisions/019-messenger-monitoring.md)).

Related: [messenger-workers.md](messenger-workers.md), [health-check.md](health-check.md), [observability-sentry.md](observability-sentry.md#background-job-runs), [../02-architecture/messenger-and-scheduler.md](../02-architecture/messenger-and-scheduler.md)

## Three levels

| Level | Question | Where to look |
|-------|----------|---------------|
| Messenger | Is message handling working? (all messages, counted per hour) | **Admin → System → Messenger** (`/admin/operations/messenger`) |
| Job | Did the job run, how long, with what result? | **Admin → System → Job runs** (`/admin/job-run`) |
| Domain | What happened to the import, the notification, the reminder? | Import list, notification deliveries, **Monthly reminders** |

Sentry stays the place for exceptions and traces. No level stores message payloads.

## Messenger page

Choose 24 hours (default), 7 days or 30 days. The queues always show their current state.

- **KPIs:**
  - handled messages, and how many only after a retry
  - failed for good, with the success rate `handled / (handled + failed)`
  - retries, with the retry rate `retried / attempts`
  - average and longest duration
  - messages pending now, and those waiting for a retry
  - last activity, and the size of the failure queue (links to **Failed messages**)
- **History:** handled, retried and failed messages as bars; average duration and the queue backlog as lines. One point per hour, per day for 30 days. Gaps in the backlog line mean no queue sample was taken: the scheduler or the worker did not run.
- **Transports:** per transport, pending messages, messages waiting for a retry, the oldest pending message and the counters of the period. `scheduler_default` has no queue ("No queue to measure"); whether its jobs run shows in the job runs.
- **Message types:** attempts, success and retry rate, duration and last activity per message class.
- **Recent failures:** failed attempts grouped by message type and exception class: how many were retried, how many given up. Details are in Sentry and, for messages given up, in the failure queue.

### What is counted

- Every handling attempt of a worker counts once: `WorkerMessageReceivedEvent` to `WorkerMessageHandledEvent` or `WorkerMessageFailedEvent`.
- A failure that Messenger retries counts as a **retry**, not as failed. **Failed** means no retry follows: the message went to the failure queue or was dropped.
- Messages handled synchronously (`sync://`; in `dev` mail and notification delivery) are not counted.
- **Tables:**
  - `messenger_metric`: one row per hour, message class and transport
  - `messenger_failure`: one row per failed attempt, with the exception class but without the message
  - `messenger_queue_sample`: the hourly backlog sample
- A failed write is logged as `messenger_metric.record_failed` and never fails or retries the message.

## Scheduler jobs in the run log

Every scheduler job writes a `job_run` row, with the trigger `scheduler`. Jobs with their own telemetry (imports, projection rebuilds, closure rebuild) are described in [observability-sentry.md](observability-sentry.md#background-job-runs). All other scheduler jobs are recorded by `JobRunMiddleware`.

| Job | Message | Schedule | `overdue_after_minutes` | Counters (`metrics`) |
|-----|---------|----------|-------------------------|----------------------|
| `kpi_daily` | `GenerateDailyKpisMessage` | every 6 h | 420 | `days_processed`, `days_with_data`, `rows` |
| `monthly_reminder` | `SendMonthlySubmissionRemindersMessage` | daily 08:00 | 1500 | `hospitals`, `queued`, `skipped`, `skipped_<reason>` |
| `analytics_aggregation` | `AggregateDailyAnalyticsMessage` | daily 02:15 | 1500 | `days_processed`, `raw_requests`, `raw_events`, `aggregate_rows`, `raw_rows_deleted` |
| `notification_cleanup` | `CleanUpNotificationsMessage` | daily 02:45 | 1500 | `deleted_notifications`, `deleted_deduplication_keys`, `corrected_recipients` |
| `notification_reconcile` | `ReconcileNotificationsMessage` | hourly :17 | 120 | `published`, `deliveries_resumed` |
| `storage_measurement` | `MeasureStorageMessage` | daily 03:30 | 1500 | `level`, `failures` |
| `messenger_queue_sample` | `SampleMessengerQueuesMessage` | hourly :05 | 120 | `pending`, `waiting`, `failed`, `oldest_pending_age_seconds` |
| `monitoring_retention` | `CleanUpMonitoringMessage` | daily 03:50 | 1500 | `folded_daily_rows`, `deleted_hourly_rows`, `deleted_failures`, `deleted_queue_samples`, `deleted_job_runs` |

Times are Europe/Berlin.

- **`skipped`:** the job ran but had nothing to do. `skip_reason: already_running` means another run held the lock. For `monthly_reminder`, `skip_reason: not_due` means it was not the first working day; this row appears every day and proves that scheduler and worker run.
- **`closure_rebuild`:** it runs hourly but writes a row only when there is work, so it has no `overdue_after_minutes`.
- **Stuck rows:** a row that stays `running` belongs to a worker that died. The health check looks at finished runs only, so the job shows up as overdue.

Last run of every scheduler job:

```sql
SELECT DISTINCT ON (job) job, status, finished_at, duration_ms, metrics
FROM job_run
WHERE finished_at IS NOT NULL
ORDER BY job, finished_at DESC;
```

## Health checks

Four checks add to Application Health ([health-check.md](health-check.md#messenger-and-scheduler-checks)): `messenger_backlog`, `messenger_stalled`, `messenger_retries` and `scheduled_jobs`. None is public, but each counts towards the status of `/health`. The admin dashboard shows them with their severity and links them here or to the job runs.

## Retention

The daily job `monitoring_retention` keeps the tables bounded:

| Data | Kept |
|------|------|
| Hourly rows of `messenger_metric` | 90 days, then folded into daily rows (whole UTC days), which are kept |
| `messenger_failure` | 30 days |
| `messenger_queue_sample` | 90 days |
| `job_run`, `succeeded` or `skipped` without an exceeded threshold | 180 days |
| every other `job_run` row | 730 days |

## Configuration

`config/packages/app.yaml`:

```yaml
app:
    job_runs:
        kpi_daily:
            overdue_after_minutes: 420   # expected interval of a scheduler job
    job_runs_retention:
        routine_days: 180
        other_days: 730
    messenger_monitoring:
        retention:
            hourly_days: 90        # at least 31: the 30-day view reads hourly rows
            failure_days: 30
            queue_sample_days: 90  # at least 31
        health:
            backlog_warning_minutes: 30
            backlog_critical_minutes: 120
            stalled_after_minutes: 15
            retry_rate_warning_percent: 10
            retry_min_attempts: 20
```

The values shown are the defaults.

## Runbooks

### `messenger_stalled` or `messenger_backlog`

1. Check the worker:
   ```bash
   systemctl --user status messenger
   journalctl --user -u messenger -n 200
   ```
2. Restart it if it is not running or keeps crashing on start (see [messenger-workers.md](messenger-workers.md)):
   ```bash
   systemctl --user restart messenger
   ```
3. Watch the queue drain on the Messenger page (pending, last activity) or with `php bin/console messenger:stats`.
4. If the worker runs but one queue stays behind, look at the oldest message's type on the page and at its runs in the job run list. A single long job (for example a large import) delays everything behind it on the same queue.

### `messenger_retries`

Open **Recent failures** on the Messenger page: the exception class shows the cause. Temporary causes (database deadlocks, SMTP rate limits) usually resolve themselves. Repeating exceptions of one message type need a fix; the Sentry issue holds the details.

### `scheduled_jobs`

- **Overdue:** check that the worker consumes `scheduler_default` (it is part of the systemd unit). Then look at the job's last runs in the job run list (filter by job).
- **Last run failed:** open the run's detail page. The error class and message are stored locally; the Sentry issue has the trace. The job runs again at its next schedule; a successful run clears the state.
