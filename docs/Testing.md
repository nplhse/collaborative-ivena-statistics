# Testing

## Test types

- **Unit / integration / functional** via PHPUnit under `tests/`
- **Fixture tests** under `tests/DataFixtures/` (reference YAML, pattern validation)
- **Import integration tests** for CSV processing and reject behavior
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

## Static analysis and linting

```bash
make lint
make phpstan
make psalm
```

## Translations (i18n)

Extract and lint (see [Translations.md](Translations.md) and [Glossary-i18n-de.md](Glossary-i18n-de.md)):

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

- **Unit job** (parallel, no database): `vendor/bin/paratest --testsuite unit` — stops on first failure
- **Database job** (parallel, PostgreSQL, migrations): `bin/phpunit --testsuite all --exclude-testsuite unit` — stops on first failure; either job failing fails the workflow
- Workflows: `.github/workflows/tests.yml`
- Linting: `.github/workflows/lint.yml`
- Security scan: `.github/workflows/security.yml`

## Common pitfalls

- Tests using `#[ResetDatabase]` or `DatabaseKernelTestCase` belong under `tests/*/Integration/` (or higher layers), not `tests/*/Unit/`: the CI unit job runs ParaTest without PostgreSQL.
- Missing test database or wrong `DATABASE_URL`
- Materialized views not refreshed in statistics-related tests
- Missing `fixtures.scale` in test env (see `config/packages/test/fixtures.yaml`)
- Queue expectations in tests vs `when@test` routing (sync)

## Related documentation

- Statistics projection: [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md)
- Fixtures: [Development-fixtures.md](Development-fixtures.md)
- Development: [Development-Workflow.md](Development-Workflow.md)
