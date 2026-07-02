# Hospital population dashboard

The Hospital Population submodule provides an overview of participating hospitals: bed statistics, participation coverage, cross-tabulations, and regional maps.

Route: `GET /statistics/hospital-population` (`app_stats_hospital_population`)

## Scope

Unlike most statistics pages, this dashboard does **not** use `StatisticsFilter`. It calls `HospitalPopulationDashboardService::build()` without a scope filter.

## Data sources

- `GetHospitalPopulationQuery`
- `GetHospitalIdsWithAllocationsQuery`
- `GetAllocationCountsPerHospitalQuery`
- `HospitalPopulationSnapshotEnricher`

## Visualisations

- Bed statistics and participation coverage
- Cross-tabulations (size × tier, urbanity × tier)
- Regional coverage maps (markers + choropleth)
- Allocation-based summary and boxplots

## Frontend

Stimulus controllers:

- `hospital-population-charts_controller.js`
- `hospital-population-map_controller.js`
- `hospital-population-regional-table_controller.js`

Geo keys shared with Case Flow via `CaseFlowGeoKeyResolver`.

## Related

- [case-flow.md](case-flow.md)
- [../../03-development/frontend.md](../../03-development/frontend.md)
