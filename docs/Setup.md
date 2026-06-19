# Setup

## Requirements

- PHP `>=8.4`
- PostgreSQL `>=16`
- Composer
- Symfony CLI (recommended)
- Optional Docker/Compose

## Install locally

```bash
git clone https://github.com/nplhse/collaborative-ivena-statistics.git
cd collaborative-ivena-statistics
cp .env.example .env.local
# configure APP_SECRET and DATABASE_URL
make setup-dev
```

`make install` is an alias for `make setup-dev`.

### Make targets

| Target | Use when |
|--------|----------|
| `setup-dev` | New machine: dev dependencies, fresh DB, fixtures, test DB |
| `setup-prod` | Prod-like local run: `--no-dev`, empty DB, no fixtures |
| `upgrade-dev` | Pull/update: keep existing dev DB, recreate test DB (incl. test cache clear), migrate, refresh assets |
| `upgrade-prod` | Same as upgrade-dev with prod env and `--no-dev` |
| `purge` | Remove assets, uploads, `var/imports`, logs, cache; empty DB; no fixtures |
| `reset` | Like `purge`, then load fixtures |
| `warmup` | Recompile assets and warm cache only (no DB changes) |

All variables: [Configuration.md](Configuration.md)

## Create `.env.local`

```bash
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Set at least:
- `APP_SECRET`
- `DATABASE_URL`

## Start the application

With Symfony CLI:

```bash
symfony serve -d
```

With Docker:

```bash
make start
```

## Prepare the database manually (optional)

```bash
symfony composer setup-database
symfony composer load-fixtures      # demo data (--group=dev)
symfony composer setup-test-env     # clear test cache, fresh test DB (drop/create/migrate)
symfony composer upgrade-test-env   # same as setup-test-env; used by make upgrade-dev
```

## Quick smoke test

```bash
make test
make lint
```

If both pass, the local development environment is ready.

Fixture details: [Development-fixtures.md](Development-fixtures.md)
