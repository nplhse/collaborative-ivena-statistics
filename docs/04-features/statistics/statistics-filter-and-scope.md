# Statistics filter and scope

Most statistics pages share a common filter model resolved by `StatisticsFilterFactory` and `ComparisonScopeResolver`.

## Filter scopes

`StatisticsFilterScope` values:

| Scope | Meaning |
|-------|---------|
| `public` | All assignments (unscoped aggregation; anonymous or fallback). UI label: *All assignments* / *Alle Zuweisungen*. |
| `my_hospitals` | Hospitals the current user can access |
| `hospital` | Allocations **to** a single hospital (`scope=hospital:ID` → `hospital_id = ID`) |
| `hospital_cohort` | Cohort of hospitals |
| `state` | Federal state via hospital attribution (see note below) |
| `dispatch_area` | Allocations **originating from** a dispatch area (`scope=dispatch_area:ID` → `dispatch_area_id = ID`) |

Hospital and dispatch area are orthogonal: destination hospital ≠ origin dispatch area. A hospital can receive cases from multiple Leitstellen; dispatch-area scope always filters projection rows by origin `dispatch_area_id`, never by expanding a 1:1 hospital portfolio.

**Exception:** Geographic / Case Flow analysis treats dispatch-area scope as related assignments (origin in the Leitstelle **or** hospital in the Leitstelle). See [case-flow.md](case-flow.md). Do not copy that into `StatisticsScopeResolver`.

### State scope note

`state` currently expands to hospital IDs via `mv_projection_hospital_dimensions` (`MIN(state_id)` per hospital), then applies `hospital_id IN (...)`. Whether state should mean allocation origin (`state_id` on the projection) like dispatch area is an open follow-up for most statistics pages; do not assume the same semantics as `dispatch_area`.

**Exception:** Geographic / Case Flow analysis (`CaseFlowSqlFilter`) interprets `state` as origin `allocation_stats_projection.state_id` and does not apply the destination hospital-ID expansion. That exception is local to this analysis and must not be copied into `StatisticsScopeResolver`.

## Periods

`StatisticsFilterPeriod`: `last_12_months`, `all_time`, `year`, `quarter`, `month`.

- `last_12_months`: rolling window from the first day of the month eleven months before the current one (`StatisticsPeriod::overviewPeriodStart()`), no upper bound. Example: on 2026-11-11 it starts at 2025-12-01 00:00.
- `all_time`: whole history, no lower and no upper bound.

### Identifier `all` (transition, #1017)

Up to v0.3 the identifier `all` meant the rolling 12-month window, which contradicted its name. It was renamed to `last_12_months`; labels and numbers did not change.

- **v0.3 (transition):** `LegacyAllPeriodRedirectSubscriber` redirects GET requests to statistics controllers with `period=all` or `comparison_period=all` to the same URL with `last_12_months` (302), so old bookmarks and e-mail links show the same numbers. `all` is not an enum value; inside the application it is never produced, and an unknown value falls back to the default (`last_12_months`).
- **Saved explorer views** that stored `all` were rewritten to `last_12_months` by migration `Version20261111214028`.
- **v0.4-beta:** the redirect is removed and `all` becomes the identifier for the whole history (the value of `StatisticsFilterPeriod::AllTime`).

Period is only the query window (`[from, toExclusive)` via `StatisticsPeriodResolver`). Time-series charts use a separate bucket size from `TimeSeriesGrainResolver` (`year`/`quarter`/`last_12_months`/`all_time` → month, `month` → day) and `TimeSeriesAxisFiller`:

- Monthly series omit the in-progress calendar month, even if it already has rows.
- Daily series (month period) include today and leave later days off the axis; past days without cases stay `0`.
- KPIs and totals still count the selected period, including the current month.

Tests: `tests/Statistics/Unit/Application/TimeSeries/TimeSeriesGrainResolverTest.php`, `TimeSeriesAxisFillerTest.php`.

## Default scope (no `scope` query parameter)

`StatisticsFilterInputFactory` chooses the initial scope:

- Administrators (`ROLE_ADMIN`) → `public` (system-wide overview; avoids a large `hospital_id IN (...)` for all hospitals)
- Participants with hospital access → `my_hospitals`
- Everyone else / anonymous → `public`

Admins can still select `my_hospitals` explicitly via `?scope=my_hospitals`.

## Default period (no `period` query parameter)

`StatisticsFilterInputFactory` defaults to `last_12_months` (rolling 12 months from `StatisticsPeriod::overviewPeriodStart()`). Explicit `?period=all_time` remains the full-history window. Invalid period strings fall back to `last_12_months` in `StatisticsFilterFactory::parsePeriod()`.

Overview (`GET /statistics/`) is the exception: `OverviewDefaultPeriodResolver` uses `last_12_months` only when at least 7 months in that window have data; otherwise it keeps `all_time`. When the resolver chooses `last_12_months` and the URL has no `period`, `DashboardController` redirects to `?period=last_12_months`.

## Resolution and fallbacks

`StatisticsFilterFactory` normalizes URL input and applies access rules:

- Anonymous users → `public`
- Missing hospital ID → `my_hospitals`
- No hospital access → `my_hospitals` or `public`
- Cohort with fewer than 3 hospitals → `public` with notice `cohort_too_small`
- State/dispatch area with fewer than 3 hospitals → `public` with `state_invalid` / `dispatch_area_invalid`

The minimum of 3 hospitals is `ScopePrivacyPolicy::MIN_HOSPITALS`. With two hospitals, a participant who knows the figures of their own hospital could subtract them from the scope total and get those of the other one (#934). The scope choices in the filter only offer states, dispatch areas and cohorts that reach the minimum, and Case Flow uses the same minimum for destination pools.

Dispatch-area eligibility uses distinct hospitals that appear with that origin `dispatch_area_id` (count MV). Filtering then uses `dispatch_area_id = :id` on `allocation_stats_projection` via `StatisticsScopeCriteria.dispatchAreaId`.

## Comparison scope

`ComparisonScopeResolver` builds a secondary filter for benchmarking and Top Lists comparison views. It derives a default cohort from the primary scope's dominant location/tier via `AllocationStatsProjectionScopeQuery`.

Insights Compare does **not** use that default cohort. Side A uses the header/primary filter (`scope`, `period`, …). Side B uses existing `comparison_*` query keys when present and otherwise copies the primary filter. After the compare dialog is applied, `comparison_*` is stored explicitly.

Permission checks use `HospitalPermission::Statistics` or `HospitalPermission::Benchmarking` depending on the page.

## Age group filter

The filter drawer and the Analysis Explorer share one age-group filter: URL key `age_group`
(`StatisticsDrawerFilter::ageGroup`), Explorer field `filterAgeGroup` (saved views store it in
`configJson.query.filters[]` with `dimensionKey: age_group`). Values and SQL conditions live in
`StatisticsAgeGroupFilter`; the choice list comes from `AnalysisFilterChoiceProvider::ageGroupChoices()`.

| Value | UI label (EN / DE) | Condition |
|-------|--------------------|-----------|
| *(empty)* | All / Alle | No age restriction; cases without age are counted |
| `under_18` | Children & adolescents (under 18) / Kinder & Jugendliche (unter 18) | `age IS NOT NULL AND age < 18` |
| `adults` | Adults (18 and over) / Erwachsene (ab 18) | `age IS NOT NULL AND age >= 18` |
| `over_80` | 80 and older / 80 und älter | `age >= 80` |
| `0_17`, `18_29` … `90_99` | Age buckets of `StatisticsAgeGroupBucketSql` | `age` between the bounds |

The adult threshold is 18 (#181): a patient aged 18 counts as an adult. The age buckets follow it: the
Explorer `age_group` dimension, the filter and Benchmarking, Insights, Overview and Case Flow all use
`StatisticsAgeGroupBucketSql` (`0_17`, `18_29`, `30_39`, …), so 18-year-olds are in `18_29` everywhere (#1040).

**Pre-#1040 keys (deprecated).** Until #1040 the Explorer used `0_18` and `19_29`. Links that still
carry them are read as `0_17` and `18_29` (`StatisticsAgeGroupFilter::normalizeKey()`, applied to the
URL, the Explorer assistant and stored Explorer filters); migration `Version20261113144614` rewrote
saved views. The mapping is removed in v0.4-beta.

**Missing age.** Cases without a recorded age cannot be assigned to an age group, so every
age-group value excludes them, from numerator and denominator alike. They only count when no age
group is selected. There is no "unknown" option. The drawer and the Explorer show this as a hint
under the age-group field. Today the import rejects rows without an age (`AllocationRowDTO`, range
1–99), but `allocation_stats_projection.age` is nullable, so the rule is part of the filter
definition and covered by tests.

**Where it applies.** Every query on Top Lists (including CSV export; the comparison side gets the
same filter), Case Flow (the public baseline used for insights stays unfiltered) and the Analysis
Explorer (including export; dropped when `age_group` is a row or column axis). The monthly report
shows the drawer but applies the filter only to part of its numbers. Overview, Benchmarking and
Insights (including Compare) do not read `age_group`.

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

- `src/Statistics/UI/Http/Controller/StatisticsFilterInputFactory.php` (default scope and period)
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
