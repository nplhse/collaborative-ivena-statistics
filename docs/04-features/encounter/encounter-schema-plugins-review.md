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
- Records belong to their import (ADR 029 per record). An encounter has no owning import and is removed
  with its last record.
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

| # | Point | Current coupling | Bundle contributes | Smallest application change |
|---|---|---|---|---|
| 1 | Schema choice and validation | Generic through `EncounterDimensionRegistry` | `EncounterDimensionInterface` service | `isAvailableTo()` returns false without any schema |
| 2 | Processing | `EncounterFileImporter` fixed to `EdVisitRowMapper`, `Encounter`, MTS first-seen | Schema handler: map rows, write records in the core's transaction, return counts and coverage | Handler interface in the contract, registry by schema; importer delegates; no handler = file rejected; `ed_visit` becomes the first handler (still in core) |
| 3 | Finding the encounter | Only ED visits exist | Uses the lookup service | `EncounterLookup` in the contract: by allocation link, by case pseudonym (exactly one, else ambiguous), create |
| 4 | Import report | Counts on `EncounterImport`, ED duplicate list, no schema shown | Counts through the handler result; optional report section | Show schema; optional section from the handler |
| 5 | Deletion, reprocessing, key change | `EncounterImportDataRemover` deletes `encounter` rows | `removeByImport()` on the handler | Encounter remover delegates to the handlers, then removes encounters without records (after the hub split) |
| 6 | Persistence | Static mapping in `doctrine.yaml`, one migrations path | Entities, mapping and migrations path prepended by the bundle | None in the application |
| 7 | Display in the case file | `HospitalCourseCaseSectionProvider` renders ED data | Record section (template and variables) per encounter | New Encounter extension point for record sections, rendered in the hospital course card (and later on the encounter detail page); access check stays in the core (`VIEW_CASES`) |
| 8 | Routes | Attribute scan of `src/` | Own controllers | Not needed for the proof of concept. For MTS: one route loader in Encounter that imports the routes of all schema bundles, so enabling a bundle needs no route file |
| 9 | Twig, translations | Namespace `@Encounter` | Bundle templates (`@<Bundle>`) and `translations/` | None (bundle conventions) |
| 10 | Navigation, tools, glossary, My Hospitals | Encounter providers | Own providers on the `Shared` and `Allocation` tags | None |
| 11 | Messenger | Routing of Encounter messages in `messenger.yaml` | Prepended routing for its messages | None |
| 12 | Data preparation tool | Fixed lists and ED logic in the core script | Schema file, profiles, scripts, catalogs | Tagged contribution; generic core script (separate issue) |
| 13 | Static analysis | Deptrac layers per context; PHPStan, Psalm on `src/` | – | Contract layer; `plugins/` and test bundles in the analysed paths |

Plugins depend on the Encounter contract and on `Shared`. Where another context offers the extension
point (case file, import data removers, import detail page), Encounter forwards to its plugins, so a
plugin needs no dependency on `Allocation` or `Import`.

## Boundary between the core and MTS

**Encounter core** (`src/Encounter`): import type extension, dispatcher, message and handler, file
reader, validator, manifest and identifying-content detection, key state and key change, grants and
voter, retention, cleanup, dimension and handler registries, the hub entity, the lookup and link
service, the contract layer, the generic import report.

**MTS bundle** (`plugins/encounter-ed-mts`, schema `ed_visit`): `EdVisitDimension`, row and mapper, the
`ed_visit` record table with all ED fields, MTS catalog with curation and glossary, plausibility table,
matching with review, swap and validation tools, `EncounterMatchDataRemover`, ED visit list and detail
pages, hospital course card content, the encounter analytics now in Statistics, the `ed_visit`
synthetic data, the ORBIS profile and `ed_visit` schema of the tool.

Implications:

- **Existing records.** The hub split keeps `encounter.id` and moves the ED columns into a record table
  keyed by `encounter_id`, so `encounter_match`, suspected duplicates and review decisions keep their
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
- a handler that attaches each stay by case pseudonym and writes it to its own table
  (`test_inpatient_stay`, foreign key to `encounter` with `ON DELETE CASCADE`, owning import, unique
  fingerprint per hospital and key generation), with its migration in its own path;
- a record section with admission, discharge diagnosis and discharge.

**Fixture** (`inpatient_stay`, four rows, explicit values):

| Row | Case pseudonym | Expected |
|---|---|---|
| 1 | case of visit A (linked to allocation X) | inserted, attached to A |
| 2 | same fingerprint as row 1 | duplicate |
| 3 | case of visits B and C (same case) | rejected: ambiguous |
| 4 | unknown case | rejected: no encounter |

Report: total 4, inserted 1, duplicates 1, rejected 2.

**Arrange:** hospital, user with the beta role and the upload and view-cases grants, allocation X,
one `ed_visit` import with visits A, B and C (B and C share the case pseudonym), A linked to X.

**Verify through the normal entry points:**

1. The upload form offers `inpatient_stay` to the authorized user; a user without the upload grant
   cannot upload.
2. Uploading the fixture creates an encounter import with schema `inpatient_stay`; running the queued
   import shows the counts above and the two reject reasons on the import detail page.
3. The case file of allocation X shows admission, discharge diagnosis and discharge of row 1 in the
   hospital course card.
4. Reprocessing gives the same counts and still one stay.
5. Deleting the import through the normal workflow removes the stay; visits A, B, C, the link to X and
   the `ed_visit` import stay.
6. Static: no class under `src/` names `inpatient_stay` or the test bundle; Deptrac allows the test
   bundle only the contract layer.

Transition, documented in ADR 037: until the hub split, deleting the `ed_visit` import also removes the
attached stay (cascade).

## Implementation plan for #1274

Part 1, proving the mechanism (MTS stays where it is):

1. Test support: generic `EncounterFileBuilder` from `EdVisitFileBuilder`, schema parameter in
   `EncounterImportRunner`. No behaviour change.
2. Contract layer `App\Encounter\Contract\` with Deptrac layer: schema handler interface, handler
   registry, `EncounterLookup`, record section interface.
3. `EncounterFileImporter` delegates to the handler; `ed_visit` becomes the first handler in the core;
   no handler rejects the file (finding 1); `EncounterImportDataRemover` delegates `removeByImport()`;
   the report shows the schema; the import type is hidden without schemas. Existing tests stay green.
4. Record sections in the hospital course card.
5. Test bundle with migration and the integration test above.

Part 2, moving MTS (#1300, one pull request per step):

6. Hub split with data migration (ADR 037, transition ends).
7. Bundle skeleton `plugins/encounter-ed-mts` as Composer path repository: prepended mapping, migrations
   path, messenger routing, route loader in the core.
8. Move MTS in slices: catalog and glossary, plausibility, matching and review tools, visit pages and
   case file content, encounter analytics out of Statistics, synthetic data, tool contribution.
9. Installation without the bundle: a test environment or kernel variant without the MTS bundle that
   boots, hides the encounter import type and runs allocation and closure imports.

Separate issue: generic data preparation tool with schema contributions (#1301).

## Open points

- How the test for an installation without the MTS bundle runs (second test environment or kernel
  variant), decided in step 9.
- Whether a case pseudonym that matches several encounters can later be resolved by time (last visit
  before admission).
- Merging and splitting encounters when a second schema creates encounters (ADR 037).
