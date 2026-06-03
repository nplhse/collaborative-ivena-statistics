# Development workflow

## Daily tasks

### Initialize the environment

```bash
make setup-dev    # greenfield with fixtures (alias: make install)
make upgrade-dev  # update deps/schema; keeps existing DB (e.g. mirror DB)
```

### Reset local runtime data

```bash
make purge   # clear assets, uploads, imports, logs; empty DB; no fixtures
make reset   # like purge, then load demo fixtures
```

### Start the local worker

```bash
make consume
```

### Run tests

```bash
make test
make coverage
```

### Code checks / coding standards

```bash
make lint
make static-analysis
make cs
```

### Refresh cache and assets

```bash
make warmup   # does not touch the database
```

For schema changes on an existing database, prefer `make upgrade-dev` over `purge` or `reset`.

### Migrations

```bash
make upgrade-dev
# or manually:
php bin/console doctrine:migrations:migrate --no-interaction
```

## Tips

- In `dev`, mail is processed synchronously; import jobs stay asynchronous.
- For reproducible import issues, always note the same input file and import ID.
- For queue problems, run `messenger:stats` first, then `messenger:failed:show`.

## Related documentation

- Setup: [Setup.md](Setup.md)
- Testing: [Testing.md](Testing.md)
- Import: [Import-workflow.md](Import-workflow.md)
- Troubleshooting: [Troubleshooting.md](Troubleshooting.md)
