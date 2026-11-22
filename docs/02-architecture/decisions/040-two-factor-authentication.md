# ADR 040: Two-factor authentication with an authenticator app (TOTP)

**Status:** accepted

## Context

Users sign in with a username or email and their password, or with a one-time login link sent by email (#1355,
[authentication.md](../../04-features/user/authentication.md)). Accounts with access to hospital data and administrator
accounts need a stronger protection than one factor. Issue #1357 asks for a second factor through an authenticator
app (TOTP, RFC 6238: Aegis, 2FAS, Google Authenticator, 1Password and others) and for decisions on the library, who
must use it, how it works with the other sign-in paths, recovery and storage of the secrets.

Constraints:

- Symfony 8.1, PHP 8.4 in production (`deployment.md`); `sodium` is part of PHP.
- Sign-in paths in the `main` firewall: `LoginFormAuthenticator`, the `login_link` authenticator,
  `ConfirmPasswordAuthenticator` (`/login/confirm` before sensitive pages), remember-me (7 days), `switch_user` for
  administrators.
- Backups contain the database but never `.env.local` (ADR 038).
- The demo environment shares its accounts between visitors (ADR 023).
- The settings recommend two-factor authentication next to the login link opt-in, without requiring it (#1355).

## Decision

### Library

We use `scheb/2fa-bundle` with `scheb/2fa-totp` and `scheb/2fa-backup-code` (MIT, maintained since 2015, v8 supports
Symfony 8 and PHP 8.4). The bundle handles the state between the first factor and the code: a two-factor token that
grants only `IS_AUTHENTICATED_2FA_IN_PROGRESS`, the code form on `/2fa` and the check path `/2fa/check`, and it sets
the remember-me cookie only after the code. `scheb/2fa-totp` brings `spomky-labs/otphp` and uses the Symfony clock
(ADR 021). The QR code is rendered as SVG with `bacon/bacon-qr-code` 3 (`endroid/qr-code` 6 needs PHP 8.5).

### Optional for everyone, configurable for administrators

Every user can switch on two-factor authentication in the settings (`/settings/two-factor`). Hospital owners and users
with an import grant see it marked as recommended. Nobody is required to, unless an installation switches on the
obligation for administrators in the app configuration (`app.security.two_factor.required_for_admins`, default
`false`, always off in dev and demo). Then administrators without it are sent to the setup and cannot switch it off.
It is a configuration node, not an environment variable: it is a lasting policy of the installation, not a secret.

### Recovery

When the app and the backup codes are lost, an administrator resets two-factor authentication in the user list (not
for the own account), and for the last administrator an operator runs `app:user:reset-two-factor <username>`. Both
write an audit entry and tell the user with a mandatory email (notification `security.two_factor_reset`).

### When the code is asked

`scheb_two_factor.security_tokens` lists the tokens that need the code. Our authenticators and the login link create
a `PostAuthenticationToken`, so the code follows the password and the login link. It is not asked:

- for remember-me sessions (`RememberMeToken`): the cookie is only issued after the code;
- for `/login/confirm`: the session passed the code before; `SkipOnPasswordConfirmationCondition` skips it on that
  route;
- for `switch_user` (`SwitchUserToken`): the administrator passed their own sign-in.

There is no "trusted device" cookie; remember-me already covers returning on the same device.

### Brute force and replay

Wrong codes are failed logins of the firewall, so they count towards `login_throttling` (5 per 15 minutes per IP and
username) and are logged by `LoginFailureSubscriber` with the exception class of scheb. A code is accepted once
(`code_reuse_cache`); `leeway: 10` accepts ten seconds of clock drift. Codes entered in the settings have their own
limit (`two_factor_settings_code`, 5 per 15 minutes per user).

### One secret per account, backup codes

Each account has one TOTP secret; several devices use it by scanning the same QR code during setup. Setting it up again
creates a new secret. Ten backup codes of ten letters are created when it is switched on and can be renewed; each
works once. They are stored as password hashes and contain no digits, so a six-digit code is never hashed against
them.

### Secrets encrypted at rest

The secret is stored encrypted with libsodium (`crypto_secretbox`) and a dedicated key `APP_TWO_FACTOR_KEY` in
`.env.local` (`TwoFactorSecretCipher`, prefix `v1:`). A database dump or backup alone does not reveal the secrets.
The key is not derived from `APP_SECRET`, because rotating that would silently break every setup.

Without a valid key the feature is not offered: no settings section, no hint next to the login link. When accounts use
two-factor authentication and the key is missing or does not match, their secrets cannot be decrypted. Then the check
fails closed: a random stand-in secret makes every code fail, backup codes still work, and the health check reports
`two_factor` as degraded (`missing_key` or `key_mismatch`).

### Environments

The demo hides the feature (`app.demo_mode`). Tests use a fixed key in `.env.test` and compute codes with otphp.

## Alternatives considered

- **Own implementation on `spomky-labs/otphp`:** no extra bundle, but the half-authenticated state, its access rules
  and the remember-me handling would be security-critical code of our own.
- **Plain secret column:** simpler, but every database dump would carry working secrets.
- **Key derived from `APP_SECRET`:** no new variable, but a routine secret rotation would lock out all users of the
  feature.
- **Several named secrets per account:** devices could be removed one by one, but scheb's TOTP provider checks one
  configuration; it would need a provider of our own. Backup codes and a new setup cover the lost device.
- **Trusted-device cookie:** a second long-lived cookie next to remember-me with little gain.

## Consequences

- Operators set `APP_TWO_FACTOR_KEY` to offer the feature and back it up with the other secrets; losing it means users
  must sign in with a backup code and set up two-factor authentication again.
- A failed code after a login link uses up the link; the user requests a new one.
- New columns on `user`: `totp_secret_encrypted`, `totp_enabled_at`, `backup_code_hashes`. They go with the account
  when it is deleted.
- An installation that switches on the obligation must have `APP_TWO_FACTOR_KEY`; without it the obligation does not
  apply, so administrators cannot be locked into a setup that is not offered.
