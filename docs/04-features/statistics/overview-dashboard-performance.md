# Overview dashboard performance

Notes from profiling for [issue #408](https://github.com/nplhse/collaborative-ivena-statistics/issues/408).

## Admin default scope (Aug 2026)

Administrators previously defaulted to `my_hospitals`, which resolves to **all** hospital IDs and applies `hospital_id IN (...)` across overview metrics, slice, and self-benchmark — same population as `public`, but a worse filter for the planner.

`StatisticsFilterInputFactory` now defaults admins to `public`. Participants with hospital access still default to `my_hospitals`. Explicit `?scope=my_hospitals` remains available for admins.

## Profiler snapshots

### Single hospital (earlier)

Request: `GET /statistics/?hospital=40&period=all&scope=hospital`

| Metric | Value |
|--------|-------|
| Database queries | 52 |
| Query time | ~1032 ms |

Dominant: Self-benchmark ~570 ms, metrics ~354 ms, slice ~38 ms.

### Public `period=all` (after admin default)

Request: `GET /statistics/?period=all`

| Metric | Value |
|--------|-------|
| Database queries | 50 |
| Query time | **~5347 ms** |

Dominant:

| Approx. time | Source |
|--------------|--------|
| ~3425 ms | Self-benchmark distributions (`WHERE (period OR 1=1)` full scan + large `UNION ALL`) |
| ~1671 ms | Self-benchmark core metrics (same tautological union where) |
| ~127 ms | `OverviewSliceQuery` |

Self-benchmark compares `period=all` (primary) to `all_time` (comparison). Public + `all_time` → comparison predicate `1 = 1`.

## Mitigation (Aug 2026)

1. **Lazy Turbo-Frame** `GET /statistics/overview/self-benchmark` — KPI grid + hospital insights load after first paint (same pattern as Top Reports).
2. **Sync path** only runs metrics + slice (plus `transport_type` buckets in `OverviewSliceQuery`; charts no longer need `BenchmarkReport`).
3. **Slim overview aggregation** `BenchmarkAggregationProvider::aggregateForOverview` — reduced core counters + `indication` distribution only (full provider unchanged for `/statistics/benchmarking`).

Sync query budget: `tests/Statistics/Functional/Controller/OverviewDashboardQueryCountTest.php` (max 30 queries; asserts no self-benchmark SQL on sync).

## Follow-up ideas

- Further tune metrics percentiles / indexes on `allocation_stats_projection` for public scopes
- Optional: period-bounded baseline for aggregate public (product change) to avoid comparison `1 = 1`
