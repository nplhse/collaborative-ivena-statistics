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
- Breached-password lookup (`framework.validation.not_compromised_password`): off, as in `test`; the demo
  wizard creates participant accounts offline.
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

### Generation runs (#791 step 3)

Generating a hospital for the presentation must not wait for the projections, and the participant should
learn when the data is complete. A **generation run** tracks this across both stages. It is a domain record
of the generator, like `import_run` for imports (ADR 016), not a `job_run` row: `job_run` is a best-effort
technical record with a retention (ADR 018/019), a run is the summary the wizard and #877 report from.

- `generation_run`: scope (`one`, `selected`, `all`), seed, period, settings (JSON), trigger, initiator,
  `notify`, status `pending` → `running` → `completed` | `failed` (also partial runs), stage `generating` →
  `projecting` → `finishing` → `done`, reason code. `generation_run_hospital`: status `pending` →
  `generating` → `generated` → `projecting` → `completed` | `failed`, counts (months, allocations,
  closures, MCI cases), the import ids, projection attempts, reason code and the error class.
- **Synchronous stage:** the caller generates and reports to `GenerationRunTracker`
  (`start`, `hospitalStarted`, `monthGenerated`, `hospitalFailed`, `generationFinished`).
  `generationFinished()` dispatches one `ProjectGeneratedHospital` per hospital with data and returns.
- **Asynchronous stage per hospital** (`async_priority_low`): the allocation projection of each of its
  imports, then the closure analysis and closure volume of that hospital (`SyntheticDataPipeline::projectHospital`).
  Both run in one handler, so the closure volume always reads finished allocation projections, and
  only the generated hospital is rebuilt. Generated imports do not raise `ImportCompleted`, so the coalesced
  `closure_rebuild_request` path of uploads is not involved.
- **Final stage** once no hospital is open (`FinishGenerationRun`): materialized views and the KPIs of the
  upload days, once for the run, then the terminal transition.
- **Retries and concurrency:** each step is a conditional update. A lock per run and hospital keeps a second
  delivery from projecting the same hospital in parallel; a delivery for a completed or failed hospital is
  a no-op; the last hospital starts the final stage through one conditional update (`claimFinishing`), and
  the terminal transition is one conditional update whose winner alone notifies. Rebuilding replaces rows,
  so a Messenger retry gives the same result. When Messenger gives up, the hospital (or the final stage) fails
  with `retries_exhausted`; `app:generate:run:retry` projects failed hospitals again without writing data
  again.
- **Notification:** `generation.completed` (in-app and toast, hospital-scoped) per completed hospital to its
  owner and to the users the run names in its setting `notify_user_ids` (the demo participant, step 4), each
  once, only for runs with `notify` and only after the run's terminal transition, i.e. after views and KPIs.
  Failed hospitals get none; the other hospitals of a partial run are still announced. See ADR 016.
- **Inspection:** `app:generate:run:list`, `app:generate:run:show [<run>]` (settings, hospitals, imports,
  failures, stale runs and the `job_run` rows of the background stages). Both messages are recorded jobs
  (`generation_project_hospital`, `generation_finish_run`), so the admin list of job runs shows them with
  `generation_run_id` in their metrics; there is no admin page of its own.
- Everything lives in `DataFixtures/Generator` and exists only where the generator services are registered
  (dev, test, demo). The migration creates both tables in every environment; in production they stay empty
  and no service, schedule or route there refers to them. Without a run, generation keeps the synchronous
  `SyntheticDataPipeline::afterImports()` (fixture load, `make reset`, `app:generate:imports` without `--async`).

### Dataset engine (#877)

The demo wizard (#791 step 4) and `app:generate:dataset` generate through one service,
`DatasetGenerator` (ADR 022, amendment #877), with two entry points that differ only in how the hospital
comes to exist: a new synthetic hospital is created by the wizard first, an existing hospital of the demo
database (prepared name, location, isochrones) is used as it is. The engine never writes hospital master data,
never creates users or access grants, and never touches real imports.

```php
$plan = $datasets->prepare(new DatasetRequest(
    scope: GenerationRunScope::One,                      // One, Selected or AllParticipating
    hospitalIds: [$hospitalId],
    period: DatasetPeriod::demoDefault($clock, 12),      // up to the month before last
    existing: ExistingData::Refuse,                      // Refuse (default), Fill or Replace
    selections: [$hospitalId => new ProfileSelection(annualVolume: 24000, closureShare: 0.05)],
    trigger: GenerationRunTrigger::Wizard,
    notify: true,
    initiatedById: $userId,
));                                                      // read-only: per hospital ready or blocked, months, planned allocations
$runId = $datasets->start($plan->withoutBlocked());      // records the run with every setting
$result = $datasets->generate($runId);                   // synchronous generation, then the asynchronous stage
$tracker->get($runId);                                   // progress and final state for the wizard
```

- **Existing hospital:** the selection carries only what the wizard asks for (annual volume, closure share);
  tier, size, beds and departments come from the hospital. **New hospital:** the wizard may also set tier,
  size, beds or a configuration.
- **Prerequisites the wizard sets up before `prepare()`:** participation, an owner with `ROLE_PARTICIPANT`
  (the demo participant), dispatch area and state. `prepare()` blocks a hospital without them and names what
  is missing.
- **Period:** `DatasetPeriod::demoDefault()` ends with the month before last, so the last complete month stays
  for the CSV upload below.
- `generate()` takes as long as the months take. It can run in a console process or a message handler; the
  wizard has the run id from `start()` and polls the run. Hospitals that fail are rolled back and can be
  generated again with `DatasetGenerator::retryRequest($runId)` (`app:generate:dataset --retry`).
- **Real identity, synthetic records:** an existing hospital keeps its real name and location while all its
  operational data is synthetic and marked as such (marker paths). The wizard says so before it starts, and
  the demo banner says that all data is synthetic; the pages themselves do not tell the two apart.

### Demo hospital wizard (#791 step 4)

`app:demo:hospital:create` (dev, test, demo) is the presenter's entry point on top of the engine. It lives in
`DataFixtures/Generator` like the engine, so it does not exist in production.

- **Master data apart from data generation:** `DemoHospitalSetup` creates the participant account and a new
  hospital, or gives an existing hospital only what generation needs and lacks: participation (since the first
  generated month), the participant as owner when there is none, otherwise an access grant with all
  permissions; ownership is never transferred. The participant is passed to the run as an additional recipient
  (`DatasetRequest::$notifyUserIds`), so the presenter gets the completion notification also when an existing
  owner keeps the hospital. Name, tier, size, beds, address, coordinates and dispatch area of an existing hospital are never
  written; the engine itself still writes no master data. A new hospital takes its size from the beds with the bed bands
  of the synthetic catalog (`BED_BANDS` of the Faker hospital provider: Small 80–299, Medium 300–599, Large
  600–1300; fewer beds count as Small, more as Large), so wizard and catalog hospitals agree; its state comes
  from the dispatch area, and stores tier, size and beds on the hospital, so the selection passed to the engine carries
  only the annual volume and the closure share for both entry points.
- **Participant:** an active, verified account `demo` (then `demo2`, `demo3`, …) with the e-mail
  `<login>@demo.invalid` (`.invalid` cannot receive mail), `ROLE_PARTICIPANT` and `ROLE_CLOSURE_BETA`, created
  through `AdministrativeUserService` like the CLI user commands, so the regular password rules apply. Every demo
  participant has the known password `Ivena!1234`: a presenter types it in front of an audience, and the demo
  runs only on the presenter's machine with synthetic data. A random password per account was tried first and
  was hard to use in a presentation. The run records the account as initiator.
- **All or nothing before generation:** account, hospital and `DatasetGenerator::prepare()` run in one
  transaction; a plan that cannot run (real imports, generated data in mode Refuse, missing master data) rolls
  everything back. `--existing=replace` is the explicit way to generate a hospital anew.
- **Location:** after the commit the wizard checks the geodata of the hospital. Coordinates with an isochrone
  file are kept. Otherwise it applies the prepared location through `PreparedLocationApplier` (never forced over
  other coordinates), then, with `OPENROUTESERVICE_API_KEY` set, geocodes and fetches isochrones through the
  regular `HospitalGeocodeService` and `HospitalIsochroneFetchService` for this one hospital. Nothing of this
  stops the wizard; the dataset does not depend on coordinates, so missing geodata is shown as information.
- **Environment:** the command also exists in dev and test. The summary names the environment and the database,
  and outside `demo` it warns, because the development database holds the same synthetic hospitals.
- **Closure rebuilds during generation:** deleting the replaced months of a hospital requests closure rebuilds,
  and the worker took them at once and deadlocked with the generator on `closure_analysis_source`. The engine
  now runs each hospital inside `ClosureVolumeProjectionRebuildInterface::whileRebuildsPaused()`, which holds the
  closure rebuild lock: other processes wait, a rebuild requested in the generating process stays open, and the
  background stage of the run takes the open requests of the hospital. Retrying on deadlocks was rejected as it
  hides the race; skipping the requests on deletion would need a flag through the import deletion.
- **Failure output:** a hospital that fails during generation is rolled back and gets no background stage; the
  wizard and `app:generate:run:show` show the first line of the error (`-v` the whole text) and one block per
  hospital instead of a wide table.
- **Progress:** the wizard implements `DatasetProgress` and shows one console section per stage (participant,
  hospital, location, monthly imports, allocations, closures, projections) with pending, running, completed,
  failed states and the counts of `GeneratedMonth`; without a terminal it writes one line per change. It
  returns after the synchronous stage; the projections continue in the worker and `app:generate:run:show`
  follows them. No TUI dependency beyond Symfony Console.
- **Offline:** the breached-password check of the validator (`not_compromised_password`) calls an online service;
  it is off in `demo` as in `test`, so the wizard works without network.

### Demo CSV upload (#791 step 5)

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
- The demo needs a Messenger worker (`messenger:consume async_priority_low`) for generation runs; without
  one a run stays in the stage `projecting`, and `app:generate:run:show` marks it stale after 30 minutes.

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
