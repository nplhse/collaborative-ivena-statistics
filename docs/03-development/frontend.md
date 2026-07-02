# Frontend architecture

The frontend uses Symfony Asset Mapper, Stimulus, Turbo, and Live Components. There is no Webpack or Vite build step.

## Asset Mapper

- Entry: `assets/app.js` (imports `bootstrap.js`, CSS, Tabler)
- Config: `config/packages/asset_mapper.yaml` — path `assets/`, `missing_import_mode: strict` (prod: `warn`)
- Import map: `importmap.php` — registers Stimulus, Turbo, Live Component, ApexCharts, Leaflet, and other dependencies

Additional entrypoints: `admin-kpi`, `admin-page-form`, `admin-trix-media`, `error-page`, `monitoring`.

## Stimulus

`assets/bootstrap.js` starts `@symfony/stimulus-bundle`.

Custom controllers live in `assets/controllers/*_controller.js`. Examples:

| Controller | Area |
|------------|------|
| `dashboard-charts` | Statistics dashboards |
| `case-flow-charts`, `case-flow-map` | Case flow |
| `hospital-population-charts`, `hospital-population-map` | Hospital population |
| `benchmarking-charts` | Benchmarking |
| `analysis-chart`, `generic-analysis-chart` | Analysis views |

`assets/controllers.json` enables `@symfony/ux-live-component` and `@symfony/ux-turbo`.

## Live Components

Two Live Components in `src/`:

| Component | Purpose |
|-----------|---------|
| `AnalysisExplorerShell` | Interactive Explorer configuration and execution |
| `BenchmarkSelectionForm` | Live benchmark selection form |

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
- [../04-features/statistics/analysis-explorer.md](../04-features/statistics/analysis-explorer.md)
