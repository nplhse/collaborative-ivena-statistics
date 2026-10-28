# Testing

## Test types

- **Unit / integration / functional** via PHPUnit under `tests/`
- **Fixture tests** under `tests/DataFixtures/` (reference YAML, pattern validation)
- **Import integration tests** for CSV processing, reject behaviour, and upload validation (Excel / unsupported extensions)
- **Command tests** for requeue and exit-code behavior

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
```

After a test run, slowest tests from the PHPUnit duration cache:

```bash
php bin/report-slowest-tests 10
```

## Test suites

PHPUnit suites are defined in `phpunit.dist.xml`: `all`, `unit`, `integration`, `functional`, `fixtures`, `system`.

Cross-cutting groups: `browser` (Zenstruck browser tests), `materialized-view` (statistics MV tests).

`BROWSER_ALWAYS_START_WEBSERVER=1` is set only when running browser tests (`make test SUITE=browser` or `GROUP=browser`), not globally.

When adding a new bounded context under `tests/`, register its `Unit` / `Integration` / `Functional` directories in the matching named suites in `phpunit.dist.xml`. The CI unit job only runs the `unit` suite.

`make check-phpunit-suite-dirs` (also part of `make ci` and the lint workflow) fails if a layer directory that contains `*Test.php` files is missing from its named suite, or if a suite lists a directory that does not exist.

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
- Database or HTTP → Integration / Functional with Foundry, not Unit doubles of persistence.

Do not introduce Mockery or Prophecy for application tests.

PR checklist: see [CONTRIBUTING.md](../../CONTRIBUTING.md) — stub by default, spy for side effects.

## Time in code and tests

Application code never reads the wall clock directly (ADR [021](../02-architecture/decisions/021-wall-clock-access.md)). Otherwise `mockTime()` cannot control it and tests depend on the real date.

| Where | Use |
|-------|-----|
| Services | Inject `Psr\Clock\ClockInterface`, call `$this->clock->now()` |
| Entities, value objects, static helpers | `Symfony\Component\Clock\Clock::get()->now()` |
| Tests | `use ClockSensitiveTrait;` and `self::mockTime('2026-05-15 10:00:00')` |

Absolute values stay fine: `new \DateTimeImmutable('2026-05-01 00:00:00')`, `new \DateTimeImmutable('@'.$timestamp)`, `new \DateTimeImmutable($row['created_at'])`. Relative values go through the clock: `$this->clock->now()->modify('first day of this month')` instead of `new \DateTimeImmutable('first day of this month')`, `$this->clock->now()->getTimestamp()` instead of `time()`, `$this->clock->now()->format('Y')` instead of `date('Y')`.

Parsing with `createFromFormat()` always starts the format with `!`: `\DateTimeImmutable::createFromFormat('!Y-m-d', $value)`. Without it, every field the format does not mention comes from the current time, so `'Y-m-d'` returns the date with the time of day the code runs at.

PHPStan reports violations in `src/` with the identifiers `wallClock.dateTime`, `wallClock.function` and `wallClock.createFromFormat`; there is no baseline for them. A deliberate exception needs `@phpstan-ignore wallClock.function (reason)`.

## Static analysis and linting

```bash
make lint
make phpstan
make psalm
```

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

## CI reference

- **Unit job** (parallel, no database, PCOV): suite `unit`, clover artifact `coverage-unit`
- **Database job** (parallel, PostgreSQL, PCOV): suites `integration,functional,fixtures,system`, clover artifact `coverage-integration`. Use this list, not `all` with `--exclude-testsuite unit`: `all` contains the unit folders too, so they would run twice.
- **Coverage job** (after both succeed): one Codecov upload with both clovers, no flags (full project coverage)
- **Prod kernel smoke**: `composer install --no-dev` (with Composer scripts, so `cache:clear` boots the prod kernel), `lint:container` and `asset-map:compile` in `APP_ENV=prod`
- CI writes only the clover report; the HTML and text reports come from `make coverage`
- CI runs ParaTest without `--stop-on-failure`, so a red run lists every failing test. Test results reach Codecov for red runs too.
- The test jobs install Composer dependencies with `--no-scripts` (the `cache:clear` auto-script would build an unused dev container)
- Either test job failing fails the workflow; the coverage job does not run unless both succeed
- A new push to a PR cancels the previous run; changes only to `docs/**` or Markdown files start no workflow
- Workflows: `.github/workflows/tests.yml`
- Codecov: [`codecov.yml`](../../codecov.yml)
- Linting: `.github/workflows/lint.yml`
- Security scan: `.github/workflows/security.yml` — Psalm taint analysis (`vendor/bin/psalm --taint-analysis`, same version and `psalm.xml` as `make psalm`), results go to GitHub code scanning as SARIF

## Rector and EasyAdmin CRUD lifecycle hooks

EasyAdmin 5 declares `persistEntity()`, `updateEntity()`, and `deleteEntity()` with `object $entityInstance` in `AbstractCrudController`. CRUD controller overrides must use the same parameter type — not an entity-specific type (PHP forbids narrowing `object` to e.g. `Post`).

After an EasyAdmin upgrade, run `composer install` so `vendor/` matches `composer.lock`. If Rector behaves unexpectedly locally, clear `var/cache/rector` and re-run `vendor/bin/rector --dry-run`. CI restores the Rector cache from earlier runs; a change to `composer.lock` or `rector.php` starts with a fresh one.

## Common pitfalls

- Tests using `#[ResetDatabase]` or `DatabaseKernelTestCase` belong under `tests/*/Integration/` (or higher layers), not `tests/*/Unit/`: the CI unit job runs ParaTest without PostgreSQL.
- Missing test database or wrong `DATABASE_URL`
- Materialized views not refreshed in statistics-related tests
- Missing `fixtures.scale` in test env (see `config/packages/test/fixtures.yaml`)
- Queue expectations in tests vs `when@test` routing (sync)

## Related documentation

- Statistics projection: [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md)
- Fixtures: [fixtures.md](fixtures.md)
- Development: [development-workflow.md](development-workflow.md)
