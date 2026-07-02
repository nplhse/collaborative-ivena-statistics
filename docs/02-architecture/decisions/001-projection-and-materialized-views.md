# ADR 001: Projection and materialized views instead of live queries

**Status:** accepted

## Context

Statistics dashboards aggregate allocation data across hospitals, time periods, and dimensions. Running complex joins and aggregations directly on normalized allocation tables for every page view would not scale with growing multi-centre datasets.

## Decision

Introduce a denormalized projection table (`allocation_stats_projection`) rebuilt after each import, plus PostgreSQL materialized views (`mv_projection_*`) for overview-level aggregates. Materialized views are refreshed only when structural changes occur (e.g. new hospital).

## Consequences

**Positive:**

- Fast read paths for dashboards and overview pages
- Predictable query performance independent of raw allocation row count
- Clear separation between write path (import) and read path (statistics)

**Negative:**

- Data is eventually consistent until projection rebuild completes
- Additional storage and maintenance (rebuild commands, MV refresh)
- Test setup must handle MV refresh (see projection docs)

## Alternatives

- **Live queries on allocation tables** — rejected due to performance risk at scale
- **Elasticsearch / external analytics DB** — rejected as operational overhead for current deployment model

## References

- [../../04-features/statistics/projection-and-materialized-views.md](../../04-features/statistics/projection-and-materialized-views.md)
- [../data-flow.md](../data-flow.md)
