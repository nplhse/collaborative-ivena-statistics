# ADR 037: Encounter as case hub, schemas as Symfony bundles

**Status:** proposed

## Context

ADR 026 introduced the `Encounter` context with one record type: `ed_visit`, one ED visit with
Manchester Triage System (MTS) triage, process times, disposition and initial vitals. Further kinds of
hospital data are expected: inpatient stays and diagnoses from controlling, prehospital data from EMS
records. They differ much more between hospitals and vendors than the IVENA formats do, and adding each
of them through an application release does not scale.

The schema registry (`encounter.dimension`) already describes field contracts per schema, and the
upload form lists its schemas. Processing is not open: `EncounterFileImporter` maps every file with the
`ed_visit` row mapper into the `Encounter` entity and records MTS catalog codes. Review:
[encounter-schema-plugins-review.md](../../04-features/encounter/encounter-schema-plugins-review.md)
(#1273).

Forces:

- **IVENA stays primary.** Allocation and closure imports are fixed IVENA formats that every instance
  uses. They are not the target of this decision.
- **The case is the unit of interest.** Data from ED, controlling and EMS describe the same case. The
  IVENA allocation is the natural anchor; MTS data also produce cases without an allocation (ADR 026).
- **Security-critical steps must exist once.** Key generation lock and check value (ADR 027), detection
  of identifying content, guarded commit, rejects, retention and deletion (ADR 029) must not be
  reimplemented per data source.
- **No patient-level key.** ADR 027 gives a stable case pseudonym per hospital and key generation; it
  must not become a way to find the same patient again across cases.
- **Data arrive in any order and can be ambiguous.** An inpatient stay may be imported before the ED
  visit it follows; one administrative case can cover several ED visits.
- **Clinical data.** Code that processes it is reviewed and deployed by the operator; nothing is
  installed at runtime.
- **Beta, one author.** The plugins are written by the maintainer for now. The contract has to work
  until 1.0, not be frozen.

## Decision

### Plugin unit

- A plugin contributes one or more **schemas of `ImportType::ENCOUNTER`**. It is a Symfony bundle,
  installed with Composer and enabled in `config/bundles.php`. Enabling or removing one needs a
  deployment (cache clear, migrations); there is no installation at runtime.
- Allocation and closure imports stay import types of the core. `ImportType` is not extended by
  plugins.

### Encounter as case hub

- An `Encounter` is the hub of **one case** for which more exists than the IVENA allocation. It knows
  only base data: hospital, key generation, case pseudonym, anchor time, age, sex and at most one link
  to an allocation (unique per allocation, changeable).
- Everything else is a **record** of a schema: an ED visit with MTS, an inpatient stay, a diagnosis
  list, an EMS record. A record that belongs to an existing case attaches to its encounter; it does not
  create a second encounter for the same case.
- An encounter has **no owning import**. The imports involved in an encounter are derived from the
  records that reference it.

### Case identity and time anchor

- The **IVENA allocation is the primary anchor** of an encounter. Its time reference is the allocation's
  `arrivalAt`, the **expected arrival** announced in IVENA. The clinical admission documented by the
  hospital (for example the ED admission of `ed_visit`) can differ from it; schemas keep their own
  clinical times in their records, and matching compares them with the expected arrival (ADR 030).
- A case pseudonym can belong to **several encounters** within one hospital and key generation, as long
  as their anchors differ, for example two allocations under one administrative case. The pseudonym
  is an attribute that helps to find candidates, not the identity of an encounter.
- An encounter without an allocation is created by a schema from its own data. In the first version
  only `ed_visit` creates such encounters. **Open decision:** which time is the anchor of an encounter
  without an allocation: the fingerprint anchor of ADR 029 (first contact of the initial assessment,
  else ED admission) or the matching anchor of ADR 030 (ED admission, else the assessment). Until it
  is decided, `ed_visit` stores the fingerprint anchor, as today.
- The boundary of ADR 027 stays: there is no patient-level key, and encounters of different cases are
  never linked.

### Assigning records to encounters

A schema handler asks the core for the encounter of a record through a service of the contract layer:
by allocation link, by case pseudonym and time, or by creating a new encounter. The service returns one
of four results:

| Result | Example | Handling |
|---|---|---|
| **assigned** | exactly one candidate | The record is attached. |
| **no candidate** | the inpatient stay arrives before its ED visit | The record is stored **unassigned**; the core tries again after later encounter imports of the hospital. |
| **ambiguous** | the pseudonym matches several encounters and the time does not decide | The record is stored unassigned with an **assignment conflict** that names the candidates. |
| **contradictory** | allocation link and pseudonym point to different encounters | As ambiguous, with both candidates. |

- Nothing is guessed. Unassigned records and conflicts are counted in the import report.
- Conflicts are visible to users with the `REVIEW` grant of the hospital (ADR 028) and are resolved by
  hand: choose a candidate, or leave the record unassigned. After the decision the record is processed
  further like an assigned one (for example its section appears in the case file). The review page
  itself is decided with the implementation.
- Record tables therefore allow an unassigned record (nullable `encounter_id`).

### Records and ownership

- Each schema stores its records in its **own tables**. There is no Doctrine inheritance: a
  discriminator map would have to list every plugin.
- ADR 029 applies **per record**: a record belongs to the import that created it, duplicate detection
  uses a schema-specific fingerprint, unique per hospital, key generation and schema.
- The foreign key from a record to its encounter is `ON DELETE RESTRICT`. The database refuses to remove
  an encounter that a record still references, also when the record belongs to a schema whose bundle is
  disabled but whose tables still exist.

### Import deletion and hub cleanup

These are two separate steps.

- **Deleting or reprocessing an import** removes only the records that belong to it, through the schema
  handler, with the cleanup of ADR 029. Records of other imports at the same encounter stay. An encounter
  may be left without records for a while, for example during a reassignment.
- **Hub cleanup** is one central service of the core. It removes encounters that no record references
  and that have no allocation link worth keeping. It runs on request: by hand (console command), on a
  schedule, or after defined events; a bundle may request it, for example after a reassignment.
  Before deleting, it checks every table with a foreign key to `encounter` (read from the database
  catalog, so the tables of disabled bundles are included); `ON DELETE RESTRICT` is the safety net.

### Matching

Matching ED visits with allocations (ADR 030) is part of the `ed_visit` schema. The core offers linking
and unlinking an encounter and an allocation. Merging and splitting encounters when a link moves is
decided once a second schema creates encounters itself.

### Shared workflow and schema handler

The core keeps the import workflow for every schema: reading the file, manifest and header validation,
detection of identifying content, key generation lock and check value, guarded commit, rejects, report
counters, source file retention, reprocessing and deletion. A schema contributes:

- its field contract per version (`EncounterDimensionInterface`, unchanged);
- a **schema handler** that maps rows, asks for the encounter of each record, writes its records inside
  the core's transaction, removes the records of an import and returns the counts for the report;
- optionally a section on the import detail page, a record section for the case file, an entry on the
  Explore overview, navigation entries, pages and tools.

The core has no branch for a specific schema.

### Places a schema appears

- **Import selection and report:** the schema choice of the encounter upload, the counts and an optional
  section on the import detail page.
- **Case file:** a record section in the hospital course card of the allocation case file, reached
  through the encounter's allocation link; later also on an encounter detail page. Access (`VIEW_CASES`)
  is checked by the core.
- **Explore overview:** an entry (title, description, icon) and its target page. Explore decides whether
  the entry is visible in the current hospital and permission context; the target page checks its access
  itself. This is a new extension point; the overview is a fixed template today.
- **Navigation and tools:** entries through the existing `shared.navigation` and `shared.tool` providers.

### Contract layer

- Plugins depend only on `App\Encounter\Contract\…` (interfaces, value objects, the base entity), on
  `Shared` extension points and on Symfony and Doctrine. A Deptrac layer enforces this.
- Where another context offers the extension point (allocation case file, Explore overview, import data
  removers, import detail page), Encounter implements it once and forwards to its plugins. A plugin
  therefore needs no dependency on `Allocation` or `Import`, and access checks stay in the core.
- The contract may change during the beta. Plugins in this repository are updated in the same change.

### Bundle layout and location

- Bundles follow the Symfony bundle best practices (`AbstractBundle`, `src/`, `config/`, `templates/`,
  `translations/`, `migrations/`), not the `src/{Context}/{UI,Application,Domain,Infrastructure}`
  layout of the application contexts (ADR 007). They prepend their Doctrine mapping, migrations path and
  Twig namespace.
- Shipped plugins live in `plugins/<name>/` as Composer path repositories, so that one can move to its
  own repository later. Test-only bundles live under `tests/` (autoload-dev) and are enabled in the
  `test` environment only.

### Data preparation tool

The tool becomes a generic shell (key file, fingerprinting, manifest, removal of identifying columns,
local map). Schemas contribute their schema file, source profiles, scripts and catalogs through a tagged
contribution instead of the fixed lists in `DataPrepToolRenderer` (#1301).

### Order of implementation

1. **#1274:** contract layer, schema handler, assignment service and extension points, proven with a
   synthetic test bundle (`inpatient_stay`) through the normal entry points. The existing `ed_visit` row
   serves as the encounter; MTS stays in the core.
2. **#1300:** split the hub out of the `encounter` table, migrate the existing data, hub cleanup, and
   the MTS bundle shipped with the application.

Until the split, `ed_visit` is the only schema in production. Deleting an `ed_visit` import whose visits
still carry records of another schema fails on the `RESTRICT` constraint instead of removing those
records; #1274 does not exercise this, #1300 resolves it with the split.

## Consequences

**Positive:**

- New kinds of hospital data need a bundle and a deployment, not an application release
- Key check, identifying-content detection, commit guard and deletion exist once for all schemas
- The case view brings data of several sources together without a patient-level key
- Deleting an import removes exactly its records; no record disappears because another import was
  deleted
- Records that arrive early or match ambiguously are kept and can be assigned later
- A fresh installation without the MTS bundle remains possible

**Negative:**

- Splitting the hub out of the `encounter` table needs a data migration on pilot data
- Unassigned records and conflicts need a review page and someone with the `REVIEW` grant
- Empty encounters exist until the hub cleanup runs
- A contract layer to maintain, and a second layout (bundles) next to the contexts
- Until merging is decided, only one schema may create encounters
- The anchor of encounters without an allocation is still open

## Alternatives

- **One `ImportType` per plugin:** rejected. `ImportType` is a PHP enum stored as Doctrine enum, and
  several services branch over it with `match`; a bundle cannot add a case, and every plugin would
  repeat the shared workflow.
- **Plugins with their own import workflow:** rejected; the key check and identifying-content
  detection would exist once per plugin.
- **Doctrine single-table or joined inheritance for records:** rejected; the base class would have to
  know every plugin.
- **One encounter per record:** rejected; an inpatient stay that follows an ED visit or an allocation is
  the same case.
- **Case pseudonym as encounter identity:** rejected; one administrative case can cover several
  allocations and ED visits.
- **Rejecting ambiguous or unmatched rows:** rejected; rejects keep no values (ADR 026), so the record
  could never be assigned later.
- **Cascading deletes from the encounter to its records:** rejected; deleting one import would remove
  records of other imports.
- **Plugins installed at runtime (uploaded code):** rejected; code processing clinical data is reviewed
  and deployed by the operator.
- **Keep `ed_visit` and MTS in the core and only add schemas:** rejected; MTS is one source among
  several, and an instance without ED data should not carry it.

## References

- Amends [026-encounter-data-context-and-exchange-format.md](026-encounter-data-context-and-exchange-format.md)
  (model: one encounter is one case, not one ED visit),
  [029-encounter-import-lifecycle.md](029-encounter-import-lifecycle.md) (ownership per record, hub
  cleanup) and [030-encounter-allocation-matching.md](030-encounter-allocation-matching.md) (matching
  owned by `ed_visit`)
- [027-encounter-pseudonym-key.md](027-encounter-pseudonym-key.md),
  [028-encounter-access-grants.md](028-encounter-access-grants.md)
- [../extension-points.md](../extension-points.md)
- Issues #1273, #1274, #1300, #1301
