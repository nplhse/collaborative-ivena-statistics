# Setup

## Requirements

- PHP `>=8.4`
- PostgreSQL `>=16`
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
make setup-dev
```

`make setup-dev` installs Composer and npm dependencies, prepares the database, and compiles assets.

`make install` is an alias for `make setup-dev`.

### Make targets

| Target | Use when |
|--------|----------|
| `setup-dev` | New machine: dev dependencies (`vendor` + `node_modules`), fresh DB, fixtures, test DB |
| `setup-prod` | Prod-like local run: `--no-dev`, empty DB, no fixtures |
| `upgrade-dev` | Pull/update: keep existing dev DB, recreate test DB (incl. test cache clear), migrate, refresh assets, `make node_modules` |
| `upgrade-prod` | Same as upgrade-dev with prod env and `--no-dev` |
| `purge` | Remove assets, uploads, `var/imports`, logs, cache; empty DB; no fixtures |
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
make lint
```

`make lint` includes `lint-js` (Prettier check + ESLint). Use `make fix-js` or `make ci` to auto-fix JavaScript before committing.

If both pass, the local development environment is ready.

Fixture details: [../03-development/fixtures.md](../03-development/fixtures.md)
