# Messenger workers

Async messages use four transports (`async_priority_high`, `async_priority_low`, `async_mail`, `scheduler_default`) configured in [`config/packages/messenger.yaml`](../../config/packages/messenger.yaml).

In production, email uses the dedicated `async_mail` transport with rate limiting and slower retries; other domain jobs use the priority queues. Scheduled KPI aggregation uses `scheduler_default`. Without a running worker, messages stay in the database.

Related: [deployment.md](deployment.md), [transactional-mail.md](transactional-mail.md), [../02-architecture/messenger-and-scheduler.md](../02-architecture/messenger-and-scheduler.md)

## systemd setup (Uberspace)

On Uberspace you manage **user** services (not root). Create `~/.config/systemd/user/messenger.service` with the content below.

Replace:

- `YOUR_USERNAME` with your Uberspace account name
- `/home/YOUR_USERNAME/html` with your Deployer `deploy_path` if different
- `/usr/bin/php` with the output of `which php` on the server

**Important:** `WorkingDirectory` must end with `/current` (the Deployer symlink), not a fixed `releases/N` path.

```ini
[Unit]
Description=Symfony Messenger worker (high + low + mail + scheduler)
StartLimitIntervalSec=60
StartLimitBurst=5

[Service]
Type=simple
WorkingDirectory=/home/YOUR_USERNAME/www/current
Environment=APP_ENV=prod
Environment=APP_DEBUG=0

ExecStart=/usr/bin/php -d memory_limit=-1 bin/console messenger:consume async_priority_high async_priority_low async_mail scheduler_default --env=prod --memory-limit=256M --time-limit=3600 -q

Restart=always
RestartSec=5
TimeoutStopSec=300

[Install]
WantedBy=default.target
```

Notes:

- **`WantedBy=default.target`** — required on Uberspace; do not use `multi-user.target`.
- **`--time-limit=3600`** — worker exits after one hour; systemd starts a fresh process.
- **`--memory-limit=256M`** — additional guard; PHP `-d memory_limit=-1` avoids a low CLI cap.
- **`-q`** — quiet logging in production; use `journalctl` for output.
- **`StartLimitBurst`** — stops restart loops if the worker crashes on every start.

Enable and start:

```bash
systemctl --user daemon-reload
systemctl --user enable --now messenger.service
systemctl --user status messenger.service
```

After changing the unit file, always run `systemctl --user daemon-reload` before `restart`.

## Smoke test

Before relying on systemd, run the consumer manually from the deploy path:

```bash
cd ~/html/current
php bin/console messenger:consume async_priority_high async_priority_low async_mail scheduler_default -vv --time-limit=30
```

Trigger an async action in the app (for example an import or statistics rebuild) and confirm messages are processed.

## Logs

```bash
journalctl --user -u messenger -f
```

## Local development

Use `make consume` (same transports, verbose). Mail is sent synchronously in `dev`; production requires the worker for queued mail.

See [../03-development/development-workflow.md](../03-development/development-workflow.md).
