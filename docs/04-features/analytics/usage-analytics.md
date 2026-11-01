# Usage analytics

**Audience:** Developers extending server-side usage tracking; operators rolling out consent changes.

## Scope

- Automatic request tracking into `analytics_request`
- Consent preference `analytics` (optional; essential cookies remain required)
- Usage events into `analytics_product_event` via `UsageAnalytics` (consent-gated)
- Admin insights under `/admin/operations/usage-analytics/*` (menu section above System)
- **Local only:** measurement runs on this application’s servers — no external analytics providers (no Google Analytics, Matomo cloud, etc.). Server-side error monitoring (e.g. Sentry) is separate and not controlled by this cookie preference.

Tables are created by migration `Version20260804151958` (run with normal deploy migrations). Completed days are rolled up into daily aggregate tables; raw rows older than 30 days are deleted only after a successful rollup. See [aggregation-and-retention.md](aggregation-and-retention.md).

## Privacy

Stored: route name, feature area, status, duration, DB query count/time, authenticated flag, primary role, normalized browser/device, whether the request counts as an access, optional pseudonymous keys, usage event names + non-PII context.

Not stored: IP, full URLs, query parameters (names or values), SQL, raw user agent, referrer, POST/form bodies, Symfony session id, user id.

## Cookie consent (`analytics` preference)

Preferences live in `cookie_consent.preferences` JSON: `{ essential, analytics }`. Legacy `monitoring` keys in stored JSON are ignored.

| Banner / UI action | `analytics` |
|--------------------|-------------|
| Accept all | true |
| Essential only | false |
| Preferences form | checkbox |

Banner is shown when `decidedAt` is null (`cookie_consent.decided` in Twig). Login also requires a decided consent.

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

| Situation | Requests | Usage events |
|-----------|----------|--------------|
| No analytics consent | Anonymous metrics (no keys) | **Not stored** |
| Analytics consent | + visitor/session cookies | Stored with keys |
| Consent + logged-in user | + HMAC user key via `APP_SECRET` | + HMAC user key |

### Analytics cookies (only with analytics consent)

| Cookie | Purpose |
|--------|---------|
| `analytics_visitor` | Long-lived visitor key |
| `analytics_session` | Session key for navigation paths |
| `consent_subject_id` | Shared consent subject (essential; not analytics-specific) |

Without analytics consent the analytics cookies are cleared on response.

Worker / async paths use `UsageAnalytics::recordForUser()` and persist only if that user has a stored consent row with `analytics=true`.

## Secrets

Pseudonymous analytics user keys use HMAC-SHA256 with `APP_SECRET` (`%kernel.secret%`).

**Risk:** Rotating `APP_SECRET` changes future `analytics_user_key` values. Existing rows keep old keys, so retention across the rotation boundary breaks. Treat rotation as a deliberate continuity break.

## Recording usage events

```php
$this->usageAnalytics->record(UsageEventName::ANALYSIS_EXPLORER_RUN, FeatureArea::Analysis);
// Worker / no request:
$this->usageAnalytics->recordForUser(UsageEventName::IMPORT_COMPLETED, $user, FeatureArea::Import);
```

Event names live in `App\Analytics\Domain\UsageEventName`. Context must be non-PII (e.g. `step`, `entity`, `user_role`) — **no** hospital/patient IDs or filter values.

### Event catalog

| Event | Typical hook |
|-------|----------------|
| `analysis.library.opened` | Analysis Explorer library |
| `analysis.explorer.opened` | Explorer (no saved view) |
| `analysis.saved_view.opened` | Explorer saved view |
| `analysis.explorer.run` | Explorer re-run |
| `analysis.saved_view.created` | Saved view create |
| `analysis.explorer.exported_csv` | CSV export |
| `import.started` | New import dispatch |
| `import.completed` | `ImportCompleted` listener |
| `explore.allocation.opened` / `hospital` / `indication` | Explore show controllers |
| `benchmarking.opened` | Benchmarking page |
| `user.registered` | `UserRegistered` listener |
| `user.became_participant` | Grant participant |

## Feature areas

Resolved from Symfony route names: `home`, `dashboard`, `statistics`, `analysis`, `explore`, `import`, `export`, `admin`, `blog`, `pages`, `other`.

### Admin area exclusion

Requests under the Admin UI (`app_admin_*` routes / path `/admin`) are **not** written to `analytics_request`. Product events and normal app usage (including by users with `ROLE_ADMIN`) remain tracked.

After deploying this exclusion, remove historical admin request rows once (optional but recommended so Overview/Performance are not skewed):

```bash
# Preview
php bin/console dbal:run-sql "SELECT COUNT(*) AS n FROM analytics_request WHERE feature_area = 'admin' OR route_name LIKE 'app_admin_%'"

# Delete
php bin/console dbal:run-sql "DELETE FROM analytics_request WHERE feature_area = 'admin' OR route_name LIKE 'app_admin_%'"
```

Do **not** delete `analytics_product_event` rows for this cleanup.

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

## Engagement depth (levels)

Derived in the dashboard (not stored). Max level per `analytics_user_key` over 30 days from events and requests:

| Level | Meaning | Signal |
|------:|---------|--------|
| 0 | Registered / low engagement | Auth/registration events without deeper use |
| 1 | Dashboard viewed | Feature area `dashboard` |
| 2 | Statistics / Explore / Import | Areas or open/import events |
| 3 | Analysis run | `analysis.explorer.run` |
| 5 | Export | `analysis.explorer.exported_csv` or area `export` |

There is no level 4. It was "filters used", derived from query parameter names, and was removed
without replacement because URL parameters do not show filter use (#952, #988). The other levels
keep their numbers.

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
| Adoption | `…/adoption` | Top events, events by role, role × area, engagement depth |
| Journeys | `…/journeys` | Entry/exit routes and transitions |
| Performance | `…/performance` | Latency/queries/errors by area, slowest routes, prioritization hints |

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

Count-based widgets (requests, feature areas, routes, event counts) combine daily aggregates with raw rows for today and any unaggregated gaps. Distinct-user and sequence metrics (DAU/WAU/MAU, engagement depth, journeys, p95) stay on the raw 30-day window. Details: [aggregation-and-retention.md](aggregation-and-retention.md).
