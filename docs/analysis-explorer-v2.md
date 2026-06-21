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

## Saved views (phase 5A)

System demo views are stored in `saved_explorer_view` (`SavedExplorerView` entity). Seed or refresh them with:

```bash
bin/console statistics:explorer-views:sync
```

Loading flow:

```text
AnalysisExplorerController (saved view route)
  └─ SavedExplorerViewLoader (slug or numeric id)
       └─ ExplorerConfigMapper + URL scope/period overlay
            └─ AnalysisExplorerShell
```

Invalid saved config falls back to the default analysis and shows `stats.analysis_explorer.saved_view.invalid_config`.

## Architecture

```text
AnalysisExplorerController
  └─ appliedConfigState (default from DefaultAnalysisViewFactory)
       └─ AnalysisExplorerShell (LiveComponent)
            ├─ ExplorerConfigMapper (state ↔ AnalysisViewConfig)
            ├─ AnalysisViewConfigNormalizer / AnalysisViewConfigValidator
            ├─ ExplorerEditFormType + ExplorerEditFormNormalizer
            ├─ AllocationsAnalysisRunner → AllocationsCountQuery
            ├─ ExplorerChartPresenter
            └─ ExplorerResultsTablePresenter
```

UI-only LiveProps on the shell: `isEditOpen`, `configWarning`, `analysisRevision`, `appliedConfigState`, `locale`. Chart/table output is request-scoped (not persisted in LiveProps).

## Config state format (schema version 1)

```json
{
  "schemaVersion": 1,
  "dataSource": "allocations",
  "query": {
    "scope": { "group": "public", "detail": null },
    "period": { "type": "all", "year": null, "quarter": null, "month": null },
    "metric": "allocation_count",
    "dimension": "time",
    "grain": "month"
  },
  "presentation": {
    "mode": "chart",
    "chartType": "bar"
  },
  "title": "Allocations over time"
}
```

Legacy flat state (`scopeGroup`, `period`, `dimensionGrain`, …) is upgraded on load via `ExplorerConfigMapper::upgradeLegacyState()`.

`ExplorerConfigMapper::buildViewConfig()` / `filterToStateArray()` / `viewPreferencesToStateArray()` are convenience helpers for future URL or partial-state composition.

## Supported capabilities (allocations)

| Area | Values |
|---|---|
| Data source | `allocations` |
| Metric | `allocation_count` |
| Dimensions | `time`, `gender`, `urgency` |
| Grains | `time`: month, year; `gender`/`urgency`: total, month, year |
| Charts | `bar`, `line`; multi-series (gender/urgency + month/year): `grouped_bar`, `stacked_bar`, `line` |

Multi-series: when dimension is not `time` and grain is `month` or `year`, the query groups by time bucket and series (gender/urgency code).

## Current limitations (intentional)

- No user-created saved views, favorites, or dashboards.
- No hospitals data source or additional metrics/dimensions.
- No URL-encoded config sharing.
- No persistence of in-memory edits (“save changes”).
- No pivot feature expansion beyond results table.
- No migration of existing Generic Analysis pages.
- Default locale remains `en`; German catalog exists for explorer keys only (`messages+intl-icu.de.xlf`).

## How to add another allocations dimension

1. Add `AnalysisDimensionKey` enum case.
2. Extend `AllocationsCapabilitiesProvider` (`dimensions`, `timeGrainsFor()` if needed).
3. Update `AllocationsCountQuery` GROUP BY / labels for the new column.
4. Extend `ExplorerTitleFactory` and chart/table presenters if label logic differs.
5. Add form choice + i18n keys under `stats.analysis_explorer.*`.
6. Add unit/integration tests.

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
| Controllers | `AnalysisExplorerController.php`, `AnalysisExplorerLibraryController.php` |
| Saved views | `SavedExplorerView.php`, `SavedExplorerViewRepository.php`, `ExplorerSystemViewSeeder.php`, `SavedExplorerViewLoader.php` |
| LiveComponent | `src/Statistics/AnalysisExplorer/UI/LiveComponent/AnalysisExplorerShell.php` |
| Config | `src/Statistics/AnalysisExplorer/Application/ExplorerConfigMapper.php` |
| Normalizer / Validator | `AnalysisViewConfigNormalizer.php`, `AnalysisViewConfigValidator.php` |
| Grain resolver | `AnalysisDimensionGrainResolver.php` |
| Runner / Query | `AllocationsAnalysisRunner.php`, `Infrastructure/Query/AllocationsCountQuery.php` |
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
