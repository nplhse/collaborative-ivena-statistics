# Data flow: import → statistics

This document describes the primary data pipeline from CSV upload to analytics-ready projections.

## Import pipeline

```
UI (NewImportController; form + ImportUploadGuard reject Excel and unsupported types)
  → file stored in var/imports/
  → ImportAllocationsMessage (async_priority_high)
  → ImportAllocationsMessageHandler
       → RuleBasedRowTypeDetector (allocation | mci_case)
       → AllocationRowProcessorRegistry
       → Resolvers (Indication, Speciality, DispatchArea, …)
       → Rejects (DB or CSV, configurable)
  → Event ImportCompleted / ImportFailed
  → RebuildAllocationStatsProjection (async_priority_low)
```

Details: [../04-features/import/import-pipeline.md](../04-features/import/import-pipeline.md)

## Statistics projection

```
RebuildAllocationStatsProjectionHandler
  → AllocationStatsProjectionRebuilder (rebuildForImport)
  → ProjectionOverviewChangeDetector (new hospital?)
  → MaterializedViewRefresher (only on structural changes)
```

Details: [../04-features/statistics/projection-and-materialized-views.md](../04-features/statistics/projection-and-materialized-views.md)

## Read path

Controllers resolve a `StatisticsFilter` via `StatisticsFilterFactory` and `ComparisonScopeResolver`, then query materialized views (overview) or the projection table directly (detail).

Details: [../04-features/statistics/statistics-filter-and-scope.md](../04-features/statistics/statistics-filter-and-scope.md)

## Sequence

1. Create upload/import (`NewImportController`; upload validated before dispatch — see [upload validation](../04-features/import/import-pipeline.md#upload-validation))
2. Dispatch `ImportAllocationsMessage`
3. Process in `ImportAllocationsMessageHandler`
4. Emit domain event `ImportCompleted`
5. Dispatch statistics projection rebuild
6. Rebuild via `AllocationStatsProjectionRebuilder`
7. Refresh materialized views when hospital structure changes

## Related commands

- `app:import:allocations` — process a single import
- `app:import:requeue-all` — sequential reimport
- `app:statistics:rebuild-projection` — manual projection rebuild
- `app:statistics:refresh-mviews` — refresh materialized views

Full reference: [../06-reference/console-commands.md](../06-reference/console-commands.md)
