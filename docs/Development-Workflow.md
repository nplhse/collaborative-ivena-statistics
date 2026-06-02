# Development workflow

## Daily tasks

### Initialize the environment

```bash
make install
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
make warmup
php bin/console cache:clear
php bin/console asset-map:compile
```

### Migrations

```bash
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
