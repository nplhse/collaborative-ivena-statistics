# Demo environment

A local, offline demo of the platform with synthetic data (issue #791, [ADR 023](../02-architecture/decisions/023-demo-environment.md)). It runs next to the development environment and never touches its database. The same environment also serves as an internal staging installation on a server of its own: see [Staging on a server](#staging-on-a-server).

| | Development | Demo |
|---|---|---|
| `APP_ENV` | `dev` | `demo` |
| Database | `.env.local` (often `app`) | `app_demo` from `.env.demo` |
| URL | http://127.0.0.1:8000 | http://127.0.0.1:8001 |
| Imports, media files | `var/imports`, `var/media` | `var/demo/imports`, `var/demo/media` |
| Cookies | `PHPSESSID`, `consent_subject_id`, … | `demo_PHPSESSID`, `demo_consent_subject_id`, … |
| Debug mode, profiler | on | off |
| Mail | Mailpit (`smtp://127.0.0.1:1025`) | Mailpit (`smtp://127.0.0.1:1025`) or none (`DEMO_MAIL=off`), never a real server ([Preview demo emails](#preview-demo-emails)) |
| Sentry, OpenRouteService | configurable | off |

## Prerequisites

- A working development checkout with **dev dependencies** (`composer install`, not `--no-dev`): the demo loads its data with the fixture code.
- The local PostgreSQL server from `compose.yaml` (or another one on this machine). The demo database must be local and its name must end in `_demo`; otherwise every request and command stops with "The demo environment refuses this database".
- Importmap assets (`php bin/console importmap:install`) while online.

## Install the demo data

```bash
make demo-install
```

This drops and recreates `app_demo`, runs the migrations, empties the demo storage, loads the dev fixtures with the `large` preset (all 20 participating hospitals of the synthetic catalog, 24 months, flu wave, MCI and icy road scenarios), rebuilds the closure volume projection and refreshes the materialized views. Expect about 5 minutes; install before the presentation.

- The load and the rebuilds run with `XDEBUG_MODE=off`, as `make reset` does ([../03-development/fixtures.md](../03-development/fixtures.md)); `XDEBUG_MODE=debug make demo-install` keeps Xdebug on.
- Quicker install: `make demo-install DEMO_PRESET=realistic` (15 hospitals, 12 months, about 2 minutes). Presets: [../03-development/fixtures.md](../03-development/fixtures.md).
- When `app_demo` already holds an installation, the target stops; `DEMO_CONFIRM=yes` replaces it.
- Users and passwords are the ones of the dev fixtures (for example `foo`).

## Remove the demo

```bash
make demo-uninstall DEMO_CONFIRM=yes
```

This drops `app_demo` and deletes everything the demo keeps on the disk: `var/demo` (imports, media,
geodata), the old upload directory `public/uploads/demo`, `var/cache/demo` and `var/log/demo.log`.
Use it when the demo is no longer needed on this machine; its files are kept apart from the development
environment (ADR 023), so nothing else cleans them up. Without `DEMO_CONFIRM=yes` the target stops
before it changes anything. The development database and `var/media` stay untouched.

## Start the demo

Two terminals:

```bash
make demo-start    # fresh cache, asset-map:compile, built-in PHP server on http://127.0.0.1:8001
```

```bash
make demo-consume  # Messenger worker: imports, projections, scheduler
```

`DEMO_PORT=8002 make demo-start` uses another port. The page title starts with "Demo" and a banner says that all data is synthetic, in the application and in the admin area.

The installed data is synthetic. A real export loaded with the import option **Override target hospital** (available in the demo, #1352) is the exception: it stays real operational data, is marked on its import page, and the upload form warns about it ([ADR 023](../02-architecture/decisions/023-demo-environment.md#real-exports-through-the-target-hospital-override)).

The demo targets call `APP_ENV=demo php bin/console`, not `symfony console`: the Symfony CLI can export `DATABASE_URL` of a Docker database, which would override `.env.demo` (the guard would then stop the command).

## Create the presented hospital

The wizard creates the hospital of the presentation and its synthetic dataset while the audience
watches. Start it once the demo runs (`make demo-start`, `make demo-consume`):

```bash
APP_ENV=demo php bin/console app:demo:hospital:create
```

Without `APP_ENV=demo` it runs in the development environment and writes into the development database,
which holds the same synthetic hospitals; the summary names environment and database and warns outside
the demo.

It asks for:

- **Which hospital:** a new synthetic hospital, or an existing hospital of the demo database (for
  example one whose real name and location you prepared). An existing hospital keeps its master data
  (name, tier, size, beds, address, coordinates, dispatch area); only the simulation is asked for.
- **New hospital:** name, emergency care tier, beds (the size follows from the bed bands of the synthetic
  catalog: Small 80–299, Medium 300–599, Large 600–1300; the summary names them), dispatch area (the federal state follows), location: a prepared location (offline,
  with isochrones), coordinates, or neither. `--location-type=urban|rural` changes the setting (default
  mixed).
- **Existing hospital without geodata** (coordinates and isochrones): a prepared location, or neither.
- **Simulation:** expected EMS allocations per year (default about 40 per bed), period of 6, 12 or 24
  months ending with the month before last, closed department-hours in % (default 5).

After a summary and a confirmation it:

1. creates an active synthetic participant account with `ROLE_PARTICIPANT`:
   login `demo` (later ones `demo2`, `demo3`, …), e-mail `<login>@demo.invalid`, password `Ivena!1234`
   for every demo participant, so it is easy to type in front of an audience;
2. creates the hospital (owner: the participant, participating from the first month), or gives an existing
   hospital what it lacks: participation, and the participant as owner when it has none, otherwise a grant
   with all permissions (the existing owner stays; the participant is notified as well);
3. checks the geodata for the maps: a hospital with coordinates and isochrones keeps them; otherwise it
   applies the prepared location (offline, never over other coordinates), and then, when
   `OPENROUTESERVICE_API_KEY` is set in `.env.demo.local`, geocodes the address and fetches the
   isochrones online. Missing geodata only shows as information: the dataset does not depend on it,
   the maps lack the pin or the isochrones;
4. generates one allocation and one closure import per month as a generation run, with live progress
   per stage (participant, hospital, location, monthly imports, allocations, closures, projections);
5. hands the projections to the worker and prints the hospital, the login, the dataset and the run id;
6. asks whether to follow the background stage (`--watch` without asking). The overview redraws itself
   every 2 seconds until the run is done: hospitals done and in progress, the step of each hospital in
   progress (for example "allocation projection 7/13", then "closure analysis and volume"), the final
   stage (materialized views, daily KPIs), whether a worker takes the work, and the first line of an
   error. When messages wait for over a minute and none is being handled, it says so and points to
   `make demo-consume`. Ctrl+C ends only the view; the worker goes on.

If the dataset cannot be generated (for example the hospital already has generated data in the period,
or real uploads), nothing is written: account and hospital are rolled back. `--existing=replace`
generates an existing hospital anew; real imports are never touched.

While the wizard (or `app:generate:dataset`) deletes and writes the months of a hospital, closure
rebuilds wait: the worker would otherwise rebuild the closure analysis of the same hospital at the
same time and both would deadlock. The rebuilds follow in the background stage of the run.

If the hospital fails during generation, its new months are rolled back and nothing goes to the
worker, so the run has no background jobs. The account and the hospital setup stay; months that
`--existing=replace` had already deleted stay deleted. The wizard prints the first line of the error
and the retry command (`app:generate:dataset --retry=<run id>`, which generates every missing month).

Every answer can be passed as an option, for example to prepare a presentation as a script:

```bash
APP_ENV=demo php bin/console app:demo:hospital:create --name="Klinikum Musterstadt" --tier=full --beds=650 \
    --dispatch-area="Frankfurt" --location=klinikum-musterstadt --annual-volume=26000 --months=12 --closure-share=0.05 --yes
APP_ENV=demo php bin/console app:demo:hospital:create --hospital=<public id> --annual-volume=18000 --months=12 --closure-share=0.03 --yes
```

### Presenter walkthrough

1. Before the presentation, online: `make demo-install`, prepare the location of the prospective hospital
   (`app:geo:prepare-location <slug> …`, see Geography), optionally try the wizard once. With a network
   during the presentation and a key in `.env.demo.local`, the wizard fetches missing geodata itself.
2. Start the demo: `make demo-start` and `make demo-consume` in two terminals, open http://127.0.0.1:8001.
3. Run the wizard in a third terminal, pick the prepared location, confirm. The stages fill up in place,
   month by month, with the real counts of allocations and closures.
4. Answer yes to follow the background stage, or switch to the browser right away: log in as `demo`
   with `Ivena!1234` (the wizard prints the login). The
   statistics fill as the worker finishes; an in-app notification arrives when everything is done
   (to the new account, and for an existing hospital also to its owner).
   `app:generate:run:show <run id>` shows the background stage, also later or after a failure: one block
   per hospital, errors shortened to their first line (`-v` shows them in full, and the run settings).
5. Show the upload: `app:demo:csv --hospital=<public id>` (printed by the wizard) writes the last complete
   month, which the wizard left free; upload it as described below.

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
(the wizard's account has it as the owner):

1. Import page → choose the hospital → upload the allocation file. `make demo-consume` must run.
2. When it is processed, upload the closure file as a closure import.

The allocation file contains the rows the generator would write for that month, including infants
with age 0. The import accepts ages 1 to 99, so these few rows show up as rejects (see the reject
analysis of the import).

The files say they are synthetic: the file name prefix, `SYNTHETIC DEMO DATA (#791)` in the free text
of every allocation (stored as its notes) and in the remark of every closure, and case numbers
starting with `SYNTH-`.

**Intended flow:** the hospital wizard (`app:demo:hospital:create`, above) generates the presented hospital up to the end
of the month before last and leaves the last complete month empty, so the upload fills a whole month
right before today. Hospitals of the baseline installation have data up
to yesterday, so the command finds no free complete month for them.

## Cookies

Browsers keep cookies per host, not per port: dev on 127.0.0.1:8000 and the demo on 127.0.0.1:8001 see each other's cookies. The demo therefore uses its own names (`demo_PHPSESSID`, `demo_REMEMBERME`, `demo_consent_subject_id`, `demo_APP_LOCALE`, `demo_analytics_*`), so a secure cookie from a dev server with TLS cannot block the demo's session, cookie banner or login. Cookies set by an older demo build still carry the dev names; delete them once (see Troubleshooting).

## Local overrides

Put local changes into `.env.demo.local` (not committed), for example another password or database name (it must still end in `_demo`). Sentry is switched off in `config/packages/demo/framework.yaml` and cannot be turned on with variables. Mail goes to the local Mailpit unless `DEMO_MAIL=off` discards it; no other value is accepted, and `MAILER_DSN` has no effect in the demo (see below).

## Preview demo emails

Like dev, the demo sends all mail (monthly reminders, notification emails, the reminder buttons in
the admin area) to Mailpit, the local mail catcher of `compose.override.yaml`. The address
`smtp://127.0.0.1:1025` is fixed in `config/packages/demo/framework.yaml`, so nothing leaves the
machine. `DEMO_MAIL` (`.env.demo`) picks between two fixed transports and nothing else:
`mailpit` (default) and `off`, which discards every mail, for a demo without Mailpit such as
[staging](#staging-on-a-server).

1. Start Mailpit (SMTP on port 1025, web UI on port 8025):

   ```bash
   docker compose up -d mailer
   ```

2. Find the id of a participating demo hospital whose owner has a verified email address:

   ```bash
   APP_ENV=demo php bin/console dbal:run-sql "SELECT h.id, h.name, u.email FROM hospital h JOIN \"user\" u ON u.id = h.owner_id WHERE h.is_participating AND u.is_verified ORDER BY h.name"
   ```

   The id is also in the URL of the hospital in the admin area (EasyAdmin).

3. Send the monthly reminder of that hospital:

   ```bash
   make demo-reminder HOSPITAL=<id>
   ```

   This runs `APP_ENV=demo php bin/console app:reminder:preview --hospital-id=<id> --send`. The
   reminder follows the owner's notification settings; an owner who switched it off gets nothing,
   and the command says so.

4. Open http://127.0.0.1:8025. The command prints this URL after sending.

Without Mailpit, `app:reminder:preview --send` stops with "Mailpit is not reachable", and mail sent
by the application fails as in dev. The preview without `--send` (HTML to the terminal or `--output`)
needs no Mailpit.

## Geography

The demo keeps its isochrone files and prepared locations under `var/demo/geo/`. To show maps around a
real place, prepare the location while online (key in `.env.demo.local`) and apply it to a demo
hospital later, also offline:

```bash
APP_ENV=demo php bin/console app:geo:prepare-location <slug> --street="…" --postal-code=… --city=… --apply
APP_ENV=demo php bin/console app:geo:apply-prepared-location <slug> --hospital-id=<id> --apply
```

Maps use the OpenStreetMap tiles like dev. Without network they drop the basemap by themselves and
still show districts, pins and isochrones; `MAP_TILE_URL=` (empty) in `.env.demo.local` switches the
basemap off for good. Details: [hospital-geodata.md](hospital-geodata.md#prepared-locations-offline-use).

## Follow a generation run

Synthetic data generated in the demo (by the hospital wizard or `app:generate:dataset`) is tracked as a generation run: the data is written synchronously, the projections follow in the worker (`make demo-consume`), and the participant gets an in-app notification once everything is done. Check the progress, including failed or open hospitals:

```bash
APP_ENV=demo php bin/console app:generate:dataset --hospital=<public id> --annual-volume=24000 --closure-share=0.05 --dry-run
APP_ENV=demo php bin/console app:generate:dataset --hospital=<public id> --annual-volume=24000 --closure-share=0.05 --notify
APP_ENV=demo php bin/console app:generate:run:list
APP_ENV=demo php bin/console app:generate:run:show
```

Without `--from`/`--to` the dataset ends with the month before last, so the last complete month is left for the CSV upload below. A hospital that already has generated data is refused unless `--existing=fill` or `--existing=replace` is given; real uploads are never touched. Details: [synthetic-data-generator.md](../03-development/synthetic-data-generator.md#datasets-for-several-hospitals-and-months).

See [ADR 023](../02-architecture/decisions/023-demo-environment.md#generation-runs-791-step-3).

## Staging on a server

The demo environment also runs as an internal staging installation of the `develop` branch on an
Uberspace account of its own, next to and apart from production. It is the demo as described above
with three differences: no mail (`DEMO_MAIL=off`), the `realistic` preset, and a nightly reset to a
snapshot. Access protection (HTTP Basic Auth) is set up on the server, not in the repository.

### Deployer host

Deployer deploys staging with the production recipe; the host entry in `hosts.yaml` switches it to
the demo environment ([hosts.yaml.example](../../hosts.yaml.example)):

- `branch: develop`, `keep_releases: 1` (one `vendor` with dev packages on the disk is enough)
- `env` with `APP_ENV: demo`: asset compile, cache warmup and migrations run in the demo environment
- `composer_options` without `--no-dev`: the demo loads its data with the fixture code (ADR 023)
- `shared_files` and `shared_dirs` of the demo, so `.env.demo.local`, the demo files and the log
  survive a deploy

A deploy runs the migrations of `develop` against the demo data, as production will later.

To give up a staging installation, remove the cron job of the nightly reset, the snapshot
(`~/demo-snapshot`), the deploy path and the `_demo` database on the server; the demo files live
inside the deploy path, so nothing is left behind.

### One-time setup on the server

1. PostgreSQL as for production ([deployment.md](deployment.md#prerequisites)) with a database whose
   name ends in `_demo`, reached over `127.0.0.1` or `localhost` (`DemoEnvironmentGuard` refuses
   other hosts). The database user must be allowed to drop and create that database
   (`make demo-install`).
2. `<deploy_path>/shared/.env.local` with `APP_ENV=demo` only.
3. `<deploy_path>/shared/.env.demo.local` with `APP_SECRET`, `DATABASE_URL`, `APP_URL`,
   `SYMFONY_TRUSTED_HOSTS` and `DEMO_MAIL=off`. It is loaded after `.env.demo`; values in `.env.local`
   would be overridden by `.env.demo`.
4. The Messenger worker as for production ([messenger-workers.md](messenger-workers.md)); the unit
   reads `APP_ENV=demo` from `.env.local`.
5. First deploy: `vendor/bin/dep deploy staging`.
6. Demo data and first snapshot: `<deploy_path>/current/bin/ops/demo-reset.sh`.
7. Nightly reset as a cron job, for example at 04:10:

   ```
   10 4 * * * $HOME/www/current/bin/ops/demo-reset.sh >> $HOME/logs/demo-reset.log 2>&1
   ```

### Access protection

The demo accounts and their passwords are known, so nobody may reach the application without the
Basic Auth credentials, not even the login page. Create the password file outside the web root
with a long random password (`openssl rand -base64 24`, `htpasswd -cB ~/staging.htpasswd <name>`)
and put the `AuthType Basic` block into a `.htaccess` above the document root (`<deploy_path>/`,
next to the `html` symlink); `public/.htaccess` comes from the repository and is replaced by every
deploy. Check after the setup and after deploys that the protection holds; each request without
credentials must give `401`, and `http://` must redirect to `https://`:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://<host>/
curl -s -o /dev/null -w '%{http_code}\n' https://<host>/login
curl -s -o /dev/null -w '%{http_code}\n' https://<host>/health
curl -sI http://<host>/ | head -1
```

### Nightly reset

`bin/ops/demo-reset.sh` stops the worker, puts the demo back to its snapshot with
`app:backup:restore` (database and the demo's file components `var/demo/imports`, `var/demo/media`,
`var/demo/geo/hospital-isochrones` and `var/reference`), empties `var/log/demo.log`, clears the cache
pools and starts the worker again. Messages visitors left in the queues are deleted before the
restore, which otherwise refuses to run while work is waiting. On its first run after a deploy (another release than the snapshot's)
it rebuilds the data with `make demo-install` and takes a new snapshot with `app:backup:create` instead, so new migrations
and fixture changes arrive in the data by the next morning; run it right after the deploy to have
them at once. `DEMO_REBUILD=yes` forces a rebuild. The snapshot lives in `~/demo-snapshot/backup`
(`DEMO_SNAPSHOT_DIR`; older snapshots with `database.dump` and `files.tar.gz` can be deleted); the dates of the data stay those of the last rebuild. The script refuses any
database whose name does not end in `_demo`.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| "The demo environment refuses this database" | `DATABASE_URL` points elsewhere (env var on the machine, `.env.demo.local`) | Unset the variable or fix `.env.demo.local` |
| Config or code change has no effect, or a `TypeError` in a container class | Demo runs without debug mode and keeps its compiled container | Restart `make demo-start` (it deletes `var/cache/demo`) |
| Page without styles | Assets not compiled | `make demo-start` compiles them; run `importmap:install` while online |
| Imports stay "pending" | No worker | `make demo-consume` |
| "Mailpit is not reachable", or mail errors in the demo | Mailpit not running | `docker compose up -d mailer` ([Preview demo emails](#preview-demo-emails)), or `DEMO_MAIL=off` without Mailpit |
| "Invalid value for "DEMO_MAIL"" | `DEMO_MAIL` is neither `mailpit` nor `off` | Fix `.env.demo.local` |
| A generation run stays in `projecting` (`app:generate:run:show` says stale) | No worker, or a hospital failed | `make demo-consume`; then `APP_ENV=demo php bin/console app:generate:run:retry <run>` |
| Cookie banner does not close, login fails with a CSRF or cookie consent error | Old cookies for 127.0.0.1 (from dev with TLS or an older demo build) | Delete the cookies for 127.0.0.1 in the browser, or open the demo as http://localhost:8001 |
