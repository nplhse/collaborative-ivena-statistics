# Dashboard overview

**Audience:** Developers changing the authenticated home page (`/`) or public homepage metrics.

The Content home dashboard is a **project overview**, not a second statistics explorer. After login it should answer:

- How large is the project?
- What was added in the last 30 days?
- What happened recently in the community?
- Where do I go next?

## Layout

Authenticated `GET /` (`DefaultController`) renders `@Content/dashboard/dashboard.html.twig`:

1. Full-width KPI cards (allocations, participating hospitals, users, imports), above the two-column layout
2. Main column: action tiles, then the project activity feed (lazy Turbo Frame)
3. Sidebar: participant notice, onboarding, latest blog posts, pages

Guests still see `@Content/public/home.html.twig`. Totals come from the same `DashboardMetricsService`.

## KPIs

`DashboardMetricsService` returns a list of `DashboardMetric` DTOs (key, value, 30-day delta, icon, translation key) so further compact stats can be appended later.

| Key | Total | 30-day delta |
|---|---|---|
| `allocations` | `COUNT(*)` on `allocation_stats_projection` | `created_at >= now() - 30 days` on the projection |
| `hospitals` | `Hospital.isParticipating = true` | participating hospitals with `created_at` in the last 30 days |
| `users` | `COUNT` on `user` | `created_at` in the last 30 days |
| `imports` | `COUNT` on `import` | `created_at` in the last 30 days |

User, hospital, and import counts run **live** on each dashboard request (small tables).

Allocation counts are the expensive path. They are stored in `cache.app` under `dashboard.allocation_counts` with a **1 hour TTL**. A cache miss runs the two projection counts once; later requests in that hour do not touch `allocation_stats_projection`. There is no scheduler job and no dedicated cache pool.

A growth of `0` is shown neutrally (no down-trend styling).

## Activity feed

The feed reads the existing `user_activity` table (`ProjectActivityQuery`). It is not an audit log.

Included types: `joined`, `first_import`, `import_milestone`, `post_published`, `comment_created`, `hospital_associated`, `hospital_owner_granted`.

Excluded: `hospital_disassociated`, `hospital_owner_revoked`. Disabled users are omitted.

The initial dashboard HTML only embeds a lazy Turbo Frame (`/dashboard/activity`). The first page (10 items) loads when that frame becomes visible. Further pages use the same keyset pagination (`occurred_at DESC, id DESC`, cursor `occurredAt` + `id`) but load only after a “Show more” click, so the rest of the dashboard (including the footer) stays reachable.

Index: `idx_user_activity_project_feed` on `(occurred_at DESC, id DESC)`.

Privacy: all `ROLE_USER` viewers see the feed. Profile and hospital links render only for `ROLE_PARTICIPANT`. Failures in the activity endpoint are logged and replaced with a compact error state; the rest of the dashboard remains usable.

## Performance

Typical **initial** dashboard request (warm allocation cache):

- Live counts for users, participating hospitals, imports (and their 30-day deltas)
- Cache get for allocation totals
- Existing onboarding, posts, and page-tree queries
- **No** `user_activity` query and **no** scan of `allocation` / `allocation_stats_projection`

Activity SQL runs only on `GET /dashboard/activity` (and subsequent cursor requests).

## Intentionally deferred

- Extra KPIs (current month, regions, data freshness, last import)
- Event for “hospital started participating”
- Cache invalidation after each import / scheduler warm-up
- Redis or a persisted snapshot table
