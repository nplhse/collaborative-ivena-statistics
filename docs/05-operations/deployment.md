# Deployment

This application is deployed with [Deployer](https://deployer.org/) using the Symfony recipe. Production runs on [Uberspace](https://uberspace.de/) with a **user systemd** service for the Symfony Messenger worker.

## Quick operations

Most common commands:

```bash
vendor/bin/dep deploy
systemctl --user status messenger
systemctl --user restart messenger
cd ~/www/current && php bin/console messenger:stats
cd ~/www/current && php bin/console messenger:failed:show
```

### Media uploads (shared across releases)

Uploaded images and PDFs live in `public/uploads/media`, which Deployer keeps in
`shared/public/uploads/media` so files survive release changes.

**One-time migration** after enabling the shared directory (copies from the release
that currently holds the most media files):

```bash
vendor/bin/dep media:migrate-to-shared coishub.uber.space
vendor/bin/dep deploy coishub.uber.space
```

**Post-deploy maintenance** (requires the `app:content:analyze-page-images` console
command in the deployed release):

```bash
vendor/bin/dep content:analyze-page-images coishub.uber.space
vendor/bin/dep content:analyze-page-images coishub.uber.space -o content_analyze_page_images_options="--dry-run"
```

Related docs:

- [../06-reference/configuration.md](../06-reference/configuration.md) — environment variables
- [backup-restore.md](backup-restore.md) — backups and restore
- [messenger-workers.md](messenger-workers.md) — systemd worker setup
- [transactional-mail.md](transactional-mail.md) — mail configuration
- [health-check.md](health-check.md) — uptime endpoint
- [troubleshooting.md](troubleshooting.md) — runtime diagnostics
- [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md), [../04-features/import/batch-requeue.md](../04-features/import/batch-requeue.md)

Further reading:

- [Uberspace: Systemd user services](https://u8manual.uberspace.de/services_systemd/)
- [Symfony Messenger with systemd (JoliCode)](https://jolicode.com/blog/symfony-messenger-systemd)

## Prerequisites

On the server:

- PHP 8.4+ and PostgreSQL 16+ (same as the web application)
- Document root pointing at `public/` under the Deployer `current` symlink
- Server `.env.local` (shared across releases via Deployer)

Required variables: see [../06-reference/configuration.md](../06-reference/configuration.md). At minimum set `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`, `MAILER_FROM`, `APP_URL`, and `MESSENGER_TRANSPORT_DSN`.

Run database migrations so the `messenger_messages` table exists (included in the default deploy workflow).

## Pre-deploy verification

Validate server configuration before going live:

```bash
cd ~/www/current
set -a && source ../shared/.env.local && set +a
php bin/console app:env:check --check-profile=prod
```

Use `--skip-database` only for a quick env-format check without a DB ping. See [../06-reference/configuration.md](../06-reference/configuration.md) for `app:env:check` profiles.

Manual checks that `app:env:check` cannot perform:

- [ ] `APP_ENV=prod` and `APP_DEBUG=0` in `shared/.env.local`
- [ ] Messenger worker running: `systemctl --user status messenger` — see [messenger-workers.md](messenger-workers.md)
- [ ] Queue consumers active: `php bin/console messenger:stats`
- [ ] No stuck failed messages: `php bin/console messenger:failed:show`
- [ ] Transactional mail works (registration or password reset test) — see [transactional-mail.md](transactional-mail.md)
- [ ] Sentry receives a test event (if `SENTRY_DSN` is set) — see [observability-sentry.md](observability-sentry.md)
- [ ] Backups scheduled on Uberspace (cron — see [backup-restore.md](backup-restore.md))
- [ ] Sentry Uptime Monitor on `GET /health` — see [health-check.md](health-check.md)

## Bootstrap order on a new server

1. Configure `shared/.env.local`
2. `php bin/console app:env:check --check-profile=prod`
3. Deploy / migrate database
4. Optionally `php bin/console app:install` (bootstrap admin)
5. Start Messenger worker — see [messenger-workers.md](messenger-workers.md)

`app:install` creates data; `app:env:check` only validates configuration.

## Local Deployer setup

1. Install dependencies (Deployer is a dev dependency):

   ```bash
   composer install
   ```

2. Copy the host inventory template:

   ```bash
   cp hosts.yaml.example hosts.yaml
   ```

   Edit `hosts.yaml` with your hostname, `remote_user`, `deploy_path`, and `web_url`. The real `hosts.yaml` is gitignored.

   Set `web_url` to the same public HTTPS URL you will use for **`APP_URL`** in server `shared/.env.local` (see [transactional-mail.md](transactional-mail.md)).

3. Deploy:

   ```bash
   vendor/bin/dep deploy
   ```

   Use `-vvv` for verbose output.

### Host options

| Option | Default | Purpose |
|--------|---------|---------|
| `messenger_systemd_service` | `messenger.service` | systemd unit name under `~/.config/systemd/user/` |
| `messenger_restart_on_deploy` | `true` | Run stop/restart hooks; set `false` until the worker is configured |

## What happens on deploy

Typical order (Symfony recipe plus project tasks):

1. New release is prepared and vendors installed (`composer install --no-dev`)
2. Asset map compile and cache warmup
3. **`messenger:stop`** — `messenger:stop-workers` on the **previous** release (graceful shutdown)
4. Database migrations
5. Symlink switch to the new release (`current`)
6. **`messenger:restart`** — `systemctl --user restart messenger.service`

**First deploy:** If the systemd unit does not exist yet, `messenger:restart` fails. Either create the unit first (see [messenger-workers.md](messenger-workers.md)) or set `messenger_restart_on_deploy: false` in `hosts.yaml` until the worker is ready.

## Operations

| Task | Command |
|------|---------|
| Worker status | `systemctl --user status messenger` |
| Restart worker | `systemctl --user restart messenger` |
| Queue stats | `cd ~/html/current && php bin/console messenger:stats` |
| Failed messages | `php bin/console messenger:failed:show` |
| Retry failed | `php bin/console messenger:failed:retry` |

See [health-check.md](health-check.md) for the `/health` endpoint and [backup-restore.md](backup-restore.md) for backups.

### Scanner path blocking (Apache)

`public/.htaccess` returns **403 Forbidden** for common WordPress scanner and exploit probe paths before requests reach Symfony. This applies on production (Uberspace/Apache) only.

After deploy, verify with:

```bash
curl -sI https://<host>/wp-login.php | head -1   # expect HTTP/1.1 403 Forbidden
curl -sI https://<host>/.env | head -1           # expect HTTP/1.1 403 Forbidden
curl -sI https://<host>/ | head -1               # expect HTTP/1.1 200 or 302
```

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Deploy fails at `messenger:restart` | Unit missing or wrong name; check `messenger_systemd_service` |
| Messages stuck in `messenger_messages` | Worker not running; see [messenger-workers.md](messenger-workers.md) |
| No verification / reset / feedback mail | See [transactional-mail.md](transactional-mail.md) |
| Worker runs old code after deploy | `WorkingDirectory` points at a fixed `releases/N` instead of `current` |
| Stop hook skipped | First deploy has no `previous_release`; normal |

More symptoms: [troubleshooting.md](troubleshooting.md)
