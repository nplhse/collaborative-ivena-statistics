# ADR 037: Encounter as case hub, schemas as Symfony bundles

**Status:** accepted (#1274 part 1: contract, assignment, deletion rules and display proven with a test-only schema bundle; hub split and MTS bundle follow in #1300)

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

- An `Encounter` is the hub of **one case** for which more exists than the IVENA allocation. It holds
  only base data: hospital, key generation, case pseudonym, anchor time, age, sex and at most one link
  to an allocation (unique per allocation, changeable).
- Everything else is a **record** of a schema: an ED visit with MTS, an inpatient stay, a diagnosis
  list, an EMS record. A record of an existing case attaches to its encounter; it does not create a
  second encounter for the same case.
- An encounter has **no owning import**. The imports involved in an encounter are derived from the
  records that reference it.

### Case identity and time anchor

- The **IVENA allocation is the primary anchor** of an encounter. Its time reference is the allocation's
  `arrivalAt`, the **expected arrival** announced in IVENA. The clinical admission documented by the
  hospital can differ from it; schemas keep their clinical times in their records, and matching
  compares them with the expected arrival (ADR 030).
- A case pseudonym can belong to **several encounters** within one hospital and key generation, as long
  as their anchors differ, for example two allocations under one administrative case. The pseudonym
  helps to find candidates; it is not the identity of an encounter.
- An encounter without an allocation is created by a schema from its own data, with that schema's time
  as anchor. Which time this is for `ed_visit` is an open question (below).
- The boundary of ADR 027 stays: there is no patient-level key, and encounters of different cases are
  never linked.

### Finding the encounter

A schema asks the core for the encounter of each record: by allocation link, by case pseudonym and
time, or by creating a new encounter. The core answers with one of four results and never guesses.

| Result | Example | Rule |
|---|---|---|
| **assigned** | exactly one candidate | The record is attached to it. |
| **no candidate** | the stay arrives before its ED visit | The record is kept **unassigned** and tried again when later encounter imports of the hospital add candidates. |
| **ambiguous** | the pseudonym matches several encounters and the time does not decide | The record is kept unassigned with an **assignment conflict** naming the candidates. |
| **contradictory** | allocation link and pseudonym point to different encounters | As ambiguous, with both candidates. |

- Unassigned records and conflicts are counted in the import report.
- Conflicts are visible to users with the `REVIEW` grant of the hospital (ADR 028) and are resolved by
  hand with one of two decisions:
  - **Assign to a candidate:** the record is attached to the chosen encounter and from then on
    processed like an automatically assigned one.
  - **Leave unassigned for now:** the conflict is closed without an assignment. The record stays
    eligible for automatic assignment: a later retry that finds exactly one candidate attaches it. A
    new conflict is raised only when a retry finds candidates other than those the reviewer saw.
    Reprocessing the import removes the decision together with the record, like other review decisions
    (ADR 029).
- Automatic retries apply to records without candidate and to records left unassigned. Open conflicts
  wait for review.
- In the first version only `ed_visit` creates encounters; other schemas attach to existing ones.

### Records and ownership

- Each schema stores its records in its **own tables**, without Doctrine inheritance.
- ADR 029 applies **per record**: a record belongs to the import that created it; duplicate detection
  uses a schema-specific fingerprint, unique per hospital, key generation and schema.
- A record references at most one encounter. The reference is empty while the record is unassigned.
- The reference is `ON DELETE RESTRICT`: the database refuses to remove an encounter that a record still
  references, also when the record belongs to a schema whose bundle is disabled but whose tables remain.
- **Deleting or reprocessing an import** removes exactly the records of that import, through its schema,
  with the cleanup of ADR 029. Records of other imports at the same encounter stay. An encounter can be
  left without records for a while, for example during a reassignment.
- **Hub cleanup** is a separate, central step of the core. It removes encounters that no record
  references, after checking every table that references `encounter`, including tables of disabled
  bundles. It can be started by hand, on a schedule or after defined events; a bundle can request it.

### Matching

Matching ED visits with allocations (ADR 030) is part of the `ed_visit` schema. The core offers linking
and unlinking an encounter and an allocation; merging and splitting encounters is an open question.

### Shared workflow and schema handler

The core keeps the import workflow for every schema: reading the file, manifest and header validation,
detection of identifying content, key generation lock and check value, guarded commit, rejects, report
counters, source file retention, reprocessing and deletion. A schema contributes its field contract per
version (`EncounterDimensionInterface`) and a **schema handler** that maps rows, finds the encounter of
each record, writes its records inside the core's transaction, removes the records of an import and
returns the counts for the report. The core has no branch for a specific schema.

### Places a schema appears

A schema may contribute to the import selection and report, the data preparation tool, the navigation
and tools, the allocation case file and the Explore overview. Where an existing extension point fits,
the schema uses it; the review lists each place with the interface and the generalisation it needs.
Two rules hold for all of them:

- Access is decided by the core and the hosting page, not trusted to the contribution. In the case file
  the core checks `VIEW_CASES`. On the Explore overview, Explore decides whether an entry is visible in
  the current hospital and permission context; the target page checks its access itself.
- The Explore overview gets a new extension point for an entry and its target page; it is a fixed
  template today.

### Contract layer

- Plugins depend only on `App\Encounter\Contract\…` (interfaces, value objects, the base entity), on
  `Shared` extension points and on Symfony and Doctrine. A Deptrac layer enforces this.
- Where another context offers the extension point (allocation case file, Explore overview, import data
  removers, import detail page), Encounter implements it once and forwards to its plugins. A plugin
  needs no dependency on `Allocation` or `Import`.
- The contract may change during the beta. Plugins in this repository are updated in the same change.

### Bundle layout and location

- Bundles follow the Symfony bundle best practices (`AbstractBundle`, `src/`, `config/`, `templates/`,
  `translations/`, `migrations/`), not the context layout of ADR 007. They contribute their Doctrine
  mapping, migrations, routes and Twig namespace through the bundle, without edits to the application
  configuration.
- Shipped plugins live in `plugins/<name>/` as Composer path repositories, so that one can move to its
  own repository later. Test-only bundles live under `tests/` and are enabled in the `test` environment
  only.

### Data preparation tool

The tool becomes a generic shell (key file, fingerprinting, manifest, removal of identifying columns,
local map); schemas contribute their schema file, profiles, scripts and catalogs (#1301).

### Transition

The decision is implemented in steps:

1. **#1274** proves the mechanism with a synthetic test bundle (`inpatient_stay`): contract layer,
   schema handler, all four assignment results (`contradictory` at the level of the assignment
   service, because the synthetic schema carries no allocation link), unassigned records and conflicts
   with their counts and candidates,
   the visibility of conflicts for `REVIEW`, case file sections and Explore entries. The existing
   `encounter` row of an ED visit serves as the encounter; MTS stays in the core.
2. **#1307** (follow-up of #1274): the two manual decisions on conflicts and retrying records
   without candidate after later imports.
3. **#1300** splits the hub out of the `encounter` table, migrates the existing data, adds the hub
   cleanup and ships MTS as a bundle.

Until step 3, `ed_visit` is the only schema in production, and its records are the encounters. The
rules on finding the encounter and on records and ownership apply in this phase as decided; resolution
and retry arrive with step 2. One consequence follows from `RESTRICT`: deleting an `ed_visit` import whose
visits still carry records of another schema fails completely; the import, its visits and the attached
records all stay. Only the test bundle can produce this case before step 3, and #1274 tests it.

## Open questions

- **Anchor without allocation:** whether an encounter created by `ed_visit` without an allocation uses
  the fingerprint anchor of ADR 029 (first contact of the initial assessment, else ED admission) or the
  matching anchor of ADR 030 (ED admission, else the assessment). Until decided, `ed_visit` keeps the
  fingerprint anchor.
- **Time rule for one pseudonym with several encounters:** whether and within which window the clinical
  time of a record (for example the admission of a stay) decides between candidates.
- **Merging and splitting:** what happens to records when a second schema may create encounters and a
  later allocation link makes two encounters the same case, or a link is removed.
- **Hub cleanup criteria:** when a scheduled run treats an encounter without records as final (for
  example after a waiting time while reassignments are open).
- **Disabled bundles:** how unassigned records and open conflicts of a schema whose bundle is disabled
  are shown and resolved.

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
- Unassigned records and conflicts need a review page and people with the `REVIEW` grant
- Encounters without records exist until the hub cleanup runs
- Until the split, deleting an `ed_visit` import fails completely while records of another schema are
  attached
- Until #1307, conflicts are visible but cannot be resolved, and records without
  candidate are not retried
- A contract layer to maintain, and a second layout (bundles) next to the contexts
- Until merging is decided, only one schema may create encounters

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
- **Removing an encounter together with the import that created it:** rejected; records of other
  imports would lose their encounter.
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
- Issues #1273, #1274, #1300, #1301, #1307
