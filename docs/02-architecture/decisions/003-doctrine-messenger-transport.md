# ADR 003: Doctrine as Messenger transport

**Status:** accepted

## Context

Production runs on Uberspace without dedicated Redis or RabbitMQ infrastructure. Async jobs (imports, statistics rebuild, mail, KPI aggregation) must be reliable and operable by a small team.

## Decision

Use Doctrine transport (`doctrine://default`) backed by the PostgreSQL `messenger_messages` table. A single systemd user service consumes `async_priority_high`, `async_priority_low`, and `scheduler_default`.

## Consequences

**Positive:**

- No additional infrastructure beyond the application database
- Messages survive worker restarts
- Failed messages inspectable via `messenger:failed:show`
- Deployer can restart workers after deploy

**Negative:**

- Queue throughput limited compared to dedicated brokers
- Database load from message polling
- Scheduler and domain jobs share the same worker process

## Alternatives

- **Redis transport** — rejected; no managed Redis on current hosting
- **Sync transport in production** — rejected; imports would block HTTP requests

## References

- [../messenger-and-scheduler.md](../messenger-and-scheduler.md)
- [../../05-operations/messenger-workers.md](../../05-operations/messenger-workers.md)
