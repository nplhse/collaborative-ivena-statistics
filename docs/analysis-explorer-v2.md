# Analysis Explorer V2

Interactive statistics explorer for ad-hoc allocation analyses. It is intentionally separate from **Generic Analysis** (saved views, library, builder) and does not share its persistence or page model.

## Where it appears

| Page | Route |
|---|---|
| Analysis Explorer (default) | `/statistics/analysis/explorer` |
| Analysis Explorer (saved view) | `/statistics/analysis/explorer/{view}` |
| Analysis library | `/statistics/analysis/library` |

Navigation label: `link.stats.analysis_explorer` in `translations/messages+intl-icu.{en,de}.xlf`.

## Purpose

- Explore allocation counts by scope, period, dimension, and chart type.
- Open predefined system demo views from the analysis library.
- Config is versioned JSON stored in `saved_explorer_view.config_json` for system views.
- In-memory editing is supported; changes are not persisted yet.
- Read-only: does not change projection data or legacy analysis pages.

## Saved views (phase 5A–5C)

System demo views are stored in `saved_explorer_view` (`SavedExplorerView` entity). Seed or refresh them with:

```bash
bin/console statistics:explorer-views:sync
```

The seeder assigns `createdBy` to the admin user (`username: admin`).

### Access model

| View type | `isSystem` | `createdBy` | Read | Save | Save As |
|---|---|---|---|---|---|
| System view | `true` | admin | everyone | no | yes (participants) |
| User view | `false` | creator | creator only | yes (creator) | yes (creator) |

User views are referenced by **numeric id** in URLs (`/statistics/analysis/explorer/{id}`). System views keep legacy slugs for seeding and backward-compatible URLs.

### Favorites

Favorites are stored in `saved_explorer_view_favorite` as a per-user relation to `saved_explorer_view` (no duplicated config).

### Library sections

The analysis library page lists **Favorites**, **My views**, and **System views**.

Loading flow:

```text
AnalysisExplorerController (saved view route)
  └─ SavedExplorerViewLoader (id or legacy slug)
       └─ ExplorerConfigMapper + URL scope/period overlay
            └─ AnalysisExplorerShell
```

Invalid saved config falls back to the default analysis and shows `stats.analysis_explorer.saved_view.invalid_config`.

## Current limitations (intentional)

- No sharing, dashboards, or recommended views.
- No hospitals data source or additional metrics beyond `allocation_count`.
- No URL-encoded config sharing.
- No delete workflow for saved views.
- No pivot feature expansion beyond results table.
- No migration of existing Generic Analysis pages.
- Default locale remains `en`; German catalog exists for explorer keys only (`messages+intl-icu.de.xlf`).

## Architecture

```text
AnalysisExplorerController
  └─ appliedConfigState (default from DefaultAnalysisViewFactory)
       └─ AnalysisExplorerShell (LiveComponent)
            ├─ ExplorerConfigMapper (state ↔ AnalysisViewConfig)
            ├─ AnalysisViewConfigNormalizer / AnalysisViewConfigValidator
            ├─ ExplorerEditFormType + ExplorerEditFormNormalizer
            ├─ AllocationsCapabilitiesProvider (scoped via GenericAnalysisDimensionPolicy)
            ├─ AllocationsAnalysisRunner → AllocationsCountQuery
            │     ├─ ExplorerAllocationQueryMapper → GenericAllocationAnalysisQuery
            │     └─ ExplorerAllocationResultMapper + AnalysisDimensionLabelResolver
            ├─ ExplorerChartPresenter
            └─ ExplorerResultsTablePresenter
```

Explorer queries reuse **Generic Analysis** aggregation (`GenericAllocationAnalysisQuery` + `DimensionRegistry` + `MetricRegistry`) via a thin bridge layer. `ExplorerAllocationAnalysisExecutor` runs SQL aggregates and `RelativeDistributionCalculator` for `percent_of_total`. Label and value formatting reuse GA `MetricValueFormatter`.

### Metric model (Phase 7)

| Category | Explorer key | GA key | Notes |
|---|---|---|---|
| Count | `allocation_count` | `count` | Primary default; charts use `visualMetric` |
| Distribution | `percent_of_total` | `percent_of_total` | Optional table column via checkbox |
| Rate | `*_rate` | same | SQL aggregate per bucket |
| Statistical | `mean_transport_time`, … | same | Registered, not enabled yet |

Multi-metric tables: `metricKeys[]` in config; charts use a single `visualMetric`. Boolean breakdown counts (e.g. CPR cases) use dimension + `allocation_count`, not a separate metric.

## How to add another allocations metric

1. Register in GA `MetricRegistry` (if not already present).
2. Add `AnalysisMetricKey` case and catalog entry; map via `registryKey()` (`allocation_count` → `count` only).
3. Add i18n `stats.analysis_explorer.metric.{key}` (EN + DE).
4. If primary-selectable: include in `primaryMetricChoices()`; statistical metrics stay `isEnabledInStepOne() === false`.
5. Add tests in `ExplorerMetricCatalogTest` and query/result mapper coverage.

No per-metric SQL in Explorer — the GA bridge handles execution.

### Matrix model (Phase 8)

Schema **v3** replaces implicit `dimension` + `grain` with explicit axes:

| Field | Role |
|---|---|
| `query.rows` | Primary axis (`dimension` + `grain`) — maps to GA `primaryDimensionKey` |
| `query.columns` | Optional series axis — maps to GA `seriesDimensionKey` |
| `query.metrics` / `visualMetric` | Unchanged from Phase 7 |
| `presentation.tableLayout` | `flat`, `matrix`, `matrix_metrics_as_rows` |

**v2 upgrade orientation:** legacy breakdown + temporal grain (e.g. `gender` + `month`) becomes `rows=time(month)`, `columns=gender` so “over time” views keep the same chart orientation.

**Execution:** flat result rows (`bucket` = row key, `seriesKey` = column key) plus `AnalysisMatrix` for chart/table presenters. `AnalysisTotals` holds grand/row/column sums for summable metrics.

**Titles:** `distribution` / `over time` / `by` naming via `ExplorerTitleFactory::titleForAxes()`.

```json
{
  "schemaVersion": 3,
  "dataSource": "allocations",
  "query": {
    "scope": { "group": "public", "detail": null },
    "period": { "type": "all", "year": null, "quarter": null, "month": null },
    "metrics": ["allocation_count"],
    "visualMetric": "allocation_count",
    "rows": { "dimension": "time", "grain": "month" },
    "columns": { "dimension": "gender", "grain": "total" }
  },
  "presentation": {
    "mode": "chart",
    "chartType": "grouped_bar",
    "tableLayout": "matrix"
  },
  "title": "Allocations by gender over time"
}
```

v1/v2 configs are upgraded on load via `ExplorerConfigMapper` + `AnalysisAxisUpgradeMapper`. Serialisation always writes v3.

UI-only LiveProps on the shell: `isEditOpen`, `configWarning`, `analysisRevision`, `appliedConfigState`, `locale`. Chart/table output is request-scoped (not persisted in LiveProps).

## Config state format (schema version 3)

Legacy v1/v2 examples are upgraded on load. Current serialisation format:

Legacy flat state (`scopeGroup`, `period`, `dimensionGrain`, …) is upgraded on load via `ExplorerConfigMapper::upgradeLegacyState()`.

`ExplorerConfigMapper::buildViewConfig()` / `filterToStateArray()` / `viewPreferencesToStateArray()` are convenience helpers for future URL or partial-state composition.

## Supported capabilities (allocations)

| Area | Values |
|---|---|
| Data source | `allocations` |
| Count metric | `allocation_count` (maps to GA `count`) |
| Distribution metric | `percent_of_total` (table column; requires `allocation_count`, breakdown + `total` grain) |
| Rate metrics | `cpr_rate`, `resus_rate`, `shock_rate`, `ventilation_rate`, `cathlab_rate`, `pregnancy_rate`, `work_accident_rate`, `with_physician_rate` |
| Statistical metrics | catalogued but disabled in Phase 7 (`mean_transport_time`, …) |
| Temporal dimension | `time` — grains: `month`, `year`, `quarter`, `week` |
| Breakdown dimensions | `gender`, `urgency`, `age_group`, `department`, `hospital_cohort`, `speciality`, `occasion`, `assignment`, `indication`, `infection`, `weekday`, `hour`, `resus`, `cathlab`, `cpr`, `ventilation`, `shock`, `workAccident`, `pregnancy`, `with_physician`, `secondary_indication`, `transport_type`, `day_time_bucket`, `shift_bucket` |
| Scope-gated breakdowns | `hospital`, `state`, `dispatchArea` (visible when `GenericAnalysisDimensionPolicy` allows for user + filter scope) |
| Grains (breakdown dims) | `total` (default); `month`, `year` for multi-series over time |
| Charts | `bar`, `line`; multi-series (breakdown + month/year): `grouped_bar`, `stacked_bar`, `line` |

Multi-series: when `columns` is set (or legacy breakdown + temporal grain), the query groups by row bucket and column series.

### System demo views (14)

Six original views (`allocations-over-time`, `gender-over-time`, …) plus eight Phase 6A demos: `age-group-distribution`, `allocations-by-weekday`, `allocations-by-department`, `transport-type-distribution`, `day-time-bucket-distribution`, `shift-bucket-distribution`, `with-physician-distribution`, `secondary-indication-distribution`.

### Discovery handoff (Phase 6A)

| Source | Notes |
|---|---|
| `DimensionRegistry` | 27 existing keys + 5 new registry entries (`secondary_indication`, `transport_type`, `day_time_bucket`, `shift_bucket`, `with_physician`) — analysis-layer only, columns already in `allocation_stats_projection` |
| Excluded | `age` (numeric/histogram), standalone `year`/`month` keys (covered by `time` + grain), `with_physician_rate` (boolean `with_physician` instead) |
| Scope policy | `hospital`, `state`, `dispatchArea` filtered via `GenericAnalysisDimensionPolicy` |

## How to add another allocations dimension

1. Register the dimension in `DimensionRegistry` (if not already present) with projection column and label metadata.
2. Add `AnalysisDimensionKey` enum case to `allocationsCatalog()`.
3. Capabilities are derived automatically; scope-gated dims need `GenericAnalysisDimensionPolicy` rules.
4. Add i18n key `stats.analysis_explorer.dimension.{key}` (EN + DE).
5. Optionally add a system demo view in `ExplorerSystemViewSeeder`.
6. Add unit tests (capabilities, query mapper, label resolver) and integration coverage.

No changes to `AllocationsCountQuery` SQL are required when the dimension is in `DimensionRegistry` — the GA bridge handles aggregation and labeling.

Grain resolution is centralized in `AnalysisDimensionGrainResolver`.

## How to add another data source later

1. Add `AnalysisDataSourceKey` and a `DataSourceCapabilities` provider.
2. Implement `AnalysisRunnerInterface` and register in `AnalysisRunnerRegistry`.
3. Add query class under `Infrastructure/Query/`.
4. Wire capabilities into normalizer/validator (today: allocations-only assumptions).
5. Extend `ExplorerConfigMapper` / default factory for the new `dataSource` value.

## Code map

| Layer | Path |
|---|---|
| Controllers | `AnalysisExplorerController.php`, `AnalysisExplorerLibraryController.php`, `SavedExplorerViewFavoriteController.php` |
| Saved views | `SavedExplorerView.php`, `SavedExplorerViewRepository.php`, `ExplorerSystemViewSeeder.php`, `SavedExplorerViewLoader.php`, `SavedExplorerViewService.php` |
| Favorites | `SavedExplorerViewFavorite.php`, `SavedExplorerViewFavoriteService.php` |
| LiveComponent | `src/Statistics/AnalysisExplorer/UI/LiveComponent/AnalysisExplorerShell.php` |
| Config | `src/Statistics/AnalysisExplorer/Application/ExplorerConfigMapper.php` |
| Normalizer / Validator | `AnalysisViewConfigNormalizer.php`, `AnalysisViewConfigValidator.php` |
| Grain resolver | `AnalysisDimensionGrainResolver.php` |
| Capabilities | `AllocationsCapabilitiesProvider.php`, `DataSourceCapabilities.php`, `ExplorerMetricCatalog.php`, `ExplorerMetricCapabilityPolicy.php` |
| Query bridge | `ExplorerAllocationQueryMapper.php`, `ExplorerAllocationResultMapper.php`, `ExplorerAllocationAnalysisExecutor.php`, `AnalysisDimensionLabelResolver.php`, `ExplorerMetricKeyMapper.php` |
| Runner / Query | `AllocationsAnalysisRunner.php`, `Infrastructure/Query/AllocationsCountQuery.php` (facade over executor) |
| Presenters | `ExplorerChartPresenter.php`, `ExplorerResultsTablePresenter.php` |
| Templates | `src/Statistics/UI/Twig/templates/analysis_explorer/`, `analysis_explorer_library/` |
| Scope/period form | `src/Statistics/UI/Form/StatisticsScopePeriodType.php` |
| Translations | `stats.analysis_explorer.*` in `translations/messages+intl-icu.en.xlf` and `.de.xlf` |

## Tests

| Type | Path |
|---|---|
| Unit | `tests/Statistics/Unit/AnalysisExplorer/` |
| Integration | `tests/Statistics/Integration/AnalysisExplorer/` |
| Functional | `tests/Statistics/Functional/Controller/AnalysisExplorerControllerTest.php`, `AnalysisExplorerLibraryControllerTest.php` |

## Related documentation

- [Statistics projection materialized views](Statistics-projection-materialized-views.md)
- [Data quality indicator](data-quality-indicator.md)
