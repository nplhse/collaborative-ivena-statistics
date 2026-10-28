# ADR 023: Separate demo environment with its own database, storage and synthetic data

**Status:** accepted

## Context

Issue #791 asks for an offline demo for prospective participating hospitals: the platform runs on
a laptop, looks populated from the start and later lets the presenter create a personalised
synthetic hospital. The demo must not reach production data, credentials or services, must not
send mail or telemetry, and must be recognisable as a demo. Production behaviour must not change.

Local development already has two databases with very different content: the default dev
database `app` often holds a mirror of production data, and some checkouts point `.env.dev.local`
at a synthetic database (`app_fixtures`). A demo that only swaps `DATABASE_URL` in the dev
environment would share caches, uploads, imports and the debug tooling with that work, and one
wrong `.env.local` would show real data in a presentation.

## Decision

### `APP_ENV=demo` is its own Symfony environment

- Configuration lives in `.env.demo` (committed, local defaults only, no secrets),
  `config/packages/demo/` and `when@demo` blocks. Symfony loads `.env.demo` after `.env.local`, so
  a developer's `.env.local` cannot redirect the demo; `.env.demo.local` is the place for local
  overrides.
- The demo runs without debug mode (`APP_DEBUG=0`): no profiler, no debug toolbar, no stack traces
  on screen. WebProfiler, Debug, Maker, UX Inspector and DAMA bundles stay off.
- The demo loads data with the fixture code (DoctrineFixturesBundle, Foundry, `App\DataFixtures`),
  so these are enabled for `demo` as for `dev` and `test`. They are dev dependencies: the demo needs
  `composer install` with dev packages, and a production deploy (`--no-dev`) cannot even boot the
  demo environment.

### Isolation

| Area | Demo |
|---|---|
| Database | `app_demo` on the local server (`.env.demo`) |
| Imports, rejects | `var/demo/imports`, `var/demo/imports/rejects` (`app.imports_base_dir`, `app.rejects_base_dir`) |
| Media uploads | `public/uploads/demo/media` (`app.media_upload_dir`, Vich mapping `media`) |
| Cache, logs | `var/cache/demo`, `var/log/demo.log` (Symfony default per environment) |
| Messenger | same Doctrine transports; the queues live in `app_demo`, so they are separate |
| Locks | PostgreSQL advisory locks of `app_demo` |
| Cookies | `demo_PHPSESSID`, `demo_REMEMBERME`, `demo_consent_subject_id`, `demo_APP_LOCALE`, `demo_analytics_*` |
| Geodata | `var/demo/geo/hospital-isochrones` (`app.hospital_isochrones_dir`), `var/demo/geo/prepared-locations` (`app.prepared_locations_dir`) |

Browsers keep cookies per host, not per port. A dev server with TLS on `https://127.0.0.1:8000`
sets secure cookies that an `http` page on port 8001 may not overwrite, so a demo with the same
cookie names gets no session (no CSRF token, no cookie consent, no login). The demo therefore names
its session cookie `demo_PHPSESSID` and prefixes the application's own cookies (consent, locale,
analytics, remember-me) with `app.cookie_prefix` (`demo_`; empty everywhere else, so the names in
dev, test and production stay as they are). The double-submit CSRF cookies are named per token and
get the `__Host-` prefix on https, so they cannot collide.

`DemoEnvironmentGuard` (registered only with `#[When('demo')]`) checks the Doctrine connection
before every main request and console command and stops with an error unless the database is on
this machine (`127.0.0.1`, `localhost`, `::1`, the compose service `database` or a socket) and its
name ends in `_demo`. This catches a `DATABASE_URL` set on the machine, which wins over every
`.env` file (the Symfony CLI exports one for Docker databases; the demo Make targets therefore use
plain `php bin/console`).

### No outbound integrations

- Mail: `framework.mailer.dsn: null://null` in `config/packages/demo/`, not an env var, so no
  variable can turn delivery on. Mail and notification delivery messages run synchronously as in
  dev, so the demo needs no mail worker.
- Sentry: `dsn: null`, no traces, no logs, also fixed in config.
- OpenRouteService: `.env.demo` leaves `OPENROUTESERVICE_API_KEY` empty. Preparing geodata while
  online is an explicit opt-in through `.env.demo.local` (offline geography step of #791).
- Usage analytics stay on: they write to the demo database only.

### Data and visible marker

- `make demo-install` loads the dev fixture presets (`config/packages/demo/fixtures.yaml` imports
  the dev file) with `large` as default (all 20 participating hospitals of the synthetic catalog,
  24 months, scenarios); `DEMO_PRESET=realistic` gives a quicker install. The data comes from the
  code defaults and the synthetic catalog (ADR 020, ADR 022); no private catalog or pattern package
  is needed.
- The parameter `app.demo_mode` (false by default, true only `when@demo`) feeds the Twig global
  `app_demo_mode`: the layout shows a banner ("all data is synthetic") and prefixes the page title;
  the EasyAdmin layout (`templates/bundles/EasyAdminBundle/layout.html.twig`) shows the same banner.

### Demo CSV upload (step 5 of #791)

The presenter shows a monthly upload with a real round trip instead of a demo-specific import
processor: `app:demo:csv` writes one synthetic month of a hospital as IVENA files (allocations and
closures), uploaded through the normal form, parser, permission checks and projections. The rows are
the ones `HospitalMonthGenerator` writes for that month (same random streams, ADR 022), resolved
against the installed catalog by name like any upload. Nothing in the import code knows about the
demo. A demo processor selected through dependency injection was the fallback in #791; it is not
needed because the synthetic catalog resolves every value of the file (#875).

Files and rows say they are synthetic: the file name starts with `synthetic-demo_`, every row has
`SYNTHETIC DEMO DATA (#791)` in the free text (allocations, stored as notes) or remark (closures),
and case numbers start with `SYNTH-`.

### Code that checks the environment by name

Two places that allowed only `dev` and `test` also allow `demo`: creating missing overview
materialized views on the fly (`OverviewMaterializedViewsInstaller`) and
`app:dev:reset-operational-data`. Both stay refused in `prod`.

## Consequences

- Dev and demo run side by side (ports 8000 and 8001) without sharing data, uploads or caches.
- A wrong `.env.local`, a machine-wide `DATABASE_URL` or a mail/Sentry setting cannot pull the demo
  onto production data or send anything out.
- Without debug mode the demo keeps its compiled container after code or configuration changes; `make demo-install` and `make demo-start` delete `var/cache/demo` first.
- `make demo-start` compiles the asset map into `public/assets`, which dev then also serves until
  the next `asset-map:compile` (as after `make install-dev`).
- `bin/check-deprecations` (CI lint) also compiles the demo container, so demo configuration
  cannot silently break.
- Storage monitoring classifies the known production paths; demo paths are not categorised.
- Every new external integration has to decide how it behaves in `demo` (off by default).

## Alternatives

- **Dev environment with another database** (`.env.dev.local`): smallest change, but shares
  uploads, imports and debug tooling with development, shows the profiler, and a wrong local file
  points the presentation at real data. No visible marker without new switches.
- **Prod environment with demo env vars**: prod-like behaviour, but the fixture bundles are dev
  dependencies and must not be enabled in prod, and demo switches would sit in the production
  configuration.
- **Separate Docker stack**: strongest isolation, but the demo laptop then needs Docker images
  built and available offline, and it duplicates the existing local setup.

## References

- Issue #791, operations guide [demo-environment.md](../../05-operations/demo-environment.md)
- [ADR 020](020-portable-reference-catalog.md), [ADR 022](022-synthetic-data-generation.md)
