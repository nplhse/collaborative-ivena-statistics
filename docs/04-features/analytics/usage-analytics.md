# Usage analytics

**Audience:** Developers extending server-side usage tracking for this application.

## Scope

- Automatic request tracking into `analytics_request`
- Consent preference `analytics` (separate from Sentry `monitoring`)
- Usage events into `analytics_product_event` via `UsageAnalytics` (consent-gated)
- Admin insights under `/admin/operations/usage-analytics/*` (menu section above System)

## Privacy

Stored: route name, feature area, status, duration, DB query count/time, authenticated flag, primary role, normalized browser/device, non-empty query **parameter names**, optional pseudonymous keys, usage event names + non-PII context.

Not stored: IP, full URLs, query values, SQL, raw user agent, referrer, POST/form bodies, Symfony session id, user id.

## Consent and keys

| Situation | Requests | Usage events |
|-----------|----------|--------------|
| No analytics consent | Anonymous metrics (no keys) | **Not stored** |
| Analytics consent | + visitor/session cookies | Stored with keys |
| Consent + logged-in user | + HMAC user key via `APP_SECRET` | + HMAC user key |

## Secrets

Pseudonymous analytics user keys use HMAC-SHA256 with `APP_SECRET` (`%kernel.secret%`).

**Risk:** Rotating `APP_SECRET` changes future `analytics_user_key` values. Existing rows keep old keys, so retention across the rotation boundary breaks. Treat rotation as a deliberate continuity break.

## Recording usage events

```php
$this->usageAnalytics->record(UsageEventName::ANALYSIS_EXPLORER_RUN, FeatureArea::Analysis);
// Worker / no request:
$this->usageAnalytics->recordForUser(UsageEventName::IMPORT_COMPLETED, $user, FeatureArea::Import);
```

Event names live in `App\Analytics\Domain\UsageEventName`. Context must be non-PII (e.g. `step`, `entity`, `user_role`).

## Feature areas

Resolved from Symfony route names: `home`, `dashboard`, `statistics`, `analysis`, `explore`, `import`, `export`, `admin`, `blog`, `pages`, `other`.

## Admin insights (Phase 1)

Menu section **Usage analytics** (above System), one page per topic:

| Page | Route | Contents |
|------|-------|----------|
| Overview | `/admin/operations/usage-analytics/overview` | Requests, DAU/WAU/MAU, feature areas, auth split, top routes |
| Adoption | `…/adoption` | Top events, events by role, role × area, engagement depth |
| Journeys | `…/journeys` | Onboarding funnel, time-to-first, entry/exit/transitions |
| Filters | `…/filters` | Filter parameter names, with/without filters by area |
| Performance | `…/performance` | Latency/queries/errors by area, slowest routes, prioritization hints |

`/admin/operations/usage-analytics` redirects to Overview.
