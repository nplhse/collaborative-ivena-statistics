# Data catalog

**Audience:** Developers who add stored variables or let a context or plugin document its data objects.

The data catalog (`/help/data-catalog`, #1415) shows what is stored: per data object the variables in
groups, with label, short description, storage location, type, whether the value may be missing, and the
permitted codes or range. It shows structure only and runs no query against case data. It is the first
slice of #597; data profiles, mappings to analyses and versioning stay there.

## Pages

| Route | Page |
|---|---|
| `app_help_data_catalog_index` (`/help/data-catalog`) | One card per visible object: name, description, number of variables, core or plugin |
| `app_help_data_catalog_show` (`/help/data-catalog/{objectKey}`) | Groups with in-page links, one table per group, an anchor per variable |

The help links to the catalog from its Reference card and the footer help column, next to the glossary
([ADR 042](../../02-architecture/decisions/042-glossary-and-data-catalog-in-help.md)).
Access needs `ROLE_PARTICIPANT`; an object with a granted attribute is shown only to users who
have it, and its detail page answers 404 otherwise. Table and column names are shown to everyone who sees
the catalog, as a small code line per variable, because they describe structure, not data.

## Documented objects

| Object | Contributor | Visible to |
|---|---|---|
| `allocation` (with the ABCD assessment) | `AllocationDataCatalogContributor` | Explore users |
| `mci_case` | `AllocationDataCatalogContributor` | Explore users |
| `hospital` | `AllocationDataCatalogContributor` | Explore users |
| `dispatch_area`, `state` (regions, #1492) | `AllocationDataCatalogContributor` | Explore users |
| `speciality`, `department`, `indication_normalized`, `indication_group`, `assignment`, `infection`, `occasion`, `secondary_transport` (reference objects, #1492) | `AllocationDataCatalogContributor` | Explore users |
| `encounter` (base data) | `EncounterDataCatalogContributor` (`src/Encounter/Application/DataCatalog/`) | `ENCOUNTER_VIEW_CASES_IN_ANY_HOSPITAL` |
| `ed_visit` (plugin) | `EdVisitDataCatalogContributor` (`plugins/ed-visit/src/Application/DataCatalog/`) | `ENCOUNTER_VIEW_CASES_IN_ANY_HOSPITAL` |

Left out on purpose: owners, the users who created or changed a record (created/updated by), the import reference, the encounter case key
and key generation (pseudonyms for matching), the user who set an encounter assignment, the ED visit uid and
fingerprint. For the reference objects also the editorial description and display order of indication groups,
the mapping tables (department and speciality aliases, raw indications) and the many-to-many link between
indications and indication groups, which is no to-one reference; the descriptions of both objects mention it.
With them, every reference of `allocation`, `mci_case` and `hospital` links to a catalog entry, except the raw
indications.

Every object ends with the group `record` ("Datensatz", #1499): its numeric `id`, the key other tables refer to,
and the record timestamps `created_at` / `updated_at` where the entity stores them (hospital and reference
objects both, encounter and ED visit only `created_at`). Allocation and MCI case store no record timestamp; their
`created_at` in the group `time` is the IVENA time. The ED visit has no id of its own, its primary key is
`ed_visit.encounter`. Public ids (UUIDs in Explore URLs) and the users who created or changed a record stay out.

Regions are documented like the other objects: their stored fields, nothing more. Districts with their
official keys (AGS) and map geometry are maintained in the admin and are not part of this catalog (see
[hospital-geodata.md](../../05-operations/hospital-geodata.md#districts-and-their-boundaries)).

## Variables

A variable is opt-in: only listed fields appear. Public ids, hashes, free-text notes and import references
stay out unless chosen on purpose; the surrogate id is listed in the group `record`.

| Information | Source |
|---|---|
| Key (`allocation.urgency`) | Curated, stable, independent of property and column; unique across all objects |
| Group | Curated (`time`, `patient`, `urgency`, `clinical`, `resources`, `transport`, `destination`, `context`, `geography`, …; `record` last) |
| Label and description | Translation keys `data_catalog.variable.<key>.label` / `.description` in the object's domain, EN and DE |
| Table and column | Doctrine mapping; embedded fields (`times.arrivedAt`) and to-one paths (`assessment.airway`) resolve to their own table and column |
| Type | `DataCatalogType` from the Doctrine type, shown with its technical term and a Tabler icon (#1499): Integer, Decimal, DateTime, Date, Time, Boolean, Enum (code), String (text), Reference (to-one association); Enum set (several codes per record, stored as a JSON list) when the definition names its enum. The icon (`DataCatalogType::icon()`, local file under `assets/icons/tabler/`) only supports the label |
| Storage type | Column declaration of the configured platform from the mapping (`varchar(255)`, `smallint`, `timestamp(0) without time zone`, `jsonb`); a string without length gets the schema tool's default length, a reference the type of the referenced column. The server version is configured, so this needs no connection |
| Time zone | DateTime and Time show "Time zone Europe/Berlin" in the values column with a "See note" badge linking to a note card at the end of the page: Doctrine writes the clock time of the application time zone (`date_default_timezone_set()` in `src/Kernel.php`) into a column without zone, so the exact point in time follows from value and zone. The note shows a winter and a summer example converted to UTC (computed in `ShowDataCatalogObjectController`), recipes for SQL, Python and R, and that one hour repeats when clocks go back |
| May be missing | Doctrine nullability; a path through an optional association is nullable |
| Codes | `Enum::cases()` of the column's `enumType`, or of the definition's `codeSetEnum` for a code set; labels from the enum's `label()` key (`CodeLabels::fromEnumLabel($domain)`) or a key prefix, the code and an optional suffix (`CodeLabels::prefixed($prefix, $domain, $suffix)`). Lists with more than five codes (`CatalogVariable::VISIBLE_CODES`) start collapsed in a `<details>` element |
| Glossary | Optional `GlossaryReference` on the definition (route, parameters, section anchor, #1507): the values column shows a badge "Definitions in the glossary" linking to the definition page; the glossary page links back ("Stored as") to every visible variable that names it (`DataCatalog::glossaryUsages()`). Set for urgency and transport type of allocation and MCI case, for hospital tier, size and location, and for the MTS category, diagram and indicator of triage and retriage (`ed_visit`, MTS glossary sections) |
| Range | Curated `ValueRange` (min, max, unit key), only where the code already defines a domain range: the import validation of the age (1–99) for allocations and MCI cases, the `Encounter` constructor for encounters, the exchange-format field contract (`EdVisitDimension`) for ED visit pain score and vital signs. The unit key is translated in the object's domain |
| Reference target | Doctrine target entity; linked to its catalog entry when that object is documented and visible, otherwise its table is named |

Object keys: `data_catalog.object.<key>.title` / `.description`; group labels: `data_catalog.group.<group>`,
both in the object's domain. Type, column and status labels (`data_catalog.type.*`, …) are in `allocation`.

## Add a stored variable

1. Add a `VariableDefinition` to the object's contributor (for Allocation `AllocationDataCatalogContributor`).
   For a code set stored as a JSON list (`ed_visit.isolation`, #1443) name its backed enum with
   `codeSetEnum`, because a JSON column has no `enumType`; the resolver rejects a class that is no backed
   enum and a code set on a column that is not JSON.
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
