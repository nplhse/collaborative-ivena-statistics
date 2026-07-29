# Health check

`GET /health` is a public endpoint (no login) that returns slim JSON for uptime monitoring.

Related: [observability-sentry.md](observability-sentry.md), [deployment.md](deployment.md), [troubleshooting.md](troubleshooting.md)

## Response statuses

| `status` | HTTP | Meaning |
|--------|------|---------|
| `healthy` | 200 | Database reachable, no failed Messenger messages |
| `degraded` | 200 | Database OK, failed queue has messages — check `messenger:failed:show` or Admin ops dashboard |
| `unhealthy` | 503 | Database unreachable |

Public JSON includes only `status` and `checks.database` (no app `version`, no `messenger_failed` count). Full details remain available in the Admin ops dashboard.

Example:

```bash
curl -sS https://<host>/health | jq
# { "status": "healthy", "checks": { "database": "ok" } }
```

## Uptime monitoring

For external uptime monitoring (Sentry Uptime or similar), point the monitor at `https://<APP_URL>/health` and expect HTTP **200**.

| Condition | Uptime alert |
|-----------|--------------|
| Timeout or connection failure | yes |
| HTTP **503** (`unhealthy`, database down) | yes |
| HTTP **200** with `"status": "degraded"` | no (by design) |

`degraded` means the app is up but the failed queue has messages. Investigate with `php bin/console messenger:failed:show` or the Admin ops panel. Sentry Uptime does not parse JSON response bodies.

## Runbook: `degraded` or failed queue entries

Use this when `/health` returns `degraded` or when routine checks show failed messages.

1. Inspect failed messages:
   ```bash
   cd ~/www/current
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

### Sentry Uptime setup

1. Open **Alerts** → **Uptime Monitors** → create monitor.
2. URL: `https://<APP_URL>/health` (same value as `APP_URL` in `shared/.env.local`).
3. Interval: e.g. every 5 minutes.
4. Expected HTTP status: **200**.

Details: [observability-sentry.md](observability-sentry.md#uptime-monitoring)
