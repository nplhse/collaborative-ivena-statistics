# Transactional mail

Registration verification, password reset, and feedback admin notifications are sent through a central mail layer ([`TransactionalMailer`](../../src/Shared/Infrastructure/Mail/TransactionalMailer.php)).

Related: [../06-reference/configuration.md](../06-reference/configuration.md), [messenger-workers.md](messenger-workers.md), [deployment.md](deployment.md)

## Required environment variables

| Variable | Example | Notes |
|----------|---------|-------|
| `MAILER_DSN` | `smtp://user:pass@smtp.example.com:587` | **Required in production.** Without a real DSN, no mail leaves the server. |
| `MAILER_FROM` | `no-reply@your-domain.example` | Sender address on all transactional mail. |
| `APP_URL` | `https://your-username.uberspace.de` | **Required in production.** Base URL for password-reset links and assets in mail. |

The **display name** in the From header comes from `app.title` in [`config/packages/app.yaml`](../../config/packages/app.yaml). Set optional **`APP_TITLE`** to override per environment. The short brand label remains `app.short_title` (`COIS` by default).

## Configuring `APP_URL`

In production, transactional mail is rendered by the **Messenger worker**, not during a browser request. Symfony cannot infer your public hostname automatically — set **`APP_URL`** to the same address users type in the browser.

| Do | Don't |
|----|--------|
| `https://your-username.uberspace.de` | `http://localhost` |
| `https://statistics.example.org` | `https://example.org/app/` (no path suffix) |
| Scheme `https://` in production | Trailing slash |

**Keep it in sync with Deployer:** the value should match `web_url` in `hosts.yaml`:

```yaml
web_url: https://your-username.uberspace.de
```

```env
# shared/.env.local on the server
APP_URL=https://your-username.uberspace.de
```

After saving, clear the prod cache and restart the Messenger worker:

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

**Smoke-test mail content:**

1. Request a password reset for a verified user.
2. Confirm the reset link starts with `https://your-username.uberspace.de/reset-password/...`.
3. Confirm header images load from `https://your-username.uberspace.de/email/tabler/...`.

## Optional environment variables

| Variable | Purpose |
|----------|---------|
| `MAILER_REPLY_TO` | Reply-to address on all transactional mail |

## Feedback notifications (role-based recipients)

`FEEDBACK_ADMIN_EMAIL` is **no longer used**. Feedback admin mail is sent to users who have **both** `ROLE_ADMIN` and `ROLE_FEEDBACK_RECIPIENT` (EasyAdmin: **Receives Feedback**).

Additional requirements: account enabled, email verified, non-empty email address.

If no matching user exists, feedback is still stored but no email is sent. Check logs for `feedback.admin_mail_skipped` with reason `no_feedback_recipients`.

## Async delivery in production

In `prod`, Symfony Mailer dispatches `SendEmailMessage` to the **`async_mail`** queue (rate-limited, with exponential backoff). In `dev`, mail is sent synchronously.

Requirements:

1. **`MESSENGER_TRANSPORT_DSN`** must be configured.
2. The **Messenger worker** must be running — see [messenger-workers.md](messenger-workers.md).

## DNS and deliverability

Configure SPF, DKIM, and DMARC for the domain used in `MAILER_FROM`.

## Post-deploy mail checklist

1. Set `MAILER_DSN`, `MAILER_FROM`, and `APP_URL` in server `.env.local`.
2. Confirm the Messenger worker is active.
3. Assign **Receives Feedback** to at least one verified admin.
4. Smoke-test: register a user, request a password reset, submit feedback.
5. If mail does not arrive, check `messenger:stats`, `messenger:failed:show`, and `journalctl --user -u messenger -f`.

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| No verification / reset / feedback mail | `MAILER_DSN` missing or worker not consuming `async_mail` |
| Reset link or mail images use `http://localhost` | `APP_URL` unset or wrong; restart worker after change |
| Feedback saved but no admin email | No user with Admin + Receives Feedback |
| Mail in spam | SPF/DKIM/DMARC or `MAILER_FROM` not aligned with SMTP provider |

More symptoms: [troubleshooting.md](troubleshooting.md)
