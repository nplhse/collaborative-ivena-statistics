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
# Dry-run (default console flags can be overridden)
vendor/bin/dep content:analyze-page-images coishub.uber.space

# Custom flags, e.g. analysis only
vendor/bin/dep content:analyze-page-images coishub.uber.space -o content_analyze_page_images_options="--dry-run"
```

Related docs:
- Configuration details: [Configuration.md](Configuration.md)
- Beta readiness checklist: [Beta-readiness-checklist.md](Beta-readiness-checklist.md)
- Backups and restore: [Backup-restore.md](Backup-restore.md)
- Runtime issues and diagnostics: [Troubleshooting.md](Troubleshooting.md)
- Import-specific operations: [Import-workflow.md](Import-workflow.md), [Import-batch-requeue.md](Import-batch-requeue.md)

Further reading:

- [Uberspace: Systemd user services](https://u8manual.uberspace.de/services_systemd/)
- [Symfony Messenger with systemd (JoliCode)](https://jolicode.com/blog/symfony-messenger-systemd)

## Prerequisites

On the server:

- PHP 8.4+ and PostgreSQL 16+ (same as the web application)
- Document root pointing at `public/` under the Deployer `current` symlink
- Server `.env.local` (shared across releases via Deployer) including at least:

| Variable | Purpose |
|----------|---------|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | Non-empty secret for Symfony (unique per environment; never commit to Git). If a previous value was exposed, generate a new secret, update `shared/.env.local`, clear the Symfony cache, and restart the Messenger worker so sessions and signed URLs use the new key. |
| `DATABASE_URL` | PostgreSQL connection |
| `MAILER_DSN` | Outbound mail transport (SMTP or provider API); see [Transactional mail](#transactional-mail) |
| `MAILER_FROM` | Default sender address for verification, password reset, and feedback notifications |
| `APP_URL` | Public HTTPS base URL of the app (e.g. `https://your-username.uberspace.de`); required for correct mail links and embedded images |
| `MESSENGER_TRANSPORT_DSN` | Queue backend for async jobs and mail (default: `doctrine://default?auto_setup=0`) |

Optional but recommended:

| Variable | Purpose |
|----------|---------|
| `MAILER_REPLY_TO` | Reply-to address on transactional mail (leave empty to omit) |

The application version label (`App\Kernel::APP_VERSION`, exposed as `app.version`) is stored with feedback submissions and used as the default Sentry release unless `SENTRY_RELEASE` is set.

Run database migrations so the `messenger_messages` table exists (included in the default deploy workflow).

## Pre-beta gate

Before opening a closed beta, validate server configuration:

```bash
cd ~/www/current
set -a && source ../shared/.env.local && set +a
php bin/console app:env:check --check-profile=beta
```

Use `--skip-database` only for a quick env-format check without a DB ping.

Manual checks that `app:env:check` cannot perform:

```bash
systemctl --user status messenger
php bin/console messenger:stats
php bin/console messenger:failed:show
```

Full checklist: [Beta-readiness-checklist.md](Beta-readiness-checklist.md).

On a **new server**, run `app:env:check` before `app:install` (both Install commands). `app:install` only creates the initial admin user; `app:env:check` validates secrets and URLs.

## Transactional mail

Registration verification, password reset, and feedback admin notifications are sent through a central mail layer 
([`TransactionalMailer`](../src/Shared/Infrastructure/Mail/TransactionalMailer.php)). Templates and business logic are 
unchanged; only transport and configuration are unified.

### Required environment variables

| Variable | Example | Notes |
|----------|---------|-------|
| `MAILER_DSN` | `smtp://user:pass@smtp.example.com:587` | **Required in production.** Without a real DSN, no mail leaves the server. Use your provider’s SMTP or native DSN (Mailgun, Brevo, Amazon SES, etc.). |
| `MAILER_FROM` | `no-reply@your-domain.example` | Sender address on all transactional mail. Defaults to `no-reply@localhost` if unset — set an address your provider allows. |
| `APP_URL` | `https://your-username.uberspace.de` | **Required in production.** Base URL for password-reset links, verification assets in mail (`absolute_url(asset(...))`), and any route generated without an HTTP request (Messenger worker). Use the same value as Deployer `web_url`. |

The **display name** in the From header (and the browser tab / navbar title) comes from `app.title`. By default this is `Collaborative IVENA statistics` in [`config/packages/app.yaml`](../config/packages/app.yaml). Set optional **`APP_TITLE`** to override it per environment (e.g. a hospital-specific name). The short brand label in the sidebar and admin UI remains `app.short_title` (`COIS` by default) in `app.yaml` — it is not controlled by an environment variable.

### Configuring `APP_URL`

In production, transactional mail is rendered by the **Messenger worker**, not during a browser request. Symfony therefore cannot infer your public hostname from PHP automatically — you must set **`APP_URL`** to the same address users type in the browser.

**Use the public HTTPS base URL of the site:**

| Do | Don't |
|----|--------|
| `https://your-username.uberspace.de` | `http://localhost` |
| `https://statistics.example.org` | `https://example.org/app/` (no path suffix) |
| Scheme `https://` in production | Trailing slash (`https://example.org/`) |

**Keep it in sync with Deployer:** the value should match `web_url` in [`hosts.yaml.example`](../hosts.yaml.example) (your real `hosts.yaml` is gitignored):

```yaml
# hosts.yaml
web_url: https://your-username.uberspace.de
```

```env
# shared/.env.local on the server (Deployer shared file)
APP_URL=https://your-username.uberspace.de
```

Deployer keeps `.env.local` as a shared file (see [`deploy.php`](../deploy.php)) across releases — edit it once on the server under `{deploy_path}/shared/.env.local`, not inside a single `releases/N` directory.

**First-time setup on the server:**

```bash
# SSH to the host, then (adjust deploy_path if needed):
nano ~/www/shared/.env.local
```

Add or update:

```env
APP_URL=https://your-username.uberspace.de
MAILER_DSN=smtp://...
MAILER_FROM=no-reply@your-domain.example
```

After saving, clear the prod cache and restart the Messenger worker so queued mail picks up the new base URL:

```bash
cd ~/www/current
php bin/console cache:clear --env=prod
systemctl --user restart messenger
```

**Verify `APP_URL` is active:**

```bash
cd ~/www/current
php bin/console debug:config framework router --env=prod | grep default_uri
```

The output should show your HTTPS URL, not `localhost`.

**Smoke-test mail content:**

1. Request a password reset for a verified user.
2. Open the message (or inspect it in your mail catcher).
3. Confirm the reset link starts with `https://your-username.uberspace.de/reset-password/...`.
4. Confirm header images load from `https://your-username.uberspace.de/email/tabler/...` (not `http://localhost/...`).

If links or images still show `localhost`, `APP_URL` is missing, wrong, or the worker was not restarted after the change.

### Optional environment variables

| Variable | Purpose |
|----------|---------|
| `MAILER_REPLY_TO` | If set, added as Reply-To on all transactional mail |

### Feedback notifications (role-based recipients)

`FEEDBACK_ADMIN_EMAIL` is **no longer used**. Feedback admin mail is sent to application users who have **both**:

- `ROLE_ADMIN`
- `ROLE_FEEDBACK_RECIPIENT` (shown in EasyAdmin as **“Receives Feedback”**)

Additional requirements: account enabled, email verified, non-empty email address.

If no matching user exists, feedback is still stored but no email is sent. Check application logs for 
`feedback.admin_mail_skipped` with reason `no_feedback_recipients`.

**After each deploy (or when onboarding admins):** open **Admin → Users**, edit the relevant admin accounts, and assign 
**Receives Feedback**. Existing admins do not receive this role automatically.

### Async delivery in production

In `prod`, Symfony Mailer dispatches `SendEmailMessage` to the **`async_priority_low`** queue (see 
[`config/packages/messenger.yaml`](../config/packages/messenger.yaml)). In `dev`, mail is sent synchronously.

That means:

1. **`MESSENGER_TRANSPORT_DSN`** must be configured (Doctrine transport is fine if migrations created `messenger_messages`).
2. The **Messenger worker** must be running — see [Messenger worker](#messenger-worker-one-time-server-setup) below.

Without a worker, verification, password reset, and feedback emails are queued but never delivered.

### DNS and deliverability

Configure SPF, DKIM, and DMARC for the domain used in `MAILER_FROM`. Use an address and domain your SMTP provider 
authorizes; otherwise messages often land in spam folders.

### Post-deploy mail checklist

1. Set `MAILER_DSN`, `MAILER_FROM`, and `APP_URL` in server `.env.local`.
2. Confirm the Messenger worker is active (`systemctl --user status messenger`).
3. Assign **Receives Feedback** to at least one verified admin (for feedback notifications).
4. Smoke-test: register a user (verification mail), request a password reset, submit feedback.
5. If mail does not arrive, check `messenger:stats`, `messenger:failed:show`, and `journalctl --user -u messenger -f`.

## Messenger worker (one-time server setup)

Async messages use three transports (`async_priority_high`, `async_priority_low`, `scheduler_default`) configured in [`config/packages/messenger.yaml`](../config/packages/messenger.yaml). 
In production, email and domain jobs are routed to the async queues; scheduled KPI aggregation uses `scheduler_default`. Without a running worker, messages stay in the database.

### 1. Create the systemd unit

On Uberspace you manage **user** services (not root). Create `~/.config/systemd/user/messenger.service` with the content 
below.

Replace:

- `YOUR_USERNAME` with your Uberspace account name
- `/home/YOUR_USERNAME/html` with your Deployer `deploy_path` if different
- `/usr/bin/php` with the output of `which php` on the server

**Important:** `WorkingDirectory` must end with `/current` (the Deployer symlink), not a fixed `releases/N` path, so 
restarts after deploy load the new release.

```ini
[Unit]
Description=Symfony Messenger worker (high + low + scheduler)
StartLimitIntervalSec=60
StartLimitBurst=5

[Service]
Type=simple
WorkingDirectory=/home/YOUR_USERNAME/www/current
Environment=APP_ENV=prod
Environment=APP_DEBUG=0

ExecStart=/usr/bin/php -d memory_limit=-1 bin/console messenger:consume async_priority_high async_priority_low scheduler_default --env=prod --memory-limit=256M --time-limit=3600 -q

Restart=always
RestartSec=5
TimeoutStopSec=300

[Install]
WantedBy=default.target
```

Notes:

- **`WantedBy=default.target`** — required on Uberspace; do not use `multi-user.target`.
- **`--time-limit=3600`** — worker exits after one hour; systemd starts a fresh process (limits memory leaks and stale DB connections).
- **`--memory-limit=256M`** — additional guard; PHP `-d memory_limit=-1` avoids a low CLI cap.
- **`-q`** — quiet logging in production; use `journalctl` for output.
- **`StartLimitBurst`** — stops restart loops if the worker crashes on every start (misconfiguration).

Enable and start:

```bash
systemctl --user daemon-reload
systemctl --user enable --now messenger.service
systemctl --user status messenger.service
```

### 2. Smoke test

Before relying on systemd, run the consumer manually from the deploy path:

```bash
cd ~/html/current
php bin/console messenger:consume async_priority_high async_priority_low scheduler_default -vv --time-limit=30
```

Trigger an async action in the app (for example an import or statistics rebuild) and confirm messages are processed.

### Logs

```bash
journalctl --user -u messenger -f
```

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

   Set `web_url` to the same public HTTPS URL you will use for **`APP_URL`** in server `shared/.env.local` (see [Configuring `APP_URL`](#configuring-app_url)).

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

**First deploy:** If the systemd unit does not exist yet, `messenger:restart` fails. Either create the unit first (see 
above) or set `messenger_restart_on_deploy: false` in `hosts.yaml` until the worker is ready.

## Operations

| Task | Command |
|------|---------|
| Worker status | `systemctl --user status messenger` |
| Restart worker | `systemctl --user restart messenger` |
| Queue stats | `cd ~/html/current && php bin/console messenger:stats` |
| Failed messages | `php bin/console messenger:failed:show` |
| Retry failed | `php bin/console messenger:failed:retry` |

### Backups

Database and file backups are documented in [Backup-restore.md](Backup-restore.md).

Quick reference on the server:

```bash
cd ~/www/current
set -a && source ../shared/.env.local && set +a
BACKUP_DIR=~/backups ./bin/ops/backup-database.sh
BACKUP_DIR=~/backups IMPORTS_DIR=~/www/shared/var/imports MEDIA_DIR=~/www/shared/public/uploads/media ./bin/ops/backup-files.sh
```

Schedule cron jobs on Uberspace separately (see Backup-restore.md for examples).

Local development with Docker:

```bash
docker compose up -d database
make backup-db
```

Local development uses `make consume` (same transports, verbose). Mail is sent synchronously in `dev`; production 
requires the worker for queued mail.

### Scanner path blocking (Apache)

`public/.htaccess` returns **403 Forbidden** for common WordPress scanner and exploit probe paths (for example `/wp-login.php`, `/xmlrpc.php`, `/.env`) before requests reach Symfony. This applies on production (Uberspace/Apache) only; local development with `symfony server` does not use `.htaccess`.

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
| Messages stuck in `messenger_messages` | Worker not running; check `systemctl --user status messenger` |
| No verification / reset / feedback mail | `MAILER_DSN` missing or worker not consuming `async_priority_low` |
| Reset link or mail images use `http://localhost` | `APP_URL` unset or wrong in `shared/.env.local`; must match public `web_url`; restart Messenger worker after change |
| Feedback saved but no admin email | No user with Admin + Receives Feedback (enabled, verified); see logs for `feedback.admin_mail_skipped` |
| Mail in spam | SPF/DKIM/DMARC or `MAILER_FROM` not aligned with SMTP provider |
| Worker runs old code after deploy | `WorkingDirectory` points at a fixed `releases/N` instead of `current` |
| `too many redirects` / 500 on web | Unrelated to Messenger; ensure `public/.htaccess` exists and `APP_SECRET` is set in `.env.local` |
| Stop hook skipped | First deploy has no `previous_release`; normal |

After changing the unit file, always run `systemctl --user daemon-reload` before `restart`.
