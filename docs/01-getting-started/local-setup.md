# Setup

## Requirements

- PHP `>=8.4`
- PostgreSQL `>=18`
- Composer
- Node.js `>=22.13` and npm (required for JavaScript linting in development)
- Symfony CLI (recommended)
- Optional Docker/Compose

## Install locally

```bash
git clone https://github.com/nplhse/collaborative-ivena-statistics.git
cd collaborative-ivena-statistics
cp .env.example .env.local
# configure APP_SECRET and DATABASE_URL
make install-dev
```

`make install-dev` installs Composer and npm dependencies, prepares the database, and compiles assets. It drops and recreates the database; if the database already holds an installation, it stops unless you add `INSTALL_CONFIRM=yes`.

`make install` is the prod-like counterpart (`--no-dev`, empty database), not an alias for the dev setup. The old names `setup-dev`, `setup-prod` and `prod` stop with a hint to the new ones.

Local `install-dev` loads demo users via fixtures. An empty database (`make install` or a new server) has no default administrator — create one with `php bin/console app:user:create`. See [../05-operations/deployment.md](../05-operations/deployment.md).

### Make targets

| Target | Use when |
|--------|----------|
| `install-dev` | New machine: dev dependencies (`vendor` + `node_modules`), fresh DB, fixtures, test DB (drops the DB) |
| `install` | Prod-like local run: `--no-dev`, empty DB, no fixtures (drops the DB) |
| `upgrade-dev` | Pull/update: keep existing dev DB, recreate test DB (incl. test cache clear), migrate, refresh assets, `make node_modules` |
| `upgrade-prod` | Same as upgrade-dev with prod env and `--no-dev` |
| `purge` | Remove assets, uploads, `var/imports`, logs, cache; drop and recreate the DB; no fixtures |
| `reset` | Like `purge`, then load fixtures |
| `warmup` | Recompile assets and warm cache only (no DB changes) |
| `node_modules` | Install npm dev dependencies from `package-lock.json` (ESLint, Prettier) |
| `lint-js` | Check JavaScript formatting (Prettier) and ESLint rules |
| `fix-js` | Auto-fix JavaScript formatting and fixable ESLint issues |

All variables: [../06-reference/configuration.md](../06-reference/configuration.md)

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
make check
```

`make check` runs every check without tests and changes no code. `make fix` applies the automatic fixes (PHP, Twig, JavaScript); review its diff before committing.

If both pass, the local development environment is ready.

Fixture details: [../03-development/fixtures.md](../03-development/fixtures.md)
