# ADR 020: Portable reference catalog, private reference data outside the repository, and synthetic data from code

**Status:** accepted

## Context

The reference catalog holds the master data that imports resolve against: states and dispatch areas, hospitals, departments and specialities with their aliases, assignments, occasions, infections, secondary transports, normalized and raw indications, and indication groups (issue #740).

Today the curated catalog is a tracked file, `fixtures/reference/catalog.yaml`. Doctrine fixtures and the catalog commands (`app:reference:export`, `app:reference:import`) both use it, and the export writes to it by default. The catalog contains knowledge built up by reviewing real EMS allocation data: aliases, raw-to-normalized indication mappings, groups, label variants. It must be maintained apart from the public source code. `fixtures/reference/speciality-department-inventory.yaml` (review record of issue 656) and `fixtures/patterns/*.yaml` (distribution patterns) are tracked as well.

The existing YAML package is not a faithful copy of the database:

- Raw indications are exported with code and name only; `target` and `normalized` are rebuilt on import by matching code and name, so curated mappings between different labels are lost. Review status, comment and dates are not exported.
- Indication groups are exported as codes; the import attaches every normalized indication with that code, so membership can grow.
- Relationships use names. The entities have a `publicId` (UUID, `HasPublicId`), but it is not exported.
- There is no format version and no validation of the whole file before writing.
- `--mode=replace` deletes hospital access grants without saying so, checks only `allocation`, `mci_case` and `import` for dependent data, and does not run in one transaction.
- The reference data cache (`cache.allocation.reference_data`) is not cleared after an import.

Development and CI must work from a fresh clone without private files. Two follow-up issues build on this: reusable synthetic hospital and allocation fixtures (#792) and an offline demo environment (#791). The application is in its alpha phase; there are no external consumers of the catalog format.

## Decision

### Four kinds of data

| Kind | Examples | Lives in |
|------|----------|----------|
| Technical defaults | closure label catalogs, dispatch area name rules, enums (tier, size, location), normalization rules | PHP code |
| Synthetic data | Faker providers (Hesse geography, PZC indications, departments, lookups, hospitals), default distributions, test packages | PHP code |
| Private reference data | curated catalog with aliases, indication mappings, groups and label variants; review records | `var/reference/`, copies in a private repository |
| Private pattern packages | aggregated distributions exported from production | `var/patterns/`, copies in a private repository |

Operational state is never part of a package: users, access grants, hospital owner and participation, allocations, MCI cases, imports, rejects, closures, KPIs, projections, isochrone files.

Private reference data and pattern packages are never committed and never part of a release. Rewriting Git history is out of scope.

### Source of truth

The production database is the source of truth for the curated catalog; it is maintained through the admin interface. An export is a snapshot. The maintainer keeps copies in a private repository outside this one.

### Package format

A reference package is one JSON file:

- `meta`: `format` (`ivena-reference-catalog`), `version`, `exported_at`, `app_version`, `source_environment`, `complete`, `types`, `counts` per type, `checksum` (SHA-256 over the canonical encoding of the data sections), `sources`, `notes`.
- One section per type: `states`, `dispatch_areas`, `departments`, `specialities`, `assignments`, `occasions`, `infections`, `secondary_transports`, `indications_normalized`, `indications_raw`, `indication_groups`, `hospitals`.

Identity is the entity's `publicId`. References inside the package use these ids; names stay in the file for readability and for matching. Aliases are nested under their department or speciality; their identity is the normalized name, which is unique per alias type. Codes and postal codes are strings.

Content per type, beyond the current fields:

- Raw indications: `target` as id (null for unmapped entries), review status, comment, `reviewed_at`, `first_matched_at`. `normalized` is a copy of `target` that the import reads; it is not exported and is set to `target` on import.
- Normalized indications: `note`.
- Indication groups: `description`, `sort_order`, members as normalized indication ids (exact membership).
- Hospitals: id, coordinates (optional).
- Aliases: classification, source, note, validity dates (as today).

Export output is deterministic: fixed key order, sorted by natural key and id, pretty-printed. Two exports of the same database differ only in `exported_at`.

There is no backward compatibility. The YAML format and its reader, writer and fixture loader are removed. The importer accepts only the current `version`; a format change raises it and old files are exported again. A package with another `format` or `version` is rejected before any other check, with a message that names the expected and the found version; it is never read in part.

### Validation before any write

A package is validated completely before anything is written, with Symfony components and no JSON Schema library:

1. Structure and types: the Serializer decodes the JSON and denormalizes it into readonly DTOs with strict types, collecting all denormalization errors and rejecting unknown fields.
2. Field rules: Validator constraints on the DTOs (required fields, lengths, UUIDs, enums, code and postal code formats, nested validation).
3. Package rules: a class constraint on the package checks unique ids and natural keys per type, that every reference resolves, that mappings do not contradict each other, that `counts` and `checksum` match, and that `version` is the current one.

All violations are reported together with their path and value. One violation stops the import.

### Stable public ids, not stable database ids

Portability guarantees stable public identities, not stable database primary keys. A restore keeps every `publicId` from the package, but the rows get new local ids.

Anything that stores a local id of a reference row depends on one installation. The inventory lists what is known today: projections and materialized views, `job_run.hospital_id`, the audit log, the isochrone file path (state database id), saved Explorer views (`saved_explorer_view.config_json` with state, dispatch area and hospital ids), hospital-scoped notifications (`notification.scope_reference_id`), statistics filters in URLs and the reference data cache.

- Rows in tables are covered by the restore's dependency check (blockers) or emptied by the development reset, and projections and caches are rebuilt.
- Files and stored configuration that use local ids are listed in the inventory with their handling. The isochrone path moves to the state's `publicId` when prepared geographic data is reused (#791). Moving saved views and notifications to public ids is follow-up work, not part of #740.
- New code that stores a reference to a catalog row outside the database row itself (files, JSON configuration, cache keys, URLs meant to be kept) uses the `publicId`.

### Actors and environment-specific fields

- No user references in a package. On import, `createdBy`/`updatedBy` and, for reviewed raw indications, `reviewedBy` are the importing user (`--user`). `createdAt`/`updatedAt` are not exported. `reviewedAt` and `firstMatchedAt` are curation metadata and are exported; `firstMatchedBy` is dropped.
- Hospital `participating`, `participatingSince` and `owner` describe an installation, not a hospital. They are not exported; imported hospitals do not participate and have no owner.
- Hospital coordinates are exported. They come from geocoding, but geocoding needs network access and an API key; an imported hospital keeps its coordinates, and `app:geo:geocode-hospitals` only fills missing ones.

### Import modes

- **Additive (`--mode=add`)**: inserts what is missing. A row is matched by id, then by natural key. Same id with another natural key, or same natural key with another id, is a conflict and fails the import. Existing rows are not changed. The command states that this is not a restore.
- **Restore (`--mode=replace`)**: requires a complete package (`meta.complete`). It checks every table that references a reference table, with or without a foreign key: operational data (`allocation`, `mci_case`, `import`, `closure_interval`, `monthly_reminder_dispatch`), permissions (`hospital_access_grant`, hospital owners) and projections (`allocation_stats_projection`, `closure_event`, `closure_analysis_interval`, `closure_volume_hour`, `kpi_daily`, `job_run` and the others listed in the inventory). Several of these foreign keys cascade, so a delete would remove their rows silently. Any dependent row is a blocker; the import lists all blockers and stops. Without blockers it deletes the whole catalog including aliases and join tables and inserts the package in one transaction, keeping the ids from the file. A failure leaves the old catalog in place. Restore never deletes operational data or permissions.
- **Dry run** for both modes: per type the rows to create, skip, conflict or delete, and the blockers.
- After an import that wrote rows, the reference data cache is cleared. The command names projections that need a rebuild; after a restore without blockers there is no operational data to rebuild.

A package is a catalog, not a database backup. It does not restore users, permissions, allocations, imports or any other operational data.

### Development reset

Dependent development data is removed by an explicit command, `app:dev:reset-operational-data`, which refuses to run outside the development and test environments (the demo environment of #791 is added to the same check). It deletes allocations, MCI cases, imports, rejects, closures, KPIs, reminder dispatches, access grants and hospital owners, empties projections and refreshes materialized views. Users and the reference catalog stay; afterwards users have no hospitals assigned.

A reset or restore must not run while background work writes the same tables. Both commands refuse to start while the Doctrine Messenger queues hold pending or in-progress messages for the async transports, and tell the operator to stop the workers first (a held import or projection lock counts as well). Both run under one lock, so two resets or a reset and a restore cannot run at the same time.

A complete rebuild of a demo installation (users, hospitals, data) is a separate operation of #791 and not covered by this reset.

Local workflow for testing real input files:

1. Load the synthetic fixtures.
2. Copy the private package to `var/reference/catalog.json`.
3. Run the development reset.
4. Restore the private package (dry run first).
5. Geocode missing coordinates if needed, then import a real file.

### Location

Packages live in `var/`: reference packages in `var/reference/`, pattern packages in `var/patterns/`. The export writes there by default and the import reads from there by default (`--source` overrides it). `var/` is not tracked and not part of a release. In production, `var/reference` is a Deployer shared directory, so an export survives the next deploy; `var/patterns` follows with the pattern export.

### Synthetic reference data from Faker providers

Development, CI and a demo without private files use a synthetic catalog built in code. There is no catalog file in the repository and the `fixtures/` directory is removed.

- The Faker providers in `src/Allocation/Infrastructure/Faker/Provider/` are extended and also back the Foundry factories, so tests, fixtures and the later generators of #792 share one source.
- Where relationships matter, providers return consistent records rather than single strings.
- Geography covers Hesse only: the state and exactly the dispatch areas known to the maps. The area provider takes its names from `config/case_flow/dispatch_area_geo_map.yaml`, so every synthetic area has a shape in `assets/geo/hessen-landkreise.geojson`. Hospital coordinates are drawn inside the district polygon from the same file.
- The indication provider holds the official PZC list ("Patientenzuweisungscode und Rückmeldeindikation", AG PZC bundesweit, released by the IVENA Anwenderbeirat Hessen; version 1.3.11 of 13 June 2025) with its official wording, one entry per code, plus generated raw label variants. It is used as publicly available reference data, with the source cited. The curated label variants, extra rows per code, raw labels from real files, mappings and groups of the private catalog stay private; a test checks the provider against a denylist of known curated variants.
- A builder composes the providers with a seed into a package and loads it through the same validation and import path as a real package.
- The synthetic catalog is fully deterministic. Public ids are derived, not random: a name-based UUID (version 5) from a fixed project namespace, the type and the natural key. Choices that depend on chance (which towns, beds, coordinates, raw label variants) come from the seeded generator only, and the builder emits types and rows in a fixed order. The same seed and the same generator version produce the same package, byte for byte including the checksum; a test enforces this. A changed provider is a new generator version and may change the package.

### Distribution patterns

Generated allocations follow distribution patterns. The tracked patterns are removed: they sample every feature independently and their values are close to uniform, which makes generated data look artificial.

This ADR sets the direction for patterns; the work is split across issues:

| Part | Where |
|------|-------|
| Remove the tracked YAML patterns and the commands that write them; plain default distributions in code, in the existing pattern shape and keyed by the synthetic catalog, so the current fixtures keep working | #740 |
| Pattern package format and validation, conditional sampling along the chain, coherence rules, default distributions, hospital profiles, scenarios | #792 |
| Export of real patterns from production, privacy review | #797 (after #792) |
| Individual demo hospitals using pattern packages | #791 |

Direction for the later parts:

- A pattern package stores a chain of conditional distributions instead of independent ones: time (month, weekday, hour by day type), indication by hospital segment, then age band, sex, urgency, department and speciality by indication category (first PZC digit), then flags and the remaining features by category and urgency, plus transport time by urgency and a volume block per segment. A segment is a hospital type (tier and location, later a hospital profile of #792), never a single hospital. An optional `scenarios` section is reserved for #792.
- Pattern packages are JSON, live in `var/patterns/` and are validated the same way as reference packages.
- Without a pattern package the generator uses default distributions defined in code: coarse shares (whole or five percent, common values only), written by hand and reviewed by the maintainer.
- Coherence rules in code apply to every source (for example: pregnant patients are female and of child-bearing age, paediatric cases are younger than 18, CPR implies urgency 1 and a resuscitation room).
- Generation is deterministic: the same pattern package, seed and settings give the same data. Each generated dataset records the package checksum (or `defaults`) and the seed.

Privacy of pattern packages. Aggregated data is not anonymous by itself, so the export applies these rules and they are reviewed before a package is used outside the maintainer's own machine:

- Aggregates only, keyed by public values; no hospital ids or names; conditioning on the indication category, not the single code.
- A segment needs a minimum number of hospitals (proposal: 5) as well as a minimum number of cases, and no single hospital may contribute more than a set share of a segment's cases (proposal: 50 %); otherwise the segment is dropped.
- Cells below a minimum case count are merged into `other`. If exactly one cell of a table would be merged, a second one is merged too, so the hidden value cannot be computed from the total. All tables of a segment use the same base data, so combining tables does not reveal more than each table.
- Shares are rounded.
- A pattern package is private data: it never becomes part of tracked fixtures, and the code defaults are never generated from it automatically.
- The export rules get an explicit privacy review, including combinations of features and segment sizes, before a package is shown in a presentation to other hospitals. Passing that review is an acceptance criterion of the export issue (#797).

## Consequences

- Export → empty database → import → export gives the same package, apart from `exported_at`.
- The reference entities' `public_id` becomes mandatory; a migration fills missing values.
- The YAML catalog, the YAML pattern files with `app:fixtures:export-patterns` and `app:fixtures:validate-patterns`, `ReferenceYamlLoader`, `ReferenceDataLoader` and `app:reference:load-indication-groups` are removed; the reference fixtures collapse into one fixture that loads the synthetic package.
- Tests, factories and scenarios that rely on real names, codes or catalog sizes change to the synthetic data.
- Statistics and maps on a fresh clone show synthetic hospitals in the real Hesse districts.
- Isochrone files, saved Explorer views and hospital-scoped notifications still store local ids; they are documented in the inventory and moved to public ids in later work (#791 for isochrones).
- Synthetic public ids are name-based; a renamed synthetic entry gets a new id.
- Restoring a private catalog into an installation with operational data is blocked until that data is reset; in production this means a restore is only possible on an empty installation.
- The maintainer keeps private packages up to date by exporting from production.

## Alternatives

- **Keep YAML.** Rejected: JSON gives strict types (codes and postal codes stay strings) and fits the Serializer and Validator; comments are not needed in generated files.
- **JSON Schema with a validator library.** Rejected: the Symfony Serializer and Validator are already installed and in use, and package rules (references, duplicates, checksum) need custom code either way.
- **Keep a synthetic catalog file in the repository.** Rejected: Faker providers already exist, are reproducible with a seed and are shared with the factories; a file would duplicate them.
- **A full database dump instead of a package.** Rejected: it carries operational data, users and permissions, and cannot replace a synthetic catalog in a development database.
- **Keep the curated catalog in the repository and only mark it private.** Rejected: the repository is public and its contents ship with every release.
- **Indications only loosely inspired by the PZC list.** Rejected: real pattern packages are keyed by PZC code, and generated data looks real only with the official codes and wording.
- **Random UUIDs for synthetic rows, fixed by the seed.** Rejected: ids would change whenever a provider draws one more random value; name-based ids stay the same as long as the natural key does.
- **Build the whole pattern system in #740.** Rejected: it is an architecture topic of its own and belongs to the generator of #792; #740 only removes the old patterns and keeps the fixtures working.
- **A separate issue for the pattern format.** Rejected: the format is the generator's data model and is built with it in #792. Only the export of real patterns is separate, because #792 works without real data and the privacy review is an acceptance step of its own.

## Related

- ADR 001 (projections and materialized views)
- ADR 002 (hospital permission bitmask)
- ADR 012 (Explore data catalog)
- ADR 015 (closure volume metrics)
- Issues #740, #791, #792, #797
- Inventory: `docs/04-features/import/reference-catalog-inventory.md` (step 1 of #740)
