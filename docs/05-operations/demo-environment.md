# Demo environment

A local, offline demo of the platform with synthetic data only (issue #791, [ADR 023](../02-architecture/decisions/023-demo-environment.md)). It runs next to the development environment and never touches its database.

| | Development | Demo |
|---|---|---|
| `APP_ENV` | `dev` | `demo` |
| Database | `.env.local` (often `app`) | `app_demo` from `.env.demo` |
| URL | http://127.0.0.1:8000 | http://127.0.0.1:8001 |
| Imports, uploads | `var/imports`, `public/uploads/media` | `var/demo/imports`, `public/uploads/demo/media` |
| Cookies | `PHPSESSID`, `consent_subject_id`, … | `demo_PHPSESSID`, `demo_consent_subject_id`, … |
| Debug mode, profiler | on | off |
| Mail, Sentry, OpenRouteService | configurable | off |

## Prerequisites

- A working development checkout with **dev dependencies** (`composer install`, not `--no-dev`): the demo loads its data with the fixture code.
- The local PostgreSQL server from `compose.yaml` (or another one on this machine). The demo database must be local and its name must end in `_demo`; otherwise every request and command stops with "The demo environment refuses this database".
- Importmap assets (`php bin/console importmap:install`) while online.

## Install the demo data

```bash
make demo-install
```

This drops and recreates `app_demo`, runs the migrations, empties the demo storage, loads the dev fixtures with the `large` preset (all 20 participating hospitals of the synthetic catalog, 24 months, flu wave, MCI and icy road scenarios), rebuilds the closure volume projection and refreshes the materialized views. Expect about 5 minutes; install before the presentation.

- Quicker install: `make demo-install DEMO_PRESET=realistic` (15 hospitals, 12 months, about 2 minutes). Presets: [../03-development/fixtures.md](../03-development/fixtures.md).
- When `app_demo` already holds an installation, the target stops; `DEMO_CONFIRM=yes` replaces it.
- Users and passwords are the ones of the dev fixtures (for example `foo`).

## Start the demo

Two terminals:

```bash
make demo-start    # fresh cache, asset-map:compile, built-in PHP server on http://127.0.0.1:8001
```

```bash
make demo-consume  # Messenger worker: imports, projections, scheduler
```

`DEMO_PORT=8002 make demo-start` uses another port. The page title starts with "Demo" and a banner says that all data is synthetic, in the application and in the admin area.

The demo targets call `APP_ENV=demo php bin/console`, not `symfony console`: the Symfony CLI can export `DATABASE_URL` of a Docker database, which would override `.env.demo` (the guard would then stop the command).

## Upload a month during the presentation

The demo shows how a participant uploads a monthly IVENA export. The files are synthetic but go
through the normal upload: form, permission checks, parser, rejects and projections.

Before the presentation, write the files for the presented hospital:

```bash
APP_ENV=demo php bin/console app:demo:csv --hospital=<public id>
```

The command writes two files to `var/demo/csv/`:

- `synthetic-demo_<hospital>_<YYYY-MM>_allocations.csv`
- `synthetic-demo_<hospital>_<YYYY-MM>_closures.csv`

Without `--month` it takes the month after the hospital's newest allocation or closure. It refuses a
month that is not complete by yesterday (or `FIXTURES_UNTIL`) and a month that already has data for
the hospital. It writes nothing into the database.

During the presentation, logged in as a participant with the import permission for the hospital
(and `ROLE_CLOSURE_BETA` for the closure file, assigned in EasyAdmin):

1. Import page → choose the hospital → upload the allocation file. `make demo-consume` must run.
2. When it is processed, upload the closure file as a closure import.

The allocation file contains the rows the generator would write for that month, including infants
with age 0. The import accepts ages 1 to 99, so these few rows show up as rejects (see the reject
analysis of the import).

The files say they are synthetic: the file name prefix, `SYNTHETIC DEMO DATA (#791)` in the free text
of every allocation (stored as its notes) and in the remark of every closure, and case numbers
starting with `SYNTH-`.

**Intended flow:** the hospital wizard (step 4 of #791) generates the presented hospital up to the end
of the month before last and leaves the last complete month empty, so the upload fills a whole month
right before today. Hospitals of the baseline installation have data up
to yesterday, so the command finds no free complete month for them.

## Cookies

Browsers keep cookies per host, not per port: dev on 127.0.0.1:8000 and the demo on 127.0.0.1:8001 see each other's cookies. The demo therefore uses its own names (`demo_PHPSESSID`, `demo_REMEMBERME`, `demo_consent_subject_id`, `demo_APP_LOCALE`, `demo_analytics_*`), so a secure cookie from a dev server with TLS cannot block the demo's session, cookie banner or login. Cookies set by an older demo build still carry the dev names; delete them once (see Troubleshooting).

## Local overrides

Put local changes into `.env.demo.local` (not committed), for example another password or database name (it must still end in `_demo`). Mail and Sentry are switched off in `config/packages/demo/framework.yaml` and cannot be turned on with variables.

## What the demo does not do (yet)

- Offline maps and isochrones, the hospital wizard and run tracking follow in later steps of #791.
- Storage monitoring does not categorise the demo paths.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "The demo environment refuses this database" | `DATABASE_URL` points elsewhere (env var on the machine, `.env.demo.local`) | Unset the variable or fix `.env.demo.local` |
| Config or code change has no effect, or a `TypeError` in a container class | Demo runs without debug mode and keeps its compiled container | Restart `make demo-start` (it deletes `var/cache/demo`) |
| Page without styles | Assets not compiled | `make demo-start` compiles them; run `importmap:install` while online |
| Imports stay "pending" | No worker | `make demo-consume` |
| Cookie banner does not close, login fails with a CSRF or cookie consent error | Old cookies for 127.0.0.1 (from dev with TLS or an older demo build) | Delete the cookies for 127.0.0.1 in the browser, or open the demo as http://localhost:8001 |
