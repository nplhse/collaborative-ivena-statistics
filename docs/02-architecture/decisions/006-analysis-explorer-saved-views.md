# ADR 006: Analysis Explorer saved views (JSON schema v3)

**Status:** accepted

## Context

The Analysis Explorer allows users to save analysis configurations (metrics, dimensions, filters, chart type). Saved views must survive schema evolution as new metrics and dimensions are added.

## Decision

Store saved view configuration as versioned JSON (`SavedExplorerView`) with schema version 3. System demo views are seeded via `app:statistics:explorer-views:sync`. The `AnalysisExplorerShell` Live Component loads and executes views against tagged query mappers.

## Consequences

**Positive:**

- Users can persist and share analysis configurations
- Schema versioning allows migration of old views
- Decoupled from specific SQL queries via `ExplorerAnalysisQueryMapperInterface`

**Negative:**

- JSON schema must be maintained when adding capabilities
- Invalid legacy views require migration or graceful degradation

## Alternatives

- **URL-only state (no persistence)** — rejected; poor UX for repeated analyses
- **Per-metric hardcoded routes** — rejected; does not scale with library growth

## References

- [../../04-features/statistics/analysis-explorer.md](../../04-features/statistics/analysis-explorer.md)
- [../extension-points.md](../extension-points.md)
