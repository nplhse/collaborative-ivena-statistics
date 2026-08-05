# Usage analytics

**Audience:** Developers extending server-side usage tracking; operators rolling out consent changes.

## Scope

- Automatic request tracking into `analytics_request`
- Consent preference `analytics` (optional; essential cookies remain required)
- Usage events into `analytics_product_event` via `UsageAnalytics` (consent-gated)
- Admin insights under `/admin/operations/usage-analytics/*` (menu section above System)
- **Local only:** measurement runs on this application’s servers — no external analytics providers (no Google Analytics, Matomo cloud, etc.). Server-side error monitoring (e.g. Sentry) is separate and not controlled by this cookie preference.

Tables are created by migration `Version20260804151958` (run with normal deploy migrations).

## Privacy

Stored: route name, feature area, status, duration, DB query count/time, authenticated flag, primary role, normalized browser/device, non-empty query **parameter names**, optional pseudonymous keys, usage event names + non-PII context.

Not stored: IP, full URLs, query values, SQL, raw user agent, referrer, POST/form bodies, Symfony session id, user id.

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
| `user.email_confirmed` | Email verification |
| `user.became_participant` | Grant participant |
| `onboarding.step.completed` | Onboarding progress (`step` in context) |

## Feature areas

Resolved from Symfony route names: `home`, `dashboard`, `statistics`, `analysis`, `explore`, `import`, `export`, `admin`, `blog`, `pages`, `other`.

## Engagement depth (levels)

Derived in the dashboard (not stored). Max level per `analytics_user_key` over 30 days from events and requests:

| Level | Meaning | Signal |
|------:|---------|--------|
| 0 | Registered / low engagement | Auth/registration events without deeper use |
| 1 | Dashboard viewed | Feature area `dashboard` |
| 2 | Statistics / Explore / Import | Areas or open/import events |
| 3 | Analysis run | `analysis.explorer.run` |
| 4 | Filters used | Non-empty `query_param_names` on analysis/statistics |
| 5 | Export | `analysis.explorer.exported_csv` or area `export` |

## Onboarding funnel

Steps (unique `analytics_user_key`):

`user.registered` → `user.email_confirmed` → `user.became_participant` → `import.completed` → `analysis.explorer.run` → `analysis.explorer.exported_csv`

Conversion % is step N → N+1. **Reliable only with analytics consent + user keys**; anonymous traffic stays in request aggregates only.

## Admin insights

Menu section **Usage analytics** (above System), one page per topic:

| Page | Route | Contents |
|------|-------|----------|
| Overview | `/admin/operations/usage-analytics/overview` | Requests, DAU/WAU/MAU, feature areas, auth split, top routes |
| Adoption | `…/adoption` | Top events, events by role, role × area, engagement depth |
| Journeys | `…/journeys` | Onboarding funnel, time-to-first, entry/exit/transitions |
| Filters | `…/filters` | Filter parameter names, with/without filters by area |
| Performance | `…/performance` | Latency/queries/errors by area, slowest routes, prioritization hints |

`/admin/operations/usage-analytics` redirects to Overview. No per-user search or raw identifiers.
