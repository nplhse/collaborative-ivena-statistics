# Notzuweisungen (forced assignments)

**Audience:** Developers extending the closed-department analysis.

UI labels: **Notzuweisungen** / **Forced assignments**. Route: `GET /statistics/closed-department-assignments` (`app_stats_closed_department_assignments`).

## Definition

A closed-department assignment is a row on `allocation_stats_projection` with:

```sql
department_was_closed IS TRUE
```

The **regular** reference group is the same scope and period with:

```sql
department_was_closed IS NOT TRUE
```

This is the imported IVENA boolean `Fachbereich war abgemeldet?` (`Allocation::$departmentWasClosed`). It is a snapshot at assignment time, not a live department status. Null projection values are not counted as closed.

MCI cases have the same field but are **not** in the statistics projection and are out of scope.

Central SQL: [`DepartmentWasClosedSql`](../../../src/Statistics/Application/Mapping/DepartmentWasClosedSql.php).

## Denominators

| Metric | Numerator | Denominator |
|--------|-----------|-------------|
| Share of all assignments | closed count | all assignments in scope/period |
| Affected departments | distinct `department_id` with closed assignments | distinct `department_id` in the same scope/period |
| Ranking share | closed count for the listed entity | all closed assignments in scope/period |
| Categorical comparison (SK, flags) | feature count in group | group size (closed or regular) |
| KPI mean transport | `AVG` of precise minutes `EXTRACT(EPOCH FROM (arrival_at - created_at)) / 60` | closed rows with timestamps |
| Transport mean | `AVG` of precise minutes | closed vs regular in the transport section |
| Transport duration bars | cases in duration bucket | closed count or all assignments in scope/period |
| Time-series share | closed count in bucket | all assignments in the same bucket |

When a denominator is `0`, the UI shows `—` instead of `0 %`.

Period uses `created_at` in `[from, toExclusive)` via the shared `StatisticsPeriodResolver`. Time-series grain follows `TimeSeriesGrainResolver`.

## Comparison

Closed vs regular uses the **same** `StatisticsScopeCriteria` and `StatisticsPeriodBounds`. Gender, urgency, resources, and clinical features show the closed share plus a signed `%` delta (closed share minus regular share) versus the regular group in the same scope and period. Zero deltas are omitted. Each row has a coloured closed bar and a grey regular-share bar directly underneath, as on Benchmarking. Gender and urgency each render every legend item on its own row; gender "other" is omitted when both closed and regular counts are 0. Transport uses the difference of means in minutes and grouped percentage bars (closed vs all assignments) per duration bucket. There is no matching, weighting, or significance test.

## Drilldown

Explore links require `ROLE_PARTICIPANT`. They always include `departmentWasClosed=1` and, when the stats period has bounds, `createdFrom` / `createdToExclusive` (`created_at`, half-open). Hospital, my-hospitals, state, and dispatch-area scopes map to existing Explore filters. Public and hospital-cohort scopes do not add extra Explore hospital filters.

Department, speciality, indication, occasion, assignment-type, and infection rankings link to Top Lists with the current scope/period plus the statistics drawer filter `departmentWasClosed=1`. The page lists up to 40 rows and shows the first 10 until expanded. The layout is one 2/3–1/3 split: time series, heatmap, and a 2×3 ranking grid on the left; context cards, transport times, dispatch areas, and isochrones on the right.

The first paint loads KPIs (`ClosedDepartmentMetricsQuery::fetchKpis`: counts, distinct departments, closed mean transport) and a single `GROUPING SETS` scan for time series plus heatmap (`ClosedDepartmentSliceQuery::fetchSummary`). Gender, urgency, resources, clinical flags, and both transport means stay on `GET /statistics/closed-department-assignments/details`. The six ranking cards (department, speciality, indication, occasion, assignment, infection) load together in one lazy Turbo Frame (`GET /statistics/closed-department-assignments/rankings`), same pattern as Overview top reports. The dispatch-area list stays a separate lazy frame (`GET /statistics/closed-department-assignments/cards/dispatch-area`). Data quality uses the same lazy drawer as Overview (`dataQualityLazyLoad`, `GET /statistics/data-quality/drawer`) and is not queried on first paint. Scope/period query parameters are forwarded via `StatisticsNavigationUrlBuilder`. Empty states skip the frames.

## Monthly Report

The Monthly Report (`/statistics/reports/monthly`) includes a compact summary of the same metrics: closed count, share of all assignments, month-over-month change, affected departments, and urgency of closed assignments. It reuses `ClosedDepartmentMetricsQuery` and `DepartmentWasClosedSql` rather than a second definition. A link forwards the report's Hospital Scope and month to this detailed analysis. Months with allocations but no closed cases still show the section with zeros.

The monthly submission reminder email repeats the headline count/share and links to the Monthly Report for the same reporting month.

## Isochrones

The existing hospital-scope isochrone widget is reused with `departmentWasClosed=1`. Bands still approximate origin from recorded transport time; allocations have no incident coordinates.

## Limitations

- The flag is source data, not an independent availability check.
- Case Complexity Score does not exist and is not shown.
- Heatmap cells (weekday × 2-hour slot) do not drill down to Explore.
- Public scope shows aggregates only.

## Related

- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
- [isochrone-origin-heatmap.md](isochrone-origin-heatmap.md)
- [data-quality-indicator.md](data-quality-indicator.md)
