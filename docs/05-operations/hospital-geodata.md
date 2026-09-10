# Hospital geodata (coordinates and isochrones)

**Audience:** Operators and developers who need to geocode hospital addresses, fetch destination isochrones, or add another federal state.

Allocation detail maps (`/explore/allocation/{publicId}`) **never** call OpenRouteService. They only read hospital coordinates from the database and GeoJSON files under `var/geo/hospital-isochrones`. The two console commands below are the only write path.

See also: [../04-features/allocation/orientation-map.md](../04-features/allocation/orientation-map.md), [../04-features/statistics/isochrone-origin-heatmap.md](../04-features/statistics/isochrone-origin-heatmap.md), [../06-reference/console-commands.md](../06-reference/console-commands.md), [configuration.md](../06-reference/configuration.md), [deployment.md](deployment.md).

## What lives where

| Data | Storage | Who writes it | Who reads it |
|------|---------|---------------|--------------|
| Hospital street address | `hospital` / address columns | Import, fixtures, admin | Geocode command |
| `Hospital.latitude` / `longitude` | Database | `app:geo:geocode-hospitals --apply` (production). Fixtures still seed city/PLZ centroids for local/CI. | Map pins, isochrone command |
| Destination isochrones (5–50 min, 5-minute bands, driving-car) | `var/geo/hospital-isochrones/{stateId}/{hospitalPublicId}.geojson` | `app:geo:fetch-isochrones --apply` | Allocation show map (one 5-minute band); Statistics Overview / Indication Insights origin heatmap (10-minute bands at 10/20/30/40/50 min, hospital scope) |

IDs in the CLI are numeric Doctrine IDs (`Hospital`, `DispatchArea`, `State`), not names or ISO codes. Isochrone files include `properties.origin` (`lat`/`lng` used for the OpenRouteService request). The fetch command skips an existing file only when that origin still matches the hospital coordinates.

## Everyday use in the web app

Hospital users and reviewers do **not** load or refresh geographic data in the UI. The Explore allocation map and the Statistics isochrone origin heatmap only display what is already stored.

| What you see | Meaning | Who fixes it |
|--------------|---------|--------------|
| Orange destination pin | Hospital has street-level (or at least stored) coordinates | — |
| No destination pin | `Hospital.latitude` / `longitude` are empty | Operator: `app:geo:geocode-hospitals --hospital-id=… --apply` |
| Travel-time band around the destination | An isochrone file exists and the recorded travel time is between 5 and 50 minutes | — |
| Pin but no travel-time band | No isochrone file yet, origin mismatch after a coordinate change, or travel time outside 5–50 minutes | Operator: `app:geo:fetch-isochrones --hospital-id=… --apply` (geocode first if the pin is missing) |
| District polygons / origin highlight | Hessen map asset; independent of OpenRouteService | See [orientation-map.md](../04-features/allocation/orientation-map.md) |

The hospital-population statistics map uses a separate city/PLZ centroid YAML for local and CI fixtures. It is not updated by these commands.

## Command scopes

Both commands share the same interface. Specify **exactly one** scope:

| Option | Effect |
|--------|--------|
| `--hospital-id=` | One hospital, including non-participating |
| `--dispatch-area-id=` | All hospitals in that dispatch area |
| `--state-id=` | All hospitals in that federal state |
| `--participating-only` | With dispatch-area or state: only `isParticipating` hospitals. Ignored (with a warning) together with `--hospital-id` |

Existing data is skipped unless `--force` is passed. Runs are idempotent: re-run the same command to fill remaining hospitals after a stop.

## Prerequisites

- `OPENROUTESERVICE_API_KEY` in `.env.local` (dev) or `shared/.env.local` (production). Empty key: `--apply` exits 1; dry-run still works.
- Public OpenRouteService allows **at most 10 ranges** per isochrone request (we send 5–50 minutes). Stay below ~20 requests/minute; default `--delay-ms=3500`.
- Geocoding uses `/geocode/search/structured`, country Germany, usable layers `address` / `venue` / `street` only (`locality` is rejected so city centroids are not stored again).
- HTTP **429** and **403 quota/limit** responses abort the run after one retry (`Retry-After`, capped at 60 seconds, or `--delay-ms` if the header is missing). Already written coordinates/files are kept. Re-run later to resume. Other HTTP errors mark that hospital `failed` and continue.

## Look up IDs

```bash
php bin/console dbal:run-sql "SELECT id, name FROM state ORDER BY id"
php bin/console dbal:run-sql "SELECT id, name, state_id FROM dispatch_area ORDER BY name"
php bin/console dbal:run-sql "SELECT id, name, is_participating FROM hospital ORDER BY name"
```

## Incremental loading

Typical order:

1. Participating hospitals in one dispatch area or state
2. Additional dispatch areas
3. The rest of the state (omit `--participating-only`)
4. A single hospital with `--force` when an address or pin must be refreshed

Always dry-run first (default; no OpenRouteService calls):

```bash
php bin/console app:geo:geocode-hospitals --state-id=<stateId> --participating-only
php bin/console app:geo:geocode-hospitals --state-id=<stateId> --participating-only --apply --force
php bin/console app:geo:fetch-isochrones --state-id=<stateId> --participating-only
php bin/console app:geo:fetch-isochrones --state-id=<stateId> --participating-only --apply --force
```

`--force` on isochrones is needed when cutting over files that have no `properties.origin` yet. After that, a later geocode that moves a hospital is picked up by `app:geo:fetch-isochrones --apply` **without** `--force`.

On production, run from the current release after deploy (`cd ~/www/current`). Keep `var/geo/hospital-isochrones` as a Deployer **shared** directory so files survive releases. See [deployment.md](deployment.md).

## First-time setup or cut-over from city centroids

Existing coordinates from `config/hospital_population/geocoding.yaml` (and fixtures) are **city/PLZ centroids**, not street points. The first street-level pass **must** use `--force`.

```bash
php bin/console app:geo:geocode-hospitals --state-id=<stateId>
php bin/console app:geo:geocode-hospitals --state-id=<stateId> --apply --force
php bin/console app:geo:fetch-isochrones --state-id=<stateId>
php bin/console app:geo:fetch-isochrones --state-id=<stateId> --apply --force
```

## New hospitals (same federal state)

Hospitals without coordinates:

```bash
php bin/console app:geo:geocode-hospitals --state-id=<stateId>
php bin/console app:geo:geocode-hospitals --state-id=<stateId> --apply
php bin/console app:geo:fetch-isochrones --state-id=<stateId> --apply
```

No `--force` unless you intend to overwrite already geocoded coordinates. Narrow with `--dispatch-area-id` or `--hospital-id` when only a few hospitals are new.

## Address or coordinate change

1. Fix the hospital address in the database (admin / import).
2. `app:geo:geocode-hospitals --hospital-id=<id> --apply --force` after a dry-run review. `--force` is required because coordinates already exist.
3. `app:geo:fetch-isochrones --hospital-id=<id> --apply` — refetches the file when the stored origin no longer matches.

## Adding another federal state

Geocode and isochrones are **per hospital**, grouped under `{stateId}` on disk. Repeat the first-time sequence for the new state's ID. That is enough for pins and travel-time polygons **if** hospitals have coordinates.

The orientation map **polygons** (dispatch-area outlines, Hessen GeoJSON) are still a Hessen pilot:

| Piece | Location | What to add for a new state |
|-------|----------|-----------------------------|
| Dispatch-area name → GeoJSON key | `config/case_flow/dispatch_area_geo_map.yaml` | Names as stored on `DispatchArea` |
| Area polygons | `assets/geo/hessen-landkreise.geojson` (today) | A GeoJSON with matching feature keys |
| Factory | `CatalogOrientationMapFactory` (`HESSEN_STATE_NAME`) | Enable the new state name and load its files |
| Stimulus map | `assets/controllers/catalog-orientation-map_controller.js` | Usually unchanged if GeoJSON + keys are wired |

Until those map files exist, allocation show for the new state can still display hospital pins and isochrones when coordinates/files are present, but district highlighting stays disabled.

## Command statuses

### `app:geo:geocode-hospitals`

| Status | Meaning |
|--------|---------|
| `skip` | Coordinates already set; skipped unless `--force` |
| `geocode` | Dry-run: would call OpenRouteService. Apply: wrote a street-level match |
| `missing-address` | No street, or neither postal code nor city |
| `unusable-match` | Result was missing, not Germany, or a coarse layer (`locality`, …) |
| `failed` | HTTP/transport error (run continues) |
| `rate-limited` | Provider limit reached after one retry; run aborts (exit 1) |

If `city` looks like a 5-digit PLZ and `postalCode` does not, the command swaps them before the request (fixture/import mix-up).

### `app:geo:fetch-isochrones`

| Status | Meaning |
|--------|---------|
| `skip` | File exists and `properties.origin` matches hospital lat/lng |
| `fetch` | Dry-run: would call OpenRouteService. Apply: wrote the file |
| `missing-coords` | Hospital has no latitude/longitude — geocode first |
| `failed` | HTTP/transport error (run continues) |
| `rate-limited` | Provider limit reached after one retry; run aborts (exit 1) |

Both commands preview by default. `--apply` writes. `--apply --dry-run` together: `--apply` wins (warning). `--delay-ms=0` is for tests only. The summary lists how many OpenRouteService requests are required before any writes in dry-run, and how many were executed after `--apply`.

## What not to do

- Do not call OpenRouteService on allocation show, hospital show, or fixture load.
- Do not commit `.env` or the API key.
- Do not treat fixture/YAML centroids as street-accurate in production; re-geocode with `--force` once.
- Do not delete `shared/var/geo/hospital-isochrones` on deploy; if it is empty, re-run the fetch command.
