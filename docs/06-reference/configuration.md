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
| `SYMFONY_TRUSTED_HOSTS` | Host names the application answers to | recommended in prod: a regular expression for the host of `APP_URL`, for example `^statistik\.example\.org$`. Requests with any other `Host` header get `400`. Read by Symfony itself (`framework.trusted_hosts`); empty accepts every host |
| `SYMFONY_TRUSTED_PROXIES` | Reverse proxies whose `X-Forwarded-*` headers are trusted | only behind a proxy that sets these headers: its address or range, for example `127.0.0.1`. Without it, client IPs (rate limits, audit log) are the proxy's address. Never `REMOTE_ADDR` or `0.0.0.0/0` unless only the proxy can reach the server |
| `APP_TITLE` | Application display title | overrides `app.title` in `app.yaml` |
| `APP_CONTENT_DEFAULT_LOCALE` | CMS page content fallback locale | default `en`; set `de` in production |
| `SENTRY_DSN` | Sentry DSN | empty = disabled |
| `SENTRY_ENVIRONMENT` | Sentry environment | falls back to `APP_ENV` |
| `SENTRY_RELEASE` | Sentry release | optional; falls back to `App\Kernel::APP_VERSION` |
| `SENTRY_TRACES_SAMPLE_RATE` | Trace sampling rate | `0.0`–`1.0` |
| `SENTRY_ENABLE_LOGS` | Structured logs | `true` / `false` |
| `SENTRY_CSP_REPORT_URI` | Sentry CSP report endpoint | optional; prod only; adds `report-uri` when set (see [content-security-policy.md](../05-operations/content-security-policy.md)) |
| `ANALYTICS_RAW_RETENTION_DAYS` | Days of detailed usage-analytics rows to keep | default `30`; see [../04-features/analytics/aggregation-and-retention.md](../04-features/analytics/aggregation-and-retention.md) |
| `OPENROUTESERVICE_API_KEY` | OpenRouteService key for `app:geo:geocode-hospitals` and `app:geo:fetch-isochrones` | optional; empty skips both commands. Maps read stored GeoJSON only. See [../05-operations/hospital-geodata.md](../05-operations/hospital-geodata.md). Also used by `app:geo:prepare-location`. |
| `MAP_TILE_URL` | Leaflet tile URL template of all maps | default OpenStreetMap (`https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png`). Empty: no basemap (manual switch). If the tile server is unreachable, maps drop the basemap on their own. See [../05-operations/hospital-geodata.md](../05-operations/hospital-geodata.md#map-tiles-and-offline-use). |
| `MAP_TILE_ATTRIBUTION` | Attribution HTML shown on the maps | default OpenStreetMap; set it to match the tile source |
| `MAP_TILE_MAX_ZOOM` | Highest zoom level of the maps | default `18`; match it to the tile server |
| `FIXTURES_PROFILE` | Which hospitals get synthetic allocations | `single`, `minimal`, `medium`, `realistic`, `large`; default `medium` in `dev`, `minimal` in `test`, `large` in `demo`; see [../03-development/fixtures.md](../03-development/fixtures.md#profiles) |
| `FIXTURES_SCALE` | Factor on the synthetic allocations per day | `0.01`–`10`, default `1` (close to real volumes); see [../03-development/fixtures.md](../03-development/fixtures.md) |
| `FIXTURES_UNTIL` | Last day of the synthetic data | `Y-m-d`, default empty (yesterday); a fixed date gives the same data on every day; see [../03-development/fixtures.md](../03-development/fixtures.md) |
| `FIXTURES_MONTHS` | Whole months of synthetic data before the month of the last day | default empty (the months of the preset, else 12); see [../03-development/fixtures.md](../03-development/fixtures.md#parameters) |
| `FIXTURES_PATTERNS` | Distribution patterns of the synthetic allocations | default `defaults` (code defaults), or a pattern package as a file name in `var/patterns/` or a path; see [../03-development/synthetic-data-generator.md](../03-development/synthetic-data-generator.md#pattern-packages) |
| `FIXTURES_REFERENCE_PACKAGE` | Reference package (JSON) whose catalog replaces the synthetic one; states, dispatch areas and hospitals stay synthetic | default empty; see [../03-development/synthetic-data-generator.md](../03-development/synthetic-data-generator.md#composed-catalog) |
| `FIXTURES_CATALOG_MAPPING` | Mapping file (JSON) that binds the generator's names to another catalog | default empty; see [../03-development/synthetic-data-generator.md](../03-development/synthetic-data-generator.md#mapping-file) |
| `STORAGE_BUDGET_BYTES` | Storage budget in bytes, the default capacity reference | empty or `0` = no budget (level `unknown`); falls back to the former `APP_ADMIN_STORAGE_LIMIT_BYTES`. See [Storage monitoring](#storage-monitoring) |
| `STORAGE_CAPACITY_SOURCE` | Capacity reference: `budget` or `filesystem` | default `budget`; `filesystem` only on a dedicated server or VPS |

## Application configuration (`app.yaml`)

Custom app settings in `config/packages/app.yaml` (via `AppExtension`):

| Key | Default | Purpose |
|-----|---------|---------|
| `app.title` | Collaborative IVENA statistics | Display name (override with `APP_TITLE`) |
| `app.short_title` | COIS | Short brand label |
| `app.default_locale` | `en` | Default UI locale |
| `app.content.default_locale` | `en` (`APP_CONTENT_DEFAULT_LOCALE`) | CMS page content fallback locale (independent of UI) |
| `app.blog.title` / `app.blog.description` | — | Blog metadata |
| `app.import.reject_writer` | `db` | Reject persistence: `db` or `csv` |
| `app.import.csv_reject_dir` | `var/import_rejects` | CSV reject output directory |
| `app.registration.blocked_email_domains` | `.ru` | Domain suffixes that silently suppress registration (exact domain or TLD; case-insensitive, label-based; UI matches a successful registration) |
| `app.feedback.spam.*` | — | Feedback spam detection thresholds |
| `app.meta.copyright_start_year` | `2025` | Start year for footer copyright range (`© 2025–{current year}`) |
| `app.meta.hoster.name` / `app.meta.hoster.url` | `Uberspace` / `https://uberspace.de` | Optional hosting provider shown in the footer meta line |
| `app.storage.thresholds.notice_percent` / `warning_percent` / `critical_percent` | `80` / `90` / `99` | Storage levels in percent of the capacity reference; must increase strictly and stay below 100 (checked at container build) |
| `app.storage.measurement_max_age_hours` | `36` | A storage measurement older than this is `stale` for Application Health |

See [../02-architecture/decisions/005-reject-writer-strategy.md](../02-architecture/decisions/005-reject-writer-strategy.md).

## Storage monitoring

Daily storage measurement, levels and alerts ([feature guide](../04-features/storage/README.md), [ADR 017](../02-architecture/decisions/017-storage-monitoring.md)):

- **Budget:** `STORAGE_BUDGET_BYTES` in `shared/.env.local`, e.g. `10737418240` for 10 GiB. Compared with files + database. `APP_ADMIN_STORAGE_LIMIT_BYTES` is still read when `STORAGE_BUDGET_BYTES` is not set; move the value over, the fallback will be removed.
- **Reference:** `STORAGE_CAPACITY_SOURCE=filesystem` compares the filesystem-wide usage with the filesystem size instead. On shared hosting this is the host disk, so keep `budget` there. Without plausible filesystem values the budget is used.
- **Levels:** `app.storage.thresholds.*` in `config/packages/app.yaml` (application configuration, not environment variables, so the container build can validate them). `exceeded` always starts at 100 %.
- **Freshness:** `app.storage.measurement_max_age_hours` decides when the health check reports `stale`.

## Database

- `default`: PostgreSQL (application data)
- Test database uses a suffix (`_test...`)

See also `config/packages/doctrine.yaml`.

## Messenger / queue

Transports (see `config/packages/messenger.yaml`):

- `async_priority_high`
- `async_priority_low`
- `async_mail`
- `scheduler_default`
- `failed`
- `sync`

Routing examples:

- Import dispatch → `async_priority_high`
- Statistics rebuild → `async_priority_low`
- Mail / notifier → `async_mail` in prod, sync in dev/test

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
- [../05-operations/content-security-policy.md](../05-operations/content-security-policy.md)
- [../05-operations/transactional-mail.md](../05-operations/transactional-mail.md)
