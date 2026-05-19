# Materialized views in tests

See the full guide: [docs/statistics/projection-and-materialized-views.md](../../../docs/statistics/projection-and-materialized-views.md).

Quick reminders:

- **DROP before Foundry reset** — test-only `MaterializedViewAwareOrmResetter` drops `mv_projection_*` so `doctrine:schema:drop --full-database` can succeed.
- **REFRESH after fixtures** — use `RefreshesStatisticsMaterializedViewsTrait::refreshStatisticsMaterializedViews()` when assertions use MV-backed queries or `StatisticsFilterFactory` state/dispatch scopes.
