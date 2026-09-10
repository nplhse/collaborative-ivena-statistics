# Isochrone origin heatmap

**Audience:** Developers extending the Statistics Overview or Indication Insights map widget.

A Leaflet map of destination isochrones around a single hospital, coloured as a heatmap by how many allocations fall into each 10-minute travel-time band (the same grouping as the transport-time chart). Fill opacity is the same on every ring so the basemap stays readable. It is a lazily loaded widget in the main charts column — not a GIS origin model.

## Where it appears

- Statistics Overview main column, below Age groups
- Indication Insights detail (and indication group dashboards that reuse the same charts partial), below Age groups

It is shown only when **all** of the following hold:

- Scope is exactly one hospital (`scope=hospital` with `hospitalId`)
- The hospital has `latitude` / `longitude`
- A stored isochrone file exists for that hospital

Public, my-hospitals, state, dispatch-area, and cohort scopes never include the frame.

## Assignment

Allocations have no incident coordinates. Bands come from recorded `transport_time_minutes` on `allocation_stats_projection`, using the same half-open 10-minute buckets as [`StatisticsTransportTimeBucketSql`](../../../src/Statistics/Application/Mapping/StatisticsTransportTimeBucketSql.php) (`0–10`, `10–20`, … `40–50`). The map draws the stored 10/20/30/40/50-minute destination polygons; stored 5-minute contours in between are unused here.

Times of 50 minutes and above (`50–60` and `>60` in the transport-time chart) are **not** folded into the outer ring; they appear only in the footnote. Missing or negative durations are likewise unmapped. Zero minutes maps into the innermost `0–10` ring.

Treat the visualisation as an approximation of travel-time share, not of geographic origin.

## Loading

The parent page embeds a Turbo Frame (`loading="lazy"`) pointing at `GET /statistics/widgets/isochrone-origin-map`. Indication and group dashboards pass `indicationId` / `groupId` so the widget uses the same filtered population as the surrounding page. Missing isochrones yield an empty frame after load.

The sync Overview path does not run the band query (see [overview-dashboard-performance.md](overview-dashboard-performance.md)).

## Code locations

| Area | Path |
|------|------|
| Assembler / DTO | `IsochroneOriginHeatmapAssembler`, `IsochroneOriginHeatmapView` |
| Band SQL | `IsochroneOriginBandSql` |
| Query | `IsochroneOriginBandQuery` |
| Route | `app_stats_isochrone_origin_map` |
| Twig | `src/Statistics/UI/Twig/templates/isochrone_origin_map/` |
| Stimulus | `assets/controllers/isochrone-origin-map_controller.js` |
| Isochrone files | `HospitalIsochroneProviderInterface` / `var/geo/hospital-isochrones` |

Rings are derived client-side with `@turf/difference` because stored OpenRouteService polygons are cumulative. Empty bands are omitted. The viewport fits the largest populated isochrone. Fill colour is a green→red heatmap by allocation count; stroke matches the fill; every ring uses the same fill opacity.

## Related

- [../../05-operations/hospital-geodata.md](../../05-operations/hospital-geodata.md)
- [../allocation/orientation-map.md](../allocation/orientation-map.md)
- [statistics-filter-and-scope.md](statistics-filter-and-scope.md)
