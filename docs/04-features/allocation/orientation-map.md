# Allocation orientation map

**Audience:** Developers changing the Explore allocation detail map.

The orientation map on `/explore/allocation/{publicId}` (and related catalog pages) is a Leaflet map: dispatch-area polygons (Hesse and neighbouring areas outside Hesse), origin/destination markers, and an optional destination-isochrone band.

It is **read-only** at request time. Coordinates come from `Hospital.latitude` / `longitude`. Isochrones come from files under `var/geo/hospital-isochrones`. Operators refresh that data with console commands — see [../../05-operations/hospital-geodata.md](../../05-operations/hospital-geodata.md).

## What the allocation map shows

| Layer | Source | Notes |
|-------|--------|-------|
| Dispatch-area polygons | `GET /statistics/geo/dispatch-areas.geojson`, built from `district` and `district_geometry` by `DispatchAreaGeoIndex` | An area is shown only when it has at least one district and every assigned district has a usable geometry (ADR 041) |
| Origin | Allocation dispatch area | Highlighted polygon |
| Destination pin | Destination hospital lat/lng | Orange pin. Missing coords: no pin. |
| Travel-time band | Stored hospital isochrones, filtered to `createdAt` → `arrivalAt` | 5-minute bands up to 50 minutes (`IsochroneTravelBand`). Intersection with the origin polygon is emphasized. |
| Extra pins (secondary transport) | Other hospitals in the **origin** dispatch area that have coordinates | Blue, smaller. Destination excluded. `SecondaryTransport` is a reason lookup, not a sending hospital — that hospital is not in the data. |

Primary allocations do not load extra hospital pins.

## Code locations

| Area | Path |
|------|------|
| Factory / DTO | `CatalogOrientationMapFactory`, `CatalogOrientationMap` |
| Travel-time band | `IsochroneTravelBand` |
| Twig | `src/Allocation/UI/Twig/templates/catalog/_orientation_map.html.twig` |
| Stimulus | `assets/controllers/catalog-orientation-map_controller.js` |
| File store | `HospitalIsochroneFileStore` (`var/geo/hospital-isochrones/{stateId}/{publicId}.geojson`) |

## Changing map behaviour

- **New pin type or legend:** factory DTO + Twig + Stimulus + translations (`allocation+intl-icu`).
- **New travel-time rule:** `IsochroneTravelBand` (keep the stored GeoJSON bands; do not call OpenRouteService here).
- **New dispatch area:** create and assign its districts and fetch their boundaries, see [hospital-geodata.md](../../05-operations/hospital-geodata.md#districts-and-their-boundaries). No code change.
- **Pin position looks wrong:** coordinates are probably still a city/PLZ centroid. Re-run street geocoding with `--hospital-id` and `--force`, then refetch isochrones.

## Tests

- `tests/Allocation/Unit/Application/Explore/Catalog/CatalogOrientationMapFactoryTest.php`
- `tests/Allocation/Unit/Application/Explore/Catalog/IsochroneTravelBandTest.php`
- Functional show tests for allocation and hospital catalog pages
