# Deployment

This application is deployed with [Deployer](https://deployer.org/) using the Symfony recipe. Production runs on [Uberspace](https://uberspace.de/) with a **user systemd** service for the Symfony Messenger worker.

The project **recommends Uberspace** for hosting. The operations docs assume it: deployment, [Messenger workers](messenger-workers.md), [backups](backup-restore.md) and [hosts.yaml.example](../../hosts.yaml.example) are written for Uberspace. Other hosts can work, but you have to adapt these steps yourself. The footer shows "Hosted on Uberspace" by default; on another host, set `APP_UBERSPACE_CREDIT=false` ([configuration](../06-reference/configuration.md)).

Upgrading an existing installation to a new release needs configuration changes and one-time commands that a deploy does not run: see [upgrading.md](upgrading.md).

## Paths on the server

The operations docs write server paths as `<deploy_path>/current` (the current release, a Deployer symlink) and `<deploy_path>/shared` (files kept across releases). `<deploy_path>` is the `deploy_path` of the host in your `hosts.yaml`. On Uberspace u8 it is usually `~/www`, as in [hosts.yaml.example](../../hosts.yaml.example).

## Quick operations

Most common commands:

```bash
vendor/bin/dep deploy
systemctl --user status messenger
systemctl --user restart messenger
cd <deploy_path>/current && php bin/console messenger:stats
cd <deploy_path>/current && php bin/console messenger:failed:show
```

### Media files (shared across releases)

Uploaded images and PDFs of the CMS and the blog live in the media storage `var/media`, which Deployer keeps in
`shared/var/media` ([ADR 039](../02-architecture/decisions/039-managed-media-storage.md)). Files are stored as
`originals/<YYYY>/<MM>/<uuid>.<ext>`, one directory per month of the upload, and served by the application under
`/media/<YYYY>/<MM>/<uuid>.<ext>` with long-lived caching. Upload is admin-only with a MIME allowlist. Nothing below
`public/` holds media any more, so there is no directory listing or script rule to maintain.

**Moving the old files (v0.3).** Releases before v0.3 kept media in `shared/public/uploads/media`. Every deploy runs
`app:media:migrate-storage` before the switch to the new release (task `media:migrate-storage`): it copies each file
not yet moved into the storage, verifies it by SHA-256 and stores its key. It does nothing once every file has moved.
The old directory stays untouched, so `vendor/bin/dep rollback` keeps working, and old URLs `/uploads/media/<file>`
redirect to the new ones. After the release is accepted, replace the old URLs in posts and pages once:

```bash
vendor/bin/dep media:rewrite-content-urls coishub.uber.space
```

Afterwards a rollback to a release before v0.3 shows broken images in the rewritten content. The redirect, the old
directory and `shared/public/uploads/media` are removed in v0.4.

**Integrity check.** The scheduler runs `app:media:check` daily at 05:10 (Europe/Berlin): missing or damaged files
show up in the admin dashboard and `/health` (check `media`) and are sent to administrators; files no media item refers
to are deleted after 24 hours ([media storage](../04-features/content/media-storage.md)).

**Post-deploy maintenance** (requires the `app:content:analyze-page-images` console
command in the deployed release):

```bash
vendor/bin/dep content:analyze-page-images coishub.uber.space
vendor/bin/dep content:analyze-page-images coishub.uber.space -o content_analyze_page_images_options="--dry-run"
```

### Hospital isochrones (shared across releases)

Destination isochrones live in `var/geo/hospital-isochrones`, which Deployer keeps in
`shared/var/geo/hospital-isochrones` so files survive release changes. Allocation
detail maps read these files only; they do not call OpenRouteService.

If the shared directory is empty after a deploy, or after hospital coordinates were geocoded, fetch them:

```bash
cd <deploy_path>/current
php bin/console dbal:run-sql "SELECT id, name FROM state ORDER BY id"
php bin/console app:geo:geocode-hospitals --state-id=<stateId>
php bin/console app:geo:geocode-hospitals --state-id=<stateId> --apply --force
php bin/console app:geo:fetch-isochrones --state-id=<stateId> --apply --force
```

The first geocode run needs `--force` because existing coordinates are city/postal-code centroids. Full operator runbook (new hospitals, address changes, adding a federal state): [hospital-geodata.md](hospital-geodata.md).

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

- PHP 8.4+ and PostgreSQL 18+ (same as the web application)
- Document root pointing at `public/` under the Deployer `current` symlink
- Server `.env.local` (shared across releases via Deployer)

Required variables: see [../06-reference/configuration.md](../06-reference/configuration.md). At minimum set `APP_ENV=prod`, `APP_DEBUG=0`, `APP_SECRET`, `DATABASE_URL`, `MAILER_DSN`, `MAILER_FROM`, `APP_URL`, and `MESSENGER_TRANSPORT_DSN`.

Also set `SYMFONY_TRUSTED_HOSTS` to the host of `APP_URL`. Password reset and e-mail verification links are built from the host of the request, so the application should only answer to its own host. Set `SYMFONY_TRUSTED_PROXIES` only when a reverse proxy in front of PHP forwards the client address in `X-Forwarded-For`.

Run database migrations so the `messenger_messages` table exists (included in the default deploy workflow).

## Pre-deploy verification

Validate server configuration before going live:

```bash
cd <deploy_path>/current
set -a && source ../shared/.env.local && set +a
php bin/console app:env:check --check-profile=prod
```

Use `--skip-database` only for a quick env-format check without a DB ping. See [../06-reference/configuration.md](../06-reference/configuration.md) for `app:env:check` profiles.

Manual checks that `app:env:check` cannot perform:

- [ ] `APP_ENV=prod` and `APP_DEBUG=0` in `shared/.env.local`
- [ ] `SYMFONY_TRUSTED_HOSTS` matches the host of `APP_URL`: `curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: other.invalid' https://<APP_URL>/health` returns `400` (or the web server refuses the host before PHP)
- [ ] Client IP is the visitor's, not a proxy's: change your language in the account settings, then compare `clientIp` in the metadata of the newest audit entry with your public IP (logins are not audited, see [audit-log.md](audit-log.md)); if it is a proxy address, set `SYMFONY_TRUSTED_PROXIES`
- [ ] Messenger worker running: `systemctl --user status messenger` — see [messenger-workers.md](messenger-workers.md)
- [ ] Queue consumers active: `php bin/console messenger:stats`
- [ ] No stuck failed messages: `php bin/console messenger:failed:show`
- [ ] Transactional mail works (registration or password reset test) — see [transactional-mail.md](transactional-mail.md)
- [ ] Sentry receives a test event (if `SENTRY_DSN` is set) — see [observability-sentry.md](observability-sentry.md)
- [ ] Session cookies are HTTPS-only in prod (`Secure`, `HttpOnly`, `SameSite=Lax` in `framework.yaml`; requires HTTPS via Nelmio forced SSL)
- [ ] CSP report-only header present: `curl -sI https://<APP_URL>/statistics/ | grep -i content-security-policy` — see [content-security-policy.md](content-security-policy.md#verification)
- [ ] Optional: `SENTRY_CSP_REPORT_URI` set and CSP violations appear in Sentry — see [content-security-policy.md#sentry-integration](content-security-policy.md#sentry-integration)
- [ ] Backups scheduled on Uberspace (cron — see [backup-restore.md](backup-restore.md))
- [ ] Sentry Uptime Monitor on `GET /health` — see [health-check.md](health-check.md)
- [ ] Storage budget set: `STORAGE_BUDGET_BYTES` in `shared/.env.local` (replaces `APP_ADMIN_STORAGE_LIMIT_BYTES`) — see [../06-reference/configuration.md](../06-reference/configuration.md#storage-monitoring)
- [ ] First storage measurement stored, so health is not `unavailable` until the scheduled run at 03:30: `cd <deploy_path>/current && php bin/console app:storage:analyze --persist` — see [../04-features/storage/README.md](../04-features/storage/README.md)

### P1 - before first invite (beta)

- [ ] Sentry Error Alert rule is active and scoped to `environment=beta` (for example: more than `N` new issues in 1 hour) — see [observability-sentry.md](observability-sentry.md#proactive-error-alerts-beta)
- [ ] Ops response for `/health` = `degraded` is documented and known by maintainers — see [health-check.md](health-check.md#runbook-degraded-or-failed-queue-entries)
- [ ] A recurring failed-message check rhythm is defined (at least weekly): `cd <deploy_path>/current && php bin/console messenger:failed:show`

## Bootstrap order on a new server

1. Configure `shared/.env.local`
2. `php bin/console app:env:check --check-profile=prod`
3. Deploy / migrate database
4. Create the first administrative user: `php bin/console app:user:create` (interactive; grant administrator privileges when prompted)
5. Start Messenger worker — see [messenger-workers.md](messenger-workers.md)

Do not use demo-fixture accounts in production. After setup, existing users can be granted or stripped of `ROLE_ADMIN` with `php bin/console app:user:promote-admin` and `php bin/console app:user:demote-admin`, or removed with `php bin/console app:user:delete` (all identify the user by **username** and ask for confirmation). The last remaining administrator cannot be demoted or deleted. A user who still owns hospitals cannot be deleted until ownership is reassigned.

`app:user:create` writes a user; `app:env:check` only validates configuration.

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
| `keep_releases` | `2` | Releases kept under `<deploy_path>/releases`: the current one and one for `vendor/bin/dep rollback` |

## What happens on deploy

Typical order (Symfony recipe plus project tasks):

1. New release is prepared and vendors installed (`composer install --no-dev`; its Composer `auto-scripts` also run `assets:install`, which copies bundle assets such as EasyAdmin to `public/bundles/`)
2. Asset map compile and cache warmup
3. **`messenger:stop`** — `systemctl --user stop messenger.service`, so no worker or scheduler runs during the migrations. With the PHP extension `pcntl` the worker finishes its current message first (up to `TimeoutStopSec`); without it the message is interrupted and delivered again. If the deploy fails after this step, the worker is started again on the previous release
4. Database migrations
5. **`media:migrate-storage`** — moves media files that are still in the old upload directory into `shared/var/media` ([media files](#media-files-shared-across-releases))
6. **`geo:import-legacy-districts`** (temporary, v0.3) — takes the legacy district geography over into the database and verifies it. A failed check stops the deploy before the switch; a completed takeover is skipped ([ADR 041](../02-architecture/decisions/041-dispatch-area-geography-as-master-data.md))
7. Symlink switch to the new release (`current`)
8. **`messenger:restart`** — `systemctl --user restart messenger.service`
9. Cleanup: releases beyond `keep_releases` (2) are deleted. Files that must survive a release change belong in `shared/`.

**First deploy:** If the systemd unit does not exist yet, `messenger:restart` fails. Either create the unit first (see [messenger-workers.md](messenger-workers.md)) or set `messenger_restart_on_deploy: false` in `hosts.yaml` until the worker is ready.

## Operations

| Task | Command |
|------|---------|
| Worker status | `systemctl --user status messenger` |
| Restart worker | `systemctl --user restart messenger` |
| Queue stats | `cd <deploy_path>/current && php bin/console messenger:stats` |
| Failed messages | `php bin/console messenger:failed:show` |
| Retry failed | `php bin/console messenger:failed:retry` |

See [health-check.md](health-check.md) for the `/health` endpoint and [backup-restore.md](backup-restore.md) for backups.

### Usage analytics rollout

After deploying the Analytics migration (`Version20260804151958`) and the new `analytics` cookie preference:

1. Confirm migrations applied (`doctrine:migrations:status`).
2. Re-prompt existing users for consent (old rows treat missing `analytics` as false while `decidedAt` stays set — banner would not reappear otherwise). Prefer deleting consent rows rather than silently enabling analytics:

   ```bash
   cd <deploy_path>/current
   php bin/console dbal:run-sql 'SELECT COUNT(*) AS n FROM cookie_consent'
   php bin/console dbal:run-sql 'DELETE FROM cookie_consent'
   ```

   Details: [../04-features/analytics/usage-analytics.md](../04-features/analytics/usage-analytics.md#rollout-re-prompt-after-introducing-analytics).
3. Smoke-check Admin → **Usage analytics** → Overview after accepting cookies with “Accept all”.
4. Optional one-off: delete historical admin UI request rows so the Overview is not skewed — see [../04-features/analytics/usage-analytics.md](../04-features/analytics/usage-analytics.md#admin-area-exclusion).

Recommended beta operations rhythm:
- Weekly (minimum): `cd <deploy_path>/current && php bin/console messenger:failed:show`
- Immediately when `/health` reports `degraded`: follow [health-check.md](health-check.md#runbook-degraded-or-failed-queue-entries)

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
