# Statistics

**Audience:** Developers extending analytics, dashboards, and the Analysis Explorer.

## Submodule overview

| Submodule | Route / area | Description |
|-----------|--------------|-------------|
| Projection & MVs | (background) | Denormalized `allocation_stats_projection` and materialized views |
| Top Lists | `/statistics/top-lists` | Catalog of ranked tables; detail routes under `/statistics/top-lists/{report}` (ranking depth Top 10–100 or All, page size 25/50/100, optional Scope/Period comparison). Comparison mode is one card: A/B selection, side-by-side tables, shared pagination, swap of the two sides, and continue with A or B as the regular Top List. Catalogue list/detail pages for indications, departments, specialities, assignments, occasions, infections, and secondary transports link here; rows link to Explore show pages. Secondary diagnoses share the indication catalogue. No top list exists for groups, hospitals, states, dispatch areas, or glossary terms. |
| Reports | `/statistics/reports` | Report catalog; detail routes under `/statistics/reports/{type}` (Monthly Report, Transport Time Profile) |
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
| [indication-dashboard-performance.md](indication-dashboard-performance.md) | Indication detail SQL optimisation notes |
| [overview-dashboard-performance.md](overview-dashboard-performance.md) | Overview default scope and profiler hotspots |
| [case-flow.md](case-flow.md) | Case flow dashboard |
| [hospital-population.md](hospital-population.md) | Hospital population dashboard |

## Reading order (statistics feature work)

1. [../../02-architecture/data-flow.md](../../02-architecture/data-flow.md)
2. [projection-and-materialized-views.md](projection-and-materialized-views.md)
3. [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
4. [../../03-development/testing.md](../../03-development/testing.md)
5. Feature-specific guide for your area of work

Other role-based paths: [../../README.md#reading-paths](../../README.md#reading-paths)
