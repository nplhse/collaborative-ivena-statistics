# Statistics filter and scope

Most statistics pages share a common filter model resolved by `StatisticsFilterFactory` and `ComparisonScopeResolver`.

## Filter scopes

`StatisticsFilterScope` values:

| Scope | Meaning |
|-------|---------|
| `public` | Aggregated public view (anonymous or fallback) |
| `my_hospitals` | Hospitals the current user can access |
| `hospital` | Single hospital (`scope:hospital:ID`) |
| `hospital_cohort` | Cohort of hospitals |
| `state` | Federal state |
| `dispatch_area` | Dispatch area |

## Periods

`StatisticsFilterPeriod`: `all`, `all_time`, `year`, `quarter`, `month`.

## Resolution and fallbacks

`StatisticsFilterFactory` normalizes URL input and applies access rules:

- Anonymous users → `public`
- Missing hospital ID → `my_hospitals`
- No hospital access → `my_hospitals` or `public`
- Cohort too small → `public` with notice `cohort_too_small`
- State/dispatch area with fewer than 2 hospitals → `public` with `state_invalid` / `dispatch_area_invalid`

## Comparison scope

`ComparisonScopeResolver` builds a secondary filter for benchmarking and comparison views. It derives a default cohort from the primary scope's dominant location/tier via `AllocationStatsProjectionScopeQuery`.

Permission checks use `HospitalPermission::Statistics` or `HospitalPermission::Benchmarking` depending on the page.

## Entry points

- `StatisticsFilterValueResolver` — controller argument resolver
- Benchmarking controllers
- Analysis Explorer
- Case Flow dashboard

## Code locations

- `src/Statistics/Application/StatisticsFilterFactory.php`
- `src/Statistics/Application/ComparisonScopeResolver.php`
- `src/Statistics/Application/DTO/StatisticsFilterScope.php`

## Related

- [permission-model.md](../../02-architecture/permission-model.md)
- [projection-and-materialized-views.md](projection-and-materialized-views.md)
- [analysis-explorer.md](analysis-explorer.md)
