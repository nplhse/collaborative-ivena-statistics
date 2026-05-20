# Deployment

This application is deployed with [Deployer](https://deployer.org/) using the Symfony recipe. Production runs on [Uberspace](https://uberspace.de/) with a 
**user systemd** service for the Symfony Messenger worker.

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
| `APP_SECRET` | Non-empty secret for Symfony |
| `DATABASE_URL` | PostgreSQL connection |
| `MAILER_DSN` | Outbound mail transport (SMTP or provider API); see [Transactional mail](#transactional-mail) |
| `MAILER_FROM` | Default sender address for verification, password reset, and feedback notifications |
| `MESSENGER_TRANSPORT_DSN` | Queue backend for async jobs and mail (default: `doctrine://default?auto_setup=0`) |

Optional but recommended:

| Variable | Purpose |
|----------|---------|
| `MAILER_REPLY_TO` | Reply-to address on transactional mail (leave empty to omit) |
| `APP_VERSION` | Release label stored with feedback and used as the default Sentry release |

Run database migrations so the `messenger_messages` table exists (included in the default deploy workflow).

## Transactional mail

Registration verification, password reset, and feedback admin notifications are sent through a central mail layer 
([`TransactionalMailer`](../src/Shared/Infrastructure/Mail/TransactionalMailer.php)). Templates and business logic are 
unchanged; only transport and configuration are unified.

### Required environment variables

| Variable | Example | Notes |
|----------|---------|-------|
| `MAILER_DSN` | `smtp://user:pass@smtp.example.com:587` | **Required in production.** Without a real DSN, no mail leaves the server. Use your provider’s SMTP or native DSN (Mailgun, Brevo, Amazon SES, etc.). |
| `MAILER_FROM` | `no-reply@your-domain.example` | Sender address on all transactional mail. Defaults to `no-reply@localhost` if unset — set an address your provider allows. |

The **display name** in the From header comes from `app.title` in [`config/packages/app.yaml`](../config/packages/app.yaml), not from an environment variable.

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

1. Set `MAILER_DSN` and `MAILER_FROM` in server `.env.local`.
2. Confirm the Messenger worker is active (`systemctl --user status messenger`).
3. Assign **Receives Feedback** to at least one verified admin (for feedback notifications).
4. Smoke-test: register a user (verification mail), request a password reset, submit feedback.
5. If mail does not arrive, check `messenger:stats`, `messenger:failed:show`, and `journalctl --user -u messenger -f`.

## Messenger worker (one-time server setup)

Async messages use two transports (`async_priority_high`, `async_priority_low`) configured in [`config/packages/messenger.yaml`](../config/packages/messenger.yaml). 
In production, email and domain jobs are routed to these queues; without a running worker, messages stay in the database.

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
Description=Symfony Messenger worker (high + low)
StartLimitIntervalSec=60
StartLimitBurst=5

[Service]
Type=simple
WorkingDirectory=/home/YOUR_USERNAME/www/current
Environment=APP_ENV=prod
Environment=APP_DEBUG=0

ExecStart=/usr/bin/php -d memory_limit=-1 bin/console messenger:consume async_priority_high async_priority_low --env=prod --memory-limit=256M --time-limit=3600 -q

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
php bin/console messenger:consume async_priority_high async_priority_low -vv --time-limit=30
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

   Edit `hosts.yaml` with your hostname, `remote_user`, `deploy_path` and `web_url`. The real `hosts.yaml` is gitignored.

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

Local development uses `make consume` (same transports, verbose). Mail is sent synchronously in `dev`; production 
requires the worker for queued mail.

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Deploy fails at `messenger:restart` | Unit missing or wrong name; check `messenger_systemd_service` |
| Messages stuck in `messenger_messages` | Worker not running; check `systemctl --user status messenger` |
| No verification / reset / feedback mail | `MAILER_DSN` missing or worker not consuming `async_priority_low` |
| Feedback saved but no admin email | No user with Admin + Receives Feedback (enabled, verified); see logs for `feedback.admin_mail_skipped` |
| Mail in spam | SPF/DKIM/DMARC or `MAILER_FROM` not aligned with SMTP provider |
| Worker runs old code after deploy | `WorkingDirectory` points at a fixed `releases/N` instead of `current` |
| `too many redirects` / 500 on web | Unrelated to Messenger; ensure `public/.htaccess` exists and `APP_SECRET` is set in `.env.local` |
| Stop hook skipped | First deploy has no `previous_release`; normal |

After changing the unit file, always run `systemctl --user daemon-reload` before `restart`.
