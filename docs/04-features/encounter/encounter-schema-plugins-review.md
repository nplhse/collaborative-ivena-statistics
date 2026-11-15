# Review: extending the Encounter context with Symfony bundles

**Audience:** Developers working on encounter data and on #1274.

**Status:** review for #1273 (evaluation). Decision: [ADR 037](../../02-architecture/decisions/037-encounter-case-hub-and-schema-plugins.md)
(proposed). Nothing described as "proposed" here is implemented yet.

## Question

New kinds of hospital data (inpatient stays and diagnoses from controlling, EMS records) should reach
the application as Composer-installed Symfony bundles instead of application releases. This review
traces the existing MTS workflow, lists every place a bundle has to plug into, proposes the boundary
between the shared Encounter core and MTS, defines the integration test for a synthetic inpatient stay
and orders the implementation for #1274.

Agreed in the discussion of #1273 and recorded in ADR 037:

- The plugin unit is a **schema of `ImportType::ENCOUNTER`**. Allocation and closure imports stay fixed
  IVENA formats of the core.
- An `Encounter` becomes the **hub of one case** with base data only (hospital, key generation, case
  pseudonym, anchor, age, sex, at most one allocation link). Schemas store **records** in their own
  tables and attach them to the encounter.
- The **IVENA allocation is the primary anchor**, with its expected arrival (`arrivalAt`) as time
  reference; clinical admission times stay in the records. A case pseudonym can belong to several
  encounters with different anchors.
- Records are assigned through a core service: assigned, no candidate, ambiguous or contradictory.
  Unassigned records are kept; users with the `REVIEW` grant resolve conflicts by assigning a candidate
  or by leaving the record unassigned, which automatic retries respect.
- Records belong to their import (ADR 029 per record). Deleting an import removes only its records.
  An encounter has no owning import; a separate hub cleanup removes encounters without records.
- Matching (ADR 030) belongs to `ed_visit`.
- No installation at runtime. Plugins are written by the maintainer for now; the contract has to work
  until 1.0.

## Current MTS workflow

Line numbers refer to `develop` at the time of the review.

### 1. Local data preparation

- `src/Encounter/UI/DataPrep/DataPrepToolRenderer.php:20-22` bundles fixed lists: the scripts, one
  profile (`profiles/orbis-mts.json`) and one schema (`schema/ed_visit.v1.json`), plus the MTS catalog
  (`render()`, `:35-55`). The catalog endpoint is `MtsCatalogForDataPrepController.php:29`.
- `js/data-prep-core.js` mixes generic parts (CSV reading, HMAC, local map, content check, versions)
  with `ed_visit` specifics: fingerprint rule `ed-visit:v1` and case prefix `case:v1` (`:13-14`), MTS
  catalog format (`:18`, parsing `:1022-1065`), care levels and vitals (`:21-32`), the anchors of the
  fingerprint (`:398-410`), the target columns (`:573-585`, `:767-815`), ED field types (`:842-979`),
  the manifest line (`:1124-1140`) and the file name prefix `ed_visit_` (`:1155`).
- `js/data-prep-app.js:682,803` passes a single `config.schema`.
- Tests: `tests/Encounter/DataPrep/data-prep-core.test.js`, `exchange-fixture.test.js` with
  `fixtures/fingerprint-vectors.json`; `tests/Encounter/Unit/UI/DataPrep/DataPrepContractTest.php`;
  `tests/Encounter/Functional/Controller/DataPrepToolControllerTest.php`,
  `DataPrepGuideControllerTest.php`; `tests/Encounter/Integration/Infrastructure/Import/DataPrepToolOutputImportTest.php`.

### 2. Import selection, upload and authorization

- Generic host: `ImportTypeExtensionInterface` (tag `import.type_extension`) with
  `ImportTypeExtensionRegistry`, consumed by `NewImportController`, `ImportCreateType`, `ImportVoter`
  (`:69-71`), `ListImportsQuery` and `ImportDetailViewFactory`.
- `src/Encounter/UI/Import/EncounterImportTypeExtension.php`: `isAvailableTo()` (`:89`, beta role),
  `buildForm()` (`:114`, schema choice from `EncounterDimensionRegistry::schemas()`, default the first),
  `validateUpload()` (`:147`, known schema, no key change in progress), `onImportCreated()` (`:167`,
  creates `EncounterImport` with schema and key generation), `vote()` (`:183`), `detailSection()` (`:202`).
- Access: `EncounterVoter.php:62-83` with the grants of `EncounterAccessGrant` (upload, review, view
  cases; ADR 028). `EncounterUploadPermission.php:29` repeats the upload rule for the message handler.
  All of this is schema-agnostic.
- Tests: `tests/Encounter/Functional/Controller/EncounterImportControllerTest.php`,
  `EncounterAccessGrantControllerTest.php`, `tests/Encounter/Integration/Infrastructure/Security/EncounterVoterTest.php`,
  `tests/Import/Unit/Application/Service/ImportStartDispatcherTest.php`.

### 3. Background processing

- `ImportEncountersDispatcher.php:49-80` (tag `import.workflow_dispatcher`) queues
  `ImportEncountersMessage(importId, runId, userId)`. The message carries **no schema**; the handler
  reads it from `EncounterImport`.
- `ImportEncountersMessageHandler.php:92-176`: load the encounter import, check creator and file
  (`resolveSource`, `:275-307`), check the key generation (`:312`), clean up a previous run (`:126`),
  run the file importer (`:135`), apply the result (`:178`), dispatch `EncountersImported` for new days
  (`:153`); file rejection `:192`, key change `:222`. The handler itself is schema-agnostic.
- `EncounterFileImporter.php` is not: it injects `EdVisitRowMapper` (`:56`) and
  `MtsCatalogFirstSeenWriter` (`:60`), maps every row to `EdVisitRow`, writes `Encounter` entities
  (`:155`), records MTS codes (`:162-171`, `:191-192`) and computes coverage from ED times (`:232`).
  The schema is used only for validation (`:78`).
- `EncounterFileValidator.php:48-90` is generic through the registry (schema, version, fingerprint
  rule, header).
- Database: `import.type` is `VARCHAR(255)` without check constraint, but mapped as `enumType:
  ImportType` (`Import.php:44`). `encounter_import.exchange_schema` is `VARCHAR(32)` without check
  constraint. No constraint blocks a new schema.
- Tests: `tests/Encounter/Integration/Infrastructure/Import/EncounterImportRunTest.php`,
  `EncounterImportEdgeCaseTest.php`, `EncounterImportRunTelemetryTest.php`, `MtsCatalogFirstSeenImportTest.php`,
  `tests/Encounter/Integration/Infrastructure/EncounterImportLifecycleTest.php`,
  `tests/Encounter/Unit/Application/Import/EncounterFileValidatorTest.php`, `EdVisitRowMapperTest.php`.

### 4. Persistence

- `Encounter.php`: unique `(hospital, keyGeneration, visitFingerprint)` (`:37`) and `visitUid` (`:38`).
- Base fields (hub candidates): hospital `:56`, keyGeneration `:59`, caseKey `:65`, anchorAt `:77`,
  age `:80`, sex `:83`, createdAt `:133`. Record fields of `ed_visit`: visitFingerprint `:62`, import
  `:70`, anchorSource `:73`, arrival, referral, admission type and case status `:86-99`, the embeddables
  `EncounterTimes`, `Triage` (`mts_*`), `Retriage`, `EncounterDisposition`, the admitting department,
  `InitialVitals` and the flags (`:102-130`).
- ED check constraints on `encounter`: `migrations/Version20261112144450.php:40-48`.
  `encounter_match.time_anchor` only allows `admission|assessment` (`Version20261112205557.php:39`).
- `EncounterInsertStatement.php:21,68` inserts with `ON CONFLICT … DO NOTHING`.
- Tests: `tests/Encounter/Unit/Domain/EncounterTest.php`, `tests/Encounter/Integration/Infrastructure/EncounterRepositoryTest.php`,
  `ConcurrentEncounterInsertTest.php`.

### 5. After the import

- `MatchAfterImportListener.php:32-50` dispatches `MatchEncountersMessage` after `EncountersImported`
  and after allocation imports. `MatchInputQuery.php:95-158` reads ED times and the MTS diagram;
  plausibility (PZC × MTS diagram) runs inside the matcher (`EncounterMatcher.php:40`).
- Tests: `tests/Encounter/Integration/Infrastructure/Matching/EncounterMatcherTest.php`,
  `EncounterMatcherPlausibilityTest.php`, `tests/Encounter/Functional/Command/MatchEncountersCommandTest.php`.

### 6. Import report

- `EncounterImportTypeExtension::detailSection()` renders `@Encounter/imports/_import_section.html.twig`
  with the counts of `EncounterImport`, rejects and suspected duplicates (`EncounterImportReportQuery.php:26,50`).
  The schema is not shown.
- Tests: `EncounterImportControllerTest.php:154,175`.

### 7. Deletion, reprocessing, key change, retention

- `ImportRelatedDataCleanupService.php:42-72` runs the `import.data_remover` services:
  `EncounterImportDataRemover.php:29-33` (encounters by import) and `EncounterMatchDataRemover.php:45-66`
  (links and decisions, then a matcher rerun; priority 10).
- Key change: `DeleteEncounterDataAfterKeyChangeMessageHandler.php:56-80` deletes imports of older
  generations. Retention: `EncounterSourceFileRetention.php:28`,
  `DeleteExpiredEncounterSourceFilesMessageHandler.php:28`, `EncounterScheduleContribution.php:19-35`.
  All schema-agnostic.
- Tests: `tests/Encounter/Integration/Infrastructure/KeyChange/EncounterKeyChangeTest.php`,
  `EncounterSourceFileRetentionTest.php`, `EncounterImportControllerTest.php:188,257`.

### 8. Views of imported data

- ED visit list and detail: `/explore/ed-visits` (`UI/Http/Visit/ListVisitHospitalsController.php:27`,
  `ListVisitsController.php:48`), `/explore/ed-visits/visit/{visitUid}` (`ShowVisitController.php:34`).
  Tests: `ShowVisitControllerTest.php`, `ListVisitsControllerTest.php`, `VisitListQueryTest.php`.
- Allocation case file: `HospitalCourseCaseSectionProvider` (tag `allocation.case_section`) with
  `HospitalCourseQuery`, MTS names included. Test: `HospitalCourseCardTest.php`.
- Navigation, tools, My Hospitals, hospital edit section and MTS glossary: `EncounterNavigationProvider`,
  `EncounterToolProvider`, `EncounterMyHospitalActionProvider`, `EncounterHospitalEditSectionProvider`,
  `EncounterGlossaryEntryProvider`.
- Encounter analytics in Statistics: `src/Statistics/UI/Http/Controller/EncounterAnalyticsController.php:9-14`
  uses `Encounter\Application` classes; the template `encounter_analytics/index.html.twig:5-6` reads
  Encounter constants; data from `EncounterOverviewQuery`. Allowed by `deptrac.yaml` as documented
  partnership (#1055). Test: `tests/Statistics/Functional/Controller/EncounterAnalyticsControllerTest.php`.

### 9. Configuration, fixtures, tests

- Static configuration for the context: Doctrine mapping (`config/packages/doctrine.yaml:74-78`), Twig
  namespace (`twig.yaml:29`), messenger routing (`messenger.yaml:67,89-91,108`), security
  (`security.yaml:72-84`), app configuration (`app.yaml:85-115`, `AppExtension.php:51-55`). Routes and
  services are loaded from `src/` only.
- Synthetic data: `src/DataFixtures/Encounter/` writes visits directly with `EdVisitRowMapper`
  (`SyntheticEncounterWriter.php:85-100`) and repeats the HMAC rules (`SyntheticEncounterKey.php:71-78`).
- Test support: `tests/Support/Encounter/EncounterImportRunner.php` (upload, store, run; schema fixed to
  `'ed_visit'` at `:66`), `EdVisitFileBuilder.php` (manifest and key check generic, rows ED),
  `EncounterTestFixtures.php`, `EncounterDataFixtures.php`.
- The test schema is built from migrations (`composer setup-test-env`, Foundry `reset.mode: migrate`),
  DAMA wraps tests in transactions. There is no test-only bundle yet.

## Findings

1. **A second schema is processed wrongly without an error.** A registered `inpatient_stay` dimension
   appears in the upload form and passes validation, and `EncounterFileImporter` then maps its rows with
   `EdVisitRowMapper` into `encounter`. Nothing dispatches by schema after validation.
2. The fingerprint and case rules (`ed-visit:v1`, `case:v1`) are repeated in the tool's JavaScript and in
   the PHP fixtures instead of coming from the dimension.
3. `DataPrepContractTest` checks every `profiles/*.json`, the renderer ships only the `PROFILES` list: a
   new profile would be tested but not shipped.
4. The comment at `deptrac.yaml:661-662` says no context may use Encounter, while `Statistics_UI` may.
5. The import detail section does not show the schema.
6. `AnchorSource` and `encounter_match.time_anchor` carry ED semantics; they belong to `ed_visit`, not to
   the hub.

Findings 2–5 are side findings for separate issues; finding 1 is solved by the schema handler below.

## Integration points

For each place: what a bundle can contribute, which existing interface fits, and where the application
has to be generalised. Plugins depend on the Encounter contract and on `Shared`. Where another context
offers the extension point, Encounter implements it once and forwards to its plugins, so a plugin needs
no dependency on `Allocation` or `Import` (ADR 037).

### Import selection and processing

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Schema choice | Schema with versions, fields, fingerprint rules | `EncounterDimensionInterface` (`encounter.dimension`), read by `EncounterImportTypeExtension` (`import.type_extension`) | A translatable label per schema (the form shows raw schema names, `EncounterImportTypeExtension.php:124`); the import type is hidden while no schema is registered |
| Upload checks | – | `EncounterImportTypeExtension::validateUpload()`, `EncounterVoter`, grants (ADR 028) | None; schema-agnostic |
| Queueing and run | – | `import.workflow_dispatcher` (`ImportEncountersDispatcher`), `ImportEncountersMessageHandler` | None; the message carries no schema |
| Processing | Row mapping, record writing, removal of an import's records | None: `EncounterFileImporter` is fixed to `ed_visit` | **Schema handler** in the contract, registry by schema; the importer delegates; a schema without handler rejects the file (finding 1) |
| Assignment | Asks for the encounter of each record | None | **Assignment service** with the four results of ADR 037; storage of unassigned records and conflicts |

### Import report

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Counts | Inserted, duplicates, skipped, rejected, assigned, unassigned, conflicts | `EncounterImport` counters, `EncounterImportTypeExtension::detailSection()` | Counts come from the handler result; assignment counts and the schema are shown |
| Rejects | Row errors with codes | `ImportReject`, `EncounterImportReportQuery` | None |
| Schema-specific details | Optional section | None (the section is ED-specific: suspected duplicates) | Optional report section per schema, rendered inside the encounter section |

### Data preparation

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Schema, profiles, scripts, catalogs | Files for the tool | None: fixed lists in `DataPrepToolRenderer.php:20-22`, ED logic in `data-prep-core.js` | Tagged contribution and a generic core script (#1301); not needed for #1274, whose fixture is written directly |

### Navigation and tools

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Menu entries | Entries in the Explore or Statistics sidebar | `NavigationProviderInterface` (`shared.navigation`) | None |
| Tools | Review or curation tools | `ToolProviderInterface` (`shared.tool`) | None |
| Routes of the bundle | Controllers | None: routes are scanned from `src/` only | Route loader in Encounter that imports the routes of all schema bundles; needed in #1274 for the Explore target page |

### Case file

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Data of a record at the allocation | Template and variables per record | `AllocationCaseSectionProviderInterface` (`allocation.case_section`), used by `HospitalCourseCaseSectionProvider` for ED data | **Record sections** of the contract, rendered by Encounter inside the hospital course card; `VIEW_CASES` checked by the core |
| Encounter detail page | Same record sections | ED visit detail `/explore/ed-visits/visit/{visitUid}` (`ShowVisitController.php:34`), ED-specific | Later, after the hub split: an encounter detail page that renders all record sections |

### Explore

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Overview entry | Title, description, icon, target route | None: `@Allocation/explore.html.twig` is a fixed template with static cards | **New extension point** in `Allocation` (pattern of `CatalogGlossaryEntryProviderInterface`); Explore decides visibility in the current hospital and permission context, Encounter forwards to its plugins |
| Target page | Own page | Bundle routes (above) | The target page checks its access itself |
| Glossary | Glossary card | `CatalogGlossaryEntryProviderInterface` (`allocation.catalog_glossary_entry`) | None |

### Further places

| Aspect | Bundle contributes | Existing interface | Generalisation needed |
|---|---|---|---|
| Deletion, reprocessing, key change | Removal of an import's records | `ImportDataRemoverInterface` (`import.data_remover`) through `EncounterImportDataRemover`; key change deletes imports | The Encounter remover delegates to the handlers; hub cleanup separately (#1300) |
| Persistence | Entities, mapping, migrations | None in the application (static `doctrine.yaml`, one migrations path) | Prepended by the bundle; no application change |
| Twig, translations | Templates, translation domain | Bundle conventions | None |
| Messenger, scheduler | Messages and their routing, scheduled jobs | `messenger.yaml` routing; `ScheduleContributionInterface` (`EncounterScheduleContribution`) | Routing prepended by the bundle |
| My Hospitals | Quick action | `MyHospitalActionProviderInterface` (`allocation.my_hospital_action`) | Through Encounter |
| Static analysis | – | Deptrac layers, PHPStan, Psalm on `src/` | Contract layer; `plugins/` and test bundles in the analysed paths |

## Boundary between the core and MTS

**Encounter core** (`src/Encounter`): import type extension, dispatcher, message and handler, file
reader, validator, manifest and identifying-content detection, key state and key change, grants and
voter, retention, import cleanup and hub cleanup, dimension and handler registries, the hub entity, the
assignment service with unassigned records and conflicts, the contract layer, the generic import report.

**MTS bundle** (`plugins/encounter-ed-mts`, schema `ed_visit`): `EdVisitDimension`, row and mapper, the
`ed_visit` record table with all ED fields, MTS catalog with curation and glossary, plausibility table,
matching with review, swap and validation tools, `EncounterMatchDataRemover`, ED visit list and detail
pages, hospital course card content, the encounter analytics now in Statistics, the `ed_visit`
synthetic data, the ORBIS profile and `ed_visit` schema of the tool.

Implications:

- **Existing records.** The hub split keeps `encounter.id` and moves the ED columns into a record table
  keyed by `encounter_id` (`ON DELETE RESTRICT`), so `encounter_match`, suspected duplicates and review decisions keep their
  references. The visit fingerprint and its unique constraint move to the record table.
- **Import identifiers.** `import.type = 'Encounter'` and `exchange_schema = 'ed_visit'` stay as they
  are.
- **Queued work.** `ImportEncountersMessage` carries no schema and stays in the core. Messages of MTS
  (`MatchEncountersMessage`) are stored with their class name in the Doctrine transport; moving the class
  needs a class alias for one release or an empty queue at deployment.
- **Migrations.** The split runs on installations that have the MTS bundle (all current ones). Proposed:
  the split migration ships with the bundle; the core migrations never touch ED columns. A bundle
  migration that changes a core table is accepted once, for this transition.

## Integration test for a synthetic inpatient stay

**Test bundle** `tests/Fixtures/Plugin/InpatientStay/` (autoload-dev), enabled in `config/bundles.php`
for `test` only. It uses only the contract layer and contributes:

- schema `inpatient_stay` v1 with the fields `stay_fingerprint`, `case_key`, `admitted_at`,
  `discharge_diagnosis`, `discharged_at` and the fingerprint rule `inpatient-stay:v1`;
- a handler that asks the assignment service by case pseudonym and admission time and writes each stay
  to its own table (`test_inpatient_stay`: nullable reference to `encounter` with `ON DELETE
  RESTRICT`, owning import, unique fingerprint per hospital and key generation), with its migration in
  its own path;
- a record section with admission, discharge diagnosis and discharge;
- an Explore overview entry "Inpatient stays (test)" with a target page that lists the stays of a
  hospital and requires `VIEW_CASES`.

**Arrange:** hospital H, user R with the beta role and the upload, review and view-cases grants for H,
user V with the view-cases grant only, user N with the beta role and no encounter grant. Allocations X
and Y of H. One `ed_visit` import with visits A, B and C: B and C share one case pseudonym (one
administrative case, two ED visits); A is linked to X, B to Y, C has no link.

**Fixture** (`inpatient_stay`, four rows, explicit values):

| Row | Case pseudonym | Case | Expected |
|---|---|---|---|
| 1 | case of visit A | unique | assigned to A |
| 2 | same fingerprint as row 1 | duplicate | duplicate, not stored again |
| 3 | case of visits B and C, admission time does not decide | ambiguous | unassigned, conflict with candidates B and C |
| 4 | case of no visit | missing | unassigned, no candidate |

Report: total 4, assigned 1, duplicates 1, conflicts 1, unassigned without candidate 1, rejected 0.

**Verify through the normal entry points:**

1. **Selection:** R sees `inpatient_stay` with its label in the upload form and can upload for H; N
   cannot upload.
2. **Processing and report:** running the queued import calls the bundle's handler through the shared
   workflow; the import detail page shows the schema and the counts above.
3. **Case file:** the case file of allocation X shows admission, discharge diagnosis and discharge of
   row 1 in the hospital course card, for R and V. The case file of Y shows no stay.
4. **Conflict:** the conflict of row 3 is stored with candidates B and C and is listed on a read-only
   conflict page for R; V (no review grant) cannot open the page. The stay of row 3 appears in no case
   file.
5. **Missing:** the stay of row 4 is stored unassigned and counted; it appears in no case file.
6. **Explore:** R and V see the entry on the Explore overview, and its target page lists the stay of
   row 1. N does not see the entry and gets 403 on the target page by direct URL.
7. **Reprocessing** gives the same counts, one stored stay per row and one conflict.
8. **Deletion:** deleting the `inpatient_stay` import through the normal workflow removes its three
   stays and the conflict. Visits A, B and C, their links and the `ed_visit` import stay.
9. **Deletion while attached:** deleting the `ed_visit` import while the stay of row 1 is attached to
   visit A fails completely: the `ed_visit` import, visits A, B and C, their links, the source file and
   all stays and the conflict stay; the user gets an error message. Run before step 8.
10. **Registration:** the test bundle is registered only through `config/bundles.php`; no class under
    `src/` names `inpatient_stay` or the test bundle; Deptrac allows the test bundle only the contract
    layer.

**Assignment service test** (integration, without upload). The synthetic schema carries no allocation
link, so `contradictory` is tested at the level of the assignment service. Arrange: visit A linked to
allocation X, visit E with another case pseudonym and no link. Ask for a record that names allocation X
and the case pseudonym of E. Expected: result `contradictory`; the record is stored unassigned with one
conflict whose candidates are A (by allocation) and E (by pseudonym), each with its reason; the conflict
is listed for R like the one of row 3. The same test class covers the other three results with one case
each, so all four results have a test at service level.

**How #1274 proves conflicts, and where resolution follows.** #1274 proves that all four assignment
results are detected, that unassigned records and conflicts are kept with their candidates, counted and
shown only to users with the review grant (steps 2, 4, 5, 7, 8 and the assignment service test). The two
manual decisions and the retry of records without candidate are a follow-up issue of #1274. Its actions
are added to the conflict page of step 4, and it extends this test with:

- "assign row 3 to B" → the case file of Y shows the stay;
- "leave row 3 unassigned", then import a visit that would decide it → the stay stays unassigned
  (automatic retries skip a deliberate decision);
- "import visit D with the case of row 4" → the stay is assigned to D.

**Implementation note for step 9.** Import deletion removes the rows of all removers in one
transaction (`ImportRelatedDataCleanupService::removeRows()`) and deletes files only afterwards
(`ImportDeletionService::delete()`), so a failing `RESTRICT` reference rolls the rows back before any
file is touched. The Encounter remover should still check for attached records of other schemas before
it deletes anything and raise a clear error, instead of relying on the database exception and on the
other removers (for example the matcher rerun of `EncounterMatchDataRemover`) having no effect outside
the transaction.

**Not part of this test:** hub cleanup (#1300).

### Acceptance criteria of #1274, part 1

| Criterion | Verified by |
|---|---|
| Import type appears in the selection for an authorized user, who can upload the fixture for their hospital | 1 |
| Shared workflow dispatches to the bundle's handler, outcome in the existing import report | 2, 7 |
| Imported fields visible in the selected view, reached through the existing allocation-to-encounter link | 3 |
| Deleting the import through the normal workflow removes its bundle-owned records | 8 |
| Registration mechanism of installed bundles, no import-type-specific branch | 10 |

Steps 4–6, step 9 and the assignment service test go beyond the wording of #1274; they prove the four
assignment results, the Explore extension point and the `RESTRICT` rule of the transition in ADR 037.

## Implementation plan for #1274

Part 1, proving the mechanism (MTS stays where it is):

1. Test support: generic `EncounterFileBuilder` from `EdVisitFileBuilder`, schema parameter in
   `EncounterImportRunner`. No behaviour change.
2. Contract layer `App\Encounter\Contract\` with Deptrac layer: schema handler, assignment service with
   its four results, record section, Explore entry.
3. `EncounterFileImporter` delegates to the handler; `ed_visit` becomes the first handler in the core;
   a schema without handler rejects the file (finding 1); `EncounterImportDataRemover` delegates the
   removal of an import's records; the report shows the schema and the assignment counts; schema labels
   in the upload form; the import type is hidden without schemas. Existing tests stay green.
4. Unassigned records and conflicts: storage in the core with all candidates and their reasons,
   counts, read-only conflict page for the `REVIEW` grant; the Encounter remover refuses to delete
   visits that carry records of another schema.
5. Record sections in the hospital course card; Explore overview extension point; route loader for
   schema bundles.
6. Test bundle with migration and the integration test above.

Follow-up of #1274 (own issue): the two manual decisions on the conflict page (assign to a candidate,
leave unassigned) and the retry of records without candidate after encounter imports.

Part 2, moving MTS (#1300, one pull request per step):

7. Hub split with data migration; `ed_visit` records reference the encounter with `RESTRICT`.
8. Central hub cleanup (command, schedule, request by a bundle), checking every table that references
   `encounter`.
9. Bundle skeleton `plugins/encounter-ed-mts` as Composer path repository: prepended mapping, migrations
   and messenger routing.
10. Move MTS in slices: catalog and glossary, plausibility, matching and review tools, visit pages and
    case file content, encounter analytics out of Statistics, synthetic data, tool contribution.
11. Installation without the bundle: a test environment or kernel variant without the MTS bundle that
    boots, hides the encounter import type and runs allocation and closure imports.

Separate issue: generic data preparation tool with schema contributions (#1301).

## Open points

The open questions of the decision are listed in
[ADR 037](../../02-architecture/decisions/037-encounter-case-hub-and-schema-plugins.md#open-questions):
anchor without allocation, time rule for one pseudonym with several encounters, merging and splitting,
hub cleanup criteria, disabled bundles. Open for the implementation only:

- How the test for an installation without the MTS bundle runs (second test environment or kernel
  variant), decided in step 11.
- Where the conflict page lives (Tools section or import detail page), decided in step 4.
