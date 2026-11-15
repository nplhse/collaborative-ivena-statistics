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
  IVENA allocation is the natural hook; MTS data also produce cases without an allocation (ADR 026).
- **Security-critical steps must exist once.** Key generation lock and check value (ADR 027), detection
  of identifying content, guarded commit, rejects, retention and deletion (ADR 029) must not be
  reimplemented per data source.
- **No patient-level key.** ADR 027 gives a stable case pseudonym per hospital and key generation; it
  must not become a way to find the same patient again across cases.
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
- There is still no patient-level key (ADR 027 unchanged). Encounters of different cases are never
  linked.

### Records and ownership

- Each schema stores its records in its **own tables**, which reference the encounter with a foreign
  key `ON DELETE CASCADE`. There is no Doctrine inheritance: a discriminator map would have to list
  every plugin.
- ADR 029 applies **per record**: a record belongs to the import that created it, duplicate detection
  uses a schema-specific fingerprint, unique per hospital, key generation and schema.
- An encounter has **no owning import**. The core removes an encounter when its last record is gone.
  Deleting an import therefore removes exactly its records, and the encounter only when nothing else
  hangs on it.

### Finding the encounter

The schema decides how a record finds its encounter, through a service of the contract layer:

- **by allocation**: the encounter linked to an allocation, if any;
- **by case pseudonym**: within hospital and key generation. Exactly one match attaches; several
  matches (one administrative case covering several ED visits) reject the row as ambiguous and count it
  in the report. A finer rule (for example the last visit before admission) is decided when real data
  show the need;
- **create**: a new encounter with the base data the record has. In the first version only `ed_visit`
  creates encounters.

Matching ED visits with allocations (ADR 030) is part of the `ed_visit` schema. The core offers linking
and unlinking an encounter and an allocation. Merging and splitting encounters when a link moves is
decided once a second schema creates encounters itself.

### Shared workflow and schema handler

The core keeps the import workflow for every schema: reading the file, manifest and header validation,
detection of identifying content, key generation lock and check value, guarded commit, rejects, report
counters, source file retention, reprocessing and deletion. A schema contributes:

- its field contract per version (`EncounterDimensionInterface`, unchanged);
- a **schema handler** that maps rows and writes its records inside the core's transaction and returns
  the counts for the report;
- optionally a section on the import detail page, pages, navigation entries, case file sections and
  tools, through the existing extension points.

The core has no branch for a specific schema.

### Contract layer

- Plugins depend only on `App\Encounter\Contract\…` (interfaces, value objects, the base entity), on
  `Shared` extension points and on Symfony and Doctrine. A Deptrac layer enforces this.
- Where another context offers the extension point (allocation case file, import data removers,
  import detail page), Encounter implements it once and forwards to its plugins. A plugin therefore
  needs no dependency on `Allocation` or `Import`, and access checks such as `VIEW_CASES` stay in the
  core.
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
contribution instead of the fixed lists in `DataPrepToolRenderer`. Separate issue.

### Transition

Until the encounter hub is split out of the `encounter` table, the existing `ed_visit` row serves as
the encounter. In that phase, deleting an `ed_visit` import also removes records of other schemas that
are attached to its visits (cascade). The hub split, with its data migration, is part of moving MTS into
its bundle.

## Consequences

**Positive:**

- New kinds of hospital data need a bundle and a deployment, not an application release
- Key check, identifying-content detection, commit guard and deletion exist once for all schemas
- The case view brings data of several sources together without a patient-level key
- Deleting an import stays predictable per record; an encounter disappears only with its last record
- A fresh installation without the MTS bundle remains possible

**Negative:**

- Splitting the hub out of the `encounter` table needs a data migration on pilot data
- Ambiguous case pseudonyms leave records unattached until a finer rule exists
- A contract layer to maintain, and a second layout (bundles) next to the contexts
- Until merging is decided, only one schema may create encounters
- During the transition, deleting an `ed_visit` import removes attached records of other imports

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
- **Plugins installed at runtime (uploaded code):** rejected; code processing clinical data is reviewed
  and deployed by the operator.
- **Keep `ed_visit` and MTS in the core and only add schemas:** rejected; MTS is one source among
  several, and an instance without ED data should not carry it.

## References

- Amends [026-encounter-data-context-and-exchange-format.md](026-encounter-data-context-and-exchange-format.md)
  (model: one encounter is one case, not one ED visit),
  [029-encounter-import-lifecycle.md](029-encounter-import-lifecycle.md) (ownership per record) and
  [030-encounter-allocation-matching.md](030-encounter-allocation-matching.md) (matching owned by
  `ed_visit`)
- [027-encounter-pseudonym-key.md](027-encounter-pseudonym-key.md)
- [../extension-points.md](../extension-points.md)
- Issues #1273, #1274
