# Beta readiness checklist

Checklist for the transition from alpha to a **closed beta** (invited participants only).

Automated checks use `php bin/console app:env:check` (Install bounded context, alongside `app:install`). Manual server checks cannot be replaced by the command.

Related: [Configuration.md](Configuration.md), [Deployment.md](Deployment.md), [Backup-restore.md](Backup-restore.md)

## P0 — before closed beta

| ID | Item | How to verify | Status |
|----|------|---------------|--------|
| P0-1 | Backup & restore strategy | Follow [Backup-restore.md](Backup-restore.md); restore drill with `make verify-restore` | |
| P0-2 | Production secrets & env | `php bin/console app:env:check --check-profile=beta` on server (see below) | |
| P0-3 | HTTP health endpoint | `GET /health` returns JSON with database OK | planned |
| P0-4 | SQL month aggregation | `countByMonthLast12Months()` uses SQL `GROUP BY` | planned |

### P0-2 on the server (Uberspace)

```bash
cd ~/www/current
set -a && source ../shared/.env.local && set +a
php bin/console app:env:check --check-profile=beta
```

Expected: exit code `0`, no `FAIL` rows. Warnings are acceptable only where documented (for example optional `SENTRY_ENVIRONMENT` in prod profile — not in beta).

Record date and result here:

- Verified on: ___________
- Result: ___________

## Manual production checks (not automated)

These are required in addition to `app:env:check`:

- [ ] `APP_ENV=prod` and `APP_DEBUG=0` in `shared/.env.local`
- [ ] Messenger worker running: `systemctl --user status messenger`
- [ ] Queue consumers active: `php bin/console messenger:stats`
- [ ] No stuck failed messages: `php bin/console messenger:failed:show`
- [ ] Transactional mail works (registration or password reset test)
- [ ] Sentry receives a test event (if `SENTRY_DSN` is set)
- [ ] Backups scheduled on Uberspace (cron — see [Backup-restore.md](Backup-restore.md))

## Environment variables reference

| Variable | Check | Risk if missing or wrong |
|----------|-------|---------------------------|
| `APP_SECRET` | set, min. 32 characters | insecure sessions, cookies, signed URLs |
| `DATABASE_URL` | PostgreSQL URL, DB reachable | application cannot persist data |
| `APP_URL` | `https://` public domain in prod | mail links point to localhost |
| `MAILER_DSN` | real transport, not `null://null` | no verification or reset mail |
| `MAILER_FROM` | valid sender address | bounces, spam folder |
| `SENTRY_DSN` | required for `--check-profile=beta` | no error monitoring during beta |
| `SENTRY_ENVIRONMENT` | e.g. `beta` or `prod` | wrong Sentry environment grouping |
| `MESSENGER_TRANSPORT_DSN` | set | async imports and mail stall |

`app:env:check` prints status per variable but **never** prints secret values.

### Profiles

| Profile | Use case |
|---------|----------|
| `dev` (default in `APP_ENV=dev`) | local development; failures become warnings |
| `prod` | production deployment gate |
| `beta` | closed beta; same as prod plus required `SENTRY_DSN` |

Options:

- `--skip-database` — skip DB connectivity ping (faster, no DB required)
- `--check-profile=beta` — beta gate on server

Local shortcut: `make env-check`

## Bootstrap order on a new server

1. Configure `shared/.env.local`
2. `php bin/console app:env:check --check-profile=beta`
3. Deploy / migrate database
4. Optionally `php bin/console app:install` (bootstrap admin — see Install command)
5. Start Messenger worker

`app:install` creates data; `app:env:check` only validates configuration.

## P1 / P2 (after P0)

Higher-priority follow-ups from the beta-readiness audit:

- Referenzdaten-Cache for explore filter dropdowns
- ShowAllocation N+1 fix
- CSP report-only
- Functional tests for auth/onboarding
- Sentry alerts and failed-message monitoring
- HTTP `/health` endpoint (P0-3)

See the project beta-readiness audit plan for the full roadmap.
