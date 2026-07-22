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
make coverage SUITE=integration
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

Do not introduce Mockery or Prophecy for application tests. Full taxonomy, findings, and follow-up backlog: [test-architecture-audit.md](test-architecture-audit.md).

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
- PHPStan: `phpstan.dist.neon`

## CI reference

- **Unit job** (parallel, no database, PCOV): `paratest --testsuite unit --coverage-clover=…` → Codecov flag `unit`
- **Database job** (parallel, PostgreSQL, PCOV): `paratest --testsuite all --exclude-testsuite unit --coverage-clover=…` → Codecov flag `integration`
- Either job failing fails the workflow; Codecov merges both flags for project coverage
- Workflows: `.github/workflows/tests.yml`
- Codecov flags: [`codecov.yml`](../../codecov.yml)
- Linting: `.github/workflows/lint.yml`
- Security scan: `.github/workflows/security.yml`

## Rector and EasyAdmin CRUD lifecycle hooks

EasyAdmin 5 declares `persistEntity()`, `updateEntity()`, and `deleteEntity()` with `object $entityInstance` in `AbstractCrudController`. CRUD controller overrides must use the same parameter type — not an entity-specific type (PHP forbids narrowing `object` to e.g. `Post`).

After an EasyAdmin upgrade, run `composer install` so `vendor/` matches `composer.lock`. If Rector behaves unexpectedly locally, clear `var/cache/rector` and re-run `vendor/bin/rector --dry-run` (CI always runs without a persisted Rector cache).

## Common pitfalls

- Tests using `#[ResetDatabase]` or `DatabaseKernelTestCase` belong under `tests/*/Integration/` (or higher layers), not `tests/*/Unit/`: the CI unit job runs ParaTest without PostgreSQL.
- Missing test database or wrong `DATABASE_URL`
- Materialized views not refreshed in statistics-related tests
- Missing `fixtures.scale` in test env (see `config/packages/test/fixtures.yaml`)
- Queue expectations in tests vs `when@test` routing (sync)

## Related documentation

- Test architecture audit and backlog: [test-architecture-audit.md](test-architecture-audit.md)
- Statistics projection: [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md)
- Fixtures: [fixtures.md](fixtures.md)
- Development: [development-workflow.md](development-workflow.md)
