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
| `bulk-selection` | Row selection, count and toolbar for DataTable bulk actions and the notification inbox |
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

## Debugging with UX Inspector

[Symfony UX Inspector](https://symfony.com/bundles/ux-inspector/current/index.html) shows the Stimulus controllers, Live Components and Turbo frames on the current page. It is a development tool only:

- `symfony/ux-inspector` is a `require-dev` package, and the bundle is registered for `dev` only (`config/bundles.php`).
- Routes and config are wrapped in `when@dev` (`config/routes/ux_inspector.yaml`, `config/packages/ux_inspector.yaml`). The bundle also stays off when `kernel.debug` is false.
- The dev firewall covers `/_ux/inspector.js`, so loading the script never starts a session.
- [`UxInspectorNotExposedTest`](../../tests/Shared/Functional/Security/UxInspectorNotExposedTest.php) fails if the bundle is registered outside `dev`.

**Opening it:** click the small pull tab on the right edge of the page, or type `u` then `x` outside a form field. It works on every HTML page, including EasyAdmin, and next to the Web Debug Toolbar (the toolbar itself is ignored). The profiler, `/_wdt`, `/_error` and `/_fragment` are excluded.

**What it shows:**

- Stimulus: values (with defaults), CSS classes, targets (also missing ones), actions (also missing methods), outlets and parent/child controllers.
- Live Components: LiveProps, models, actions, listeners, loading and polling settings, child components.
- Activity log ("Show activity"): Live Component requests, model changes and re-renders, Turbo frame loads and streams, controller events dispatched via `data-action`.

**Stimulus connection:** `assets/bootstrap.js` sets `window.Stimulus = app` when `app.debug` is true (it follows `kernel.debug`). This lets the Inspector read controller details beyond the HTML attributes. It reuses the existing Stimulus application; no application code reads `window.Stimulus`. EasyAdmin pages start their own small Stimulus application in `assets/admin-kpi.js`; it is connected the same way, but only when the `<ux-inspector>` element is on the page (dev with debug on).

**Limits:** the Inspector is experimental and its API may change, so nothing in the application may depend on it. It only reads the page. Stimulus `connect()` and `disconnect()` are not shown in the activity log. It needs a modern browser (Baseline 2024).

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
