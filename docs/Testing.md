# Testing

## Test types

- **Unit / integration / functional** via PHPUnit under `tests/`
- **Import integration tests** for CSV processing and reject behavior
- **Command tests** for requeue and exit-code behavior

## Run locally

```bash
make test
make testdox
make coverage
```

## Static analysis and linting

```bash
make lint
make phpstan
make psalm
```

## Relevant configuration

- PHPUnit: `phpunit.dist.xml`
- Test bootstrap: `tests/bootstrap.php`
- PHPStan: `phpstan.dist.neon`

## CI reference

- Tests: `.github/workflows/tests.yml`
- Linting: `.github/workflows/lint.yml`
- Security scan: `.github/workflows/security.yml`

## Common pitfalls

- Missing test database or wrong `DATABASE_URL`
- Materialized views not refreshed in statistics-related tests
- Queue expectations in tests vs `when@test` routing (sync)

## Related documentation

- Statistics projection: [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md)
- Development: [Development-Workflow.md](Development-Workflow.md)
