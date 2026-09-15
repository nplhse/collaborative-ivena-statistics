# Geographic / case flow analysis

Route: `GET /statistics/case-flow` (`app_stats_case_flow`)

The Case Flow dashboard is the consolidated **geographic flow analysis**: where allocations originate, where they are assigned, and how geography and transport time shape those flows.

## Analysis levels

`CaseFlowModeResolver` selects the dashboard mode:

| Mode | When | Question |
|------|------|----------|
| `system_flow` (regional) | Public, state, hospital cohort | Where do allocations from this region go, and which hospital types receive them? |
| Dispatch area flow | Scope `dispatch_area` | Inflow from other Leitstellen, all related assignments, destinations inside vs outside the district |
| `hospital_origin` | Scope `hospital` or `my_hospitals` | Where do this hospital’s (or my hospitals’) allocations originate? |

Single-hospital scope also shows the **inflow diagram**: cases from other Leitstellen vs from this hospital’s own dispatch area, flowing into all assignments to this hospital. There is no outflow stage — every case already has this hospital as destination.

Hospital cohorts are **not** a geographic analysis level. They remain a destination classification (tier × location cards).

### Origin-state filter (this page only)

Global `state` scope still expands to destination hospital IDs elsewhere. On this page, `state` filters `allocation_stats_projection.state_id` (allocation origin). The global `StatisticsScopeResolver` is unchanged.

### Dispatch-area population (this page only)

Elsewhere, `dispatch_area` still means origin `dispatch_area_id` only. On this page it is the **Leitstelle as a system**:

- **Related assignments** (KPIs, Sankey, destination pins, structure, transport): origin in the Leitstelle **or** hospital belonging to the Leitstelle. That includes inflow, local stays, and outflow.
- **Catchment map**: origins of cases assigned **to hospitals in this Leitstelle**, so other dispatch areas are visible and outflow does not inflate the home polygon.

Flow: *inflow from outside* + *originated here* → *all related assignments* → *destination in district* / *outflow (destination outside)*. Orange map outline marks the selected Leitstelle; orange pins are outflow hospitals.

## Shared map architecture

Pipeline: **Population / Scope → Geographic aggregation → Map layers → Presentation**.

- Payload builder: `GeographicMapPayloadBuilder`
- Leaflet kernel: `assets/js/geo-map/`
- Stimulus controller: `geo-map_controller.js`
- Compact and expanded views share the same payload; expanded is a fullscreen overlay with layer toggles
- Initial viewport fits the selected Leitstelle together with every origin dispatch area that assigned cases there. A hospital view fits the isochrone covering most cases. Statewide views show districts with data.

Layers (selected per mode):

- Origin choropleth (Hessen dispatch-area GeoJSON): sequential blue scale with square-root contrast. **Relative (%)** (default) colours each region by its share of all mapped cases on a 0–100 % scale and labels the share. **Absolute** stretches colour to the highest case count in the current selection and labels the counts. Because share is count ÷ total, region ranking stays the same; the scale and labels are what change.
- Destination hospital pins (regional, expanded)
- Hospital pin + isochrone bands (single-hospital scope)
- Observed transport-time intensity colours the isochrone rings; the legend distinguishes estimated ORS geography from observed times

## Components

- **Service:** `CaseFlowDashboardService` orchestrates queries, destination pins, and isochrone assembly
- **Queries:** `CaseFlowRegionalMetricsQuery`, `CaseFlowFlowMatrixQuery`, `CaseFlowDestinationStructureQuery`, `GeographicDestinationHospitalQuery`, …
- **Privacy:** `CaseFlowPrivacySuppressor` suppresses small-N cells and omits destination pins with n < 10 or without coordinates (grouped by hospital). Dispatch-area maps count omitted inside/outside destinations in the legend without naming them.
- **Filters:** Scope, period, and the statistics drawer (`ProjectionDrawerFilterSql`) apply to map and charts together

## Frontend

Stimulus controllers:

- `geo-map_controller.js` (shared geographic map)
- `case-flow-charts_controller.js`

GeoJSON: `assets/geo/hessen-landkreise.geojson`

## GeoJSON build command

```bash
php bin/console app:statistics:case-flow:build-geojson
```

Merges Hessen dispatch-area GeoJSON from `config/case_flow/dispatch_area_geo_sources.yaml`.

## Related

- [isochrone-origin-heatmap.md](isochrone-origin-heatmap.md) — isochrones as a hospital-scope layer and Overview widget
- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
- [../../03-development/frontend.md](../../03-development/frontend.md)
