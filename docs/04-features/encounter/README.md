# Encounter data (Behandlungsdaten)

**Audience:** Developers and domain experts working on the encounter data pilot.

**Status:** in development for beta v0.3 (umbrella #1043). This page describes the agreed design.
Sections are updated as the implementation steps land; nothing here is available in a release yet.

## Purpose

The pilot shows whether emergency departments (EDs) can link their routine data with IVENA
allocations and use them for local care improvement. IVENA allocations remain the primary data of
the project. The first source is the Manchester Triage System (MTS) export of ORBIS: triage, process
times, disposition and initial vitals.

Decisions: [ADR 025](../../02-architecture/decisions/025-encounter-data-context-and-exchange-format.md)
(context, exchange format, retention), [ADR 026](../../02-architecture/decisions/026-encounter-pseudonym-key.md)
(pseudonym key), [ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md) (access),
[ADR 028](../../02-architecture/decisions/028-encounter-import-lifecycle.md) (import lifecycle),
[ADR 029](../../02-architecture/decisions/029-encounter-allocation-matching.md) (matching).

## Flow

1. The hospital downloads the data preparation tool (one HTML file) from the encounter page
   ([data-preparation-tool.md](data-preparation-tool.md)).
2. In the browser, the tool reads the vendor export and the hospital's key file, drops identifying
   columns, computes the visit fingerprint and writes a canonical `ed_visit` file
   ([exchange-format.md](exchange-format.md)).
3. A user with the upload grant uploads that file and confirms that it was produced with the tool.
4. The server validates manifest, header, key check value and content, skips known visits (counted
   as duplicates) and inserts new ones.
5. Matching links encounters with IVENA allocations; automatic links are effective, people review
   proposals and conflicts.
6. Uploaded files are kept for 30 days for error analysis (`app.encounter.source_file_retention_days`);
   files recognised as raw HIS exports are deleted immediately
   ([upload file retention](#upload-file-retention)).

Implemented in #1051: the tool v1 with the ORBIS MTS profile, its download on `/encounter`, the
public update check `/data-prep/latest.json` and the version options `app.encounter.data_prep`
([data-preparation-tool.md](data-preparation-tool.md)).

## Model

- One `Encounter` = one ED visit of a hospital, with triage, process times, disposition, initial
  vitals, flags and at most one link to an allocation.
- Identity: internal `visit_uid`; duplicate detection by a keyed visit fingerprint, unique per
  hospital and key generation.
- Each encounter belongs to the import that created it. Deleting that import removes it, with its
  links and review decisions.
- The administrative case number is carried as a pseudonym only to report suspected duplicates; it
  does not group encounters. No patient-level key.
- `Allocation` is not changed and has no reference to encounter data.

Implemented in #1047:

- **One table `encounter`, one row per visit.** Groups of the `ed_visit` contract are Doctrine
  embeddables in the same row: `EncounterTimes` (no prefix), `Triage` (`mts_*`), `Retriage`
  (`retriage_*`), `EncounterDisposition` (`disposition_code`, `disposition_care_level`) and
  `InitialVitals` (`vitals_*`). The admitting department is a foreign key to the department catalog
  (`SET NULL` when the department is deleted). Code lists are backed enums in
  `src/Encounter/Domain/Enum/`; the isolation set is a sorted JSON list of codes. MTS diagram and
  indicator are stored as codes of the MTS catalog ([MTS glossary](#mts-glossary)). No dimension tables:
  every field belongs to exactly one visit.
- **Identity:** internal `visit_uid` (UUID v7), unique `(hospital_id, key_generation,
  visit_fingerprint)`. The row also stores the fingerprint anchor (`anchor_at`, `anchor_source`:
  first assessment contact, else ED admission, minute precision as exchanged); the matching anchor of
  ADR 029 (ED admission first) is derived from the timestamps and stored with the link (#1053).
- **Invariants** checked by the entity and, where cheap, by check constraints: age 1–99, key
  generation ≥ 1, a time anchor exists, care level only with `admitted` or `transferred_external`,
  vitals need a reference when any value is set, `vitals_measured_at` only with
  `admission_measurement`. Plausibility ranges of the file are checked by the import ([Import](#import)).
- **Flags** `work_accident` and `tetanus_protected` are nullable: `true` = documented yes, `null` = not
  documented (unknown). The export only marks "yes", so there is no `false`; a check constraint
  rejects it.
- **Insert:** `EncounterRepository::insertIfNew()` runs `INSERT … ON CONFLICT (hospital_id,
  key_generation, visit_fingerprint) DO NOTHING RETURNING id` and returns `Inserted` or `Duplicate`.
  A second session inserting the same visit waits for the first and then inserts nothing, so parallel
  imports cannot create a visit twice. A known visit is never updated. New visits are never persisted
  through the EntityManager.
- **Owning import:** `import_id` (`ON DELETE CASCADE`). Deleting an import and the cleanup before a
  reprocess both go through `ImportRelatedDataCleanupService`, which calls `EncounterImportDataRemover`
  ([extension point](../../02-architecture/extension-points.md#import-data-removers)); it deletes
  exactly the visits the import created. Links and review decisions (#1053, #1054) must reference
  `encounter` with `ON DELETE CASCADE` so they go with their visit.
- **Key state:** `encounter_key_state`, one row per hospital: `key_generation` (starts at 1),
  `active_check_value`, `retired_check_values`, `key_change_in_progress`. The import registers and
  checks check values ([Import](#import)); the [key change](#key-change) moves the generation. Changes
  through the ORM (the key change) are audited; the import's registration of a check value is not.
- **Schemas:** `EncounterDimensionInterface` (tag `encounter.dimension`) with `EdVisitDimension` as
  field contract of `ed_visit` v1
  ([extension point](../../02-architecture/extension-points.md#encounter-schemas)).

Example (tested in `EncounterImportLifecycleTest`): import A has visits on 1, 5, 10, 12 and 15 October,
import B on 10, 12, 15, 20 and 31 October. B inserts the 20th and 31st and counts three duplicates.
Deleting B leaves A's five visits; deleting A instead leaves only B's two, and B must be reprocessed
to restore the 10th, 12th and 15th. Reprocessing B removes its two visits and creates them anew
(new `visit_uid`); A's visits keep theirs.

## Import

Implemented in #1048. `ImportType::ENCOUNTER` runs through the import runs of the other import types
(queue, supersede, guarded commits, cleanup before a reprocess, deletion); the rest lives in the Encounter
context.

Encounter imports use the same import pages as allocation and closure imports (upload form, list,
detail page, processing page, reprocess and delete). The Encounter context plugs into them through the
`import.type_extension` extension point (`EncounterImportTypeExtension`), so `Import` does not depend
on it.

**Upload** (`/import/new`, type "Encounter data (beta)"): offered to users with the beta role and
`UPLOAD` on at least one hospital (or `ROLE_ADMIN`); the hospital list then also contains the hospitals
with `UPLOAD`, even without the hospital permission `Import`. For this type the form has a schema
(`ed_visit`) and a required confirmation that the file was produced with the data preparation tool and
contains no direct identifiers; its help links to the `/encounter` page for the tool (#1051). The
confirmation is stored on `encounter_import` (`confirmed_by`, `confirmed_at`, audited with the import
creation), together with the hospital's key generation at upload. Uploads are refused while the
hospital's key change is in progress.

**File checks** (`EncounterFileReader`, `EncounterFileValidator`), each rejecting the whole file; the run
fails with `invalid_file` and the reason is stored as `encounter_import.file_rejection`:

| Reason | Check | Source file |
|---|---|---|
| `raw_export` | first line is no `#encounter-exchange` manifest, or the header has a column that names identifying source data (name, birth, case number, patient, address, free-text columns …) | deleted at once |
| `identifying_content` | any cell with a date in a non-timestamp field, a name-like value (`Surname, Given name`, a title before a name) or more than 64 characters (code sets: 128) | deleted at once |
| `encoding_invalid` | not UTF-8 | kept |
| `manifest_invalid` | a manifest key is missing, `schema_version`/`rows` are no integers, `key_check` is no 64-digit hex, `tool` is no `x.y.z` | kept |
| `schema_unsupported` | schema differs from the one chosen at upload, or the version is not supported | kept |
| `profile_unsupported` | profile not `[a-z0-9-]`, profile version no positive integer | kept |
| `tool_version_too_old` | `tool` below `app.encounter.data_prep.min_version` (default `1.0.0`) | kept |
| `fingerprint_rule_unsupported` | not a rule of the schema version (`ed-visit:v1`) | kept |
| `header_mismatch` | header differs from the schema version in any way (order, missing or extra column) | kept |
| `row_count_mismatch` | number of data rows differs from `rows` | kept |
| `key_check_retired` | key check value belongs to an earlier key generation | kept |
| `key_check_mismatch` | key check value differs from the active one | kept |

The checks for identifying content run first and on every row, so such a file is deleted even when
its manifest is also wrong. The first file of a key generation registers its key check value. A deleted
file cannot be reprocessed.

**Rows** (`EdVisitRowMapper`, field contract `EdVisitDimension`): a row is rejected with line number,
field and error code (`required`, `invalid_format`, `invalid_code`, `out_of_range`, `inconsistent`,
`column_count`) and the profile version, stored as `import_reject` (`messages` like
`heart_rate: out_of_range`, `row` only holds profile and profile version). Rejects never contain the row
or a cell value. Values outside the plausibility ranges reject the row: the tool empties them before
upload. `admitting_department` must name a department of the reference catalog (case-insensitive, unique
name). A row without ED admission and without first assessment contact is skipped and counted.

**Write**: all valid rows are written in one guarded commit. It locks the hospital's key state, checks
that the key generation stored at upload is still current and no key change is in progress (otherwise
the run fails with `key_generation_changed` and nothing is written), checks or registers the key check
value and inserts row by row with `insertIfNew()`: known visits count as duplicates, new ones belong to
the import. The key generation is also checked before a reprocess removes the import's data, so a
reprocess after a key change keeps the visits.

**Suspected duplicates**: a new visit with the same `case_key` as another visit of the hospital and key
generation (earlier, or earlier in the same file) and a time anchor at most 15 minutes away is inserted
and recorded in `encounter_suspected_duplicate` (line, minutes, anchor sources, both visits). The report
page lists them for users with `UPLOAD`, without case keys or timestamps. They are never merged.

**List and detail page** (`/import`, `/import/{id}`): encounter imports are listed and opened only with
`UPLOAD` on their hospital (or `ROLE_ADMIN`); hospital ownership or hospital grants alone do not show them
(ADR 027). The detail page adds an encounter section: new visits, known visits, skipped and rejected
rows, suspected duplicates, profile, tool version, key generation, confirmation, file rejection, the
reject list and the suspected-duplicate list, and until when the upload file is kept or when it was
deleted. No file content. **Download** (`/import/{id}/source-file`) with `UPLOAD` on the hospital (or
`ROLE_ADMIN`), only while the file is kept; downloads are audited (`import.source_file.downloaded`).

| Count | Meaning |
|---|---|
| `import.row_count` | data rows of the file |
| `import.rows_rejected` | rejected rows; `rows_passed` = rows − rejected |
| `import.rows_deduplicated` | known visits (duplicates) |
| `encounter_import.inserted / duplicates / skipped / rejected / suspected_duplicates` | the report counts |

The import status follows the rejection ratio as for other imports (`ImportEvaluation`). The data
coverage counts the valid visits of the file (new and known) per day of their time anchor.

**Reprocess** (`/import/{id}/reprocess`, `UPLOAD`, only while the file is retained): the run removes the import's visits
(their links and review decisions go with them) and imports the retained file again. The page warns
before that links and review decisions of the import are lost.

**Delete** (`/import/{id}/delete`, `UPLOAD` via `EncounterAttribute::DELETE_IMPORT`): goes through
`ImportDeletionService` like every import deletion and removes the import's visits with their IVENA links
and review decisions, its rejects, report, suspected-duplicate records and source file. Visits of other
imports stay (ADR 028). The confirmation dialog says so; the deletion is audited like every import
deletion.

### Upload file retention

Accepted files and files rejected for a possible processing error are kept for
`app.encounter.source_file_retention_days` days (default 30, positive integer, no env variable) for
error analysis and reprocessing after a bug fix (ADR 025, #1049).

- The age counts from the upload (`import.created_at`); a reprocess does not extend it. A file is
  deleted once it is older than the retention: uploaded on 1 October 10:00, it is kept on 30 October
  and deleted by the run on 1 November.
- The daily scheduler job `encounter_source_file_retention` (03:10 Europe/Berlin,
  `DeleteExpiredEncounterSourceFilesMessage`, `EncounterSourceFileRetention`) deletes the files and
  stores the date in `encounter_import.source_file_deleted_at`. It is recorded in `job_run` with the
  counters `deleted_files`, `missing_files`, `failed_deletions` and `retention_days`
  ([messenger-monitoring.md](../../05-operations/messenger-monitoring.md#scheduler-jobs-in-the-run-log)).
- Imports with a pending or running run are left for the next day. A file that is already gone is
  marked as deleted; a failed deletion is logged and retried the next day.
- Manual run: `php bin/console app:encounter:delete-expired-source-files [--dry-run]` (import ids only;
  not recorded as a job run).
- Afterwards the detail page shows "File deleted on …"; download and reprocess are no longer offered
  and refused. The import record, its report, rejects and the imported encounters stay.
- Files recognised as raw HIS exports or with identifying content are deleted by the import at once,
  with the date of that run.
- Allocation and closure imports are not affected.

**Afterwards**: a successful run with new visits dispatches `EncountersImported` (hospital, import, anchor
days of the inserted visits); the matcher reruns those days ([Matching](#matching)).

## Key change

Implemented in #1050 (ADR 026). Page `/hospitals/{id}/edit/encounter-key` ("Change pseudonym key"), linked
from the encounter section of the hospital edit pages for the hospital owner and administrators
(`EncounterAttribute::CHANGE_KEY`, beta role required). Users with an encounter grant, also `UPLOAD`, get
403.

The page explains in a few sentences what the key file is for (recognising visits again without the
server learning who the patient is), when to change it (only if the key file was lost or may have been
seen by unauthorised people) and what follows: everyone needs the new key file, and visits uploaded so
far can no longer be matched with new uploads. The person then chooses **delete** (shows how many
encounters and imports go; afterwards any period can be uploaded again) or **keep** (only upload new
periods afterwards, otherwise visits are counted twice, because the new key gives them other fingerprints
and duplicate detection works only within one generation) and types the hospital name exactly in a red
confirmation box. The button stays disabled until the name matches (`encounter-key-change` Stimulus
controller; the server checks the name again). The button opens a confirmation modal (the shared `Modal`
component, as for deleting an import) that names the consequence of the chosen option; its button submits
the form. The "delete" texts also name the IVENA links (automatic or confirmed) and the
review decisions (confirmed or rejected) of the hospital that go with the visits.

Flow (`EncounterKeyChanger`, one transaction holding the key state lock that the import commit takes):

1. Refused when a key change of the hospital is already in progress.
2. `key_change_in_progress` is set, `key_generation` incremented, the active check value moved to the
   retired values (`active_check_value` becomes empty). The audit entry of `encounter_key_state` carries
   the intent `encounter.key.changed` with `option`, `previous_generation` and `generation`.
3. Imports uploaded with the old generation can no longer write: a queued run fails before it starts, a
   running one in its commit, both with `key_generation_changed` and without data ([Import](#import)).
   A file carrying a retired check value is rejected with `key_check_retired`.
4. **keep**: the change ends in the same transaction. Existing visits keep their `visit_uid` and
   generation; their links stay effective. Old imports stay listed; a reprocess fails with
   `key_generation_changed` and keeps their visits.
   **delete**: `DeleteEncounterDataAfterKeyChangeMessage` is queued in the same transaction (worker,
   queue `low`). The worker deletes every encounter import of the hospital with an earlier generation
   through `ImportDeletionService`: visits with links and review decisions, rejects, report, suspected
   duplicates, source and reject files. An import that is still running is retried after a minute
   (Messenger retry strategy of the transport, at most three times; then the message is in the failed
   transport and the change stays in progress until it is retried). Afterwards it ends the change under
   the audit intent `encounter.key.change_completed`. Uploads stay refused until then.
5. The next upload registers the check value of the new generation.

Administrators get `admin.encounter_key_changed` (hospital, username, new generation, option) once per
hospital and generation ([notifications](../notification/README.md#notification-types)).

## Population

Self-presenters and EMS arrivals without IVENA link are part of the pilot population on purpose.
Two separate attributes describe each encounter:

| Attribute | Values | Source |
|---|---|---|
| Arrival route | EMS, self-presenter, unknown | derived from the documented `arrival_mode` of the clinical export |
| IVENA link | linked (automatic), linked (confirmed), not linked | matching |

Arrival route mapping (`ArrivalRoute::fromArrivalMode()`, stored as `encounter.arrival_route` so queries
can filter on it; a check constraint keeps it consistent with `arrival_mode`):

| `arrival_mode` | ORBIS `Transport` | Arrival route |
|---|---|---|
| `ambulance` | `RTW` | EMS |
| `patient_transport` | `KTW` | EMS |
| `physician_vehicle` | `NAW/NEF/ITW` | EMS (a special case, usually an RTW with an emergency physician) |
| `self` | `Ohne` | self-presenter |
| `other`, empty | `Anderes`, empty | unknown |

A missing link says nothing about the arrival route. EMS arrivals without an allocation are normal.
Overviews cross both attributes, for example "EMS arrival, not linked".

## Access

| Action | Needs |
|---|---|
| Pilot pages and navigation | `ROLE_ENCOUNTER_BETA` (inherited by `ROLE_ADMIN`) |
| Upload | beta role + encounter grant `UPLOAD` on the hospital, or `ROLE_ADMIN` |
| Key change | hospital owner or `ROLE_ADMIN` (no grant action allows it) |
| Match review | beta role + encounter grant `REVIEW` (implies `VIEW_CASES`), or `ROLE_ADMIN` |
| Case-level views, case card on an allocation | beta role + encounter grant `VIEW_CASES`, or `ROLE_ADMIN` |
| Descriptive aggregates of the own hospital | beta role + any encounter grant + `STATISTICS`, or `ROLE_ADMIN` |
| View or download a retained upload file | beta role + encounter grant `UPLOAD` on the hospital, or `ROLE_ADMIN` |
| Reprocess a retained upload file | beta role + `UPLOAD`, or `ROLE_ADMIN` (shows no file content) |
| Delete an encounter import | beta role + `UPLOAD`, or `ROLE_ADMIN` |
| Manage encounter grants of a hospital | hospital owner or `ROLE_ADMIN` |
| Import status, counts, reject codes, match diagnostics without case details or file content | `ROLE_ADMIN` |

Hospital ownership and hospital grants do not imply an encounter grant. `ROLE_ADMIN` holds every
grant action on every hospital without a stored grant; an administrator impersonating a user has that
user's rights only. Every row
needs `ROLE_ENCOUNTER_BETA` first, including grant management and the key change, so a hospital owner
needs the beta role to manage encounter grants.

Implemented in #1046:

- Pilot entry page `/encounter` with a navigation entry for the beta role; it lists the user's own
  encounter grants until the later steps add their pages.
- Grant management at `/hospitals/{id}/edit/encounter-access`, linked from the hospital edit pages.
  Candidates are the owner (who can grant themselves case access) and users with a hospital access
  grant. Removing someone's hospital access, or changing the owner, removes their encounter grant.
- `EncounterVoter` with the attributes in `EncounterAttribute`; later steps check them on the hospital
  of the requested object. Downloads of upload files are audited like every import source file download.

Details:
[ADR 027](../../02-architecture/decisions/027-encounter-access-grants.md),
[permission-model.md](../../02-architecture/permission-model.md#encounter-grants).

## Matching

Summary of [ADR 029](../../02-architecture/decisions/029-encounter-allocation-matching.md):

- Same hospital; pass 1 EMS arrivals, −20 … +45 min, age ±2; pass 2 the rest, −30 … +90 min, age ±5.
- Features: time difference, sex, age difference, arrival route, physician vehicle, work accident.
  MTS category, MTS diagram and admitting department are not used.
- Score 0.0–1.0 with competition between candidates, shown as agreement with feature contributions.
- `auto_accepted` (≥ 0.90, no conflict: no other allocation reaches 0.90 for the same visit in the
  same pass) is an effective link with visible automatic origin;
  `proposed` (≥ 0.50) needs a decision; manual decisions are sticky.
- Pass 1 reserves both sides; later runs only fill gaps and never reassign links. The review may
  propose a targeted swap.

Implemented in #1053 (matcher version `1.0.0`). The review is #1054; the overview and the case card (#1055) show links as described in [Statistics in the pilot](#statistics-in-the-pilot).

**Table `encounter_match`**, one row per pair of allocation and visit: `status` (`auto_accepted`,
`proposed`, `confirmed`, `rejected`), `conflict`, `score`, `points`, `contributions` (points per feature),
`pass`, `matcher_version`, `time_anchor` (`admission`, or `assessment` as marked substitute),
`time_difference_minutes` (anchor − IVENA arrival), `decided_by`/`decided_at` (manual decisions only;
a check constraint enforces it) and `created_at`. Partial unique indexes make effective links
(`auto_accepted`, `confirmed`) one-to-one on both sides. The row goes with its visit, its allocation and
its hospital (`ON DELETE CASCADE`); a deleted user leaves the decision with `decided_by` empty.

**Score**: each candidate visit of an allocation weighs 2^points, the "no match" option 2^4; a
candidate's score is its share of the total weight (`MatchPlanner`, points in `FeatureScorer`). The
points and the "no match" weight are set by judgement, not estimated (ADR 029); calibration follows
#1057. Changing them changes the matcher version.

| Feature | Points |
|---|---|
| time: matching anchor − IVENA arrival | −5 … +15 min: +4; −20 … −6 or +16 … +30: +2; +31 … +45: 0; otherwise (pass 2): −2 |
| sex | equal: +2; male vs female: −4; one side diverse: 0 |
| age difference | 0: +2; 1: +1; 2: 0; 3–5 (pass 2): −2 |
| arrival route | EMS: +1; unknown: 0; self-presenter (pass 2): −2 |
| physician vehicle ↔ IVENA "with physician" | physician vehicle and physician: +2; one of them only (physician vehicle without, ambulance or patient transport with): −1; otherwise 0 |
| work accident ↔ IVENA work accident | both: +2; one side only: −1; neither: 0 |

Example: arrival 10:00, ED admission 10:07, same sex and age, ambulance: 4 + 2 + 2 + 1 = 9 points; alone
it scores 512 / (16 + 512) = 0.9697 and becomes `auto_accepted`. A second visit at 10:06 with the same
values gives both 512 / 1040 = 0.4923: nothing is proposed. Times are compared on the local wall clock
at minute precision.

**Runs** (`EncounterMatcher`, one transaction under a per-hospital advisory lock that the review takes too):

1. Scope: the allocations of one hospital whose IVENA arrival falls on the given days; candidate visits
   from the pass windows around them, whatever their day.
2. Existing effective links reserve both sides; rejected pairs are left out; open proposals of the
   scope's allocations are dropped and recomputed. Links and manual decisions are never changed.
3. Pass 1, then pass 2 (`MatchPlanner`, pure). Within a pass all claims are computed first; a claim
   ≥ auto-accept becomes a link unless another allocation also reaches it for the same visit (then all
   such claims become proposals with `conflict` and nothing is reserved). A pair proposed in pass 1 keeps
   its pass-1 result.
4. Proposals touching a side that is now linked are removed.

**Triggers** (`MatchEncountersMessage`, queue `low`):

| Event | Days |
|---|---|
| `EncountersImported` (encounter import with new visits) | the visits' anchor days, each widened by one day on both sides |
| `ImportCompleted` of an allocation import, if the hospital has encounter data | the arrival days of its allocations |
| Deletion or reprocess of an allocation or encounter import (`EncounterMatchDataRemover`) | the arrival days of its allocations and the days around its visits; the import's rows are left out and the run waits 30 s for the deletion to commit |

Deleting, reprocessing or requeueing an allocation import removes the links and manual decisions of
its allocations (ADR 029, as ADR 028 does for encounter imports). The reprocessed import gets new
allocation ids; the next run links them again automatically, manual decisions have to be made again.
The allocation import pages do not warn about it (Import does not depend on Encounter); the import docs
do ([import pipeline](../import/import-pipeline.md#reprocessing), [batch requeue](../import/batch-requeue.md#reimport-cleanup)).

**Proposals across runs**: open proposals are not kept; each run recomputes those of its allocations. A
pair proposed in pass 1 keeps its pass-1 score when pass 2 sees it again, and proposals touching a side
that is linked at the end of the run are removed.

**Manual run**: `php bin/console app:encounter:match --hospital=<id> --from=YYYY-MM-DD [--to=YYYY-MM-DD]`
prints counts only. Synthetic encounters loaded by the fixtures are not matched until it runs.

**Configuration** (`app.encounter.matching`): `auto_accept_threshold` (0.90), `proposal_threshold`
(0.50), `pass1` and `pass2` with `minutes_before`, `minutes_after`, `max_age_difference` (20/45/2 and
30/90/5). A change applies from the next run; existing links stay.

**Read model**: `EncounterKeyChangeSummaryQuery` counts links and decisions per hospital;
`EncounterOverviewQuery` and `HospitalCourseQuery` read them for the pages of #1055.

## Statistics in the pilot

Descriptive only, for the own hospital: coverage, encounters by arrival route and link status, MTS
categories and diagrams, process times recomputed from timestamps (median, IQR), disposition and
care level. No cross-hospital aggregates.

### Hospital overview

Implemented in #1055. Statistics page `/statistics/encounter-analytics` ("Encounter data (beta)" in the
statistics sub-navigation, title "Analysis" / "Auswertung"; `EncounterAnalyticsController` in `Statistics/UI`),
also linked from the pilot entry page `/encounter` for every hospital the user may see aggregates of. It
uses the statistics building blocks: the scope and period picker (`Statistics:AnalysisContext`) in
`AnalysisContextScopeMode::AssignedHospital`, the statistics filter (`StatisticsFilterValueResolver`) and
period window (`StatisticsPeriodResolver`), and the statistics page layout. The picker offers exactly the
hospitals of `AggregateHospitalScope` (`EncounterAttribute::VIEW_AGGREGATES`: beta role + any encounter
grant + hospital permission `STATISTICS`; administrators every hospital with stored visits) and no "my
hospitals" aggregate; any other scope in the URL (public, state, cohort, another hospital) is redirected
to `scope=hospital&hospital=<id>` of an allowed hospital. Users without such a hospital see a notice;
without the beta role the page is forbidden. Content tabs (`?tab=`): overview (KPI deck, segmented bars
for arrival route and MTS categories, visits over time, distribution cards), process times (box plots and
table), IVENA link (route × link), distributions (full tables) and data coverage. Definitions sit in info
popovers on the card titles. Every figure belongs to exactly one hospital; the page never adds up
hospitals and compares with none. Reads: `EncounterOverviewQuery` (`Infrastructure/Query/`, ADR 009)
behind `EncounterOverviewReaderInterface`; suppression and ordering: `EncounterOverviewFactory`.
Statistics depends on these Encounter application contracts (documented partnership,
[bounded-contexts.md](../../02-architecture/bounded-contexts.md)); Encounter does not depend on Statistics.

**Common rules for every figure:**

| Aspect | Rule |
|---|---|
| Population | All stored visits of the hospital (self-presenters, EMS arrivals with and without allocation, unknown route), all key generations, all imports. |
| Period | The statistics period of the picker (`period`, `year`, `quarter`, `month`): last 12 months (default, from the first day of the month eleven months before today, open end), all time, a calendar year, quarter or month ([statistics-filter-and-scope.md](../statistics/statistics-filter-and-scope.md#periods)). Its window `[from, toExclusive)` becomes whole days (`EncounterPeriod::fromBounds()`). A visit belongs to the period by its time anchor `anchor_at` (first assessment contact, else ED admission; local wall clock as exchanged), the same day the import coverage uses: `anchor_at >= from 00:00 AND anchor_at < toExclusive`. |
| Filters | None besides hospital and period. |
| Small cells | Counts from 1 to 4 are shown as `< 5` (ADR 012, `MtsCount`, `CatalogPrivacyPolicy::MIN_ALLOCATIONS`), without share, and left out of charts (`null`); 0 is shown as a dash. No complementary suppression: totals are shown when they reach 5, as in the MTS glossary. |
| Comparison groups | None. |

**Figures:**

| Figure | Numerator | Denominator (shares) | Missing or unknown values |
|---|---|---|---|
| Coverage | Visits, distinct owning imports, distinct anchor days, first and last anchor in the period; below, the same for all data | – (not suppressed: they describe the uploaded data, not patients) | – |
| Arrival route × IVENA link | Visits per route (`ems`, `self`, `unknown` from `arrival_route`) and link: linked automatically (`auto_accepted`), linked after confirmation (`confirmed`), linked in total (both), not linked | Visits of the row (route), last row all visits | Route `unknown` = `arrival_mode` `other` or empty. Not linked = no effective link: no pair, only an open proposal, or a rejected pair. Automatic links count as linked; their origin is a column of its own. |
| MTS categories | Visits per initial `mts_category`, in MTS order red … blue | All visits of the period | "Not documented" row for visits without category. Retriage not counted. |
| Top presentations (MTS diagrams) | The 10 most frequent initial `mts_diagram` codes (ties by code), then "other diagrams" (sum of the rest) | All visits of the period | "Not documented" row. Names from the MTS catalog (`MtsNames`); unknown codes shown as code. |
| Process times | Median, Q1 and Q3 of the valid durations (PostgreSQL `percentile_cont`, linear interpolation), counted visits | – | Per interval: "time missing" (one of the two timestamps empty), "negative" (end before start), "over 24 h" (more than `ProcessInterval::MAX_PLAUSIBLE_MINUTES` = 1440). They are excluded from median and IQR and shown as counts. Median and IQR need at least 5 valid durations. |
| KPIs (overview tab) | visits; EMS arrivals linked (automatic or confirmed) of all EMS arrivals; median arrival → first physician and arrival → ED discharge with IQR; admitted to this hospital of all visits | as stated | a suppressed numerator shows `< 5` instead of a share |
| Visits over time | visits per bucket and arrival route; buckets are calendar weeks (Monday) when the visits of the period span at most 120 days (`TimeGrain`), else months; every bucket from the first to the last visit, empty ones as 0 | – | buckets with 1–4 visits per route are gaps in the chart |
| Process time box plots | per interval and initial MTS category: P10, Q1, median, Q3, P90 of the valid durations (whiskers P10/P90, never minimum or maximum, which describe single visits) | – | only categories with at least 5 valid durations are drawn |
| Disposition | Visits per `disposition_code` in code order | All visits of the period | "Not documented" row. |
| Care level | Visits per `disposition_care_level` among visits with disposition `admitted` or `transferred_external` | These admissions and transfers | "Not documented" row for such visits without care level; `unknown` (ward not mappable) is a level of its own. |

**Process time intervals** (`ProcessInterval`), recomputed from the stored timestamps, never taken from the
export. All start at the ED admission (`ed_admission_at`, "Aufnahme ZNA", the documented arrival in the
emergency department); durations are whole minutes of the local wall clock (a visit spanning the switch
to or from daylight saving time is off by one hour). Valid: 0 to 1440 minutes, both inclusive.

| Interval | Start → end |
|---|---|
| Arrival → first assessment | `ed_admission_at` → `first_assessment_contact_at` |
| Arrival → first physician | `ed_admission_at` → `first_physician_at` |
| Arrival → ED discharge | `ed_admission_at` → `ed_discharge_at` (length of stay in the ED) |

Visits without ED admission (only a first assessment contact) are "time missing" in all three.

Example (tested in `EncounterOverviewQueryTest`, data in `OverviewDataFixtures`): durations from admission
to first assessment of 5, 10, 15, 20, 25 and 30 minutes give Q1 = 10 + 0.25 × 5 = 11.25, median
(15 + 20) / 2 = 17.5 and Q3 = 20 + 0.75 × 5 = 23.75; a discharge after 1560 minutes is "over 24 h",
one after exactly 1440 minutes counts. A visit at 00:00 on the first day and one at 23:59 on the last
day are in the period, visits at 23:59 the day before and 00:00 the day after are not.

Charts (`encounter-overview-chart` Stimulus controller, ApexCharts): visits over time and route × link as
stacked bars, process times as horizontal box plots; the segmented bars and distribution cards reuse
the markup of the statistics overview. Tables on the IVENA link, process times and distributions tabs
carry the exact figures.

### Case card "Hospital course"

Implemented in #1055. Card on the allocation case file (`/explore/allocation/{publicId}`), contributed
through the [allocation case file extension point](../../02-architecture/extension-points.md#allocation-case-file-sections)
(`HospitalCourseCaseSectionProvider`); Allocation does not depend on Encounter. Shown only with
`EncounterAttribute::VIEW_CASES` on the allocation's hospital (grant `VIEW_CASES` or `REVIEW` with the
beta role, or `ROLE_ADMIN`); everyone else sees the case file without it.

- Reads the effective link of the allocation (`auto_accepted` or `confirmed`, `HospitalCourseQuery`).
  Without one the card says that no visit is linked; proposals belong to the match review (#1054).
- **Link:** origin "linked automatically" (nobody checked it) or "linked after confirmation", the
  agreement score in percent (rounded down) and the time difference between matching anchor and IVENA
  arrival.
- **Triage:** initial MTS category, diagram (catalog name), indicator, pain score.
- **Timeline:** IVENA arrival, ED admission, first assessment, first physician, treatment end, ED
  discharge, in time order, each with minutes relative to the ED admission. Vitals appear on it only
  with `vitals_reference = admission_measurement` and a measurement time.
- **Process times:** the three intervals of the overview for this visit; a value outside 0–1440 minutes
  is marked as excluded from the statistics.
- **Disposition:** disposition, care level, admitting department.
- **Vitals:** with `documented_initial` labelled "documented first value" with the note that the time
  of measurement is not assured, and never placed on the timeline.

## MTS glossary

Implemented in #1056. Explore → Glossary → Manchester Triage System (`/explore/glossary/mts`, one page
per diagram under `/explore/glossary/mts/diagram/{code}`), contributed through the Explore glossary
extension point ([explore-glossary.md](../allocation/explore-glossary.md)); Allocation does not depend
on Encounter.

- **Single catalog.** `src/Encounter/Application/Catalog/mts-de.json` (40 diagrams, 70 indicators, both
  open lists) is read by the data preparation tool (bundled as catalog `mts-de`) and by `MtsCatalog`.
  `MtsCatalog::code()` derives the code exactly as the tool's `catalogCode()`; `MtsCatalogTest` checks
  examples, the codes of the tool's canonical sample output and the English names. The JSON content
  is unchanged by the move, so the tool version stays.
- **Categories:** `MtsCategory` with the target time from the first assessment contact to the first
  physician contact (`physicianContactTargetMinutes()`: red 0, orange 10, yellow 30, green 90, blue
  120 minutes), colour and name in EN and DE.
- **Names only.** No discriminator texts and no flowcharts (copyright). The German name is the source.
  English: diagrams show their well-known English chart name (`encounter.mts.diagram_name.<code>`,
  English translation file only, for 39 of 40 diagrams; `Generelle Indikatoren` keeps its German
  name) with the German name below; indicators keep their German name in both languages, as there is
  no generally known English name for most of them and a translation of our own would invent terms.
  The German locale never reads these keys; German names come from the catalog, not from a translation
  file.
- **Visibility.** Definitions are shown to every participant (`^/explore` rule). Counts are shown only
  to users with `VIEW_CASES` or `VIEW_AGGREGATES` on a hospital (`EncounterVoter`), summed over exactly
  those hospitals (`EncounterCountScopeResolver`); administrators with the beta role count all
  hospitals, as ADR 027 gives them every action. Other hospitals are never counted.
- **What is counted.** Encounters by the initial triage (`mts_category`, `mts_diagram`,
  `mts_indicator`), all key generations, no period filter; retriage values are not counted. Population:
  every stored encounter of the hospitals in scope; encounters without a value are not counted for that
  code. A diagram page counts all encounters with the diagram (total) and per indicator; encounters of
  the diagram without an indicator are one extra row.
- **Small cells.** Counts from 1 to 4 are shown as `< 5` (`MtsCount`, threshold
  `CatalogPrivacyPolicy::MIN_ALLOCATIONS`, ADR 012); 0 is shown as a dash.
- **Diagram page.** Lists the indicators seen with the diagram in the encounters the user may see, with
  a filter on name and code (`?q=`). Indicators seen in data but not in the catalog are listed with
  their code and marked "not in the catalog". Without encounter access the page shows the definition
  and explains that the indicator list comes from encounter data: listing all catalog indicators would
  suggest pairs that the MTS does not have, and pairs from other hospitals' data are not shared.

## Synthetic data

Implemented in #1052: development, tests and the demo use generated encounters of synthetic
allocations with known true links, never real data. The generator creates linked visits with time
and age noise, self-presenters, EMS arrivals without allocation, repeat visits under one case number,
visits without ED admission time and late assessments; it writes them straight into the database
(fixture group `encounters`, part of `dev`; the demo user `foo` gets the beta role and an encounter
grant with `UPLOAD`, `REVIEW` and `VIEW_CASES` for its hospital) or as canonical `ed_visit` files with a test key file,
overlapping exports and a truth file (`app:generate:encounter-files`). Details:
[synthetic-data-generator.md](../../03-development/synthetic-data-generator.md#encounter-data).

The contract between the tool and the import is tested end to end: `exchange-fixture.test.js` runs
the tool core on the synthetic ORBIS-like sample and keeps its output in
`tests/Encounter/DataPrep/fixtures/orbis-mts-sample.ed_visit.csv`; `DataPrepToolOutputImportTest`
imports that file and expects no file or row rejects.

## Governance

Before the first real upload the pilot hospital documents the data flow:
[governance.md](governance.md).

## Steps

| Issue | Step |
|---|---|
| #1044 | ADRs and documentation |
| #1045 | ORBIS visit fingerprint |
| #1046 | Context skeleton, beta role, encounter grants |
| #1047 | Data model and import lifecycle |
| #1048 | Encounter import |
| #1049 | Source file retention |
| #1050 | Key change |
| #1051 | Data preparation tool v1 |
| #1052 | Synthetic encounter data |
| #1053 | Matcher v1 |
| #1054 | Match review |
| #1055 | Descriptive pages and case card |
| #1056 | MTS glossary |
| #1057 | Match validation |
| #1058 | IVENA case number in encounter sources (after the pilot) |
