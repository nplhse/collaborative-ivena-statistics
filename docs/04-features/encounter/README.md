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
  indicator are stored as codes until the MTS catalog is fixed (#1048, #1056). No dimension tables:
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
days of the inserted visits) for the matcher (#1053). Nothing consumes it yet.

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
the form. Links and review decisions are added to the counts
with matching (#1053) and review (#1054).

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

## Statistics in the pilot

Descriptive only, for the own hospital: coverage, encounters by arrival route and link status, MTS
categories and diagrams, process times recomputed from timestamps (median, IQR), disposition and
care level. No cross-hospital aggregates. Each figure documents population, filters, numerator and
denominator, missing values and period boundaries when it is implemented (#1055).

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
