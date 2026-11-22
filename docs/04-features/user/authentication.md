# Authentication

**Audience:** Developers changing sign-in, sessions or account security.

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
- From version 1.0 (#1356), the login field accepts email addresses only. The internal identifier stays the username,
  so sessions and remember-me cookies survive the switch. Until then, the login page, the account settings and the
  participation welcome mail announce the switch (`message.login.email_switch_hint` under the login field,
  `message.login.email_switch_notice` elsewhere). Administrators can find
  accounts without a verified email with the "Email verified" filter in the user list.

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
the shared throttling; `RememberMeLoginTest` and `ConfirmPasswordControllerTest` cover sessions and re-authentication.
