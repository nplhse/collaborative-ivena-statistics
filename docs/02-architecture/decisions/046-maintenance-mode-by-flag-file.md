# ADR 046: Maintenance mode by a shared flag file

**Status:** accepted

## Context

A deploy runs the database migrations while the previous release still serves requests, and an upgrade often has
one-time commands after the switch (geodata takeover, backfills, classification runs). Until now users could work
during both phases: requests to the old release could fail on a changed schema, and users saw the new release before
its data was complete. Administrators had no protected window to run the commands and check the application (#1680).

Options considered:

1. **Web-server page.** Deployer points the Uberspace DocumentRoot symlink at a static directory during the deploy.
   Independent of PHP, the database and the release, but it blocks administrators and `/health` as well, and cannot
   be tested locally.
2. **Environment variable** in `shared/.env.local`, checked by a request listener. Same reach as a flag file, but
   switching means editing the configuration file with all secrets.
3. **Flag file** in a shared directory, checked by a request listener.

## Decision

1. **A flag file switches the mode.** `var/maintenance/enabled` lies in a directory Deployer shares between releases,
   so the old and the new release see the same switch. `app:maintenance on|off|status` is the only interface;
   Deployer has `maintenance:on` and `maintenance:off`.
2. **Administrators keep working.** A request listener after the firewall lets signed-in administrators through, also
   while they impersonate another user. Everyone else gets a 503 with `Retry-After` and a page without database
   reads; background requests get an empty 503. `/health`, the sign-in with its second factor, logout, the cookie and
   language choice and media files stay reachable. Console commands and the worker are not affected.
3. **The mode is hard to miss.** Administrators see a red banner with the start time in the application, and the
   health check reports `degraded` while it is on, which the admin dashboard shows.
4. **Deploys switch it on and off.** On before the migrations, off after the switch to the new release.
   `-o keep_maintenance=true` keeps it on for one-time commands. A failed deploy leaves it on.

## Consequences

**Positive:**

- Administrators can run one-time commands and check the new release before users return.
- No new configuration, no database access, one `is_file()` per request.

**Negative:**

- The listener has to be in the release that serves requests during the migrations. The first deploy with the mode
  (v0.2.x to v0.3-beta) leaves the migrations themselves unprotected; from the next deploy on the whole window is
  covered.
- If the release cannot boot at all, the listener cannot answer either. A web-server page (option 1) can still be
  added for that case.
