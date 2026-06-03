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
| `LEGACY_DATABASE_URL` | Legacy migration source (MySQL/MariaDB) | only for legacy migration |
| `MESSENGER_TRANSPORT_DSN` | Queue backend | default `doctrine://default?auto_setup=0` |
| `MAILER_DSN` | Mail transport | required in production |
| `MAILER_FROM` | Sender address | transactional mail |
| `MAILER_REPLY_TO` | Reply-to address | optional |
| `APP_URL` | Public base URL | required in prod for correct links |
| `SENTRY_DSN` | Sentry DSN | empty = disabled |
| `SENTRY_ENVIRONMENT` | Sentry environment | falls back to `APP_ENV` |
| `SENTRY_RELEASE` | Sentry release | optional; falls back to `App\Kernel::VERSION` (`app.version`) |
| `SENTRY_TRACES_SAMPLE_RATE` | Trace sampling rate | `0.0`–`1.0` |
| `SENTRY_ENABLE_LOGS` | Structured logs | `true` / `false` |

## Database

- `default`: PostgreSQL (application data)
- `legacy`: optional second connection for legacy migration
- Test database uses a suffix (`_test...`)

See also `config/packages/doctrine.yaml`.

## Messenger / queue

Transports (see `config/packages/messenger.yaml`):
- `async_priority_high`
- `async_priority_low`
- `failed`
- `sync`

Routing examples:
- Import dispatch → `async_priority_high`
- Statistics rebuild → `async_priority_low`
- Mail / notifier → async in prod, partly sync in dev

## External dependencies

- PostgreSQL
- Optional SMTP provider (`MAILER_DSN`)
- Optional Sentry
- Optional legacy DB for migration

## Operations-related configuration

- [Deployment.md](Deployment.md)
- [Observability-sentry.md](Observability-sentry.md)
