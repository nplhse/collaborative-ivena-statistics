# Materialized views in tests

See the full guide: [docs/Statistics-projection-materialized-views.md](../../../docs/Statistics-projection-materialized-views.md).

Quick reminders:

- **DROP before Foundry reset** — not required with `migrate` reset (database drop removes MVs). `MaterializedViewAwareOrmResetter` only ensures views exist after migrate reset.
- **REFRESH after fixtures** — use `RefreshesStatisticsMaterializedViewsTrait::refreshStatisticsMaterializedViews()` when assertions use MV-backed queries or `StatisticsFilterFactory` state/dispatch scopes.
