# Isochrone origin heatmap

**Audience:** Developers extending the Statistics Overview or Insights map widget.

A Leaflet map of destination isochrones around a single hospital, coloured as a heatmap by how many allocations fall into each 10-minute travel-time band (the same grouping as the transport-time chart). Fill opacity is the same on every ring so the basemap stays readable.

On the Geographic / Case Flow dashboard, the same isochrone data is a **layer** of hospital-focused analysis (together with origin choropleth and the hospital pin), not a separate map stack. Compact Overview widgets reuse `geo-map_controller.js`. Insights detail uses the same widget with origin choropleth available as a toggle (default: isochrones only).

Treat estimated isochrone travel times (OpenRouteService polygons) and observed `transport_time_minutes` as complementary, not equivalent.

## Where it appears

- Statistics Overview main column, below Age groups
- Insights detail (and indication group dashboards that reuse the same charts partial), below Age groups

It is shown only when **all** of the following hold:

- Scope is exactly one hospital (`scope=hospital` with `hospitalId`)
- The hospital has `latitude` / `longitude`
- A stored isochrone file exists for that hospital

Public, my-hospitals, state, dispatch-area, and cohort scopes never include the frame.

## Assignment

Allocations have no incident coordinates. Bands come from recorded `transport_time_minutes` on `allocation_stats_projection`, using the same half-open 10-minute buckets as [`StatisticsTransportTimeBucketSql`](../../../src/Statistics/Application/Mapping/StatisticsTransportTimeBucketSql.php) (`0–10`, `10–20`, … `40–50`). The map draws the stored 10/20/30/40/50-minute destination polygons; stored 5-minute contours in between are unused here.

Times of 50 minutes and above (`50–60` and `>60` in the transport-time chart) are **not** folded into the outer ring; they appear as compact count chips under the map. Missing or negative durations are likewise unmapped. Method notes sit behind a collapsed **Notes** disclosure. Zero minutes maps into the innermost `0–10` ring.

Treat the visualisation as an approximation of travel-time share, not of geographic origin.

## Loading

The parent page embeds a Turbo Frame (`loading="lazy"`) pointing at `GET /statistics/widgets/isochrone-origin-map`. Insights detail passes `dimension` and `id` so the widget uses `InsightPopulationFilter` for the same population as the surrounding page. Origin choropleth (Leitstellen) is available next to the isochrone heatmap; compact and expanded views start with isochrones on and origin off. Overview and Closed Department omit those query params and stay isochrone-only. Legacy `indicationId` / `groupId` still filter the bands. Missing isochrones yield an empty frame after load.

The sync Overview path does not run the band query (see [overview-dashboard-performance.md](overview-dashboard-performance.md)).

## Code locations

| Area | Path |
|------|------|
| Assembler / DTO | `IsochroneOriginHeatmapAssembler`, `IsochroneOriginHeatmapView` |
| Band SQL | `IsochroneOriginBandSql` |
| Query | `IsochroneOriginBandQuery` (`InsightPopulationFilter` or `indicationIds`); Insights also `CaseFlowOriginDistributionQuery` |
| Payload | `GeographicMapPayloadBuilder` (`compactEnabledLayers` for Insights default) |
| Route | `app_stats_isochrone_origin_map` |
| Twig | `src/Statistics/UI/Twig/templates/isochrone_origin_map/` |
| Stimulus | `assets/controllers/geo-map_controller.js` (shared kernel in `assets/js/geo-map/`) |
| Isochrone files | `HospitalIsochroneProviderInterface` / `var/geo/hospital-isochrones` |

Rings are derived client-side with `@turf/difference` because stored OpenRouteService polygons are cumulative. Empty bands are omitted. When only isochrones are shown, the viewport fits the largest populated isochrone and stays centred on the hospital. Fill colour is a green→red heatmap by allocation count; stroke matches the fill; every ring uses the same fill opacity. Layer checkboxes sit under the map title on Insights detail.

## Related

- [case-flow.md](case-flow.md)
- [../../05-operations/hospital-geodata.md](../../05-operations/hospital-geodata.md)
- [../allocation/orientation-map.md](../allocation/orientation-map.md)
- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
