# ADR 041: Dispatch-area geography as master data

**Status:** accepted

## Context

`DispatchArea` is master data, but its map shape lives in repository files (`config/case_flow/*.yaml`,
`assets/geo/hessen-landkreise.geojson`) and is matched by name. Every new area needs a code change and a release, and
hospitals outside Hesse are expected to join (#1406). IVENA has no district field: a dispatch area covers one or more
districts, and allocations do not reference districts.

## Decision

### The database is the source for the maps

State → dispatch area → district is modelled as master data. A `District` is identified by its official key (AGS) and
belongs to at most one dispatch area. There is no permanent seed file and no seed step in the regular deployment. The
reference catalog transfers districts and assignments; geometries move through the database backup or an admin action.

The state key is stored on the district without a relation to `State`, so districts of other states do not add empty
states to filters and Explore.

### Boundaries in a separate table

`district_geometry` holds the boundary, its provenance (provider, licence, attribution, retrieval date) and the fetch
state. It is not audited and not loaded with district lists, because the audit log must not store polygons.

### Boundaries are fetched only on an explicit admin action

An admin starts the fetch for a district as a background job against the BKG (VG250, `dl-de/by-2-0`). Creating a hospital
or district, a catalog or IVENA import and a map request never cause an external call. Every attempt has a unique id, and
only the current attempt may write its result, so a late job cannot overwrite a newer one. The first fetch never replaces
a stored geometry.

### One completeness rule

A dispatch area has a map shape only if it has at least one district and every assigned district has a usable geometry.
The map endpoint, the geo index, the admin check and the "cases without map area" hint use this one rule.

### One-time takeover before the map switch

Existing assignments and geometries are taken over offline, keyed by AGS, by a temporary Deployer task that runs after
the migrations and before the symlink switch. It verifies all 29 existing shapes against reference data in its transfer
file. If the check fails, the deployment stops and the previous release stays active.

## Alternatives considered

- **Ship all of Germany's boundaries in the repository:** adds data most installations never use and ties updates to
  releases.
- **A permanent seed file for Hesse and its neighbours:** a second source next to the database, and a deploy step that
  could conflict with maintained data.
- **Fetch boundaries automatically when they are needed:** imports and page views would depend on an external service.
- **A shared geo provider registry in this change:** not needed for one boundary source; it can be planned separately
  together with geocoding, coordinate provenance and isochrones.

## Implementation note (step 6)

The shapes cache key contains a fingerprint of the source rows instead of an explicit invalidation. A committed change of an assignment, geometry, area or hospital therefore takes effect on the next read, also after a catalog import, restore or database restore, and no save path can forget to clear the cache. Feature keys are dispatch area ids.

## Consequences

- A new region is data maintenance and needs no release.
- Assigning a district without a geometry hides its dispatch area from the maps until the fetch succeeds.
- Existing boundaries keep their vintages (2023 and 2025) until they are refreshed (#1517).
- This supersedes the statements in ADR 020 and `reference-catalog-inventory.md` that map geography stays in the
  repository; both are updated when the old path is removed.
