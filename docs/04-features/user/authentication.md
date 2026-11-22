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

## Login link

Users can sign in with a one-time link sent by email instead of their password (#1355). Every account keeps its
password; `/login/confirm` (re-authentication) always asks for it.

- **Opt-in:** `User::$loginLinkEnabled` (column `login_link_enabled`, default off), switched on in the account settings
  (`POST /settings/login-link`, needs `IS_AUTHENTICATED_FULLY`). The settings recommend two-factor authentication
  (#1357) next to the switch, without requiring it. Administrators do not get the option and cannot use a link:
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

## Account checks

`UserAccountStatusChecker` rejects disabled and unverified accounts before the password is checked, with the same
generic "Invalid credentials." as an unknown account or a wrong password. A disabled account is also signed out on its
next request. Changing the email in the settings resets `isVerified` until the new address is
confirmed. Sign-in also requires a cookie-consent decision.

## Throttling

`login_throttling` in `config/packages/security.yaml` allows 5 failed attempts per 15 minutes per IP and identifier
(Symfony's default limiter, which keys on the `UserBadge` identifier). Because emails are resolved to the username
first, a username and the email of the same account share one limit. Unknown identifiers are limited on the raw input.

## Sessions and re-authentication

- Remember-me lasts 7 days (cookie `REMEMBERME`, with the demo prefix in the demo environment, ADR 023).
- Sensitive pages require `IS_AUTHENTICATED_FULLY`. A remembered user is sent to `/login/confirm`
  (`ConfirmPasswordAuthenticator`), which always asks for the password.
- An expired password (`credentialsExpired`) leads to `app_force_change_password` after sign-in.

## Logging

`LoginFailureSubscriber` logs every failed sign-in as `security.login.failure` to the `security` channel, with a
SHA-256 hash of the lower-cased identifier, the IP, the user agent and the exception class. Successful sign-ins are not
logged. An aggregated view for administrators is planned in #1487.

## Tests

`tests/User/Functional/Controller/SecurityControllerTest.php` covers username and email login, the generic errors and
the shared throttling; `LoginLinkTest` covers the login link (opt-in, same answer for every request, rate limit,
confirmation page, single use, invalidation, consent); `RememberMeLoginTest` and `ConfirmPasswordControllerTest` cover
sessions and re-authentication.
