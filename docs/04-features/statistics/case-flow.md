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
- Initial viewport fits the selected Leitstelle together with every origin dispatch area that assigned cases there. A hospital view fits those assigning Landkreise together with the populated isochrone rings and the hospital pin. Statewide views show districts with data.

Layers (selected per mode):

- Origin choropleth (Hessen dispatch-area GeoJSON): sequential blue scale with square-root contrast. **Relative (%)** (default) colours each region by its share of all mapped cases on a 0–100 % scale and labels the share. **Absolute** stretches colour to the highest case count in the current selection and labels the counts. Because share is count ÷ total, region ranking stays the same; the scale and labels are what change.
- Destination hospital pins (regional, expanded)
- Hospital pin + isochrone bands (single-hospital scope). Compact and expanded views share origin/isochrone checkboxes when both layers are present; destination pins and the hospital pin stay overlay-only.
- Observed transport-time intensity colours the isochrone rings. The compact legend puts share/count and travel-time scales on one row with pin keys and unmapped-count chips; method text (share vs count, estimated vs observed, omitted destinations) sits behind a **Notes** disclosure.

## Components

- **Service:** `CaseFlowDashboardService` orchestrates queries, destination pins, and isochrone assembly
- **Queries:** `CaseFlowRegionalMetricsQuery`, `CaseFlowDestinationStructureQuery`, `GeographicDestinationHospitalQuery`, …
- **Privacy:** `CaseFlowPrivacySuppressor` suppresses small-N cells and omits destination pins with n < 10 or without coordinates (grouped by hospital). Dispatch-area maps count omitted inside/outside destinations in the legend without naming them.
- **Filters:** Scope, period, and the statistics drawer (`ProjectionDrawerFilterSql`) apply to map and charts together

## Geographic segment profile

The card under the map characterises the current Case Flow population. With no `geo_segment` it is the **entire area** (Scope + Period + Drawer). Choosing an origin area or exclusive travel-time band adds that predicate as an extra AND. It is a second filtered read of `allocation_stats_projection`, not a parallel statistics system. `CaseFlowDashboardService` still builds the page; `GeographicSegmentProfileService` builds the profile.

The origin-distribution chart sits in the right column as a share (%) bar so it fits the narrow sidebar. The standalone transport-time chart and the origin-to-destination care-level stacked bar are omitted.

The picker splits options into **Dispatch area** and **Travel time** `<optgroup>`s, with “Entire area” as the first option. It sits on the right of the profile card header.

### Reuse

- Population: `CaseFlowSqlFilter` (scope, period, drawer) plus `GeographicSegmentSql`
- Dispatch-area origin clicks use **Catchment** (`hospital` in the Leitstelle **and** `dispatch_area_id` of the clicked origin), same as the choropleth, not Related
- Privacy: `CaseFlowPrivacyPolicy::MIN_CASES_PER_CELL` (n < 10). No second privacy engine
- Age slices: `StatisticsAgeGroupBucketSql` (`0_17`, `18_29`, … — not Explorer `0_18`)
- Travel bands: same exclusive 10-minute rings as the isochrone layer (`[0,10)`, `[10,20)`, … `[40,50)`, `≥50`). Allocations have no incident coordinates, so segments never intersect GeoJSON
- UI: compact dimension tables like Top Lists (`table-sm` + share bar), tabs like Hospital Population, Turbo frame like Closed Department details
- Reference population: the current Scope **AND** Period **AND** Drawer without the geographic segment. Dimension shares of a selected segment are compared with that parent population; Δ is the difference in percentage points, computed from unrounded shares

### New pieces

- `GeographicSegment` / `GeographicSegmentType` (`origin_area` | `travel_time_band`)
- Catalog from already-loaded map features and isochrone bands (`GeographicSegmentCatalogFactory`); the frame reloads origins only via `GeographicSegmentCatalogService`
- `GeographicSegmentMetricsQuery` and `GeographicSegmentDistributionQuery` (one selected tab per request; overview loads urgency and gender groups together). Distributions use `COUNT(*) FILTER` so segment and reference counts come from a single scan of the parent population
- `GeographicSegmentShareMath` / `GeographicSegmentDistributionRow`: unrounded shares and Δ, rounded only for display
- Compact tables in `_segment_dimension_table.html.twig` reuse the Top-List share bar
- `GET /statistics/case-flow/segment-profile` (`app_stats_case_flow_segment_profile`) as Turbo frame so tab changes do not remount Leaflet
- Map payload `selectedSegment` + `segmentSelectionEnabled`; choropleth/isochrone click → `Turbo.visit` with `geo_segment`. Destination pins are not segments

### URL and types

- `geo_segment=origin:15` or `geo_segment=travel:10_20`
- `geo_profile=overview|age|resources|features` (default `overview`). Legacy `urgency`, `gender`, and `demographics` map to `overview`.
- Both keys are in `StatisticsQueryKeys::REMOVE_SCOPE_DEPENDENT` and drop on scope change

| Context | Origin area | Travel-time band |
|---|---|---|
| Single hospital | Origins from map features | Exclusive 10-min rings of the map |
| Dispatch area | Catchment origins | No |
| Public / state / cohort / my hospitals | Origins with n ≥ 10 | No |

MVP bands: `under_10`, `10_20`, `20_30`, `30_40`, `40_50`, `beyond_max`. Optional `unknown` only when n ≥ 10, without a map highlight. No destination-pin segments and no invented intervals such as 30–45.

### MVP dimensions

| Tab | Content |
|---|---|
| Overview | n, share of the current population, median transport (hidden for travel bands), plus urgency (SK1–SK3) and gender tables |
| Age | Age groups |
| Resources | resus, cathlab |
| Clinical features | with_physician, cpr, ventilation, shock, pregnancy, work accident, infectious |

Entire area omits the population and Δ columns because segment and reference are identical. A selected origin or travel band always shows the comparison, including categories with 0 cases in the segment.

Not in MVP: department, indication, assignment, transport type, segment-vs-segment comparison.

### Filter chain and privacy

Population is always Scope **AND** Period **AND** Drawer. A selected segment is an extra AND for the profile counts; the reference shares stay on that parent population. There is no all-data fallback. If the drawer already sets `urgency=1`, the overview urgency table is correspondingly degenerate. The picker lists unsuppressed origins (n ≥ 10) and travel bands with count > 0; a click on a suppressed polygon shows the same suppressed empty state as n < 10.

Share for a dispatch-area origin is against the **catchment** population (assignments to hospitals of that Leitstelle), not against Related KPI totals that include outflow.

### Tests

- Unit: segment parse/SQL (origin vs half-open travel band), catalog per scope, privacy threshold, payload `selectedSegment`, scope change drops `geo_segment`, unrounded share/Δ rounding
- Integration: metrics with scope+period+drawer+segment (no leak outside the hospital); travel `[10,20)` excludes 20; dispatch-area origin excludes outflow; dimension counts return segment and reference in one aggregation; travel-band urgency Δ vs hospital population
- Functional: hospital + travel band comparison table; dispatch area + origin; entire area without reference/Δ columns; tab switch loads only the frame; small population; drawer stays in the frame URL; scope change removes `geo_segment`

### Deferred

Comparison of two segments, department/indication/assignment, 5-minute isochrones, geometry rings, destination segments.

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
