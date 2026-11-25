# Hospital geodata (coordinates and isochrones)

**Audience:** Operators and developers who need to geocode hospital addresses, fetch destination isochrones, or add another federal state.

Allocation detail maps (`/explore/allocation/{publicId}`) **never** call OpenRouteService. They only read hospital coordinates from the database and GeoJSON files under `var/geo/hospital-isochrones`. The two console commands below are the only write path that calls OpenRouteService for hospitals; [prepared locations](#prepared-locations-offline-use) reuse the same clients and write the same files.

See also: [../04-features/allocation/orientation-map.md](../04-features/allocation/orientation-map.md), [../04-features/statistics/isochrone-origin-heatmap.md](../04-features/statistics/isochrone-origin-heatmap.md), [../06-reference/console-commands.md](../06-reference/console-commands.md), [configuration.md](../06-reference/configuration.md), [deployment.md](deployment.md).

## What lives where

| Data | Storage | Who writes it | Who reads it |
|------|---------|---------------|--------------|
| Hospital street address | `hospital` / address columns | Import, fixtures, admin | Geocode command |
| `Hospital.latitude` / `longitude` | Database | `app:geo:geocode-hospitals --apply` (production). Fixtures still seed city/PLZ centroids for local/CI. | Map pins, isochrone command |
| Destination isochrones (5–50 min, 5-minute bands, driving-car) | `var/geo/hospital-isochrones/{stateId}/{hospitalPublicId}.geojson` | `app:geo:fetch-isochrones --apply`, `app:geo:apply-prepared-location --apply` | Allocation show map (one 5-minute band); Statistics Overview / Insights origin heatmap (10-minute bands at 10/20/30/40/50 min, hospital scope) |
| Prepared locations (address, coordinates, isochrones; one JSON per slug) | `var/geo/prepared-locations/{slug}.json` (`app.prepared_locations_dir`) | `app:geo:prepare-location --apply` | `app:geo:apply-prepared-location` |
| District boundaries | `district_geometry`, part of the database backup | One-time takeover, admin action **Fetch district boundary**, demo fixtures | `GET /statistics/geo/dispatch-areas.geojson` |
| Map background tiles | Tile server from `MAP_TILE_URL` (default OpenStreetMap) | — (not stored) | Browser (Leaflet), see [Map tiles](#map-tiles-and-offline-use) |

IDs in the CLI are numeric Doctrine IDs (`Hospital`, `DispatchArea`, `State`), not names or ISO codes. Isochrone files include `properties.origin` (`lat`/`lng` used for the OpenRouteService request). The fetch command skips an existing file only when that origin still matches the hospital coordinates.

## Everyday use in the web app

Hospital users and reviewers do **not** load or refresh geographic data in the UI. The Explore allocation map and the Statistics isochrone origin heatmap only display what is already stored.

| What you see | Meaning | Who fixes it |
|--------------|---------|--------------|
| Orange destination pin | Hospital has street-level (or at least stored) coordinates | — |
| No destination pin | `Hospital.latitude` / `longitude` are empty | Operator: `app:geo:geocode-hospitals --hospital-id=… --apply` |
| Travel-time band around the destination | An isochrone file exists and the recorded travel time is between 5 and 50 minutes | — |
| Pin but no travel-time band | No isochrone file yet, origin mismatch after a coordinate change, or travel time outside 5–50 minutes | Operator: `app:geo:fetch-isochrones --hospital-id=… --apply` (geocode first if the pin is missing) |
| District polygons / origin highlight | Map asset for Hesse and neighbouring areas; independent of OpenRouteService | See [orientation-map.md](../04-features/allocation/orientation-map.md) and [Adding another federal state](#adding-another-federal-state) |

The hospital-population statistics map uses a separate city/PLZ centroid YAML for local and CI fixtures. It is not updated by these commands.

## Data sources page

The public page `/data-sources` (footer, legal column) lists the geographic sources with their use, licence and source note. District boundaries come from the provenance stored in `district_geometry`. The map tile entry follows `MAP_TILE_URL` and `MAP_TILE_ATTRIBUTION`, without showing the address. When you add another external source without stored provenance, add it to `DataSourceCatalog`.

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
- Requests go to the HeiGIT API host `api.heigit.org` (`/pelias/v1/search/structured` for geocoding, `/openrouteservice/v2/isochrones/driving-car` for isochrones). The former host `api.openrouteservice.org` was switched off on 2026-11-24 and answers `410 Gone`; existing API keys work on the new host.
- Geocoding uses Pelias structured search, country Germany, usable layers `address` / `venue` / `street` only (`locality` is rejected so city centroids are not stored again).
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

On production, run from the current release after deploy (`cd <deploy_path>/current`, see [paths on the server](deployment.md#paths-on-the-server)). Keep `var/geo/hospital-isochrones` as a Deployer **shared** directory so files survive releases. See [deployment.md](deployment.md).

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

## Districts and their boundaries

Districts are master data ([ADR 041](../02-architecture/decisions/041-dispatch-area-geography-as-master-data.md)). In the
admin under **Data → Districts**:

1. Create the district with its official key (AGS) and name, and assign its dispatch area.
2. Click **Fetch district boundary**. A background job fetches it from the BKG (VG250, `dl-de/by-2-0`), so a Messenger
   worker must be running. Nothing else calls the BKG: not creating records, imports or map requests.
3. The district shows whether the boundary is missing, being fetched or failed. A failed fetch, or one that did not finish
   within 15 minutes, can be started again. An existing boundary is not replaced.

A dispatch area has a complete map area once every assigned district has a boundary. `app:geo:check-dispatch-areas`
lists the areas with allocations that are not complete yet.

## Adding another federal state

Geocode and isochrones are **per hospital**, grouped under `{stateId}` on disk. Repeat the first-time sequence for the new state's ID. That is enough for pins and travel-time polygons **if** hospitals have coordinates.

Map **polygons** (dispatch-area outlines) come from the database. A new dispatch area from any state gets its shape through data maintenance, without a code change or release: create its districts, assign them and fetch their boundaries as described in [Districts and their boundaries](#districts-and-their-boundaries). An area without a hospital gets a dashed outline and does not set the default map view. A federal state page in Explore shows a map as soon as one area of that state has a complete shape.

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

## Prepared locations (offline use)

The offline demo (#791) shows maps and isochrones around a real place while hospital identities and
clinical records stay synthetic. A **prepared location** carries everything the maps need for one
place: label, address, coordinates and the destination isochrones (5–50 minutes, the same format and
`properties.origin` as a hospital file). It is keyed by a slug, not by a database id, so it moves
between installations (dev, demo) and can be applied to a hospital that does not exist yet when it is
prepared.

Prepared location files name real places, often a prospective participant. **Never commit them, never
copy them into tests or fixtures, and never attach them to issues or pull requests.** They live under
`var/` (git-ignored).

### Prepare while online

Needs `OPENROUTESERVICE_API_KEY` (in the demo environment: `.env.demo.local`). Dry-run first; it
shows the steps and how many OpenRouteService requests `--apply` makes (at most two).

```bash
# From an address (geocode + isochrones: 2 requests)
php bin/console app:geo:prepare-location klinikum-musterstadt --label="Klinikum Musterstadt" \
    --street="Musterstraße 1" --postal-code=12345 --city=Musterstadt
php bin/console app:geo:prepare-location klinikum-musterstadt --label="Klinikum Musterstadt" \
    --street="Musterstraße 1" --postal-code=12345 --city=Musterstadt --apply

# From a manual pin (isochrones only: 1 request); address optional, used to fill an empty hospital address
php bin/console app:geo:prepare-location klinikum-musterstadt --lat=50.5 --lng=8.25 --apply

# From a hospital that already has coordinates; reuses its isochrone file when the origin matches (0 requests)
php bin/console app:geo:prepare-location klinikum-musterstadt --from-hospital-id=<id> --apply
```

The geocoding rules are those of `app:geo:geocode-hospitals` (street-level matches only). If no
usable match comes back, pass `--lat`/`--lng`. An existing slug is only replaced with `--force`. Rate
limits abort after one retry; re-run later.

### Use offline

```bash
php bin/console app:geo:list-prepared-locations
php bin/console app:geo:apply-prepared-location klinikum-musterstadt --hospital-id=<id>          # preview
php bin/console app:geo:apply-prepared-location klinikum-musterstadt --hospital-id=<id> --apply
```

Applying needs no network and no API key. It

- sets the hospital's latitude/longitude (different existing coordinates only with `--force`),
- fills the address only when street, postal code and city are all empty,
- writes the isochrone file through the regular store, so every map and the heatmap read it as usual,
- never changes name, dispatch area, state or any other master data.

After applying, `app:geo:fetch-isochrones` reports the hospital as `skip` (origin matches). The
dispatch area still decides which district outline is highlighted; choose a hospital in the matching
dispatch area. The demo hospital wizard (#791) calls the same `PreparedLocationApplier`.

The isochrone file path keeps the state's **database** id. Prepared locations make moving it to the
state public id unnecessary: applying writes into the path of the current installation.

## Map tiles and offline use

All Leaflet maps take their background tiles from `MAP_TILE_URL` (with `MAP_TILE_ATTRIBUTION` and
`MAP_TILE_MAX_ZOOM`), passed to the browser through `<meta name="map-tiles">` in the base layout and
read by `assets/js/geo-map/createMap.js`. The demo environment uses the default as well.

| `MAP_TILE_URL` | Result |
|----------------|--------|
| default `https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png` | OpenStreetMap tiles (needs network) |
| empty | Manual switch: no basemap. District outlines, pins and isochrones on a neutral background, no tile requests. |

**Tile server unreachable** (no network during a demo, OpenStreetMap down): a map drops its tile layer
after 4 failed tile requests without a single loaded tile and shows the same neutral background as
the empty setting. Districts, pins and isochrones stay visible. Maps created later on the same page
(also after Turbo navigation) skip the tile layer; a full reload tries the tile server again. So the
browser console shows a handful of failed tile requests, not one per tile. A tile request that hangs
instead of failing is not detected; in that case set `MAP_TILE_URL` empty.

Everything else the maps load is local: Leaflet and Turf through AssetMapper (`assets/vendor`, run
`php bin/console importmap:install` once while online), and the district polygons from
the database.

There is no local tile cache and no command downloads tiles; bulk downloading from
`tile.openstreetmap.org` is not allowed by the OpenStreetMap tile usage policy. When `MAP_TILE_URL`
points to another tile server, set `MAP_TILE_ATTRIBUTION` to match it and add the host to `img-src` /
`connect-src` ([content-security-policy.md](content-security-policy.md)).

## What not to do

- Do not call OpenRouteService on allocation show, hospital show, or fixture load.
- Do not commit `.env` or the API key.
- Do not treat fixture/YAML centroids as street-accurate in production; re-geocode with `--force` once.
- Do not delete `shared/var/geo/hospital-isochrones` on deploy; if it is empty, re-run the fetch command.
- Do not commit prepared location files or use real places in tests; tests use made-up addresses.
