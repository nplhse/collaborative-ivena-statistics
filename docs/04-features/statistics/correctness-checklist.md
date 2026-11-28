# Statistics correctness checklist

**Audience:** Developers adding or changing an analysis surface (page, frame, table, export).

The same Scope × Period × Filter must give the same population on every surface. This page records how each surface
builds its numbers, the differences that are intentional, and what to check when a surface is added or changed. The
semantics themselves are defined in [statistics-filter-and-scope.md](statistics-filter-and-scope.md); the audit behind
this page is #579.

## How the surfaces compute their numbers

All allocation counts read `allocation_stats_projection` and filter `created_at` in `[from, toExclusive)` from
`StatisticsPeriodResolver` (Europe/Berlin bounds), except Closure Analytics and Hospital Population. The materialized
views only turn a state or cohort scope into hospital IDs; none has time buckets.

| Surface | Scope | Drawer filter | Shares | Empty denominator | Export |
|---|---|---|---|---|---|
| Overview (page, top reports, self-benchmark) | `StatisticsScopeResolver`: hospital IDs and origin dispatch area | not read | 1 decimal | counts 0, median and mean no value | – |
| Analysis Explorer | resolver plus cohort location/tier codes per row | own filters instead | 2 decimals | `no_data`, "—" | CSV runs the same runner |
| Insights (detail, compare) | resolver plus cohort codes | not read | 1 decimal | median no value | – |
| Top Lists (incl. comparison) | hospital IDs and origin dispatch area | applied, both sides | 1 decimal | no rows | CSV uses the same loader |
| Benchmarking | per side: IDs, dispatch area, cohort codes | not read | charts 1 decimal | see #1788 | – |
| Case Flow | own rule, see exceptions | applied, except the public baseline | 1 decimal | no value | – |
| Closed department assignments | resolver plus cohort codes | not read | 1 decimal | no value | – |
| Monthly report | resolver | applied to numerator and denominator | 1 decimal | closed share no value | – |
| Closure Analytics | own rule, see exceptions | own closure filter | minutes | – | CSV uses the same query |
| Hospital Population | none | none | – | – | – |

Pages that do not read the drawer do not show it either. Extending the drawer to them is #1041.

## Named exceptions

These differences are intentional. A new surface may only differ from the table above in one of these ways, or it
adds its own exception here.

- **Case Flow, state and dispatch area:** state means the origin state (`state_id` of the allocation); dispatch area
  means origin **or** catchment of the destination hospital. Its total for a dispatch area is therefore higher than on
  the other surfaces ([case-flow.md](case-flow.md)).
- **Closure Analytics:** hospital-local; it covers the hospitals the user can access, narrowed only by a hospital
  scope ([closure-analytics.md](closure-analytics.md)).
- **Hospital Population:** describes the hospitals, not a period; no scope and no period
  ([hospital-population.md](hospital-population.md)).
- **Analysis Explorer:** shares with 2 decimals; a filter on the dimension used as an axis is dropped (the form
  disables it); empty buckets are left out of totals unless the view includes them.
- **Top Lists of optional fields:** infections, secondary diagnoses and secondary transports divide by the allocations
  that have the field, not by all allocations.
- **Benchmarking:** the default comparison cohort contains the primary hospital.

## Known differences being fixed

| Issue | Difference |
|---|---|
| #1785 | Overview frames fall back to the rolling 12 months when the page shows the whole history without `period` in the URL |
| #1786 | The share in the Overview hospital summary tile is always 100 % |
| #1787 | Mean transport time includes negative durations, the time buckets do not |
| #1788 | Empty periods show 0.0 instead of no value on Benchmarking, the self-benchmark and the monthly report |
| #1789 | Overview top reports group by name, the other surfaces by ID |
| #1790 | Closure Analytics counts allocations from `allocation`, not from the deduplicated projection |
| #1724 | Allocations without an age: unknown bucket on the Overview, left out in the Explorer, part of the denominator on Benchmarking |
| #1252 | A relative change against 0 is no value on Overview and monthly report, 0.0 on Benchmarking |

Remove a row when its issue is merged, and adjust the table above if the fix changes it.

## Checklist for a new or changed surface

1. **Population:** reads `allocation_stats_projection`, or the difference is a named exception.
2. **Scope:** uses `StatisticsScopeResolver` (or `BenchmarkSqlFilter` / `GenericAnalysisScopeSqlFilter`, which build on
   it); no own state or dispatch-area rule.
3. **Period:** `StatisticsPeriodResolver` bounds on `created_at`, upper bound exclusive; frames and exports get the
   period the page resolved, not the raw request.
4. **Drawer filter:** applied to numerator and denominator, or the page does not show the drawer.
5. **Shares:** the denominator is named in the docs of the surface; a share without denominator has no value
   (no 0 %, no 100 %).
6. **Missing values:** unknown or missing values are handled as in
   [statistics-filter-and-scope.md](statistics-filter-and-scope.md#age-group-filter).
7. **Export:** the CSV uses the same query or loader as the table on screen.
8. **Test:** add the surface to `ScopePeriodFilterConsistencyTest` with numbers you can follow by hand.

## Tests

- `tests/Statistics/Integration/Consistency/ScopePeriodFilterConsistencyTest.php`: one fixture, Overview, Explorer,
  Top Lists, Benchmarking, Insights, Closed department assignments, monthly report and Case Flow, for hospital, state,
  cohort, dispatch area and public scope, a quarter, the whole history and an empty month, with and without drawer
  filter.
- `tests/Statistics/Integration/Consistency/OverviewExplorerExportConsistencyTest.php`: Overview, Explorer and its
  CSV export for one quarter, including the boundary minutes (#583).
