# Configuration

## Basics

- Defaults live in `.env` and `.env.example`
- Local overrides in `.env.local` (do not commit)
- Production uses server-side `shared/.env.local` (Deployer)

## Required variables (local)

| Variable | Purpose | Example |
|---|---|---|
| `APP_ENV` | Environment | `dev` |
| `APP_SECRET` | Symfony secret | hex string |
| `DATABASE_URL` | Primary database (PostgreSQL) | `postgresql://...` |

## Important optional variables

| Variable | Purpose | Notes |
|---|---|---|
| `MESSENGER_TRANSPORT_DSN` | Queue backend | default `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | Mail transport | required in production |
| `MAILER_FROM` | Sender address | transactional mail |
| `MAILER_REPLY_TO` | Reply-to address | optional |
| `APP_URL` | Public base URL | required in prod for correct links |
| `APP_TITLE` | Application display title | overrides `app.title` in `app.yaml` |
| `SENTRY_DSN` | Sentry DSN | empty = disabled |
| `SENTRY_ENVIRONMENT` | Sentry environment | falls back to `APP_ENV` |
| `SENTRY_RELEASE` | Sentry release | optional; falls back to `App\Kernel::APP_VERSION` |
| `SENTRY_TRACES_SAMPLE_RATE` | Trace sampling rate | `0.0`–`1.0` |
| `SENTRY_ENABLE_LOGS` | Structured logs | `true` / `false` |
| `FIXTURES_SCALE` | Dev fixture volume multiplier | `1`–`10`; see [../03-development/fixtures.md](../03-development/fixtures.md) |

## Application configuration (`app.yaml`)

Custom app settings in `config/packages/app.yaml` (via `AppExtension`):

| Key | Default | Purpose |
|-----|---------|---------|
| `app.title` | Collaborative IVENA statistics | Display name (override with `APP_TITLE`) |
| `app.short_title` | COIS | Short brand label |
| `app.default_locale` | `en` | Default locale |
| `app.blog.title` / `app.blog.description` | — | Blog metadata |
| `app.import.reject_writer` | `db` | Reject persistence: `db` or `csv` |
| `app.import.csv_reject_dir` | `var/import_rejects` | CSV reject output directory |
| `app.feedback.spam.*` | — | Feedback spam detection thresholds |

See [../02-architecture/decisions/005-reject-writer-strategy.md](../02-architecture/decisions/005-reject-writer-strategy.md).

## Database

- `default`: PostgreSQL (application data)
- Test database uses a suffix (`_test...`)

See also `config/packages/doctrine.yaml`.

## Messenger / queue

Transports (see `config/packages/messenger.yaml`):

- `async_priority_high`
- `async_priority_low`
- `scheduler_default`
- `failed`
- `sync`

Routing examples:

- Import dispatch → `async_priority_high`
- Statistics rebuild → `async_priority_low`
- Mail / notifier → async in prod, partly sync in dev

Details: [../02-architecture/messenger-and-scheduler.md](../02-architecture/messenger-and-scheduler.md)

## External dependencies

- PostgreSQL
- Optional SMTP provider (`MAILER_DSN`)
- Optional Sentry

## `app:env:check`

Validate environment variables before deployment:

```bash
php bin/console app:env:check              # dev profile locally
php bin/console app:env:check --check-profile=prod
make env-check
```

| Profile | Use case |
|---------|----------|
| `dev` (default in `APP_ENV=dev`) | Local development; failures become warnings |
| `prod` | Production deployment gate |
| `beta` | Same as prod plus required `SENTRY_DSN` |

Options:

- `--skip-database` — skip DB connectivity ping
- `--check-profile=prod` — production gate on server

`app:env:check` prints status per variable but **never** prints secret values.

Production verification checklist: [../05-operations/deployment.md](../05-operations/deployment.md#pre-deploy-verification)

## Operations-related configuration

- [../05-operations/deployment.md](../05-operations/deployment.md)
- [../05-operations/observability-sentry.md](../05-operations/observability-sentry.md)
- [../05-operations/transactional-mail.md](../05-operations/transactional-mail.md)
