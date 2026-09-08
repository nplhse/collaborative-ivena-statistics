# Hospital geodata (coordinates and isochrones)

**Audience:** Operators and developers who need to geocode hospital addresses, fetch destination isochrones, or add another federal state.

Allocation detail maps (`/explore/allocation/{publicId}`) **never** call OpenRouteService. They only read hospital coordinates from the database and GeoJSON files under `var/geo/hospital-isochrones`. The two console commands below are the only write path.

See also: [../04-features/allocation/orientation-map.md](../04-features/allocation/orientation-map.md), [../06-reference/console-commands.md](../06-reference/console-commands.md), [configuration.md](../06-reference/configuration.md), [deployment.md](deployment.md).

## What lives where

| Data | Storage | Who writes it | Who reads it |
|------|---------|---------------|--------------|
| Hospital street address | `hospital` / address columns | Import, fixtures, admin | Geocode command |
| `Hospital.latitude` / `longitude` | Database | `app:hospital:geocode-coordinates --apply` (production). Fixtures still seed city/PLZ centroids for local/CI. | Map pins, isochrone command |
| Destination isochrones (5–50 min, 5-minute bands, driving-car) | `var/geo/hospital-isochrones/{stateId}/{hospitalPublicId}.geojson` | `app:allocation:fetch-hospital-isochrones --apply` | Allocation show map (one band only) |

`stateId` is the numeric Doctrine ID of `State`, not a name or ISO code. Files include `properties.origin` (`lat`/`lng` used for the OpenRouteService request). The fetch command skips an existing file only when that origin still matches the hospital coordinates.

## Prerequisites

- `OPENROUTESERVICE_API_KEY` in `.env.local` (dev) or `shared/.env.local` (production). Empty key: `--apply` exits 1; dry-run still works.
- Public OpenRouteService allows **at most 10 ranges** per isochrone request (we send 5–50 minutes). Stay below ~20 requests/minute; default `--delay-ms=3500`.
- Geocoding uses `/geocode/search/structured`, country Germany, usable layers `address` / `venue` / `street` only (`locality` is rejected so city centroids are not stored again).

## Look up the federal state ID

```bash
php bin/console dbal:run-sql "SELECT id, name FROM state ORDER BY id"
```

## First-time setup or cut-over from city centroids

Existing coordinates from `config/hospital_population/geocoding.yaml` (and fixtures) are **city/PLZ centroids**, not street points. The first street-level pass **must** use `--force`.

Always dry-run first:

```bash
php bin/console app:hospital:geocode-coordinates <stateId>
php bin/console app:hospital:geocode-coordinates <stateId> --apply --force
php bin/console app:allocation:fetch-hospital-isochrones <stateId>
php bin/console app:allocation:fetch-hospital-isochrones <stateId> --apply --force
```

`--force` on isochrones is needed when cutting over files that have no `properties.origin` yet. After that, a later geocode that moves a hospital is picked up by `fetch-hospital-isochrones --apply` **without** `--force`.

On production, run from the current release after deploy (`cd ~/www/current`). Keep `var/geo/hospital-isochrones` as a Deployer **shared** directory so files survive releases. See [deployment.md](deployment.md).

## New hospitals (same federal state)

Hospitals without coordinates:

```bash
php bin/console app:hospital:geocode-coordinates <stateId>
php bin/console app:hospital:geocode-coordinates <stateId> --apply
php bin/console app:allocation:fetch-hospital-isochrones <stateId> --apply
```

No `--force` unless you intend to overwrite already geocoded coordinates.

## Address or coordinate change

1. Fix the hospital address in the database (admin / import).
2. `app:hospital:geocode-coordinates <stateId> --apply --force` (or geocode only that hospital after a dry-run review). `--force` is required because coordinates already exist.
3. `app:allocation:fetch-hospital-isochrones <stateId> --apply` — refetches files whose stored origin no longer matches.

## Adding another federal state

Geocode and isochrones are **per `stateId`**. Repeat the first-time sequence for the new state's ID. That is enough for pins and travel-time polygons **if** hospitals have coordinates.

The orientation map **polygons** (dispatch-area outlines, Hessen GeoJSON) are still a Hessen pilot:

| Piece | Location | What to add for a new state |
|-------|----------|-----------------------------|
| Dispatch-area name → GeoJSON key | `config/case_flow/dispatch_area_geo_map.yaml` | Names as stored on `DispatchArea` |
| Area polygons | `assets/geo/hessen-landkreise.geojson` (today) | A GeoJSON with matching feature keys |
| Factory | `CatalogOrientationMapFactory` (`HESSEN_STATE_NAME`) | Enable the new state name and load its files |
| Stimulus map | `assets/controllers/catalog-orientation-map_controller.js` | Usually unchanged if GeoJSON + keys are wired |

Until those map files exist, allocation show for the new state can still display hospital pins and isochrones when coordinates/files are present, but district highlighting stays disabled.

## Command statuses

### `app:hospital:geocode-coordinates`

| Status | Meaning |
|--------|---------|
| `skip` | Coordinates already set; skipped unless `--force` |
| `geocode` | Dry-run: would call OpenRouteService. Apply: wrote a street-level match |
| `missing-address` | No street, or neither postal code nor city |
| `unusable-match` | Result was missing, not Germany, or a coarse layer (`locality`, …) |
| `failed` | HTTP/transport error |

If `city` looks like a 5-digit PLZ and `postalCode` does not, the command swaps them before the request (fixture/import mix-up).

### `app:allocation:fetch-hospital-isochrones`

| Status | Meaning |
|--------|---------|
| `skip` | File exists and `properties.origin` matches hospital lat/lng |
| `fetch` | Dry-run: would call OpenRouteService. Apply: wrote the file (or counted as `failed` if the request returned nothing) |
| `missing-coords` | Hospital has no latitude/longitude — geocode first |

Both commands preview by default. `--apply` writes. `--apply --dry-run` together: `--apply` wins (warning). `--delay-ms=0` is for tests only.

## What not to do

- Do not call OpenRouteService on allocation show, hospital show, or fixture load.
- Do not commit `.env` or the API key.
- Do not treat fixture/YAML centroids as street-accurate in production; re-geocode with `--force` once.
- Do not delete `shared/var/geo/hospital-isochrones` on deploy; if it is empty, re-run the fetch command.
