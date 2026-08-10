# Statistics filter and scope

Most statistics pages share a common filter model resolved by `StatisticsFilterFactory` and `ComparisonScopeResolver`.

## Filter scopes

`StatisticsFilterScope` values:

| Scope | Meaning |
|-------|---------|
| `public` | Aggregated public view (anonymous or fallback) |
| `my_hospitals` | Hospitals the current user can access |
| `hospital` | Allocations **to** a single hospital (`scope=hospital:ID` → `hospital_id = ID`) |
| `hospital_cohort` | Cohort of hospitals |
| `state` | Federal state via hospital attribution (see note below) |
| `dispatch_area` | Allocations **originating from** a dispatch area (`scope=dispatch_area:ID` → `dispatch_area_id = ID`) |

Hospital and dispatch area are orthogonal: destination hospital ≠ origin dispatch area. A hospital can receive cases from multiple Leitstellen; dispatch-area scope always filters projection rows by origin `dispatch_area_id`, never by expanding a 1:1 hospital portfolio.

### State scope note

`state` currently expands to hospital IDs via `mv_projection_hospital_dimensions` (`MIN(state_id)` per hospital), then applies `hospital_id IN (...)`. Whether state should mean allocation origin (`state_id` on the projection) like dispatch area is an open follow-up; do not assume the same semantics as `dispatch_area`.

## Periods

`StatisticsFilterPeriod`: `all`, `all_time`, `year`, `quarter`, `month`.

## Default scope (no `scope` query parameter)

`StatisticsFilterInputFactory` chooses the initial scope:

- Administrators (`ROLE_ADMIN`) → `public` (system-wide overview; avoids a large `hospital_id IN (...)` for all hospitals)
- Participants with hospital access → `my_hospitals`
- Everyone else / anonymous → `public`

Admins can still select `my_hospitals` explicitly via `?scope=my_hospitals`.

## Resolution and fallbacks

`StatisticsFilterFactory` normalizes URL input and applies access rules:

- Anonymous users → `public`
- Missing hospital ID → `my_hospitals`
- No hospital access → `my_hospitals` or `public`
- Cohort too small → `public` with notice `cohort_too_small`
- State/dispatch area with fewer than 2 hospitals → `public` with `state_invalid` / `dispatch_area_invalid`

Dispatch-area eligibility uses distinct hospitals that appear with that origin `dispatch_area_id` (count MV). Filtering then uses `dispatch_area_id = :id` on `allocation_stats_projection` via `StatisticsScopeCriteria.dispatchAreaId`.

## Comparison scope

`ComparisonScopeResolver` builds a secondary filter for benchmarking and comparison views. It derives a default cohort from the primary scope's dominant location/tier via `AllocationStatsProjectionScopeQuery`.

Permission checks use `HospitalPermission::Statistics` or `HospitalPermission::Benchmarking` depending on the page.

## Entry points

- `StatisticsFilterValueResolver` — controller argument resolver
- Benchmarking controllers
- Analysis Explorer
- Case Flow dashboard

## Scope choice performance

`StatisticsFilterFormChoiceProvider` builds primary/detail dropdowns for scope forms (Explorer edit drawer, benchmarking sides, statistics filter UI):

- Eligible state/dispatch-area IDs resolve to labels via repository `findNamesByIds` (one query per entity type, not N×`findById`).
- Eligible rows, cohort choices, and hospital detail choices are memoized on the provider instance for the request lifetime so repeated form rebuilds (e.g. LiveComponent `refreshEditForm`) reuse the same lists.
- Hospital summaries remain uncached across HTTP requests (user- and permission-specific).

## Code locations

- `src/Statistics/UI/Http/Controller/StatisticsFilterInputFactory.php` (default scope)
- `src/Statistics/UI/Application/StatisticsFilterFormChoiceProvider.php` (scope/period choice lists)
- `src/Statistics/Application/StatisticsFilterFactory.php`
- `src/Statistics/Application/StatisticsScopeResolver.php`
- `src/Statistics/Application/DTO/StatisticsScopeCriteria.php`
- `src/Statistics/Application/ComparisonScopeResolver.php`
- `src/Statistics/Application/DTO/StatisticsFilterScope.php`

## Related

- [permission-model.md](../../02-architecture/permission-model.md)
- [projection-and-materialized-views.md](projection-and-materialized-views.md)
- [overview-dashboard-performance.md](overview-dashboard-performance.md)
- [analysis-explorer.md](analysis-explorer.md)
