# Frontend architecture

The frontend uses Symfony Asset Mapper, Stimulus, Turbo, and Live Components. There is no Webpack or Vite build step.

## Asset Mapper

- Entry: `assets/app.js` (imports `bootstrap.js`, CSS, Tabler)
- Config: `config/packages/asset_mapper.yaml` — path `assets/`, `missing_import_mode: strict` (prod: `warn`)
- Import map: `importmap.php` — registers Stimulus, Turbo, Live Component, ApexCharts, Leaflet, Turf, `es-module-shims` (self-hosted import-map polyfill), and other dependencies

Additional entrypoints: `admin-kpi`, `admin-page-form`, `admin-trix-media`, `error-page`.

### ApexCharts

Charts use **ApexCharts 7.6.1**. The import map entry `apexcharts` pulls in `apexcharts/core`; [`assets/lib/load-apexcharts.js`](../../assets/lib/load-apexcharts.js) imports the main module and uses its default export. That module registers chart types and the exports feature (`dataURI`) on load. Do not import `apexcharts/core` on its own — PNG export then throws because the exports feature is not registered.

These version-specific details stay in place because 7.6.1 still depends on them:

- `plotOptions.bar.borderRadiusWhenStacked: 'last'` in [`build-analysis-chart-options.js`](../../assets/lib/build-analysis-chart-options.js). The library default is `'all'`, which rounds both ends of a stacked bar. The v7.0 note that this option was removed does not match 7.6.1.
- Axis title config always includes a `text` key. ApexCharts reads `axis.title.text` without checking that `title` exists.
- PNG export sets an explicit font stack. Off-screen export cannot resolve `fontFamily: 'inherit'`.

Not part of this upgrade. Add them only when a chart needs them:

- Box plot jitter via `plotOptions.boxPlot.points`, once hospital or explorer distributions supply the raw observations.
- Facet tokens (`--apx-*`) if charts should follow Tabler theme tokens more closely.
- Violin, sunburst, icicle, trellis, the canvas renderer, storyboard, and ink. They need extra entry points and are unused by the current line, area, bar, heatmap, and box plot charts.

## Stimulus

`assets/bootstrap.js` starts `@symfony/stimulus-bundle`.

Custom controllers live in `assets/controllers/*_controller.js`. Examples:

| Controller | Area |
|------------|------|
| `dashboard-charts` | Statistics dashboards |
| `geo-map`, `case-flow-charts`, `case-flow-map` | Geographic / case flow (shared Leaflet kernel in `assets/js/geo-map/`) |
| `hospital-population-charts`, `hospital-population-map` | Hospital population |
| `benchmarking-charts` | Benchmarking |
| `analysis-chart`, `generic-analysis-chart` | Analysis views |
| `catalog-orientation-map` | Explore allocation/hospital orientation map (Leaflet, Turf) |
| `result-count-mirror` | Copies DataTable `#result-count` into the page header after Turbo frame loads |
| `geo-map`, `isochrone-origin-map` | Statistics hospital-scope travel-time isochrone heatmap (Leaflet, Turf); widgets use `geo-map` |

`assets/controllers.json` enables `@symfony/ux-live-component` and `@symfony/ux-turbo`.

## Live Components

Four Live Components in `src/`:

| Component | Template | Purpose |
|-----------|----------|---------|
| [`AnalysisExplorerShell`](../../src/Statistics/AnalysisExplorer/UI/LiveComponent/AnalysisExplorerShell.php) | `@Statistics/analysis_explorer/AnalysisExplorerShell.html.twig` | Interactive Explorer configuration and execution |
| [`BenchmarkSelectionForm`](../../src/Statistics/Benchmarking/UI/LiveComponent/BenchmarkSelectionForm.php) | `@Statistics/live/BenchmarkSelectionForm.html.twig` | Live benchmark selection form |
| [`TopListComparisonSelectionForm`](../../src/Statistics/UI/LiveComponent/TopListComparisonSelectionForm.php) | `@Statistics/live/TopListComparisonSelectionForm.html.twig` | Scope and period selection for one Top List comparison side |
| [`InsightCompareSelectionForm`](../../src/Statistics/UI/LiveComponent/InsightCompareSelectionForm.php) | `@Statistics/live/InsightCompareSelectionForm.html.twig` | Comparison subject selection on an Insight |

Routes: `config/routes/ux_live_component.yaml`

## Colocated templates

Twig templates are colocated with controllers under `src/*/UI/Twig/templates/`. See [../02-architecture/decisions/004-colocated-templates.md](../02-architecture/decisions/004-colocated-templates.md).

## Development workflow

After changing JS or CSS:

```bash
make lint    # includes asset-related checks where applicable
```

No separate `npm run build` — Asset Mapper serves files directly.

## Related

- [translations.md](translations.md) — UI string domains
- [twig-components.md](twig-components.md) — shared Card, Alert, ActiveFilters, and FilterDrawer
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)
