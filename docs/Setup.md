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
make install
```

`make install` installs dependencies, initializes databases, and compiles assets.

## Create `.env.local`

```bash
cp .env.example .env.local
php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
```

Set at least:
- `APP_SECRET`
- `DATABASE_URL`

All variables: [Configuration.md](Configuration.md)

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
symfony composer setup-env
symfony composer setup-test-env
```

## Quick smoke test

```bash
make test
make lint
```

If both pass, the local development environment is ready.
