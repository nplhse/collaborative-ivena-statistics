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

#### Light and dark mode

Chart colours for the two colour schemes are set globally, not per chart (#1278):

- [`assets/styles/charts.css`](../../assets/styles/charts.css), loaded by the `app` and `admin-kpi` entrypoints, styles axis and legend text, grid lines, axis lines and ticks, heatmap cell gaps, marker rings and tooltips for every ApexCharts chart. It uses Tabler variables, with Bootstrap fallbacks for EasyAdmin pages. Tabler's colours are `light-dark()` values, so charts follow the colour scheme and a theme change without re-rendering.
- Controllers and option builders do not set `chart.foreColor`, `grid.borderColor`, `theme.mode`, `tooltip.theme` or colours for axis titles. An explicit value would override the global rules in one chart.
- Data colours that must be plain colour values in the options (empty heatmap cells, neutral series, bar separators) come from [`assets/lib/chart-colors.js`](../../assets/lib/chart-colors.js): `chartColors(element)` returns the light or dark set, based on `isDarkTheme()` in [`chart-theme.js`](../../assets/lib/chart-theme.js). These values are read when the chart renders; after a theme change they apply on the next render.
- The PNG export of analysis charts always uses the light colours.
- ApexCharts' own `--apx-fore` and `--apx-grid` tokens are not used: ApexCharts copies them into SVG attributes, where `light-dark()` does not work.

#### Single points in line charts

Line and area charts draw no markers, only the line. With a single point (for example one month in the selected period) there is no line, so every line and area chart takes `markers` from [`assets/lib/chart-markers.js`](../../assets/lib/chart-markers.js): `lineMarkers(pointCount, markers)` returns a visible marker for exactly one point and the chart's own options otherwise (#1284). `seriesPointCount(series)` counts the points of charts without a shared category list.

Not part of this upgrade. Add them only when a chart needs them:

- Box plot jitter via `plotOptions.boxPlot.points`, once hospital or explorer distributions supply the raw observations.
- Violin, sunburst, icicle, trellis, the canvas renderer, storyboard, and ink. They need extra entry points and are unused by the current line, area, bar, heatmap, and box plot charts.

## Stimulus

`assets/bootstrap.js` starts `@symfony/stimulus-bundle`.

Custom controllers live in `assets/controllers/*_controller.js`. Examples:

| Controller | Area |
|------------|------|
| `dashboard-charts` | Statistics dashboards |
| `geo-map`, `case-flow-charts`, `case-flow-map` | Geographic / case flow (shared Leaflet kernel in `assets/js/geo-map/`; tiles come from `<meta name="map-tiles">` in the base layout, see `MAP_TILE_URL`) |
| `hospital-population-charts`, `hospital-population-map` | Hospital population |
| `benchmarking-charts` | Benchmarking |
| `analysis-chart`, `generic-analysis-chart` | Analysis views |
| `encounter-overview-chart` | Bar and box plot charts of the encounter overview; suppressed counts arrive as `null` and are not drawn |
| `catalog-orientation-map` | Explore allocation/hospital orientation map (Leaflet, Turf) |
| `result-count-mirror` | Copies DataTable `#result-count` into the page header after Turbo frame loads |
| `bulk-selection` | Row selection, count and toolbar for DataTable bulk actions and the notification inbox |
| `geo-map`, `isochrone-origin-map` | Statistics hospital-scope travel-time isochrone heatmap (Leaflet, Turf); widgets use `geo-map` |

`assets/controllers.json` enables `@symfony/ux-live-component` and `@symfony/ux-turbo`.
The `turbo-core` entry keeps the autoimport of `mercure_stream_source_element.js` that Symfony Flex adds from the
`@symfony/ux-turbo` package config (#1305). It only registers the `<turbo-mercure-stream-source>` custom element and
opens no connection while no page uses that element; `mercure-turbo-stream` stays disabled. Keeping it stops
`composer update` from re-adding it as an unrelated diff.

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

## Icons

Icons render with `ux_icon()` / `<twig:ux:icon>` (Symfony UX Icons). Every used icon is committed as SVG in
`assets/icons/<prefix>/<name>.svg`; the app does not rely on fetching icons from `api.iconify.design`.

When you use a new icon:

```bash
bin/console ux:icons:import tabler:user-plus
```

`bin/console ux:icons:lock` finds names written in templates, but not names that come from PHP (presenters, enums,
view models) and reach the template as a variable. Import those by name. `IconFilesTest`
(`tests/Shared/Unit/UI/`) scans `src/`, `templates/`, `config/` and `assets/` for icon names and fails if an SVG is
missing. In the test env, on-demand Iconify is off (`config/packages/ux_icons.yaml`), so a missing icon also fails
the rendering test instead of calling the network.

## Explanatory texts on admin pages

Admin pages show data and actions, not explanations (#1494). Add a visible explanatory text (intro, definition,
hint under a heading, footnote, CRUD page help) only when an admin would otherwise misread a number and decide
wrongly, trigger an action with consequences they cannot see, or miss a legal or privacy obligation the page has to
state. Warnings, empty states, confirmation dialogs and form field help are not affected.

Put a short explanation that is still useful into a tooltip on an info icon next to the label:
`{{ include('@Admin/_info_tooltip.html.twig', {text: 'key'|trans({}, 'domain')}) }}`. For an EasyAdmin action use
`setHtmlAttributes(['title' => new TranslatableMessage(...)])`. Definitions and background belong in `docs/`.

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
