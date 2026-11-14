# Usage analytics

**Audience:** Developers extending server-side usage tracking; operators rolling out consent changes.

## Scope

- Automatic request tracking into `analytics_request`
- Consent preference `analytics` (optional; essential cookies remain required)
- Admin insights under `/admin/operations/usage-analytics/*` (menu section above System)
- **Local only:** measurement runs on this application’s servers — no external analytics providers (no Google Analytics, Matomo cloud, etc.). Server-side error monitoring (e.g. Sentry) is separate and not controlled by this cookie preference.
- **No request performance:** usage analytics records no durations or database queries. Request performance is monitored with the optional Sentry integration ([observability-sentry.md](../../05-operations/observability-sentry.md)).

Tables are created by migration `Version20260804151958` (run with normal deploy migrations). Completed days are rolled up into daily aggregate tables; raw rows older than 30 days are deleted only after a successful rollup. See [aggregation-and-retention.md](aggregation-and-retention.md).

## Privacy

Stored: route name, feature area, HTTP status, authenticated flag, primary role, normalized browser/device, whether the request counts as an access, optional pseudonymous keys.

Not stored: IP, full URLs, query parameters (names or values), SQL, raw user agent, referrer, POST/form bodies, Symfony session id, user id.

## Cookie consent (`analytics` preference)

Preferences live in `cookie_consent.preferences` JSON: `{ essential, analytics }`. Legacy `monitoring` keys in stored JSON are ignored.

| Banner / UI action | `analytics` |
|--------------------|-------------|
| Accept all | true |
| Essential only | false |
| Preferences form | checkbox |

Banner is shown when `decidedAt` is null (`cookie_consent.decided` in Twig). Login also requires a decided consent.

### Consent version and the performance option

`cookie_consent.consent_version` records the consent texts a decision was made under; every decision is stored with
`CookieConsent::CURRENT_VERSION`.

| Version | Texts |
|---------|-------|
| `v1` | Usage analytics inside the application |
| `v2` | Adds the performance option and the notice about error reports (only shown while Sentry is used) |

**Performance option** (`preferences.performance`, sub-option of `analytics`): allows Sentry performance traces to carry
the internal user id, together with how the application was used and how it performed. It is offered only while Sentry
is used (`SENTRY_DSN` set), names the data location (`SENTRY_DATA_LOCATION` or derived from the DSN host, see
[observability-sentry.md](../../05-operations/observability-sentry.md#environment-variables)) and is only stored together
with `analytics`. "Accept all" includes it while Sentry is used; "Essential only" does not. Without Sentry the option,
the banner sentence and the error notice are not shown, and the analytics consent only covers the local usage
analytics.

**Error reports** always carry the internal user id when a user is logged in (operator's interest in running the
platform); the preferences page says so while Sentry is used. No name, username or email address is sent.

Rows decided before the option existed have no `performance` key; it counts as not given, and the banner is not shown
again, so those users send traces without user id until they change their preferences.

### Backward compatibility

Older rows may lack the `analytics` key. Missing values are treated as **`false`**. Users who already decided (including former “accept all” when only monitoring existed) therefore keep the banner hidden while **analytics stays off** until they decide again.

### Rollout: re-prompt after introducing analytics

Do **not** silently set `analytics=true` on historical “accept all” rows. Prefer asking again:

```bash
# Count first
php bin/console dbal:run-sql 'SELECT COUNT(*) AS n FROM cookie_consent'

# Reset decisions (banner shows again; subject cookie can remain)
php bin/console dbal:run-sql 'DELETE FROM cookie_consent'
```

Alternative (keep rows, clear decision):

```bash
php bin/console dbal:run-sql "UPDATE cookie_consent SET decided_at = NULL, preferences = '{\"essential\":true,\"analytics\":false}'::jsonb"
```

(`::jsonb` assumes PostgreSQL; adjust if the column type differs.)

There is no dedicated `app:…` reset command; use `dbal:run-sql` as above. See also [deployment.md](../../05-operations/deployment.md#usage-analytics-rollout).

## Consent and keys

| Situation | Requests |
|-----------|----------|
| No analytics consent | Anonymous metrics (no keys) |
| Analytics consent | + visitor/session cookies |
| Consent + logged-in user | + HMAC user key via `APP_SECRET` |

### Analytics cookies (only with analytics consent)

| Cookie | Purpose |
|--------|---------|
| `analytics_visitor` | Long-lived visitor key |
| `analytics_session` | Session key for session counts |
| `consent_subject_id` | Shared consent subject (essential; not analytics-specific) |

Without analytics consent the analytics cookies are cleared on response.

## Secrets

Pseudonymous analytics user keys use HMAC-SHA256 with `APP_SECRET` (`%kernel.secret%`).

**Risk:** Rotating `APP_SECRET` changes future `analytics_user_key` values. Existing rows keep old keys, so retention across the rotation boundary breaks. Treat rotation as a deliberate continuity break.

## Feature areas

Resolved from Symfony route names: `home`, `dashboard`, `statistics`, `analysis`, `explore`, `import`, `export`, `admin`, `blog`, `pages`, `other`.

### Admin area exclusion

Requests under the Admin UI (`app_admin_*` routes / path `/admin`) are **not** written to `analytics_request`. Normal app usage (including by users with `ROLE_ADMIN`) remains tracked.

After deploying this exclusion, remove historical admin request rows once (optional but recommended so the Overview is not skewed):

```bash
# Preview
php bin/console dbal:run-sql "SELECT COUNT(*) AS n FROM analytics_request WHERE feature_area = 'admin' OR route_name LIKE 'app_admin_%'"

# Delete
php bin/console dbal:run-sql "DELETE FROM analytics_request WHERE feature_area = 'admin' OR route_name LIKE 'app_admin_%'"
```

## Counted accesses

Every tracked request stores `analytics_request.counted_access`: whether it counts as an **access**,
that is a normal page view. Access counts are request counts after the filters below; they are not
visitors, users or sessions. The rule lives in `CountedAccessClassifier`.

A request counts when all of the following hold:

| Condition | Not counted |
|-----------|-------------|
| Method `GET` or `HEAD` | `POST`, `DELETE`, … (forms, actions) |
| Status 2xx | Redirects (3xx), client and server errors (4xx, 5xx) |
| No `Turbo-Frame` header | Frame loads and frame navigation |
| Not XHR (`X-Requested-With`) and no prefetch (`Sec-Purpose`, `Purpose`, `X-Sec-Purpose`) | Background calls, Turbo and browser prefetches |
| Device type is not `Bot` | Recognised crawlers and link previews (`UserAgentNormalizer`) |
| Route is a page route | Technical endpoints (`robots.txt`, `sitemap.xml`, RSS, health, locale switch, cookie routes), Live Components, polling and JSON endpoints, lazily loaded frames and partials (route list plus suffixes `_frame`, `_drawer`), downloads and CSV exports (`_export_csv`) |

Requests that are not tracked at all are not counted either: the Admin UI, the profiler, assets and
notification polling ([admin area exclusion](#admin-area-exclusion)). Bots that send a normal browser
user agent cannot be recognised without invasive tracking and are counted.

When a route is added that only serves a frame, a partial or a file, add it to
`CountedAccessClassifier::NON_PAGE_ROUTES` (or give it one of the suffixes). A test fails when a
listed route no longer exists.

### Rows recorded before the flag

Rows recorded before `counted_access` existed are `NULL`. Classify them once after the deploy:

```bash
php bin/console app:analytics:classify-accesses          # preview
php bin/console app:analytics:classify-accesses --apply  # write
```

Only the stored fields are available for them (status, route, device type). Method and request
headers were not recorded, so frame navigations on page routes and non-GET requests with a 2xx
response are counted for these rows. Already classified rows are never changed; running the command
again is safe. Raw rows only exist for the last `ANALYTICS_RAW_RETENTION_DAYS` days
([aggregation-and-retention.md](aggregation-and-retention.md)); older days cannot be classified.

## Removed analytics

These analyses were removed without replacement; their tables and stored rows were dropped by
migration `Version20261111213946`:

- **Usage events, adoption and engagement depth** (#989): event names recorded in controllers and
  listeners (`analysis.explorer.run`, `import.completed`, `benchmarking.opened`, …), stored in
  `analytics_product_event` and `analytics_event_daily`, and the admin page *Adoption*. The
  underlying domain events (`ImportCompleted`, `UserRegistered`, …) and their other listeners are
  unchanged.
- **Journeys** (#989): entry and exit routes and route transitions per session
  (`analytics_session_boundary_daily`, `analytics_transition_daily`) and the admin page *Journeys*.
  The session key is still stored on requests and counted on Overview ("Sessions").
- **Filter usage** (#988): derived from query parameter names, which are no longer stored
  ([Privacy](#privacy)).

## Onboarding

Onboarding is not analysed in the admin backend (#950). The onboarding steps and their progress
(`user_onboarding_step`) work as before, but completing a step records no usage event, and there is
no onboarding funnel or time-to-first report. The former events `onboarding.step.completed` and
`user.email_confirmed` are no longer recorded; stored rows were deleted by migration
`Version20261101201114`.

## Admin insights

Menu section **Usage analytics** (above System), one page per topic:

| Page | Route | Contents |
|------|-------|----------|
| Overview | `/admin/operations/usage-analytics/overview` | Requests, DAU/WAU/MAU, feature areas, auth split, top routes |
| Accesses | `…/accesses` | Counted accesses (page views) in total, per year and per month since the counting started, monthly chart |

`/admin/operations/usage-analytics` redirects to Overview. No per-user search or raw identifiers.

### Accesses page

Long-term view of [counted accesses](#counted-accesses), built by `AccessTrendService` from
`analytics_access_daily` plus raw requests of days not aggregated yet
([aggregation-and-retention.md](aggregation-and-retention.md#counted-accesses-per-day)):

- **Total** since the counting started, split into signed in and anonymous, with the start date
  ("Counted since"). Nothing before that date is counted or shown.
- **Per year and per month**, newest first, from the period the counting started in to the current
  one. A period without counted accesses shows 0.
- **Incomplete periods** are marked: the current month and year ("Not over yet") and the period in
  which the counting started ("Counted from …"). The chart draws them lighter.
- **Time zone:** days, months and years are Europe/Berlin calendar periods.

Count-based widgets (requests, feature areas, routes) combine daily aggregates with raw rows for today and any unaggregated gaps. Distinct-user metrics (DAU/WAU/MAU, sessions) stay on the raw 30-day window. Details: [aggregation-and-retention.md](aggregation-and-retention.md).
