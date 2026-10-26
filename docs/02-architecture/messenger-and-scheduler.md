# Messenger and scheduler

Symfony Messenger handles async domain work. The Symfony Scheduler dispatches recurring jobs into the same worker.

Related: [../05-operations/messenger-workers.md](../05-operations/messenger-workers.md), [decisions/003-doctrine-messenger-transport.md](decisions/003-doctrine-messenger-transport.md)

## Transports

Configured in `config/packages/messenger.yaml`:

| Transport | Purpose |
|-----------|---------|
| `async_priority_high` | Import processing |
| `async_priority_low` | Statistics rebuild, KPI aggregation, analytics aggregation, notification retention and reconciliation, storage measurement |
| `async_mail` | Transactional and bulk mail (rate-limited) |
| `scheduler_default` | Symfony Scheduler messages |
| `failed` | Failed message store |

Default DSN: `doctrine://default?auto_setup=0` (PostgreSQL `messenger_messages` table).

## Message routing (examples)

| Message | Transport |
|---------|-----------|
| `ImportAllocationsMessage` | `async_priority_high` |
| `ImportClosuresMessage` | `async_priority_high` |
| `DeleteImportsMessage` (bulk deletion from the import list) | `async_priority_low` |
| `RebuildAllocationStatsProjection` | `async_priority_low` |
| `GenerateDailyKpisMessage` | `async_priority_low` |
| `SendMonthlySubmissionRemindersMessage` | `async_priority_low` |
| `AggregateDailyAnalyticsMessage` | `async_priority_low` |
| `CleanUpNotificationsMessage` | `async_priority_low` |
| `ReconcileNotificationsMessage` | `async_priority_low` |
| `DeliverNotificationMessage` | `async_priority_low` |
| `SampleMessengerQueuesMessage`, `CleanUpMonitoringMessage` | `async_priority_low` |
| `SendEmailMessage` (prod) | `async_mail` |

In `dev`, mail is synchronous. In `test`, most messages use `sync`.

## Scheduler

`DefaultScheduleProvider` (`src/Shared/Infrastructure/Scheduler/DefaultScheduleProvider.php`) collects jobs from `ScheduleContributionInterface` implementations:

| Schedule | Message |
|----------|---------|
| `0 */6 * * *` (every 6 hours) | `GenerateDailyKpisMessage` |
| `0 8 * * *` (daily 08:00 Europe/Berlin) | `SendMonthlySubmissionRemindersMessage` |
| `15 2 * * *` (daily 02:15 Europe/Berlin) | `AggregateDailyAnalyticsMessage` |
| `45 2 * * *` (daily 02:45 Europe/Berlin) | `CleanUpNotificationsMessage` |
| `17 * * * *` (hourly) | `ReconcileNotificationsMessage` |
| `30 3 * * *` (daily 03:30 Europe/Berlin) | `MeasureStorageMessage` (ADR 017) |
| `5 * * * *` (hourly) | `SampleMessengerQueuesMessage` (queue backlog sample, ADR 019) |
| `50 3 * * *` (daily 03:50 Europe/Berlin) | `CleanUpMonitoringMessage` (monitoring retention, ADR 019) |

The scheduler requires a running worker consuming `scheduler_default`. Locally: `make consume`.

Details: [../04-features/kpi/kpi-aggregation.md](../04-features/kpi/kpi-aggregation.md), [../04-features/analytics/aggregation-and-retention.md](../04-features/analytics/aggregation-and-retention.md)

## Local development

```bash
make consume
```

This runs `messenger:consume async_priority_high async_priority_low async_mail scheduler_default` with verbose output.

## Production

A systemd user service must consume all three transports. See [../05-operations/messenger-workers.md](../05-operations/messenger-workers.md).

Deployer restarts the worker after each deploy via `messenger:restart`.
