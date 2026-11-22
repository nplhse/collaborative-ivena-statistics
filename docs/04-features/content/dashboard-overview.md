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
2. Main column: action tiles, then a **recent activity preview** (lazy Turbo Frame)
3. Sidebar: participant notice, onboarding, latest blog posts (pinned posts first, [details](blog-pinned-and-announcements.md)), pages

Guests still see `@Content/public/home.html.twig`. Totals come from the same `DashboardMetricsService`.

## KPIs

`DashboardMetricsService` returns a list of `DashboardMetric` DTOs (key, value, 30-day delta, icon, translation key, optional explore/import route and query params) so further compact stats can be appended later.

The 30-day delta is **platform growth** (records added to the project), not the IVENA event date. Historical CSVs uploaded today increase the allocations delta even when the cases themselves are months old.

| Key | Total | 30-day delta |
|---|---|---|
| `allocations` | `COUNT(*)` on `allocation_stats_projection` | projection rows whose `import.created_at` is in the last 30 days |
| `hospitals` | `Hospital.isParticipating = true` | participating hospitals with `participating_since` in the last 30 days |
| `users` | `COUNT` on `user` | `created_at` in the last 30 days |
| `imports` | `COUNT` on `import` | `created_at` in the last 30 days |

### Participating hospitals

**Participating** means `Hospital.isParticipating = true` (the admin/participant checkbox). That is distinct from a hospital existing in the catalog (`created_at`) and from a hospital contributing data (first successful import). The KPI total and its historical development both use this flag.

`Hospital.participatingSince` is when the hospital **first** became participating:

- Set on the first `false → true` transition; left unchanged when participation is turned off or later turned on again.
- On create-as-participating (`PrePersist`), copied from `createdAt` when still empty.
- Editable in EasyAdmin as a manual fallback. Not shown on the participant hospital form.

`createdAt` is only the catalog row’s birth date and is **not** used for the 30-day hospital delta.

For `ROLE_PARTICIPANT`, the hospitals card links to Explore with `participating=1` so the list matches the KPI total. The dashboard next-steps tile “View all Hospitals” stays unfiltered.

#### Backfill (`app:hospital:backfill-participating-since`)

Existing participating hospitals with a null `participatingSince` can be filled once. Default is a dry-run preview; `--apply` writes. Candidates are only `is_participating = true` and `participating_since IS NULL`.

| Priority | Source | Used when |
|---|---|---|
| 1 | Earliest `audit_log` evidence | Hospital `create` with `isParticipating.new = true`, or `update` `false → true` |
| 2 | First successful import | `MIN(import.created_at)` where status is `Completed` or `Partial` |
| 3 | Leave null | No automatic `created_at` (catalog age is not participation start). Set the EasyAdmin field by hand. |

Audit of the flag exists only since the `audit_log` table (~March 2026). Earlier joins therefore fall through to the first successful import or stay empty. Reconstructed timestamps are written with SQL so the backfill does not bump `updated_at` or add audit rows.

The column can later support “new participating hospitals per month” and a cumulative first-join series without another schema change. A true stock series (“how many were participating on date X”) would also need leave dates and is out of scope.

User, hospital, and import counts run **live** on each dashboard request (small tables).

Allocation counts are the expensive path. They are stored in `cache.app` under `dashboard.allocation_counts` with a **1 hour TTL**. A cache miss runs the two projection counts once; later requests in that hour do not touch `allocation_stats_projection`. There is no scheduler job and no dedicated cache pool.

A delta of `0` is omitted from the card. Positive deltas keep the compact `+ N last 30 days` form.

For `ROLE_PARTICIPANT`, each card links to the matching Explore/Import list (`app_explore_allocation_list`, `app_explore_hospital_list?participating=1`, `app_explore_user_list`, `app_import_index`). Other authenticated users see the same totals without those links (`/explore` and `/import` require `ROLE_PARTICIPANT`).

## Activity preview and dedicated timeline

The feed reads the existing `user_activity` table (`ProjectActivityQuery`). It is not an audit log.

Included types: `joined`, `first_import`, `import_milestone`, `post_published`, `comment_created`, `hospital_associated`, `hospital_owner_granted`.

Excluded: `hospital_disassociated`, `hospital_owner_revoked`. Disabled users are omitted.

The homepage only embeds a lazy Turbo Frame (`/dashboard/activity`) with the **five** most recent items and a “View all activity” link to `GET /activity`. Pagination does not run on the dashboard endpoint.

The dedicated timeline (`app_activity_timeline`, `ROLE_USER`) reuses the same query with filters applied at SQL level. Filters sit in a left sidebar (`col-md-3`) next to the results (`col-md-9`); type and period presets are GET links that keep the other query parameters.

| Query param | Filter |
|---|---|
| `from` / `until` | `occurred_at` range (`Y-m-d`, until is inclusive of that day) |
| `type` | one value from `ProjectActivityPage::feedTypes()` |
| `user` | exact, case-insensitive username |
| `search` | `LIKE` on username and display name plus JSON `title` / `postTitle` / `excerpt` / `hospitalName` (not slugs or IDs) |
| `cursor` | keyset pagination on `/activity/feed` |
| `hospital` | public ID (UUID) of a hospital: only activities whose metadata `hospitalPublicId` matches, with the types of `ProjectActivityPage::hospitalFeedTypes()` (#488). Used by the hospital page, not shown as a filter on `/activity`; invalid values are ignored |

Further pages use keyset pagination (`occurred_at DESC, id DESC`, cursor `occurredAt` + `id`) and load when the next Turbo Frame enters the viewport (`loading="lazy"`). A fallback link remains for clients without Turbo. Filter query parameters are preserved on that request.

Index: `idx_user_activity_project_feed` on `(occurred_at DESC, id DESC)`.

### Hospital activity

The hospital detail page (`/explore/hospital/{publicId}`, `ROLE_PARTICIPANT`) shows an **Activity** card in the main column (#488). It lazy-loads `/activity/feed?hospital={publicId}` into the `activity-timeline` Turbo Frame, so items, timestamps, avatars, links and further pages are the same as on `/activity`.

- **Population:** `user_activity` rows whose metadata `hospitalPublicId` is this hospital, of the types `first_import`, `import_milestone`, `hospital_associated`, `hospital_disassociated`, `hospital_owner_granted` and `hospital_owner_revoked`. Unlike the project feed, removed access and ownership are included; the same rows are already visible to signed-in users on the actors' profiles. Disabled users are omitted, as in every feed.
- **Not included:** single imports (the projection only knows each user's import milestones; imports stay in the import list), configuration changes, audit log entries. Nothing is derived from other tables.
- The items leave out the hospital name, which is the page itself. Without activity the card shows the shared `EmptyState`.
- The filter is a `JSON_GET_TEXT(metadata, 'hospitalPublicId')` comparison without its own index; `user_activity` holds a few rows per user.

Privacy: all `ROLE_USER` viewers see the feed. Each item shows the actor's [`UserAvatar`](../../03-development/twig-components.md#useravatar) and display name (username as fallback and tooltip); the activity type is a small icon before the action text. Profile and hospital links render only for `ROLE_PARTICIPANT`. Failures in the activity endpoints are logged and replaced with a compact error state; the rest of the dashboard remains usable.

Timestamps are rendered as localized relative time (`just now`, `3 days ago`, …) via the shared `RelativeTimestamp` Twig component. The absolute instant is kept in a `<time datetime>` element and exposed on hover/focus (`title` plus a visually hidden label). Timezone is the app default `Europe/Berlin`. Sorting still uses stored `occurred_at`.

`post_published` entries show a compact content preview taken live from currently published posts on the current page (`publishedAt <= now`), using the same first-paragraph sanitizer as the blog list (`PostContentSanitizer`). The activity projection keeps only title/slug; unpublished, scheduled, deleted, or empty posts omit the preview block. Keyword search still looks at activity metadata, not the post body.

On `/activity` and the hospital feed every item has a reaction bar, loaded after the page ([reactions.md](reactions.md)); the five-item preview on the dashboard has none.

## Performance

Typical **initial** dashboard request (warm allocation cache):

- Live counts for users, participating hospitals, imports (and their 30-day deltas)
- Cache get for allocation totals
- Existing onboarding, posts, and page-tree queries
- **No** `user_activity` query and **no** scan of `allocation` / `allocation_stats_projection`

Activity SQL runs on `GET /dashboard/activity` (preview), `GET /activity`, and `GET /activity/feed` (cursor pages). When the current page contains `post_published` items, one additional query loads published posts by slug for the compact preview.

## Intentionally deferred

- Extra KPIs (current month, regions, data freshness, last import)
- Event for “hospital started participating”
- Cache invalidation after each import / scheduler warm-up
- Redis or a persisted snapshot table
- GIN / generated-column index for keyword search
