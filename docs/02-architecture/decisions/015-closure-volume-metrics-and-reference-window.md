# ADR 015: Closure volume metrics, symmetric reference window, and incremental rebuild

**Status:** accepted

## Context

Closure Analytics compares observed allocations during and around a closure event with an expected volume derived from a reference period. The first implementation ([closure-architecture.md](../../04-features/statistics/closure-architecture.md)) has several weaknesses:

- Resource requirements (`requires_resus`, `requires_cathlab`) are counted as strata of an urgency code. A population anchor mixes the observed population with the closed urgency.
- The reference window covers eight weeks before the event only. With monthly imports, the most recent data never contributes to the reference of earlier events.
- Coverage is inferred from "the hospital had at least one allocation that day". `import.export_starts_at` / `export_ends_at` exist but are never written, so the `export_boundary` relation never matches.
- Every allocation or closure import rebuilds all events of a hospital. Each weekly partition reloads eight weeks of `allocation_stats_projection`, so overlapping data is loaded repeatedly.

The October 2026 benchmark (hospital 40, 822 events) showed that materializing hourly reference counts is cheap, that per-window loading causes query explosions, and that loading everything at once exceeds the 256 MB worker budget. Reducing the number of rebuilt events is worth more than speeding up each calculation.

## Decision

**Metrics.** Every population-hour carries five independent counters: `emergency` (SK1), `inpatient` (SK2), `outpatient` (SK3), `resus`, `cathlab`. `total = emergency + inpatient + outpatient`; resource counters are never added to the total. A population is `hospital + scope + speciality + department`, without urgency. Closure conditions (department, care level, interval) are bound to a population separately. All five metrics are always calculated for every event and population.

**Closure relevance.** Each event × population × metric result carries `closure_relevance`: `closed` (metric closed in this department), `partial` (aggregated population where only some departments were closed; the number of closed departments is stored) or `none` (calculated for comparison only). Burden and profile aggregations treat only `closed`/`partial` as closure effects.

**Reference blocking.** Blocking is metric-specific:

- SK metrics are blocked by closures of the matching urgency. Care level `other` counts as SK1–SK3.
- `resus` and `cathlab` are blocked only where SK1, SK2 and SK3 are closed simultaneously in the same department, possibly from several overlapping intervals.
- On speciality scope, a reference hour is dropped for a metric as soon as any department of the speciality blocks that metric in that hour. Counts of single departments are never subtracted from an aggregate.
- Hospital scope is not blocked.

**Reference window.** Symmetric: `[event_start − 4 weeks, event_start)` and `[event_end, event_end + 4 weeks)`. The event extent, including gaps between its segments, is excluded. Context hours (±6 h) remain part of the reference. Weekday/hour matching, the day-time bucket fallback and the minimum of four reference slots are unchanged. Reference windows are never snapped to calendar days or weeks; shared time blocks are a data-loading detail only.

**Provisional results.** An event is `provisional` while allocation coverage (`awaiting_allocations`) or closure coverage (`awaiting_closures`) does not reach `event_end + 4 weeks`. It becomes final automatically once both do. Provisional events are included in statistics with a marker and a filter.

**Coverage.** Export periods are derived conservatively and stored in `import.export_starts_at` / `export_ends_at` with `export_period_source` (`derived` | `manual`):

- Allocation imports cover whole days from the first to the last allocation.
- Closure imports cover whole days from the first start to the last end. They are extended to calendar-month boundaries only when a plausibility rule holds; monthly exports are not assumed.
- Administrators can override the period.

Under-estimated coverage only keeps events provisional longer. Over-estimated coverage could finalize events with missing blocking data.

**Processing.**

- Allocation counts are aggregated from `allocation_stats_projection` per rebuild partition, as before. A materialised hourly reference table was planned here and dropped after measuring (see Alternatives).
- Rebuild time is spent in PHP planning, writing and reference rates; these are optimised instead: closures that can influence a context hour are looked up per population and time instead of scanned, and the build table is written with `COPY`.
- Every run records seconds and SQL statements per step; `closure_rebuild_run.query_count` keeps the statement count.
- `closure_volume_hour` becomes a narrow result table keyed by `event_id, scope, speciality_id, department_id, bucket_start, metric_kind`. It keeps population and hospital observed/expected values.
- An SQL-based planner selects events whose observation or reference window intersects changed data. Results are replaced atomically per event batch.
- Closure processing runs only for hospitals with `closure_event` rows. A full rebuild remains available for recovery and migrations.

**Time.** Source timestamps are naive Europe/Berlin wall-clock values. The repeated autumn hour cannot be distinguished and is a documented limitation.

## Consequences

**Positive:**

- Urgency and resource requirements are analysed independently and consistently with the event allocation list
- Monthly imports refine earlier events instead of being ignored by their reference
- Routine imports recalculate only dependent events within a bounded memory budget
- Coverage, blocking and provisional state are explicit and visible in the UI

**Negative:**

- Results of the last four weeks (or more, with late uploads) change after later imports
- Speciality references lose hours when departments close often; tracked via `blocked_slot_count`
- Derived coverage is a heuristic and may need manual correction
- Breaking schema change for `closure_volume_hour`, its read models, chart payloads and CSV export
- Event ids of single events still change when a closure is extended across an export boundary

## Alternatives

- **Keep urgency × stratum (`base`/`resus`/`cathlab`)** — rejected; couples resource requirements to urgency and diverges from the allocation list
- **Subtract closed departments from aggregated references** — rejected; systematically lowers the expected volume because redirected patients are missing
- **Keep partially closed hours in aggregated references** — rejected; the reference would include closure-affected hours
- **Eight weeks before the event only** — rejected; does not use subsequent monthly imports
- **Load the full hospital window into PHP (benchmark variant A)** — rejected for routine imports; exceeds the worker memory budget
- **Per-window reference loading or bounded LRU cache (variants E1/E2)** — rejected; thousands of near-unique windows cause query explosion and cache churn
- **Projection state machine per hospital** — rejected for now; an existence check on `closure_event` suffices
- **Materialised hourly reference table (`closure_reference_hour`) with batched loading** — dropped on 25 October 2026 after profiling the rebuild on production data (hospitals 40, 64, 77): loading allocation counts took under 0.5 s per hospital, while planning the hour rows took 38–55 %, inserting 24–27 % and reference rates 6–19 % of 184–561 s. The table would have added refresh logic after every allocation import for about 0.1 % of the run time

## References

- [001-projection-and-materialized-views.md](001-projection-and-materialized-views.md)
- [../../04-features/statistics/closure-architecture.md](../../04-features/statistics/closure-architecture.md)
