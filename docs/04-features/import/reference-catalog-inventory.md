# Reference catalog inventory

**Audience:** developers working on the reference catalog export, import, fixtures or synthetic data.

This page lists every type of reference data, how each field is classified, which tables depend on the catalog, and what is deliberately left out. The design is recorded in [ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md). Inventory taken on 2026-10-26 (`main` 19046b15, issue #740).

> **Implemented with #740.** The gaps and the YAML catalog (`fixtures/reference/catalog.yaml`) described below are the starting point; the YAML file, its loaders and the pattern files have since been removed. The current workflow is in [reference-catalog.md](reference-catalog.md), the package format in [reference-catalog-format.md](reference-catalog-format.md). The dependent-table and local-id sections still apply: they are what the restore checks.

## Classification

| Class | Meaning | In the package |
|-------|---------|----------------|
| **Portable** | Reference content that means the same in every installation | yes |
| **Derived** | Technical data that can be computed from portable content | no, rebuilt on import |
| **Environment** | State of one installation (users, permissions, operations) | no |

## Reference types

All entities below live in `src/Allocation/Domain/Entity/`. Except for the two alias entities, all use `HasRequiredPublicId` (UUID `public_id`, required and unique, created on persist unless set before) and are audited.

Fields that every type has and that are never exported:

| Field | Class | Reason |
|-------|-------|--------|
| `id` | Derived | Local auto-increment id; references in the package use `public_id` |
| `createdAt`, `updatedAt` | Derived | Describe when the row was written in this installation |
| `createdBy`, `updatedBy` | Environment | User accounts of the source installation; the importing user is set instead |

### States (`state`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `name` | Portable | Natural key |

### Dispatch areas (`dispatch_area`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `name` | Portable | Natural key together with the state. Maps match it by exact name (see [Geography](#geography)) |
| `state` | Portable | Reference to a state |

### Departments and specialities (`department`, `speciality`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `name` | Portable | Natural key |

### Department and speciality aliases (`department_alias`, `speciality_alias`)

No public id. `normalized_name` has a unique index per table, so one alias spelling belongs to exactly one department (or speciality).

| Field | Class | Notes |
|-------|-------|-------|
| owner (`department` / `speciality`) | Portable | Reference to the owner |
| `name` | Portable | Spelling as found in source files |
| `normalizedName` | Derived | Computed from `name`; identity of the alias within its type |
| `classification` | Portable | `ReferenceNameAliasClassification` |
| `source` | Portable | Where the alias came from |
| `note` | Portable | Curation note |
| `validFrom`, `validTo` | Portable | Validity period (dates) |

### Assignments, occasions, infections, secondary transports

`assignment`, `occasion`, `infection`, `secondary_transport`.

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `name` | Portable | Natural key |

### Normalized indications (`indication_normalized`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `code` | Portable | Integer column, exported as a three-digit string (`IndicationKey::normalizeCode`) |
| `name` | Portable | Several rows may share a code (curated split of one PZC code) |
| `note` | Portable | Not exported today |
| `children` | — | Inverse side of `IndicationRaw.target` |
| `groups` | — | Inverse side of the group membership |

### Raw indications (`indication_raw`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `code`, `name` | Portable | Label as found in source files; `name` may be empty |
| `hash` | Derived | `IndicationKey` of code and name; natural key used by the import |
| `target` | Portable | Curated mapping to a normalized indication, null = unmapped. Not exported today |
| `normalized` | Derived | Copy of `target`, see [Target and normalized](#target-and-normalized) |
| `reviewStatus` | Portable | `unreviewed`, `needs_review`, `matched`, `not_matchable`, `ignored`. Not exported today |
| `reviewComment` | Portable | Not exported today |
| `reviewedAt` | Portable | Not exported today |
| `reviewedBy` | Environment | Becomes the importing user for reviewed rows |
| `firstMatchedAt` | Portable | Not exported today |
| `firstMatchedBy` | Environment | Dropped |

### Indication groups (`indication_group`, join table `indication_group_indication_normalized`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity |
| `name` | Portable | Natural key |
| `category` | Portable | Free text |
| `description` | Portable | Not exported today |
| `sortOrder` | Portable | Not exported today |
| `indications` | Portable | Exact members by normalized indication id. Exported today as codes, which attaches every row sharing a code on import |

### Hospitals (`hospital`)

| Field | Class | Notes |
|-------|-------|-------|
| `public_id` | Portable | Identity; also part of the isochrone file path |
| `name` | Portable | Natural key |
| `state`, `dispatchArea` | Portable | References |
| `address` (embedded: street, postal code, city, state, country) | Portable | Postal code is a string |
| `tier`, `size`, `location` | Portable | Enums `HospitalTier`, `HospitalSize`, `HospitalLocation` |
| `beds` | Portable | |
| `latitude`, `longitude` | Portable | Result of geocoding; kept because geocoding needs network access and an API key. Not exported today |
| `owner` | Environment | User account |
| `isParticipating`, `participatingSince` | Environment | Participation in this installation. Exported today as `participating`; removed from the package |
| `accessGrants` (`hospital_access_grant`) | Environment | Permissions (ADR 002) |

## Target and normalized

`IndicationRaw` has two references to `IndicationNormalized`:

- **`target`** is the curated mapping. `IndicationRawReviewService` sets it when a match is proposed or approved, together with the review status and dates.
- **`normalized`** is what the import reads: `IndicationCreationStrategy` resolves a raw label through its hash and sets the allocation's normalized indication from `normalized`. `BackfillAllocationIndicationNormalizedService` copies `target` into `normalized` where `normalized` is empty and fills allocations from `COALESCE(normalized, target)`.
- `IndicationRawReviewHealthCheckQuery` treats `target` as the truth: `target ≠ normalized` is a failure, `normalized` without `target` is a warning ("copy normalized_id to target_id or clear normalized_id").

Decision: the package carries `target` only. On import, `normalized` is set to `target`. A row with `normalized` but no `target` is reported by the export as a warning, because its meaning is unclear and the health check already flags it.

## Constraints that matter for import

- The only unique indexes on reference tables are `public_id` and `normalized_name` on the two alias tables. Names, the raw indication hash and `state + name` of dispatch areas are unique only by convention. The package validation checks them; existing duplicates in a database surface as export warnings.
- `public_id` is required since migration `Version20261026215253`, which filled missing values (step 2 of #740).

## Tables that depend on the catalog

From the database schema (foreign keys, `on delete`: `c` = cascade, `a` = no action).

| Table | Columns | References | On delete | Kind |
|-------|---------|-----------|-----------|------|
| `allocation` | `state_id`, `dispatch_area_id`, `hospital_id`, `department_id`, `speciality_id`, `assignment_id`, `occasion_id`, `infection_id`, `secondary_transport_id`, `indication_raw_id`, `secondary_indication_raw_id`, `indication_normalized_id`, `secondary_indication_normalized_id` | all types except groups | a | operational |
| `mci_case` | `state_id`, `dispatch_area_id`, `hospital_id`, `department_id`, `speciality_id`, `occasion_id`, `infection_id`, `indication_raw_id`, `indication_normalized_id` | | a | operational |
| `import` | `hospital_id` | hospital | a | operational |
| `closure_interval` | `hospital_id`, `department_id`, `speciality_id` | | a | operational |
| `closure_event` | `hospital_id` | hospital | **c** | projection (`ClosureAnalysisRebuilder`) |
| `closure_analysis_interval` | `hospital_id` (c), `department_id`, `speciality_id` (a) | | mixed | projection |
| `kpi_daily` | `hospital_id` | hospital | **c** | projection |
| `monthly_reminder_dispatch` | `hospital_id` | hospital | **c** | operational |
| `hospital_access_grant` | `hospital_id` | hospital | **c** | permissions |
| `hospital` | `owner_id` → `user` | user | — | environment field on the catalog row |

Inside the catalog: `dispatch_area.state_id`, `hospital.state_id`, `hospital.dispatch_area_id` (a); `department_alias`, `speciality_alias`, the group join table (c); `indication_raw.target_id`, `indication_raw.normalized_id` (a).

Tables and views that store catalog ids **without** a foreign key:

| Relation | Columns | Kind |
|----------|---------|------|
| `allocation_stats_projection` | `state_id`, `dispatch_area_id`, `hospital_id`, `department_id`, `speciality_id`, `assignment_id`, `occasion_id`, `infection_id`, `secondary_transport_id`, `indication_normalized_id`, `secondary_indication_normalized_id` | projection |
| `closure_volume_hour` | `hospital_id`, `department_id`, `speciality_id` | projection (ADR 015) |
| `closure_rebuild_request` | `hospital_id` | projection queue |
| `closure_relation_candidate` | `hospital_id` | projection |
| `job_run` | `hospital_id` | monitoring (ADR 018) |
| `mv_projection_hospital_dimensions` | `state_id`, `dispatch_area_id`, `hospital_id` | materialized view |
| `mv_projection_state_hospital_count` | `state_id` | materialized view |
| `mv_projection_dispatch_area_hospital_count` | `dispatch_area_id` | materialized view |

`closure_volume_snapshot_*` tables are diagnostic copies created on demand by `ClosureVolumeSnapshotStore`; they are not part of the migrations but hold the same ids.

The audit log stores changes of the audited reference entities with their local ids; after a restore, old entries point to ids that no longer exist.

## Local ids outside the catalog tables

A restore keeps public ids but not local database ids (ADR 020). These places store local ids of reference rows:

| Place | What | Handling |
|-------|------|----------|
| Projection tables and materialized views (see above) | hospital and catalog ids | blocker for a restore, emptied by the development reset, rebuilt or refreshed |
| `job_run.hospital_id` | hospital of an import or rebuild run | blocker; emptied by the development reset |
| `audit_log` | entity id in each change | kept; old entries no longer resolve after a restore |
| `saved_explorer_view.config_json` | state, dispatch area and hospital ids in the saved filter scope | blocker for a restore; moving to public ids is follow-up work |
| `notification.scope_reference_id` | hospital of a hospital-scoped notification | blocker for a restore; moving to public ids is follow-up work |
| Statistics filters in URLs (`stateId`, `dispatchAreaId`, hospital ids) | bookmarks and shared links | not stored by the application; links break after a restore |
| `var/geo/hospital-isochrones/<state id>/…` | isochrone files | path keeps the state id; after a restore with new ids re-run `app:geo:fetch-isochrones` or apply a prepared location (#791) |
| `cache.allocation.reference_data` | Explore filter options | cleared after an import |

Consequences for a restore:

- Every operational, permission and projection table above is a blocker when it holds rows. The cascading foreign keys (`closure_event`, `kpi_daily`, `monthly_reminder_dispatch`, `hospital_access_grant`, `closure_analysis_interval`) would otherwise delete rows silently.
- Tables without a foreign key would keep stale ids; they are checked as well.
- The materialized views are refreshed after a restore.
- Today's `--mode=replace` checks only `allocation`, `mci_case` and `import` and deletes `hospital_access_grant` itself.

## Caches

- `cache.allocation.reference_data` (`config/packages/cache.yaml`) holds the Explore filter options (`ExploreFilterOptionsProvider`). It is cleared after an import that wrote rows.
- The import resolvers keep per-run caches only (for example the indication cache of `IndicationCreationStrategy`); a new import run starts empty.

## Geography

- **Maps:** `config/case_flow/dispatch_area_geo_map.yaml` maps each dispatch area name to a geo key (24 entries, all in Hesse). `config/case_flow/dispatch_area_geo_sources.yaml` maps the keys to official district names, and `app:statistics:case-flow:build-geojson` (`BuildCaseFlowGeoJsonCommand`) builds `assets/geo/hessen-landkreise.geojson` from `assets/geo/.source/hessen-kreise-raw.geojson` (BKG VG250 via Opendatasoft). Explore, case flow, hospital population and isochrone maps match a dispatch area **by exact name**. A dispatch area with another name has no shape. These files are public geography and stay in the repository.
- **Hospital coordinates:** `latitude`/`longitude` on the hospital, filled by `app:geo:geocode-hospitals` through OpenRouteService (`OPENROUTESERVICE_API_KEY`). `HospitalGeoScopeResolver` selects hospitals by hospital, dispatch area or state.
- **Isochrones:** `app:geo:fetch-isochrones` stores one GeoJSON per hospital in `var/geo/hospital-isochrones/<state id>/<hospital public id>.geojson` (`HospitalIsochroneFileStore`, Deployer shared directory). The hospital part is portable; the state part is the local database id. Prepared isochrones survive a restore only if the state keeps its id. The path stays on the state id: to reuse geographic data in another installation (offline demo, #791), prepare a location (`app:geo:prepare-location`, keyed by slug) and apply it there (`app:geo:apply-prepared-location`); see [hospital-geodata.md](../../05-operations/hospital-geodata.md#prepared-locations-offline-use).
- **For synthetic data:** synthetic dispatch areas use the names from `dispatch_area_geo_map.yaml`, and synthetic hospital coordinates lie inside their district polygon, so all maps work offline without geocoding.

## Indications and the PZC list

The normalized indications follow the official PZC list "Patientenzuweisungscode und Rückmeldeindikation" (AG PZC bundesweit, released by the IVENA Anwenderbeirat Hessen, distributed through the IVENA web portal; version 1.3.11 of 13 June 2025, 208 codes). It is used as publicly available reference data, with the source cited. The document itself is not stored in the repository.

Comparison with the curated catalog on 2026-10-26:

- All 204 codes of the curated catalog are in the official list; 172 names are identical.
- The other names are curation: labels without the `KST-` prefix, longer or older wordings, and extra rows sharing a code (for example one PZC code split into several planned transports).
- Four official codes are not in the curated catalog.

The synthetic indication provider holds the official codes with their official wording. The curated variants stay private (ADR 020).

## Other tracked data

| Location | Content | Class | Handling (#740) |
|----------|---------|-------|-----------------|
| `fixtures/reference/catalog.yaml` | Curated catalog (YAML) | Private | Removed; synthetic data from Faker providers |
| `fixtures/reference/speciality-department-inventory.yaml` | Review record of issue 656 with real labels and sources | Private | Removed |
| `fixtures/patterns/*.yaml` | Distribution patterns; values are close to uniform (urgency ≈ 33 % each, `is_cpr` and `is_pregnant` ≈ 50 %), so they come from fake data | Neither useful nor private | Removed; replaced by pattern packages and code defaults |
| `src/Import/Application/Mapping/Closure*Catalog.php` | IVENA labels of closure care level, reason, facility kind | Technical default | Stays in code |
| `src/Import/Application/Mapping/DispatchAreaNameNormalizer.php` | Generic prefixes and suffixes of dispatch area names | Technical default | Stays in code |
| `config/case_flow/*.yaml`, `assets/geo/*` | Hesse geography for the maps | Public | Stays |
| `src/Allocation/Infrastructure/Faker/Provider/*` | Synthetic names, areas, indications | Synthetic | Extended (ADR 020) |

Documentation (`reference-catalog.md`, `reference-catalog-yaml.md`, `docs/03-development/fixtures.md`) recommends committing exports to `fixtures/reference/catalog.yaml`; it is rewritten at the end of #740.

## Not part of the reference catalog

Users and credentials, hospital access grants and owners, participation, allocations, MCI cases, imports and import runs, rejects, closure intervals and events, KPIs, projections and materialized views, notifications, engagement and reminder data, analytics, CMS content, audit log, isochrone files, job runs and monitoring data.

## Related

- [ADR 020](../../02-architecture/decisions/020-portable-reference-catalog.md)
- [reference-catalog.md](reference-catalog.md)
- [reference-catalog-format.md](reference-catalog-format.md)
- [closure-import.md](closure-import.md)
