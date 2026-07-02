# Indication dashboard query performance

## Problem (before optimisation)

The detail page issued **7 sequential scans** on `allocation_stats_projection`. The metrics query alone used ~74% of DB time because it:

- filtered only by scope (`hospital_id IN (...)`), not by indication in `WHERE`
- computed indication **and** baseline via 44× `FILTER (WHERE indication_normalized_id = ? / IS DISTINCT FROM ?)` on every row
- ran 4× `PERCENTILE_CONT` on the full scope set

With `period=all` and a wide hospital scope, that forces a large heap scan per request.

## Strategy (after optimisation)

### Metrics (`IndicationDashboardMetricsQuery`)

Two targeted queries:

1. **Scope totals** — one pass over the scope filter; plain `COUNT(*) FILTER (...)` columns plus baseline medians (`PERCENTILE_CONT` with `indication_normalized_id IS DISTINCT FROM :id`).
2. **Indication slice** — `WHERE indication_normalized_id = :id` plus scope; same count columns and indication medians on a small row set.

Additive baseline counts: `baseline_metric = scope_metric - indication_metric`.

### Slice dimensions (`IndicationDashboardSliceQuery`)

One `WITH slice AS (...)` CTE and `UNION ALL` aggregations for:

- gender, time series, age groups, transport-time buckets, day-time heatmap, shift heatmap

Replaces six separate round-trips.

### Scope binding

Hospital (and location/tier) filters use `IN (:array)` with `ArrayParameterType::INTEGER` instead of inlined literal lists.

### Indexes

| Index | Use case |
|-------|----------|
| `idx_asp_hospital_indication (hospital_id, indication_normalized_id)` | Slice + indication metrics when scope lists many hospitals |
| `idx_asp_indication_hospital_created (indication_normalized_id, hospital_id, created_at)` | Indication-first with period filter |
| `idx_asp_indication_weekday_shift (indication_normalized_id, created_weekday, shift_bucket_code)` | Shift heatmap |
| `idx_asp_indication_weekday_daytime (...)` | Day-time heatmap |

## Verification

Run on production-like data:

```sql
EXPLAIN (ANALYZE, BUFFERS)
-- paste scope + indication SQL from IndicationDashboardMetricsQuery / SliceQuery
```

Expect:

- **&lt;5** dashboard-related statements per request (2 metrics + 1 slice + ORM overhead)
- indication slice: index scan or bitmap on `indication_normalized_id` / `hospital_id`
- scope totals: index scan on `hospital_id` or `idx_asp_hospital_created` when period is set

## Future work

Pre-aggregated materialized view keyed by `(scope_key, indication_id)` if sub-100ms is required at any scope size.
