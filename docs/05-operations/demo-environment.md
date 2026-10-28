# Demo environment

A local, offline demo of the platform with synthetic data only (issue #791, [ADR 023](../02-architecture/decisions/023-demo-environment.md)). It runs next to the development environment and never touches its database.

| | Development | Demo |
|---|---|---|
| `APP_ENV` | `dev` | `demo` |
| Database | `.env.local` (often `app`) | `app_demo` from `.env.demo` |
| URL | http://127.0.0.1:8000 | http://127.0.0.1:8001 |
| Imports, uploads | `var/imports`, `public/uploads/media` | `var/demo/imports`, `public/uploads/demo/media` |
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

## Local overrides

Put local changes into `.env.demo.local` (not committed), for example another password or database name (it must still end in `_demo`). Mail and Sentry are switched off in `config/packages/demo/framework.yaml` and cannot be turned on with variables.

## What the demo does not do (yet)

- Offline maps and isochrones, the hospital wizard, run tracking and the demo CSV upload follow in later steps of #791.
- Storage monitoring does not categorise the demo paths.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "The demo environment refuses this database" | `DATABASE_URL` points elsewhere (env var on the machine, `.env.demo.local`) | Unset the variable or fix `.env.demo.local` |
| Config or code change has no effect, or a `TypeError` in a container class | Demo runs without debug mode and keeps its compiled container | Restart `make demo-start` (it deletes `var/cache/demo`) |
| Page without styles | Assets not compiled | `make demo-start` compiles them; run `importmap:install` while online |
| Imports stay "pending" | No worker | `make demo-consume` |
