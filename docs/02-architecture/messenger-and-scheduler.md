# Messenger and scheduler

Symfony Messenger handles async domain work. The Symfony Scheduler dispatches recurring jobs into the same worker.

Related: [../05-operations/messenger-workers.md](../05-operations/messenger-workers.md), [decisions/003-doctrine-messenger-transport.md](decisions/003-doctrine-messenger-transport.md)

## Transports

Configured in `config/packages/messenger.yaml`:

| Transport | Purpose |
|-----------|---------|
| `async_priority_high` | Import processing |
| `async_priority_low` | Statistics rebuild, KPI aggregation |
| `async_mail` | Transactional and bulk mail (rate-limited) |
| `scheduler_default` | Symfony Scheduler messages |
| `failed` | Failed message store |

Default DSN: `doctrine://default?auto_setup=0` (PostgreSQL `messenger_messages` table).

## Message routing (examples)

| Message | Transport |
|---------|-----------|
| `ImportAllocationsMessage` | `async_priority_high` |
| `RebuildAllocationStatsProjection` | `async_priority_low` |
| `GenerateDailyKpisMessage` | `async_priority_low` |
| `SendEmailMessage` (prod) | `async_mail` |

In `dev`, mail is synchronous. In `test`, most messages use `sync`.

## Scheduler

`KpiScheduleProvider` (`src/Kpi/Infrastructure/Scheduler/KpiScheduleProvider.php`) registers:

| Schedule | Message |
|----------|---------|
| `0 */6 * * *` (every 6 hours) | `GenerateDailyKpisMessage` |

`EngagementScheduleProvider` (`src/Engagement/Infrastructure/Scheduler/EngagementScheduleProvider.php`, schedule name `engagement`) registers:

| Schedule | Message |
|----------|---------|
| `0 8 * * *` (daily 08:00 Europe/Berlin) | `SendMonthlySubmissionRemindersMessage` |

Workers must consume `scheduler_default` and `scheduler_engagement` (see `make consume`).

The scheduler requires a running worker consuming `scheduler_default`. Locally: `make consume`.

Details: [../04-features/kpi/kpi-aggregation.md](../04-features/kpi/kpi-aggregation.md)

## Local development

```bash
make consume
```

This runs `messenger:consume async_priority_high async_priority_low async_mail scheduler_default` with verbose output.

## Production

A systemd user service must consume all three transports. See [../05-operations/messenger-workers.md](../05-operations/messenger-workers.md).

Deployer restarts the worker after each deploy via `messenger:restart`.
