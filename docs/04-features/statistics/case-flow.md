# Case flow dashboard

The Case Flow submodule visualises regional allocation flow metrics, destination structure, and transport patterns.

Route: `GET /statistics/case-flow` (`app_stats_case_flow`)

## Modes

`CaseFlowModeResolver` selects the dashboard mode:

| Mode | When |
|------|------|
| `system_flow` | Public or regional scopes (state, dispatch area) |
| `hospital_origin` | Scope `hospital` or `my_hospitals` |

## Components

- **Service:** `CaseFlowDashboardService` orchestrates queries
- **Queries:** `CaseFlowRegionalMetricsQuery`, `CaseFlowFlowMatrixQuery`, `CaseFlowDestinationStructureQuery`, …
- **Privacy:** `CaseFlowPrivacySuppressor` suppresses small-N cells

## Frontend

Stimulus controllers:

- `case-flow-charts_controller.js`
- `case-flow-map_controller.js`

GeoJSON: `assets/geo/hessen-landkreise.geojson`

## GeoJSON build command

```bash
php bin/console app:statistics:case-flow:build-geojson
```

Merges Hessen dispatch-area GeoJSON from `config/case_flow/dispatch_area_geo_sources.yaml`.

## Related

- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
- [../../03-development/frontend.md](../../03-development/frontend.md)
