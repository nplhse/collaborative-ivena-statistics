# Statistics

**Audience:** Developers extending analytics, dashboards, and the Analysis Explorer.

## Submodule overview

| Submodule | Route / area | Description |
|-----------|--------------|-------------|
| Projection & MVs | (background) | Denormalized `allocation_stats_projection` and materialized views |
| GenericAnalysis | Various report pages | Shared SQL kernel for tabular reports |
| AnalysisExplorer | `/statistics/explorer` | Interactive saved-view analytics |
| Benchmarking | `/statistics/benchmarking` | Hospital comparison |
| DataQuality | Dashboard badges | Traffic-light data quality indicator |
| CaseFlow | `/statistics/case-flow` | Regional flow metrics and maps |
| HospitalPopulation | `/statistics/hospital-population` | Hospital population overview |

## Documents

| Document | Description |
|----------|-------------|
| [projection-and-materialized-views.md](projection-and-materialized-views.md) | Projection table, MV refresh, test handling |
| [statistics-filter-and-scope.md](statistics-filter-and-scope.md) | Filter scopes and comparison resolution |
| [data-quality-indicator.md](data-quality-indicator.md) | Traffic-light badge dimensions |
| [analysis-explorer.md](analysis-explorer.md) | Explorer V2 architecture and schema |
| [analysis-explorer-library-standards.md](analysis-explorer-library-standards.md) | Product standards and dashboard alignment |
| [indication-dashboard-performance.md](indication-dashboard-performance.md) | SQL optimisation notes |
| [case-flow.md](case-flow.md) | Case flow dashboard |
| [hospital-population.md](hospital-population.md) | Hospital population dashboard |

## Reading order (statistics feature work)

1. [../../02-architecture/data-flow.md](../../02-architecture/data-flow.md)
2. [projection-and-materialized-views.md](projection-and-materialized-views.md)
3. [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
4. [../../03-development/testing.md](../../03-development/testing.md)
5. Feature-specific guide for your area of work

Other role-based paths: [../../README.md#reading-paths](../../README.md#reading-paths)
