# Authentication

**Audience:** Developers changing sign-in, login links, sessions or account security.

Roles and hospital permissions are described in the [permission model](../../02-architecture/permission-model.md).
This page covers how users prove who they are.

## Sign-in identifiers

Users sign in on `/login` with their **username or email address** and their password (#1355).

- `LoginFormAuthenticator` treats an identifier containing `@` as an email. Usernames cannot contain `@`
  (`UserUsernameConstraints::PATTERN`), so the two never collide. The email is looked up in lower case;
  `User::setEmail()` stores emails lower-cased and trimmed.
- A found email is replaced by the account's username before the `UserBadge` is built. The user identifier
  (`User::getUserIdentifier()`) is always the username: sessions, remember-me cookies and `switch_user` use it.
  An unknown email stays as entered and fails like an unknown username.
- Username logins are matched exactly by the entity provider (`property: username`).
- From version 1.0 (#1356), the login field accepts email addresses only. The internal identifier stays the username, so
  sessions, remember-me cookies and login links survive the switch. Until then, the login page, the account settings and
  the participation welcome mail announce the switch (`message.login.email_switch_hint` under the login field,
  `message.login.email_switch_notice` elsewhere). Administrators can find accounts without a verified email with the
  "Email verified" filter in the user list.

## Settings: Sign-in & security

"Sign-in & security" (`/settings/security`, #1508) is an area of the user settings with three pages below it: the
password (`/settings/password`), the login link (`/settings/login-link`, not for administrators) and two-factor
authentication (`/settings/two-factor`, where it is available). Its own page lists the three with their status (on /
off, "recommended" for two-factor authentication) and links to each page. `SettingsNavigationExtension` defines the
area and its sub-items for the overview tiles and the settings navigation.

## Login link

Users can sign in with a one-time link sent by email instead of their password (#1355). Every account keeps its
password; `/login/confirm` (re-authentication) always asks for it.

- **Opt-in:** `User::$loginLinkEnabled` (column `login_link_enabled`, default off), switched on its own settings page
  below "Sign-in & security" (`GET /settings/login-link`, #1508; `POST /settings/login-link`, needs
  `IS_AUTHENTICATED_FULLY`). The page shows the status prominently (badge and status row) and recommends two-factor
  authentication
  ([below](#two-factor-authentication)) next to the switch, without requiring it, when it is available. Administrators do not get the option and cannot use a link:
  `User::isLoginLinkAllowed()` is false for `ROLE_ADMIN`.
- **Request:** `/login/link` (own page) and the second button of the login form ("Send me a sign-in link", posts
  `login[username]` to the same route without a password). Username or email are accepted, with the same rule as the
  password login. A mail goes only to enabled, verified, opted-in non-admin accounts, always to the stored address.
  Every request ends on `/login/link/sent` with the same text, so the form reveals nothing about accounts.
- **Rate limits:** `login_link_request` (5 per 15 minutes per IP) and `login_link_request_identifier` (3 per 15
  minutes per entered identifier, hashed). Exceeding one sends nothing and logs `security.login_link.rate_limited`.
- **Link:** Symfony's `login_link` in the `main` firewall: `lifetime: 900` (15 minutes,
  `LoginLinkController::LIFETIME_MINUTES` for the texts), `max_uses: 1` (used links in `cache.app`),
  `signature_properties: [email, password, loginLinkAllowed]`. Changing the email or the password, switching the
  opt-in off or becoming an administrator invalidates open links. The URL carries the username, never the email.
- **Confirmation page:** the link opens `/login/link/check` (GET), which shows a "Sign in" button. It offers no
  remember-me: users who sign in by link can request a new one. For a valid link it also shows the avatar, display name
  and username (`UserAvatar` with `revealDisplayName`): the link is checked with the same signature without using it up,
  and whoever holds a valid link can sign in anyway. Invalid or expired links show only the username from the URL. Only
  the POST signs in (`check_post_only`), so mail scanners that open links in advance cannot use them up.
  `LoginLinkConsentListener` requires a cookie-consent decision before the link is consumed. Invalid, used or expired
  links lead back to the login page with an error.
- **Mail:** `@User/security/login_link_email.html.twig` through `TransactionalMailer::sendLoginLinkEmail()` and the
  `async_mail` transport. The mail worker must run, otherwise links expire before they arrive.

## Two-factor authentication

Users can protect their account with a six-digit code from an authenticator app (#1357,
[ADR 040](../../02-architecture/decisions/040-two-factor-authentication.md)). It is optional for everyone.

- **Bundle:** `scheb/2fa-bundle` with TOTP and backup codes (`config/packages/scheb_2fa.yaml`, `two_factor` in the
  `main` firewall). After the password or the login link, an account with two-factor authentication gets a two-factor
  token and can only reach `/2fa` (code form, `@User/security/two_factor.html.twig`), `/2fa/check` and `/logout` until
  the code is entered.
- **Not asked** for remember-me sessions (the cookie is set only after the code), on `/login/confirm`
  (`SkipOnPasswordConfirmationCondition`; it never confirms a sign-in that still waits for its code, #1689) and when an
  administrator impersonates a user.
- **Wrong codes** are limited per account by `two_factor_code` (5 per 15 minutes, sliding window,
  `TwoFactorFailureThrottlingListener`, #1691): every code on `/2fa/check` takes a token before it is checked, and
  only an accepted code resets the limit. A password login or a login link does not, and a new IP or session does
  not help either. They also count towards `login_throttling` ([Throttling](#throttling)) and are logged as
  `security.login.failure` with `InvalidTwoFactorCodeException` (or `TooManyLoginAttemptsAuthenticationException`
  once a limit is reached). Someone who knows the password can therefore block the code form of that account for
  up to 15 minutes; the account owner then waits or uses the same limit after it expires. A code is accepted once
  (`code_reuse_cache`); `leeway: 10` seconds.
- **Setup:** `/settings/two-factor` (needs `IS_AUTHENTICATED_FULLY`) shows a QR code and the key; the first code
  switches it on. Several devices scan the same QR code; setting it up again creates a new secret. The pending secret
  stays in the session (encrypted) until the code is confirmed.
- **Backup codes:** ten codes of ten letters (`BackupCodeGenerator`), shown once after switching on or renewing, stored
  as password hashes (`user.backup_code_hashes`). Each works once on the code form.
- **Changes** (renew backup codes, switch off) need a current code or a backup code, a CSRF token and stay within
  `two_factor_settings_code` (5 per 15 minutes per user). Audit intents: `user.settings.two_factor_enabled`,
  `user.settings.two_factor_backup_codes_renewed`, `user.settings.two_factor_disabled`.
- **Secret storage:** `user.totp_secret_encrypted`, encrypted with `APP_TWO_FACTOR_KEY` (`TwoFactorSecretCipher`,
  libsodium) and decrypted after loading (`TwoFactorSecretLoader`); the decrypted secret is never serialized into the
  session.
- **Recommended** for administrators without two-factor authentication: the settings overview marks the "Sign-in &
  security" tile and its overview page the two-factor row (`TwoFactorRecommendationQuery`). Until #1508 hospital owners
  and users with an import grant were marked too; as they are most of the members, the recommendation is limited to
  administrators for now and the wider group is evaluated later.
- **Obligation for administrators:** `app.security.two_factor.required_for_admins` in `config/packages/app.yaml`
  (default `true` since #1567, forced off in dev, demo and test). When on and the feature is available, `RequireTwoFactorListener` sends
  administrators without it to `/settings/two-factor` on every page load (other requests get a 403) and they cannot
  switch it off. Setup, password confirmation, logout, cookie and language choice stay reachable. It also checks
  requests that sign in through the remember-me cookie, and an administrator without it who impersonates a user is
  sent out of the impersonation first (#1689).
- **Reset:** administrators reset it for other accounts on the user's detail page in EasyAdmin (POST with CSRF token,
  `IS_AUTHENTICATED_FULLY`); for the last administrator an operator runs `app:user:reset-two-factor <username>`.
  `TwoFactorReset` switches it off, writes the audit intent `user.two_factor.reset_by_admin` or
  `user.two_factor.reset_by_console` and sends the notification `security.two_factor_reset` with a mandatory email.
- **Availability:** offered only with a valid `APP_TWO_FACTOR_KEY` (64 hex characters) and never in the demo
  (`TwoFactorAvailability`). If accounts use it and the key is missing or wrong, codes fail and only backup codes work;
  the `two_factor` health check reports it ([health-check.md](../../05-operations/health-check.md)).

## Registration

Self-registration on `/register` does not reveal whether an email address already has an account (#936, decision in
the issue). Usernames are public in Explore, so a taken username is still shown as a form error
(`validation.registration.username_taken`, `RegistrationIdentityGuard::isUsernameTaken()`).

- **New address:** the account is created unverified and gets the verification mail; the visitor is sent to
  `/register/check-email`.
- **Address of an existing account** (compared lower-cased): no account is created and the visitor gets the same
  redirect to `/register/check-email`. `ExistingAccountRegistrationNotifier` mails the existing account instead
  (`@User/registration/registration_attempt_email.html.twig`): someone tried to register with this address, its
  username, a link to the password reset and to the login, and the confirmation that nothing was changed. The password is hashed in both cases so the answer time
  stays similar; both mails go through `async_mail`.
- **Flooding:** at most one such mail per account within 24 hours (`registration_attempt_notice`); further attempts
  get the same answer, no mail, and a `security.registration.existing_email_notice_limited` log entry. The
  `register` limit (5 per hour per IP) applies first.
- **Race:** if another registration takes the address between the check and the flush, the unique constraint is
  answered the same way; a taken username shows the username error.
- Blocked email domains (`RegistrationEmailSuppressionPolicy`) are answered with the same redirect and send nothing.
- Accounts created by administrators (EasyAdmin, `app:user:create`) still report a taken username or email
  (`isIdentityTaken()`).

## Terms of use acceptance

Registration requires accepting the terms of use (#1564, [ADR 043](../../02-architecture/decisions/043-terms-of-use-version-by-configuration.md)).
`RegistrationController` stores the acceptance as a `TermsAcceptance` row in the same flush as the account:
kind `terms`, the version from `APP_TERMS_VERSION` (empty while none is set), the time, the language of the shown terms
page and the SHA-256 of its published content. The page comes from Content through `TermsDocumentProviderInterface`,
with the same language fallback as the page itself; without a published terms page the hash stays empty.

Rows are only added and are deleted with the account. Admins see the latest acceptance on the user detail page and,
per person with access, on the hospital detail page, together with how many of them accepted the current version.
Accounts created by an administrator or before #1564 have no row.

**Login prompt.** While `APP_TERMS_VERSION` is set, `RequireTermsAcceptanceListener` (request listener, priority 1,
after the firewall) sends signed-in users without a row for that version to `/terms/accept` (`app_terms_accept`). The
page names the version, links the terms page and asks for a checkbox; accepting stores a row and returns to the
page the user asked for. Until then:

- Only the acceptance page, logout, the language and cookie choice and the CMS pages with the keys `terms`, `imprint`
  and `privacy` are reachable. Page loads are redirected; forms and background requests get a 403.
- Administrators who impersonate a user, users in the middle of the two-factor login and stateless routes (media files)
  are not affected. Requests that sign in through the remember-me cookie are checked like those with a session (#1689).
- The terms come before the two-factor obligation: `RequireTwoFactorListener` lets the acceptance page through, so an
  administrator first accepts and then sets up two-factor authentication.
- `TermsAcceptanceStatus` remembers account and accepted version in the session, so the table is read once per
  session and again after the version changes.

**Upload authority** (#1565): the same table holds the confirmation that a person may upload data for a hospital,
kind `upload_authority` with the hospital (deleted with the hospital as well). It is asked in the upload form, once
per hospital and terms version ([import-pipeline.md](../import/import-pipeline.md#who-may-upload)); the hospital
detail page in the admin backend shows it for the owner and for every grant with the import permission. After a new
version, the acceptance page offers an optional second checkbox for the hospitals confirmed under an earlier version
("I am still authorised to upload data for …"); ticking it stores the upload authority for the new version in the same
step, so nobody confirms twice. Without it the upload form asks again before the next upload.

Raising the version asks everyone again at their next request (the variable is read at runtime); editing the terms page does not. The demo and the test
environment have no version; tests of the prompt set one (`TermsAcceptanceAtLoginTest`).

## Account checks

`UserAccountStatusChecker` rejects disabled and unverified accounts before the password is checked, with the same
generic "Invalid credentials." as an unknown account or a wrong password. A disabled account is also signed out on its
next request. Changing the email in the settings resets `isVerified` until the new address is
confirmed. Sign-in also requires a cookie-consent decision.

## Throttling

`login_throttling` in `config/packages/security.yaml` allows 5 failed attempts per 15 minutes per IP and identifier
(Symfony's default limiter, which keys on the `UserBadge` identifier). Because emails are resolved to the username
first, a username and the email of the same account share one limit. Unknown identifiers are limited on the raw input.
A successful password login resets this limiter, so two-factor codes have their own per-account limit
`two_factor_code` ([Two-factor authentication](#two-factor-authentication)). Codes in the two-factor settings use
`two_factor_settings_code` (5 per 15 minutes per user).

## Account lock

`login_throttling` does not stop many IPs from guessing one account. `AccountLock` (#1762) therefore counts wrong
passwords per account across all IPs in `login_account_failure` (sliding window, 20 per hour). The 20th locks the
account for three hours (`AccountLock::LOCK_DURATION`, column `user.login_locked_until`):

- `AccountLockListener` refuses sign-ins with the password form and the login link while the account is locked, before
  the password is checked, with the same "Invalid credentials." as a wrong password, so the form does not reveal the
  account or the lock. Password confirmation of a signed-in user and two-factor codes are not affected.
- Only a wrong password for an enabled, verified account counts. Unknown identifiers, CSRF errors, throttling and
  refusals for disabled or unverified accounts do not, and failures during a lock neither extend it nor count.
- The lock ends on its own after three hours, or when the owner resets the password (`ResetPasswordController`, audit
  intent `user.password_reset_completed` with `login_lock_lifted`). Lifting a lock from the admin area follows with
  #1487.
- Locking writes the audit intent `user.login.account_locked`, logs `security.login.account_locked` (user id only) and
  tells the owner once per lock with the notification `security.account_locked` (in-app and a mandatory email), which
  points to the password reset.

The lock is **off by default** (`APP_ACCOUNT_LOCK_ENABLED=false`, [configuration](../../06-reference/configuration.md)).
Off, wrong passwords are counted the same way, but reaching the threshold only logs
`security.login.account_lock_threshold_reached` (user id only, `security` channel) and starts counting again: nothing is
locked, nobody is told and no audit entry is written. This shows how often such attacks happen before sign-ins are
refused. An existing `login_locked_until` is ignored while the lock is off. The test environment switches it on.

## Sessions and re-authentication

- Remember-me lasts 7 days (cookie `REMEMBERME`, with the demo prefix in the demo environment, ADR 023). Its signature
  covers `password` and `totpEnabledAt`, so changing the password or switching two-factor authentication on, off or
  resetting it ends remembered sessions (#1689).
- Sensitive pages require `IS_AUTHENTICATED_FULLY`. A remembered user is sent to `/login/confirm`
  (`ConfirmPasswordAuthenticator`), which always asks for the password.
- An expired password (`credentialsExpired`) leads to `app_force_change_password` after sign-in.

## Logging

`LoginFailureSubscriber` logs every failed sign-in as `security.login.failure` to the `security` channel, with a
SHA-256 hash of the lower-cased identifier, the IP, the user agent and the exception class. Successful sign-ins are not
logged. These entries are not sent to Sentry (#1577); an aggregated view for administrators is planned in #1487.

## Tests

`tests/User/Functional/Controller/SecurityControllerTest.php` covers username and email login, the generic errors and
the shared throttling; `LoginLinkTest` covers the login link (opt-in, same answer for every request, rate limit,
confirmation page, single use, invalidation, consent); `TwoFactorLoginTest`, `TwoFactorSettingsTest`,
`TwoFactorObligationAndResetTest`, `SignInGatesTest` and `ResetTwoFactorCommandTest` cover the second factor;
`AccountLockTest` (unit: counting, duration, expiry, clearing; functional: many IPs, password reset) covers the account
lock; `RegistrationControllerTest` covers the registration answers for new and existing addresses; `RememberMeLoginTest` and `ConfirmPasswordControllerTest` cover
sessions and re-authentication.
