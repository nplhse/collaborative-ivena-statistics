# Data catalog

**Audience:** Developers who add stored variables or let a context or plugin document its data objects.

The data catalog (`/explore/data-catalog`, #1415) shows what is stored: per data object the variables in
groups, with label, short description, storage location, type, whether the value may be missing, and the
permitted codes or range. It shows structure only and runs no query against case data. It is the first
slice of #597; data profiles, mappings to analyses and versioning stay there.

## Pages

| Route | Page |
|---|---|
| `app_explore_data_catalog_index` (`/explore/data-catalog`) | One card per visible object: name, description, number of variables, core or plugin |
| `app_explore_data_catalog_show` (`/explore/data-catalog/{objectKey}`) | Groups with in-page links, one table per group, an anchor per variable |

Explore links to the catalog with a tile and a sidebar entry in the reference group, next to the glossary.
Access follows Explore (`ROLE_PARTICIPANT`); an object with a granted attribute is shown only to users who
have it, and its detail page answers 404 otherwise. Table and column names are shown to everyone who sees
the catalog, as a small code line per variable, because they describe structure, not data.

## Documented objects

| Object | Contributor | Visible to |
|---|---|---|
| `allocation` (with the ABCDE assessment) | `AllocationDataCatalogContributor` | Explore users |
| `mci_case` | `AllocationDataCatalogContributor` | Explore users |
| `hospital` | `AllocationDataCatalogContributor` | Explore users |
| `encounter` (base data) | `EncounterDataCatalogContributor` (`src/Encounter/Application/DataCatalog/`) | `ENCOUNTER_VIEW_CASES_IN_ANY_HOSPITAL` |
| `ed_visit` (plugin) | `EdVisitDataCatalogContributor` (`plugins/ed-visit/src/Application/DataCatalog/`) | `ENCOUNTER_VIEW_CASES_IN_ANY_HOSPITAL` |

Left out on purpose: owners, audit fields (created/updated by), the import reference, the encounter case key
and key generation (pseudonyms for matching), the user who set an encounter assignment, the ED visit uid and
fingerprint, and the ED isolation codes (a JSON code set, which the catalog cannot describe yet).

## Variables

A variable is opt-in: only listed fields appear. Surrogate and public ids, hashes, free-text notes and
import references stay out unless chosen on purpose.

| Information | Source |
|---|---|
| Key (`allocation.urgency`) | Curated, stable, independent of property and column; unique across all objects |
| Group | Curated (`time`, `patient`, `urgency`, `clinical`, `resources`, `transport`, `destination`, `context`, `geography`, …) |
| Label and description | Translation keys `data_catalog.variable.<key>.label` / `.description` in the object's domain, EN and DE |
| Table and column | Doctrine mapping; embedded fields (`times.arrivedAt`) and to-one paths (`assessment.airway`) resolve to their own table and column |
| Type | Doctrine type: integer, decimal, date/time, boolean, code (enum), text, reference (to-one association) |
| May be missing | Doctrine nullability; a path through an optional association is nullable |
| Codes | `Enum::cases()`; labels from the enum's `label()` key (`CodeLabels::fromEnumLabel($domain)`) or a key prefix, the code and an optional suffix (`CodeLabels::prefixed($prefix, $domain, $suffix)`) |
| Range | Curated `ValueRange` (min, max, unit key), only where the code already defines a domain range: the import validation of the age (1–99) for allocations and MCI cases, the `Encounter` constructor for encounters, the exchange-format field contract (`EdVisitDimension`) for ED visit pain score and vital signs. The unit key is translated in the object's domain |
| Reference target | Doctrine target entity; linked to its catalog entry when that object is documented and visible, otherwise its table is named |

Object keys: `data_catalog.object.<key>.title` / `.description`; group labels: `data_catalog.group.<group>`,
both in the object's domain. Type, column and status labels (`data_catalog.type.*`, …) are in `allocation`.

## Add a stored variable

1. Add a `VariableDefinition` to the object's contributor (for Allocation `AllocationDataCatalogContributor`).
2. Add label and description in EN and DE in the object's translation domain.
3. Run `DataCatalogMetadataTest`: it resolves every variable against the mapping and checks the translations.

## Contribute a data object

Implement `DataCatalogContributorInterface` (tag `allocation.data_catalog_object`, autoconfigured) in the
owning context or plugin, in its `Application/DataCatalog/` directory, and return `DataObjectDefinition`s.
Contributors are wired only through the tag, which Psalm cannot see; `psalm.xml` exempts
`src/*/Application/DataCatalog/` from `UnusedClass` for that reason (a plugin adds its own directory
there). `PluginBoundaryTest::ALLOWED` lets a plugin use the extension point and `ValueRange`. Set `contributedByPlugin: true` for a plugin and a `grantedAttribute` when its data area is
restricted. See
[extension-points.md](../../02-architecture/extension-points.md#explore-data-catalog).

## Code locations

| Area | Path |
|---|---|
| Extension point, registry, resolver | `src/Allocation/Application/Explore/DataCatalog/` |
| Contributors | `src/Allocation/Application/DataCatalog/`, `src/Encounter/Application/DataCatalog/`, `plugins/ed-visit/src/Application/DataCatalog/` |
| View DTOs | `src/Allocation/Application/DTO/DataCatalog/` |
| Controllers | `src/Allocation/UI/Http/Controller/DataCatalog/` |
| Templates | `src/Allocation/UI/Twig/templates/data_catalog/` |
| Tests | `tests/Allocation/{Unit,Integration,Functional}/**/DataCatalog*`, `tests/Encounter/Functional/DataCatalog/`, `plugins/ed-visit/tests/**/DataCatalog/` |
