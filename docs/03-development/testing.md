# Testing

## Test types

- **Unit / integration / functional** via PHPUnit under `tests/`
- **Fixture tests** under `tests/DataFixtures/` (reference YAML, pattern validation)
- **Import integration tests** for CSV processing, reject behaviour, and upload validation (Excel / unsupported extensions)
- **Command tests** for requeue and exit-code behavior
- **JavaScript tests** with Node's built-in runner: `npm run test:js`, also part of `make lint-js`. They cover the encounter data preparation tool (`tests/Encounter/DataPrep/*.test.js`, see [data-preparation-tool.md](../04-features/tools/data-preparation-tool.md#tests)), admin scripts (`tests/Admin/JS/*.test.js`) and the sidebar search filter (`tests/Shared/JavaScript/*.test.js`). A new directory has to be added to the `test:js`, `lint` and `format` scripts in `package.json`.

## Test levels and conventions

The suite is a pyramid: many simple unit tests, focused integration tests that prove components work together, and few functional tests that go through the web interface from end to end. Functional tests are the slowest and most expensive to keep, so a behaviour that can be checked lower down is checked there (#1348).

### Which level for what

Pick the lowest level that can test the behaviour, in this order:

1. **Unit test** with real value objects and entities, and stubs or fakes for collaborators.
2. **Unit test with an in-memory fake** for persistence, where application logic sits around simple persistence (see [Fakes and the database](#fakes-and-the-database)).
3. **Integration test with the database**, only for what needs it: queries and their SQL semantics, materialized views and the projection, migrations, transactions and locking, Doctrine mapping. Mocks and spies for the parts that are not under test are fine.
4. **Functional test** (HTTP or console) for the entry points and main flows: page wiring, access rules, redirects and the assumptions between layers. Functional tests do not re-check every calculated value, label or translation; that belongs to the levels below.

| What | Level |
|---|---|
| Calculations, mappers, presenters, policies, validators | Unit, with the data passed in instead of a repository |
| Application services with logic around simple find/save/delete | Unit with an in-memory fake and a contract test |
| Query classes, repository read methods, MVs, projection, non-trivial SQL | Integration against PostgreSQL with a small, hand-checkable fixture |
| Controllers, commands, access rules, main flows | Functional, one test per behaviour, not per value |

Each behaviour is tested on one level. When a calculation is covered at query or service level, the HTTP test only checks that the page shows it. Tests under `tests/*/Unit/` boot no kernel and touch no database; a test that needs either belongs under `Integration/`.

### Structure

- **One behaviour per test**, readable without knowing the whole fixture set. Few helpers, no logic (loops, conditions) in the test itself; repeat cases go into a `#[DataProvider]`.
- **Arrange, Act, Assert**: three blocks separated by blank lines, one act per test. Comments only where the blocks are not obvious.
- **Expected values can be followed by hand**: small fixtures with explicit numbers, not snapshots of the current output (see [AGENTS.md](../../AGENTS.md#changes-to-statistics) for statistics).
- **Standards first**: PHPUnit and Symfony test tooling as documented (`TestCase`, `KernelTestCase`, `WebTestCase`, PHPUnit attributes), `zenstruck/foundry` factories for entities and `zenstruck/browser` for HTTP flows where it makes the test clearer. No project-specific test frameworks beyond thin base classes such as `DatabaseKernelTestCase`.
- **The regular test kernel.** A test that needs another kernel (another environment, bundles left out) makes CI compile an extra container. Avoid it; where it is needed, register the kernel in `bin/warmup-test-kernels` (see [CI reference](#ci-reference)).
- **No dependence on the debug kernel.** The database job runs with `APP_DEBUG=0`, so a test must pass without debug: `debug:config` and other container debug commands compile the container again, and the profiler's `db` collector works only because `when@test` enables Doctrine profiling.

### Time budget

Guide values per test method, measured locally as described below:

| Level | Budget |
|---|---|
| Unit | well under 100 ms |
| Integration | under 1 s |
| Functional | under 2 s |

A test above its budget gets a look before it is merged. A slow test can point at slow application code: profile it before deciding whether the test or the code has to change.

### How to measure

CI runners vary by ±20–25 % in every job, and a single test by up to a factor of four, so single CI runs say little.

- **Per change:** run the touched test classes locally, serially, before and after, with `XDEBUG_MODE=off APP_DEBUG=0` (after `APP_DEBUG=0 bin/console cache:clear --env=test`).
- **For the suite:** use the CI time only as the median over at least five runs on `develop`, together with the test count of the day.
- Find slow tests with `php bin/report-slowest-tests 25` after a run, or from the JUnit file of a CI run. Profile a single test with `XDEBUG_MODE=profile`.
- Xdebug in `develop` mode skews timings and, without debug, breaks EasyAdmin association forms.

## Run locally

```bash
make test                              # full suite
make test SUITE=unit                   # unit layer only
make test SUITE=functional-http        # functional without Zenstruck browser
make test SUITE=browser                # browser tests (starts webserver)
make test PATH_ARG=tests/Statistics    # single bounded context
make test ARGS="--filter FooTest"      # passthrough to PHPUnit
make testdox SUITE=unit
make coverage SUITE=integration       # clover + HTML report in var/coverage/report + summary
make test-unit                         # unit suite via ParaTest, as the CI unit job
make test-database                     # all other suites via ParaTest, as the CI database job
```

`make test` runs PHPUnit serially and stops at the first failure. `make test-unit` and `make test-database` run the same groups as the two CI jobs in parallel (`PROCESSES=4` by default) and do not stop early. `COVERAGE=1` writes the clover report to `var/coverage/logs/clover-unit.xml` / `clover-integration.xml` (needs PCOV or Xdebug), `JUNIT=1` writes `junit-unit.xml` / `junit-integration.xml`. Each ParaTest worker uses its own test database (`dbname_suffix` with `TEST_TOKEN`), and each git worktree its own set of them (`TEST_DATABASE_SUFFIX`, see [Work in a git worktree](development-workflow.md#work-in-a-git-worktree)).

After a test run, slowest tests from the PHPUnit duration cache:

```bash
php bin/report-slowest-tests 10
```

## Test suites

PHPUnit suites are defined in `phpunit.dist.xml`: `all`, `unit`, `integration`, `functional`, `fixtures`, `system`.

Cross-cutting groups: `browser` (Zenstruck browser tests), `materialized-view` (statistics MV tests).

`BROWSER_ALWAYS_START_WEBSERVER=1` is set only when running browser tests (`make test SUITE=browser` or `GROUP=browser`), not globally.

When adding a new bounded context under `tests/`, register its `Unit` / `Integration` / `Functional` directories in the matching named suites in `phpunit.dist.xml`. The CI unit job only runs the `unit` suite.

`make check-phpunit-suite-dirs` (part of `make lint` and the Linting workflow) fails if a layer directory that contains `*Test.php` files is missing from its named suite, or if a suite lists a directory that does not exist.

## Test doubles

Prefer the lightest double that matches what the test asserts. PHPUnit’s `createMock()` can act as stub, spy, or mock depending on usage — choose the API that communicates intent.

| Need | Prefer | PHPUnit |
|------|--------|---------|
| Unused constructor dependency | Dummy | `createStub()` (no method setup) or a null object |
| Control collaborator **input**; assert SUT output | Stub | `createStub()` + `method()->willReturn(…)` |
| Verify a **side effect** after the act | Spy | `createMock()` + `willReturnCallback` recording into an array, then `assert*` |
| Exact call count / `never()` is the spec | Mock | `createMock()` + `expects(…)` |
| Stateful collaborator across several calls | Fake | Hand-written class under `tests/*/Doubles/` (see Import) |

Heuristics:

- Asserting on a return value or domain state → stubs for queries; real value objects / entities (do not mock them).
- Asserting that mail / messages / writes happened → spy before a strict mock.
- Persistence → a fake behind an interface we own, or an integration test with Foundry; see below.

Do not introduce Mockery or Prophecy for application tests.

### Fakes and the database

ADR [009](../02-architecture/decisions/009-ports-repositories-query-objects-and-naming.md#amendment-november-2026-1348-testing-persistence) sets the rules:

- **Repositories and query classes are tested against PostgreSQL.** Mocking them leaves the SQL untested, and an in-memory database is no substitute (materialized views, PostgreSQL-specific SQL).
- **Never mock what we do not own**: no doubles of `EntityManagerInterface`, `Connection` or other Doctrine types.
- **A fake pays off for simple access** (find, save, delete) used by application logic. It sits behind a narrow interface in the owning context's `Application/Contract/`, lives under `tests/<Context>/Doubles/`, and a **contract test** proves that the fake and the Doctrine implementation behave the same: one abstract test class with the expectations, one subclass for the fake (unit) and one for the real implementation (integration).
- Complex queries are not faked; they would have to be implemented twice. The code that uses them gets the query result as data, or stays an integration test.

Reference: the import ports in `src/Import/Application/Contracts/` (`RowReaderInterface`, `RejectWriterInterface`) with the fakes in `tests/Import/Doubles/` and `AllocationImporterTest`. These fakes predate the contract-test rule and do not have one yet.

PR checklist: see [CONTRIBUTING.md](../../CONTRIBUTING.md) — stub by default, spy for side effects.

## Fast database tests

Database tests make up most of the CI time. Keep them fast without weakening what they assert (#833).

- **Factory defaults are lazy.** In a Foundry factory's `defaults()`, wrap every relation that queries or creates rows (`::random()`, `::randomOrCreate()`, `::createOne()`) in `LazyValue::new(fn () => …)`. It then only runs when the test does not pass that value. A random condition that decides about the relation goes inside the closure too: `LazyValue::new(fn (): ?object => self::faker()->boolean(20) ? FooFactory::randomOrCreate() : null)`.
- **Many objects: one flush.** When a test creates dozens of objects in a loop or with `createMany()`, wrap it in `Zenstruck\Foundry\Persistence\flush_after(function () { … })`. Without it, every object is flushed on its own. Example: `ClosureVolumeIncrementalRebuildTest`.
- **Config read at compile time** forces extra container builds in tests. Prefer `%env()%` values that are read at runtime (example: the CSP `report-uri`, so `ContentSecurityPolicyTest` compiles one prod container for both cases).
- **Property info is cached per process.** The debug test kernel has no property info cache, so Live Components would parse the same docblocks on every render. `config/packages/property_info.yaml` (`when@test`) adds an in-memory cache that lives as long as the PHP process ([`ProcessPropertyInfoCache`](../../tests/Support/PropertyInfo/ProcessPropertyInfoCache.php)); it never outlives a run, so changed docblocks are always read fresh.

To find slow tests, run the suite and then `php bin/report-slowest-tests 25`, or read the JUnit file of a CI run. Profile a single test with `XDEBUG_MODE=profile` before changing it; the cause is not always the data setup.

## Tests with two database sessions

DAMA runs every test inside one uncommitted transaction, so a second connection sees none of its
fixtures. A test that needs real concurrency (for example two imports inserting the same encounter)
commits a minimal fixture through its own plain DBAL connection with raw SQL (no audit rows), removes
it in `tearDown()`, and sends the competing statement asynchronously with the `pgsql` extension
(`pg_send_query_params`), which CI installs. Example: `ConcurrentEncounterInsertTest`. Each ParaTest
worker has its own database, so the committed rows never reach another worker.

## Time in code and tests

Application code never reads the wall clock directly (ADR [021](../02-architecture/decisions/021-wall-clock-access.md)). Otherwise `mockTime()` cannot control it and tests depend on the real date.

| Where | Use |
|-------|-----|
| Services | Inject `Psr\Clock\ClockInterface`, call `$this->clock->now()` |
| Entities, value objects, static helpers | `Symfony\Component\Clock\Clock::get()->now()` |
| Twig templates | `clock_now()`, e.g. `clock_now()|date('Y')`, instead of `'now'|date` |
| Tests | `use ClockSensitiveTrait;` and `self::mockTime('2026-05-15 10:00:00')` |

Absolute values stay fine: `new \DateTimeImmutable('2026-05-01 00:00:00')`, `new \DateTimeImmutable('@'.$timestamp)`, `new \DateTimeImmutable($row['created_at'])`. Relative values go through the clock: `$this->clock->now()->modify('first day of this month')` instead of `new \DateTimeImmutable('first day of this month')`, `$this->clock->now()->getTimestamp()` instead of `time()`, `$this->clock->now()->format('Y')` instead of `date('Y')`.

Parsing with `createFromFormat()` always starts the format with `!`: `\DateTimeImmutable::createFromFormat('!Y-m-d', $value)`. Without it, every field the format does not mention comes from the current time, so `'Y-m-d'` returns the date with the time of day the code runs at.

PHPStan checks PHP only; in templates `'now'|date` reads the system time unnoticed.

PHPStan reports violations in `src/` with the identifiers `wallClock.dateTime`, `wallClock.function` and `wallClock.createFromFormat`; there is no baseline for them. A deliberate exception needs `@phpstan-ignore wallClock.function (reason)`.

## Static analysis and linting

```bash
make check     # every check, no tests, changes no code
make analyse   # PHPStan and Psalm only
```

Overview of checks, fixes and tests: [development-workflow.md](development-workflow.md#checks-fixes-and-tests).

## Translations (i18n)

Extract and lint (see [translations.md](translations.md) and [../06-reference/glossary-i18n-de.md](../06-reference/glossary-i18n-de.md)):

```bash
make trans-all      # extract EN for all app domains
make trans-de-all   # scaffold missing DE units
make trans-de       # scaffold missing DE units (messages only)
make lint-trans     # lint EN + DE catalogues
make lint-trans-de  # lint DE catalogue only
```

## Relevant configuration

- PHPUnit: `phpunit.dist.xml`
- Test bootstrap: `tests/bootstrap.php`
- PHPStan: `phpstan.dist.neon`, project rules in `tests/PHPStan/Rules/`

## Code coverage

Coverage measures `src/` and `plugins/ed-visit/src`, including `src/Admin` with its EasyAdmin CRUD controllers (#1100). The only exclusion is `src/DataFixtures`: fixture loaders are run by `make fixtures`, not by PHPUnit, so they would only add uncovered lines. It is excluded in two places, `phpunit.dist.xml` (`<source><exclude>`) and `codecov.yml` (`ignore`); keep both in sync when the list changes.

## CI reference

- **Unit job** (parallel, no database, PCOV): `make test-unit COVERAGE=1 JUNIT=1`, clover artifact `coverage-unit`
- **Database job** (parallel, PostgreSQL, PCOV): `make test-database COVERAGE=1 JUNIT=1`, suites `integration,functional,fixtures,system`, clover artifact `coverage-integration`. The list lives in `DATABASE_SUITES` in the `Makefile`; use it, not `all` with `--exclude-testsuite unit`: `all` contains the unit folders too, so they would run twice.
- **Extra test containers are compiled before the tests** (#1343). Some tests boot another kernel than the regular test kernel: `KernelWithoutEdVisit`, `KernelWithoutEncounterSchemas` (#1300) and the demo kernel (`DemoEnvironmentConfigTest`). Compiling such a container inside a test took one to two and a half minutes under PCOV and blocked a ParaTest worker. `make test-database` therefore runs `make test-warmup` first (`bin/warmup-test-kernels`, without PCOV). The test cache itself is warmed without PCOV too. A new test kernel class belongs in `bin/warmup-test-kernels`.
- **The prod kernel without a database** (#1684, #1685): `bin/check-prod-without-database` points `DATABASE_URL` at a closed port and handles `GET /health` (expects its 503 JSON with `database: unreachable`) and `GET /` with and without the consent cookie (expects the HTML error page with 503), each with its own kernel. It runs in the same job; locally: `APP_ENV=prod APP_DEBUG=0 php bin/check-prod-without-database`.
- **The prod CSP header is checked outside the test suite** (#1343): `bin/check-prod-csp` runs in the **Prod kernel smoke** job, which builds the prod container anyway, and handles `GET /data-prep/latest.json` with `SENTRY_CSP_REPORT_URI` empty and set. That job has no database, so the script uses this stateless route, which needs none and answers 200; only the header is checked. Locally: `APP_ENV=prod APP_DEBUG=0 php bin/check-prod-csp`.
- **The database job runs without the debug kernel** (`APP_DEBUG=0`, #1343). Debug kernels collect query backtraces, trace every event and check container freshness on each boot; without them the DB tests need about 40 % less time and memory. What stays the same:
  - Doctrine profiling is switched on explicitly in `when@test` (`profiling: true`), because the query-count tests read the profiler's `db` collector and Doctrine would otherwise tie it to `kernel.debug`.
  - Container deprecations are checked by `make lint-container` in the Linters workflow (`--no-debug`); runtime deprecations (`trigger_deprecation()`) reach PHPUnit with or without debug. The tests never enabled the `DebugClassLoader`.
  - A non-debug container is not rebuilt when a file changes. CI builds it fresh. Locally, `make test*` keeps the debug kernel; to run like CI, clear the cache first: `APP_DEBUG=0 bin/console cache:clear --env=test`, then `APP_DEBUG=0 make test-database`.
  - With Xdebug in `develop` mode, entities that Doctrine loads as lazy ghosts can lose their identifier for Doctrine's reflection, and EasyAdmin edit forms then reject associations ("The selected choice is invalid"). Run the tests with `XDEBUG_MODE=off` (or PCOV) when you see this; CI does not load Xdebug.
- **Coverage job** (after both succeed): one Codecov upload with both clovers, no flags (full project coverage)
- **Prod kernel smoke**: `composer install --no-dev` (with Composer scripts, so `cache:clear` boots the prod kernel), `lint:container`, `asset-map:compile`, `bin/check-prod-csp` and `bin/check-prod-without-database` in `APP_ENV=prod`
- CI writes only the clover report; the HTML and text reports come from `make coverage`
- CI runs ParaTest without `--stop-on-failure`, so a red run lists every failing test. Test results reach Codecov for red runs too.
- The test jobs install Composer dependencies with `--no-scripts` (the `cache:clear` auto-script would build an unused dev container)
- Either test job failing fails the workflow; the coverage job does not run unless both succeed
- **Coverage runs on every PR**, including step PRs into integration branches, so coverage stays high at every step and is not only checked on `main`. PCOV makes the tests about 2.4× slower; this cost is accepted, and speed-ups come from the tests and workflows instead (decided in #833)
- A new push to a PR cancels the previous run. A pull request that only changes `docs/**` or Markdown files still starts the workflows, but the changes job (`.github/workflows/changes.yml`) skips the code jobs; skipped jobs count as passed for the checks the rulesets require (#1183). Pushes to `main` and `develop` with only such changes start no workflow
- Workflows: `.github/workflows/tests.yml`
- Codecov: [`codecov.yml`](../../codecov.yml)
- Linting: `.github/workflows/lint.yml`
- Security scan: `.github/workflows/security.yml` — Psalm taint analysis (`vendor/bin/psalm --taint-analysis`, same version and `psalm.xml` as `make psalm`), results go to GitHub code scanning as SARIF
- Pushes to `main` and `develop` run Linting, Testsuite and Security Scan; the weekly security scan runs on the default branch (`main`)

## Rector and EasyAdmin CRUD lifecycle hooks

EasyAdmin 5 declares `persistEntity()`, `updateEntity()`, and `deleteEntity()` with `object $entityInstance` in `AbstractCrudController`. CRUD controller overrides must use the same parameter type — not an entity-specific type (PHP forbids narrowing `object` to e.g. `Post`).

After an EasyAdmin upgrade, run `composer install` so `vendor/` matches `composer.lock`. If Rector behaves unexpectedly locally, clear `var/cache/rector` and `var/cache/rector-phpstan` (Rector's internal PHPStan cache, kept per checkout so a removed worktree cannot break other checkouts, #1132) and re-run `vendor/bin/rector --dry-run`. CI restores the Rector cache from earlier runs; a change to `composer.lock` or `rector.php` starts with a fresh one.

## Common pitfalls

- Tests using `#[ResetDatabase]` or `DatabaseKernelTestCase` belong under `tests/*/Integration/` (or higher layers), not `tests/*/Unit/`: the CI unit job runs ParaTest without PostgreSQL. The same goes for tests that boot the kernel (`KernelTestCase`).
- Missing test database or wrong `DATABASE_URL`
- `terminating connection due to administrator command` in a filtered run: the first `#[ResetDatabase]` reset terminates DAMA's kept connections; `MaterializedViewAwareOrmResetter` drops them afterwards ([details](../04-features/statistics/projection-and-materialized-views.md#test-only-reset-decorator), #887). If it shows up again after a DAMA upgrade, check `DamaStaticConnections`.
- Materialized views not refreshed in statistics-related tests
- Missing `fixtures.scale` in test env (see `config/packages/test/fixtures.yaml`)
- Queue expectations in tests vs `when@test` routing (sync)

## Related documentation

- Statistics projection: [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md)
- Fixtures: [fixtures.md](fixtures.md)
- Development: [development-workflow.md](development-workflow.md)
