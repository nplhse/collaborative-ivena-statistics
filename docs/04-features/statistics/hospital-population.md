# Hospital population dashboard

The Hospital Population submodule provides an overview of participating hospitals: participation geography, structural coverage, bed statistics, and allocation volumes.

Routes (`app_stats_hospital_population`):

- `GET /statistics/hospital-population` (default: Participation)
- `GET /statistics/hospital-population/participation`
- `GET /statistics/hospital-population/coverage`
- `GET /statistics/hospital-population/beds`
- `GET /statistics/hospital-population/allocations`

`GET /statistics/hospital-population/characteristics` redirects to `/beds`.

Secondary navigation uses in-page tabs. The Statistics subnav entry **Hospitals** stays a single top-level item.

## Sections

| Section | Analytical question | Content |
|---------|---------------------|---------|
| Participation | Who participates, and where? | KPIs (hospitals, participants, represented dispatch areas, coverage), regional table, map |
| Coverage | How representative is the participating sample? | 1D representativity tables (tier, size, location, state) and coverage cross-tables (location×tier, size×tier) |
| Beds | How are hospitals distributed by bed capacity? | Beds descriptive matrix, bed box plots by tier and location |
| Allocations | How are allocation volumes distributed? | Allocation bar charts and cross-tables |

Each section loads independently. Allocation counts are queried only for Allocations. Map payload is built only for Participation; chart payloads only for Beds or Allocations.

## Scope

Unlike most statistics pages, this dashboard does **not** use `StatisticsFilter`. It calls `HospitalPopulationDashboardService` without a scope filter.

## Data sources

- `GetHospitalPopulationQuery`
- `GetHospitalIdsWithAllocationsQuery`
- `GetAllocationCountsPerHospitalQuery` (Allocations only)
- `HospitalPopulationSnapshotEnricher`

## Frontend

Stimulus controllers:

- `hospital-population-charts_controller.js` (Beds, Allocations)
- `hospital-population-map_controller.js` (Participation)
- `hospital-population-regional-table_controller.js` (Participation)

Geo keys shared with Case Flow via `CaseFlowGeoKeyResolver`.

## Related

- [case-flow.md](case-flow.md)
- [../../03-development/frontend.md](../../03-development/frontend.md)
