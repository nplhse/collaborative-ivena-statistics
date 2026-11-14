# Encounter data (Behandlungsdaten)

**Audience:** Developers and domain experts working on the encounter data pilot.

**Status:** in development for beta v0.3 (umbrella #1043). This page describes the agreed design.
Sections are updated as the implementation steps land; nothing here is available in a release yet.

## Purpose

The pilot shows whether emergency departments (EDs) can link their routine data with IVENA
allocations and use them for local care improvement. IVENA allocations remain the primary data of
the project. The first source is the Manchester Triage System (MTS) export of ORBIS: triage, process
times, disposition and initial vitals.

Decisions: [ADR 026](../../02-architecture/decisions/026-encounter-data-context-and-exchange-format.md)
(context, exchange format, retention), [ADR 027](../../02-architecture/decisions/027-encounter-pseudonym-key.md)
(pseudonym key), [ADR 028](../../02-architecture/decisions/028-encounter-access-grants.md) (access),
[ADR 029](../../02-architecture/decisions/029-encounter-import-lifecycle.md) (import lifecycle),
[ADR 030](../../02-architecture/decisions/030-encounter-allocation-matching.md) (matching),
[ADR 032](../../02-architecture/decisions/032-mts-catalog-as-maintained-data.md) (MTS catalog),
[ADR 033](../../02-architecture/decisions/033-learning-plausibility-table.md) (plausibility table).

## Flow

1. The hospital downloads the data preparation tool (one HTML file) from its landing page
   `/tools/data-prep`, which also walks through grants, export, key file and local map
   ([data-preparation-tool.md](data-preparation-tool.md#landing-page)).
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

Implemented in #1051: the tool v1 with the ORBIS MTS profile, its download (now on the landing page), the
public update check `/data-prep/latest.json` and the version options `app.encounter.data_prep`
([data-preparation-tool.md](data-preparation-tool.md)). #1130 added the landing page
`/tools/data-prep` (getting started, versions, changelog) with subpages for the key file and the
local map, and the local map editor in the tool (1.1.0). #1157 added the public MTS catalog
`/data-prep/mts-catalog.json` for the tool (1.2.0) and provisional catalog entries created by the import
([MTS glossary](#mts-glossary)).

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
  ADR 030 (ED admission first) is derived from the timestamps and stored with the link (#1053).
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
contains no direct identifiers; its help links to the tool's landing page `/tools/data-prep` (#1051, #1130). The
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
reprocess after a key change keeps the visits. In the same commit, MTS diagram and indicator codes (initial
triage and retriage) of the new visits that resolve to nothing become provisional catalog entries
([MTS glossary](#mts-glossary), #1157); the visits keep the codes as sent.

**Suspected duplicates**: a new visit with the same `case_key` as another visit of the hospital and key
generation (earlier, or earlier in the same file) and a time anchor at most 15 minutes away is inserted
and recorded in `encounter_suspected_duplicate` (line, minutes, anchor sources, both visits). The report
page lists them for users with `UPLOAD`, without case keys or timestamps. They are never merged.

**List and detail page** (`/import`, `/import/{id}`): encounter imports are listed and opened only with
`UPLOAD` on their hospital (or `ROLE_ADMIN`); hospital ownership or hospital grants alone do not show them
(ADR 028). The detail page adds an encounter section: new visits, known visits, skipped and rejected
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
imports stay (ADR 029). The confirmation dialog says so; the deletion is audited like every import
deletion.

### Upload file retention

Accepted files and files rejected for a possible processing error are kept for
`app.encounter.source_file_retention_days` days (default 30, positive integer, no env variable) for
error analysis and reprocessing after a bug fix (ADR 026, #1049).

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

**Monitoring**: every attempt is recorded in `job_run` as job `encounter_import` with the row counters,
also when Messenger gives up after retries; there are no thresholds until runs have been measured
([observability-sentry.md](../../05-operations/observability-sentry.md#imports), #1070).

**Afterwards**: a successful run with new visits dispatches `EncountersImported` (hospital, import, anchor
days of the inserted visits); the matcher reruns those days ([Matching](#matching)).

## Key change

Implemented in #1050 (ADR 027). Page `/hospitals/{id}/edit/encounter-key` ("Change pseudonym key"), linked
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
| Match validation: draw samples, record verdicts, read the report | beta role + encounter grant `REVIEW`, or `ROLE_ADMIN` |
| Case-level views, case card on an allocation | beta role + encounter grant `VIEW_CASES`, or `ROLE_ADMIN` |
| Descriptive aggregates of the own hospital | beta role + any encounter grant + `STATISTICS`, or `ROLE_ADMIN` |
| View or download a retained upload file | beta role + encounter grant `UPLOAD` on the hospital, or `ROLE_ADMIN` |
| Reprocess a retained upload file | beta role + `UPLOAD`, or `ROLE_ADMIN` (shows no file content) |
| Delete an encounter import | beta role + `UPLOAD`, or `ROLE_ADMIN` |
| Manage encounter grants of a hospital | hospital owner or `ROLE_ADMIN` |
| Import status, counts, reject codes, match diagnostics without case details or file content | `ROLE_ADMIN` |
| Plausibility table: edit problem areas, review, activate, retire and delete snapshots (points only, no case access) | beta role + `ROLE_ENCOUNTER_CATALOG_REVIEWER` (inherited by `ROLE_ADMIN`) |

Hospital ownership and hospital grants do not imply an encounter grant. `ROLE_ADMIN` holds every
grant action on every hospital without a stored grant; an administrator impersonating a user has that
user's rights only. Every row
needs `ROLE_ENCOUNTER_BETA` first, including grant management and the key change, so a hospital owner
needs the beta role to manage encounter grants.

Implemented in #1046:

- Pilot entry page `/encounter` with a navigation entry for the beta role. Removed in #1128: review,
  validation and the data preparation tool are Tools ([below](#tools-section)), `/encounter` redirects to
  `/tools`, and My Hospitals shows "Encounter data" on the cards of the hospitals whose encounter analysis
  the user may open.
- Grant management at `/hospitals/{id}/edit/encounter-access`, linked from the hospital edit pages.
  Candidates are the owner (who can grant themselves case access) and users with a hospital access
  grant. Removing someone's hospital access, or changing the owner, removes their encounter grant.
- `EncounterVoter` with the attributes in `EncounterAttribute`; later steps check them on the hospital
  of the requested object. Downloads of upload files are audited like every import source file download.

Details:
[ADR 028](../../02-architecture/decisions/028-encounter-access-grants.md),
[permission-model.md](../../02-architecture/permission-model.md#encounter-grants).

## Matching

Summary of [ADR 030](../../02-architecture/decisions/030-encounter-allocation-matching.md):

- Same hospital; pass 1 EMS arrivals, −20 … +45 min, age ±2; pass 2 the rest, −30 … +90 min, age ±5.
- Features: time difference, sex, age difference, arrival route, physician vehicle, work accident.
  MTS category, MTS indicator and admitting department are not used. Since matcher `1.2.0` (#1154) pass 2 adds
  a bonus of 0 … +3 when PZC and MTS diagram are plausible together in the active snapshot
  ([plausibility table](#plausibility-table)); pass 1 never uses the diagram.
- Score 0.0–1.0 with competition between candidates, shown as agreement with feature contributions.
- `auto_accepted` (≥ 0.90, no conflict: no other allocation reaches 0.90 for the same visit in the
  same pass) is an effective link with visible automatic origin;
  `proposed` (≥ 0.50) needs a decision; manual decisions are sticky.
- Pass 1 reserves both sides; later runs only fill gaps and never reassign links. The review may
  propose a targeted swap.

Implemented in #1053 (matcher version `1.0.0`); production matcher since #1154: `1.2.0` (`1.1.0` before; see [ADR 030](../../02-architecture/decisions/030-encounter-allocation-matching.md#matcher-versions) and the matcher versions below). The review is #1054 ([Match review](#match-review)); the overview and the case card (#1055) show links as described in [Statistics in the pilot](#statistics-in-the-pilot).

**Table `encounter_match`**, one row per pair of allocation and visit: `status` (`auto_accepted`,
`proposed`, `confirmed`, `rejected`), `conflict`, `score`, `points`, `contributions` (points per feature),
`pass`, `matcher_version`, `time_anchor` (`admission`, or `assessment` as marked substitute),
`time_difference_minutes` (anchor − IVENA arrival), `plausibility_snapshot_id`, `plausibility_level` and
`plausibility_decisive` (since `1.2.0`, see below), `decided_by`/`decided_at` (manual decisions only;
a check constraint enforces it) and `created_at`. Partial unique indexes make effective links
(`auto_accepted`, `confirmed`) one-to-one on both sides. The row goes with its visit, its allocation and
its hospital (`ON DELETE CASCADE`); a deleted user leaves the decision with `decided_by` empty.

**Score**: each candidate visit of an allocation weighs 2^points, the "no match" option 2^4; a
candidate's score is its share of the total weight (`MatchPlanner`, points in `FeatureScorer`). The
points and the "no match" weight were set by judgement for v1 (ADR 030); the
[match validation](#match-validation) measures them. They live in `MatchWeights` with their version
(`MatchWeights::production()`, currently `MatchWeights::v1_2()`, `1.2.0`); a new matcher version is a new
set of points with a new version, which `MatchPlanner::VERSION` names and every written row stores.

| Feature | Points (`1.2.0`; earlier versions where different) |
|---|---|
| time: matching anchor − IVENA arrival | −5 … +15 min: +4; −20 … −6 or +16 … +30: +3 (v1: +2); +31 … +45: 0; otherwise (pass 2): −2 |
| sex | equal: +2; male vs female: −2 (v1: −4); one side diverse: 0 |
| age difference | 0: +2; 1: +1; 2: 0; 3–5 (pass 2): −2 |
| arrival route | EMS: +1; unknown: 0; self-presenter (pass 2): −2 |
| physician vehicle ↔ IVENA "with physician" | physician vehicle and physician: +2; ambulance with physician (rendezvous system, `physician.rendezvous`): +1 (`1.0.0`, `1.1.0`: −1); physician vehicle without, or patient transport with physician: −1; otherwise 0 |
| work accident ↔ IVENA work accident | both: +2; one side only: −1; neither: 0 |
| plausibility (pass 2 only, since `1.2.0`) | bonus of the allocation's PZC × the visit's canonical MTS diagram in the active snapshot, 0 … +3 ([plausibility table](#plausibility-table)); never negative |

**Plausibility bonus** (`1.2.0`, ADR 030 amendment, ADR 033): pass 2 reads the snapshot active when the run
starts (`PlausibilityLookup::active()`) and adds, for every candidate, the bonus of its cell (exact PZC × diagram,
or the PZC's problem area × diagram when the exact cell has too few observations; stored alias codes resolve to the
canonical diagram), at most `MatchWeights::$plausibilityCap` (+3), as contribution `plausibility` (0 included). It
competes like any other point; candidates, windows, thresholds and the conflict rule are unchanged. No PZC (no
normalized indication), no diagram, no cell or no active snapshot: 0. Pass 1 adds nothing and records nothing. Each
pass-2 pair stores `plausibility_snapshot_id` (null without active snapshot; the reference keeps active and retired
snapshots from being deleted), `plausibility_level` (`exact`, `area` or `none`) and `plausibility_decisive`: its
score with the bonus removed from every candidate of the allocation in that pass stays below the threshold of its
status (0.90 for an automatic link, 0.50 for a proposal), so the bonus decided it. Pairs of pass 1, of earlier
versions and manual pairs are never decisive. The bonus can also lower a pair: a competitor's bonus takes share of
the score, so an automatic link becomes a proposal or a proposal is not stored. Nothing marks such a pair (it is
not decisive, or there is no row); `app:encounter:evaluate-matcher` counts these allocations ("worse with the bonus"). The match review and the validation score candidates as in pass 2, with the bonus of
the active snapshot, and a pair confirmed there without a stored row records snapshot and level, never decisive.

Example (as in the unit tests): arrival 10:00, admission 10:07, male vs female, same age, ambulance:
4 − 2 + 2 + 1 = 5 points, 32 / 48 = 0.6667, a proposal of pass 1. With PZC 711 and the diagram "Urologisches
Problem" (seed area "Urologie", +3) pass 2 gives 8 points, 256 / 272 = 0.9412: an automatic link, decisive
(0.6667 < 0.90). An ambulance with IVENA "with physician" and everything else matching scores
4 + 2 + 2 + 1 + 1 = 10 points, 1024 / 1040 = 0.9846 (`1.1.0`: 8 points, 0.9412).

Example: arrival 10:00, ED admission 10:07, same sex and age, ambulance: 4 + 2 + 2 + 1 = 9 points; alone
it scores 512 / (16 + 512) = 0.9697 and becomes `auto_accepted`. A second visit at 10:06 with the same
values gives both 512 / 1040 = 0.4923: nothing is proposed. Admission at 10:20 instead ("near"):
3 + 2 + 2 + 1 = 8 points, 256 / 272 = 0.9412, `auto_accepted` (v1: 7 points, 0.8889, a proposal). Admission
at 10:07 but male vs female: 4 − 2 + 2 + 1 = 5 points, 32 / 48 = 0.6667, a proposal (v1: 3 points, 0.3333,
nothing). Times are compared on the local wall clock at minute precision.

**Matcher versions** (ADR 030): `1.0.0` (points by judgement), `1.1.0`
(`time.near` +3, `sex.different` −2, after the validation of one pilot hospital, October 2026) and `1.2.0`
(`physician.rendezvous` +1, plausibility bonus in pass 2, cap +3) are all defined; `1.2.0` is the production
matcher. Stored pairs keep the version that scored them. A new version
rescores nothing in bulk: automatic links and manual decisions stay with their old version, open proposals
are recomputed with the new version by the next run over their allocations (after an import, or
`app:encounter:match`), and unlinked cases are matched with it on that run. Activating or retiring a
plausibility snapshot follows the same rule (ADR 033): links and decisions stay, open proposals change on the next
run over their allocations.

**Rollout of `1.2.0`**: the migration adds the three plausibility columns; existing pairs get no snapshot, no level
and `plausibility_decisive` false. Nothing is rescored on deploy. Until an administrator or reviewer activates a
snapshot, `1.2.0` differs from `1.1.0` only by the rendezvous rule. To apply `1.2.0` and a newly activated snapshot to
open proposals and unlinked cases of past months, run `app:encounter:match --hospital=<id> --from=… --to=…` per
hospital; check the effect first with `app:encounter:evaluate-matcher --snapshot=<id>`.

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
its allocations (ADR 030, as ADR 029 does for encounter imports). The reprocessed import gets new
allocation ids; the next run links them again automatically, manual decisions have to be made again.
The allocation import pages do not warn about it (Import does not depend on Encounter); the import docs
do ([import pipeline](../import/import-pipeline.md#reprocessing), [batch requeue](../import/batch-requeue.md#reimport-cleanup)).

**Proposals across runs**: open proposals are not kept; each run recomputes those of its allocations. A
pair proposed in pass 1 keeps its pass-1 score when pass 2 sees it again, and proposals touching a side
that is linked at the end of the run are removed.

**Manual run**: `php bin/console app:encounter:match --hospital=<id> --from=YYYY-MM-DD [--to=YYYY-MM-DD]`
prints counts only (including the new links and proposals the plausibility bonus decided). Synthetic encounters
loaded by the fixtures are not matched until it runs.

**Configuration** (`app.encounter.matching`): `auto_accept_threshold` (0.90), `proposal_threshold`
(0.50), `pass1` and `pass2` with `minutes_before`, `minutes_after`, `max_age_difference` (20/45/2 and
30/90/5). A change applies from the next run; existing links stay.

**Read model**: `EncounterKeyChangeSummaryQuery` counts links and decisions per hospital;
`EncounterOverviewQuery` and `HospitalCourseQuery` read them for the pages of #1055; `MatchReviewQuery` reads
the review (below).

## Plausibility table

Decided in [ADR 033](../../02-architecture/decisions/033-learning-plausibility-table.md) and the
[ADR 030 amendment](../../02-architecture/decisions/030-encounter-allocation-matching.md#amendment-matcher-120)
(#1136); implementation in #1153 (table and seed), #1154 (matcher `1.2.0`), #1155 (learning job) and #1156
(analyses). The table, the seed and the review pages exist since #1153 (see [Implemented](#implemented-in-1153));
matcher `1.2.0` reads the active snapshot in pass 2 since #1154 ([matching](#matching)).

- **Cells:** exact PZC of the allocation × canonical MTS diagram of the visit's initial triage → bonus 0 … +3.
  When the exact cell has too few observations (minimum, 10 as starting value), the cell of the PZC's curated
  problem area × diagram applies. No negative points. No PZC, no diagram or no cell: 0. A finer level by MTS
  indicator is reserved; the learning report shows it, the matcher does not read it.
- **One global table** for all hospitals, read from the **active snapshot**; without one the bonus is 0.
- **Seed:** problem areas (curated groups of PZC codes, not the PZC prefix) with the MTS diagrams plausible
  with them and points, reviewed in the app.
- **Learning:** a job builds a draft snapshot from pairs confirmed or validated by people and automatic links
  whose decision did not depend on the bonus; other candidates of the same allocations are the counter-cases.
  Laplace smoothing, shrinkage towards the seed, minimum counts, cap +3; the report replays the draft
  cross-fitted by period.
- **Snapshots:** `draft` → `active` → `retired`; activated by an administrator or a holder of
  `ROLE_ENCOUNTER_CATALOG_REVIEWER` ([permission model](../../02-architecture/permission-model.md#encounter-grants)),
  audited. Active and retired snapshots are immutable.
- **Matcher:** pass 2 only; the pair records the bonus (`plausibility` contribution), the snapshot id and
  whether the bonus was decisive. A new active snapshot changes no link or manual decision; open proposals
  are recomputed by the next run.
- **Bias:** analyses comparing PZC with the MTS diagram exclude links decided by the bonus or report them
  apart ([PZC ↔ MTS comparisons](#pzc--mts-comparisons-linkage-bias)); the match validation reports these
  links as a group of their own ([match validation](#match-validation)).
- **Privacy:** reviewers never see case counts across hospitals. The review and activation pages show points,
  source (curated, learned) and the change against the active snapshot; replay counts and rates cover only
  the hospitals on which the viewer holds the encounter grant `REVIEW` (administrators: all).

### Implemented in #1153

- **Tables** of the Encounter context: `encounter_plausibility_area` (code derived once from the German name,
  German and English name), `encounter_plausibility_area_code` (PZC → area, unique per PZC, so each PZC belongs to
  at most one area) and `encounter_plausibility_area_diagram` (curated rule: area × MTS diagram with points 0 … +3)
  hold the editable seed. `encounter_plausibility_snapshot` (status `draft` / `active` / `retired`, origin `seed` /
  `learned`, activation and retirement time; the learning job adds period, eligible pairs and report in #1155),
  `encounter_plausibility_snapshot_code` (the PZC → area membership copied into the snapshot, so editing an
  area never changes an active snapshot) and `encounter_plausibility_cell` (exact cell: PZC × canonical diagram code;
  fallback cell: area code × diagram; reserved nullable indicator code on exact cells; points 0 … +3; source
  `curated` / `learned`; positives and negatives as counts for the learning job). Check constraints and a partial
  unique index keep points in range, each cell keyed by PZC or area, and at most one snapshot active.
- **Lookup** (`PlausibilityLookup`, `PlausibilityTable`): the stored diagram code resolves to its canonical code
  ([MTS glossary](#mts-glossary)); then the exact cell if it has at least `app.encounter.plausibility.min_observations`
  observations (positives + negatives, default 10), otherwise the fallback cell of the PZC's area, otherwise 0. No
  PZC, no diagram or no active snapshot: 0. Indicator cells are not read. Points are capped at +3.
- **Seed.** The migration `Version20261113235145` carries the a-priori draft of #1136 (no real data, no counts) and
  builds one snapshot with status `draft` and origin `seed` from it: 24 problem areas with 114 PZC codes (list
  1.3.11) and 61 rules at +3, giving 61 fallback cells and 373 exact cells with counts 0. No snapshot is active, so
  the bonus is 0 until a person activates one. Codes named "sonstige" as a catch-all, transfers (KST),
  resuscitation, paediatrics, obstetrics and gynaecology and the other specialties belong to no area; unspecific
  diagrams (`unwohlsein_bei_erwachsenen`, `hautausschlaege` and others) have no rule. The seed needs the clinical
  review in the app before the first activation.
- **Review pages** as the tool "Plausibility table" under `/tools/encounter-plausibility` (routes
  `app_tools_encounter_plausibility*`, [ADR 031](../../02-architecture/decisions/031-tools-section-and-tool-registry.md)),
  listed by `EncounterToolProvider` with the beta badge only for users with `ROLE_ENCOUNTER_CATALOG_REVIEWER` and
  `ROLE_ENCOUNTER_BETA`; the controllers check both roles (`^/tools` itself only needs `ROLE_USER`). Administrators
  also reach it from Admin → Data → MTS catalog:
  - Problem areas: list with PZC codes and plausible diagrams, create, edit (names, PZC codes and ranges such as
    `711-718`, points per active diagram), delete. A PZC of another area is refused. Every save rebuilds the newest
    `seed` draft from all areas (or creates one), in the same transaction (`PlausibilityCuration`,
    `PlausibilitySnapshotBuilder`, same rule as the migration); audit intents `encounter.plausibility.area_*`.
  - Snapshots: list and review page per snapshot with, per problem area, its PZC codes and the points and source of
    its fallback cells, and, when another snapshot is active, the points before and the changed bonus per PZC ×
    diagram (lookup rule, so a learned exact value shows only once a cell has the minimum observations). Activate a
    draft (retires the active one), retire the active one (bonus 0 everywhere), delete a draft
    (`PlausibilitySnapshots`; audit intents `encounter.plausibility.snapshot_*`). Active and retired snapshots are
    never changed or deleted.
  - No page shows counts, totals or rates of the table. The learning report with replay figures per hospital follows in #1155.
- **Catalog merge.** A provisional diagram named by a curated rule cannot be merged
  (`MtsCatalogException::MERGE_HAS_PLAUSIBILITY_RULES`, [MTS glossary](#mts-glossary)).
- **Matcher** (#1154): `1.2.0` reads the active snapshot in pass 2 and stores the bonus, snapshot, level and
  decisive flag per pair ([matching](#matching)).
- **Not yet:** the learning job and its report (#1155), analyses (#1156), export and import of the reviewed table to
  production (follow-up of ADR 032).

Measured on the pilot hospital (October 2026, 496 allocations): automatic links 421 (84.9 %) with `1.1.0`,
453 (91.3 %) with an a-priori bonus of +3 in pass 2, 89.5 % with a table by exact PZC with fallback learned
from half a month (cross-fitted by day);
none linked to another visit than the reference.

## Match review

Implemented in #1054. Pages under `/tools/encounter-review` (Tools card "Encounter match review"): beta role + encounter
grant `REVIEW` on the hospital, or `ROLE_ADMIN`. With one reviewable hospital the entry opens it directly;
administrators see every hospital with encounter data. Another hospital's page answers 403, an allocation
of another hospital under one's own hospital 404.

**Period**: `from`/`to` (whole days, at most 92); by default the calendar month of the hospital's latest
visit. Allocations count by expected IVENA arrival, visits by matching anchor (ED admission, else first
assessment contact), both on the stored local wall clock.

**Layout** follows the indication review ([indication-normalization.md](../allocation/indication-normalization.md#review-worklist)):

- **Worklist** `/tools/encounter-review/{hospital}`: one DataTable with three tabs and their counts (below), the
  period and the tab's filter in a filter drawer, sortable by arrival, agreement and time difference
  ("to review" and "done"), a "Review" action per row and mobile cards. "Start review" opens the first
  allocation of the tab.
- **Allocation page**: the comparison on the left, the candidate visits stacked in a narrower column on the
  right, actions at the bottom (see below).
  "Leave open" skips to the next allocation; confirm, change, reject and swaps continue with the next one
  too (`MatchReviewNavigator`, same order as the list); at the end of the tab the list opens again.
  Tab, filter, period and sort order travel in the query string (`ReviewContext`).
- **Overview** `/tools/encounter-review/{hospital}/overview`: the counts with denominators below, each linked to its
  tab and filter.
- **Unlinked visits** `/tools/encounter-review/{hospital}/visits`: visits without link, optionally by arrival route
  (`route=ems|self|unknown`), reached from the overview's counts. Nothing is decided there; a visit with an
  open proposal links to the review of that allocation.

| Tab (`segment`) | Rows | Filter |
|---|---|---|
| To review (`open`) | one per allocation that needs a person: no link and an open proposal (with the best one and the number of proposals), or a swap proposal (with its automatic link); conflict and swap shown as badges (`OpenReviewItems`) | `kind=conflicts` or `kind=swaps` |
| Without proposal (`no_proposal`) | one per allocation without link and without proposal | – |
| Done (`done`) | one per decided pair: automatic or confirmed link, rejected pair | `status=auto_accepted`, `confirmed` or `rejected` |

**Overview with denominators** (`MatchReviewQuery::summary()`):

| Count | Population and rule |
|---|---|
| Allocations in the period | denominator of the next four rows; each allocation counts once |
| Linked automatically | has an `auto_accepted` row |
| Linked by confirmation | has a `confirmed` row |
| Open proposal, not linked | no effective link, at least one `proposed` row; *of which in conflict*: one of them is an open conflict |
| Not linked, no proposal | neither |
| Rejected pairs | `rejected` rows of the period's allocations (pairs, not allocations) |
| Swap proposals | see below |
| Visits by arrival route | per route: total, linked (effective link), unlinked; a self-presenter without link is not an error |

A proposal is in **open conflict** while it carries `conflict` and another allocation's conflict proposal
for the same visit is still open; once one side is decided, the rest counts as an ordinary proposal.

**Allocation page** (`/tools/encounter-review/{hospital}/allocations/{allocation}`). A swap proposal, if any, is
shown above. The candidate column lists every candidate visit of the **review window**
(`MatchingSettings::reviewWindow()`: widest bounds of all passes, every arrival route, −30 … +90 min, age
±5 by default) as a choice, with the visit's anchor time, time difference, agreement among all candidates of
that window, the stored score of the pass that found the pair, and its status or link to another allocation.
The comparison column sets the chosen candidate against the allocation: expected IVENA arrival and ED admission (or the
marked substitute, first assessment contact) side by side with the agreement between them, then per feature
both compared values and the contribution (for, against, neutral or missing) and the total points, and other
allocations proposed for the same visit. The total is coloured by what it would mean for a sole candidate
(`MatchPlanner::agreementAlone()`): "fits well" (green) reaches the auto-accept threshold (8 points and more
by default), "fits partly" (yellow) the proposal threshold (4–7), "hardly fits" (red) neither. It rates the
features only; the agreement beside it also accounts for competing candidates. A section "Extended information (MTS, IVENA)" adds, for
unclear cases, IVENA urgency and normalized indication beside MTS category, diagram and indicator of the visit
(`MatchReviewQuery::extendedInfo()`). They are no matching features (ADR 030) and the page says so: later
analyses compare exactly these between IVENA and the ED. The only exception is the plausibility row of the
comparison (since `1.2.0`, "PZC × MTS diagram"): the PZC and the visit's diagram with the bonus of the active
snapshot, the way pass 2 scores the pair. It is collapsed for a clear candidate and opens by
itself when the candidate's agreement among all candidates stays below the auto-accept threshold. Decisions
made with this section open are deliberately not marked. The card header names the current link and its origin (automatic:
score and pass). The linked visit is chosen first, else the open proposal with the highest stored score, else
the candidate with the most points. The Stimulus controller `encounter-match-review` switches the comparison
and enables only the decisions that apply to the chosen visit; the server checks again. The score is labelled
"agreement" ("Übereinstimmung") and explained as a ranking, not a probability (ADR 030).

**Decisions** (form `MatchReviewDecisionType` on the allocation page; `MatchDecider`, one transaction under
the matcher's per-hospital lock):

| Action | Effect |
|---|---|
| Confirm | the pair becomes `confirmed` (created from its review-window scoring if the matcher had not stored it; `pass` empty) |
| Change | confirming another candidate while the allocation is linked: the old pair becomes `rejected`, the new one `confirmed` |
| Reject | a stored pair (proposal, automatic or confirmed link) becomes `rejected`; it is never proposed again; a candidate without stored pair cannot be rejected |
| Leave open | writes nothing; continues with the next allocation of the segment |

A visit linked to another allocation cannot be confirmed (one-to-one); the page says so and points to
swaps. After a link is made, open proposals touching either side are removed, as at the end of a run,
which resolves a conflict. Every decision sets `decided_by`/`decided_at` and is audited with intent
`encounter.match.decided` (`decision` confirm/change/reject, previous status, replaced visit); manual
decisions are sticky for later runs. A rejected link frees both sides; the review does not start a run,
the next triggered run fills the gap.

**Swap proposals** (`SwapPlanner`, pure; `MatchSwapFinder` loads the period with a margin). Only
automatic links are considered; confirmed links stay as a person decided them. For an automatic link A–W
and an unlinked visit V in A's review window (A–V not rejected):

1. *Chain*: an unlinked allocation B has W in its review window (B–W not rejected): link A–V and B–W, one
   link more. Among several B the one with most points.
2. *Replacement*: otherwise, if A–V has more points than A–W: link A–V, W becomes unlinked.

Every new pair must reach the proposal threshold as the only candidate (2^points / (2^points + 2^4) ≥
0.50, i.e. at least 4 points). Per allocation the best proposal is kept (chain first, then points gain);
overlapping proposals are dropped. Applying a swap is a person's decision: A–W becomes `rejected`, the new
pairs `confirmed`; audited with intent `encounter.match.swapped`. A swap that no longer matches the
current links is refused.

Example (tested in `MatchReviewControllerTest`): A at 10:00 is linked automatically to W at 10:10
(9 points). B at 10:05, age 72, would score 7 with W; the self-presenter V at 10:05 scores 6 with A. The
chain A–V + B–W gives one link more and 6 + 7 − 9 = 4 points more.

## Match validation

Implemented in #1057 (ADR 030, "Quality check"). Pages under `/tools/encounter-review/{hospital}/validation`,
linked from the review overview; access as the review (beta role + encounter grant `REVIEW`, or
`ROLE_ADMIN`).

**Sample** (`MatchValidator::draw()`, populations from `ValidationSampleQuery`): for a period (whole days,
at most 92, period rules as in the review) up to *n* cases per cell (default 20,
`app.encounter.validation.sample_size`):

| Stratum | Population | Cells |
|---|---|---|
| Automatic link | `auto_accepted` pairs of the period's allocations | per score band |
| Proposal | `proposed` pairs of the period's allocations | per score band |
| Allocation without link | no effective link and no open proposal | one |
| Visit without link | EMS or unknown arrival route, no effective link and no open proposal; self-presenters are left out, their missing link is expected | one |

Score bands come from `app.encounter.validation.score_bands` (lower bounds, default 0.50, 0.70, 0.90,
0.99; the last band includes 1.0, labels like `0.90–0.99`). Within a cell the cases are ordered by
`md5(seed, stratum, id)` and the first *n* are drawn, so the same seed on the same data draws the same
sample. The sample stores period, *n*, seed, score bands, the current matcher version and the population
of every cell; each item keeps the pair as sampled (status, score, band, points, pass, matcher version, and since
#1156 the plausibility snapshot of its pass-2 bonus and whether the bonus was decisive; for items drawn before, the
flag is empty: "not recorded").
Drawing changes no link: automatic links stay effective while they are validated, and their origin stays
visible on the item. Tables `encounter_validation_sample` and `encounter_validation_item`; samples go with
their hospital, items with their sample, allocation or visit (`ON DELETE CASCADE`).

**Verdicts** (item page: one item, one question, one decision; comparisons as in the review):

| Stratum | Page | Decisions |
|---|---|---|
| Automatic link, proposal | the allocation compared with the sampled visit; below, collapsed, "Alternatives (n)": the other candidate visits of the review window by points (time difference, sex/age/route marks, points and agreement, a link elsewhere), each expandable to the full comparison | correct · wrong, no matching visit · wrong, another visit fits ("link instead" on an alternative) · unclear |
| Allocation without link | every candidate visit of the review window as an option | "link this visit" (partner missed) · no partner · unclear |
| Visit without link | every allocation whose review window contains the visit as an option, compared as in that allocation's review | "link this allocation" (partner missed) · no partner · unclear |

A verdict and its link decisions are written in one transaction under one audit intent
(`encounter.validation.verdict_recorded`; the review decider joins it and lists its decisions as
`decisions` in the intent metadata), through the review's decider (same lock, sticky for later runs):

- "Correct" confirms an automatic link or open proposal (it becomes `confirmed`), unless a person decided
  the pair already, a rerun dropped it, or a link elsewhere stands in the way; then the verdict alone.
- "Wrong" rejects the pair unless it is already rejected or no longer stored; a rejected automatic link
  stops being effective.
- "Link instead" / "link this visit" / "link this allocation" (`ValidationLinkPlanner`) confirms the chosen
  pair; for a pair the sampled pair is rejected first, so for an automatic link this acts like a review
  "change". A link is never moved silently: if the chosen visit is linked to another allocation, or the
  allocation is linked to another visit, only the review's swap proposal that makes the pair applies
  ("link with swap"); otherwise the row points to that allocation's review and a request is refused with
  nothing written.
- A pair item judged "wrong, another visit fits" keeps that visit (`replacement_encounter_id`, set null if
  the visit is deleted); the item's sampled state stays as drawn.
- Changing a verdict later undoes no link (links are manual decisions now; change them in the review); the
  replacement follows the latest verdict.

Every item page also offers "skip": a link to the next item without verdict after this one (wrapping
around; the sample page when no other item is open) that records nothing and leaves the item open.
"Unclear" is a verdict (the reviewer looked and cannot decide; it counts as judged and is left out of the
share); "skip" is not.

Every verdict sets `decided_by`/`decided_at`; drawing is audited with `encounter.validation.sample_drawn`.

**Report** (`ValidationReport`, pure): per stratum and band the population when drawn, sampled, open,
correct or missed, wrong or no partner (pairs: split into "another visit fits" and "no partner"; the
split shows whether errors are mix-ups between candidates, a matter of the time and age weights, or false
links, a matter of the thresholds), unclear, and the observed share correct (pairs) or missed
(unlinked cases) among the decisive verdicts with its Wilson 95 % interval. Per pair stratum an estimate
weighted by the band populations, Σ N_b × share_b / Σ N_b, given only when every band with cases has a
decisive verdict. The overview adds up the verdicts of all samples per matcher version, without population
and weighting, because samples can overlap.

**By plausibility snapshot** (#1156, `PlausibilityValidationReport`, pure; ADR 033): the overview also groups the
verdicts on sampled pairs (automatic links, proposals) by the matcher version of the pair as sampled, its plausibility
snapshot and whether the bonus was decisive. Groups: "not recorded" (drawn before #1156; nothing is guessed), "no
snapshot" (pass 1, versions before `1.2.0`, runs without an active snapshot), a snapshot whose bonus did not decide
the pair, and a snapshot whose bonus decided it (highlighted).
Each group shows sampled, open, correct, wrong (of which another visit fits), unclear and the share correct with its
Wilson interval, without score bands, population or weighting (samples can overlap). A share correct of the
bonus-decided pairs clearly below that of the other pairs of the same snapshot means the bonus causes wrong links;
the drawn sample is stratified by score band, not by bonus, so with few decisive pairs the interval stays wide. The
item page does not show the flag, so the verdict is not influenced by it. Example (tested in
`PlausibilityValidationReportTest`): decisive automatic links with 1 correct, 1 wrong (another visit fits), 1 unclear
and 1 open give 1 / 2 = 50 %; non-decisive ones of the same snapshot with 3 correct and 1 wrong 3 / 4 = 75 %.

Example (tested in `ValidationReportTest`): automatic links with 100 cases in band 0.99–1.00 (3 correct,
1 wrong: 0.75) and 20 in 0.90–0.99 (2 correct: 1.0) give (100 × 0.75 + 20 × 1.0) / 120 = 0.79.

**Calibration**: the shares are observations that calibrate the score; no calibration is stored yet, so the
score stays "agreement" and is never shown as a hit probability (ADR 030). Real numbers come from the pilot
hospital's data, not from synthetic data.

**Tuning** (`php bin/console app:encounter:evaluate-matcher --hospital=<id> --from=YYYY-MM-DD [--to=…]`):
replays the planner on the stored allocations and visits of the period as a clean run (no existing links or
decisions) and writes nothing. `--weights=<version>` picks the matcher version the replay starts from
(default: the production matcher; `1.0.0` reproduces v1). Options change the thresholds (`--auto-accept`,
`--proposal`), the "no match" weight (`--no-match-points`) and single points (`--points=time.near=1`,
repeatable; keys as in `MatchWeights`). `--snapshot=<id>` replays the plausibility bonus of pass 2 with any snapshot,
also a draft or a retired one; `--snapshot=none` without bonus; the default is the active snapshot (matchers before
`1.2.0` have no bonus whatever the snapshot). A table compares the allocations linked automatically, with a proposal
and with neither, with and without the bonus, counts the automatic links and proposals the bonus decided, and counts
the allocations whose outcome is worse with the bonus (automatic → proposal, automatic → none, proposal → none). The
command prints counts only, never a pair. It reports pairs, correct, wrong and unknown per status and band against the validation
verdicts of the hospital, or with `--truth=<truth.json>` (from `app:generate:encounter-files`) against the
synthetic truth, then also the recall: true pairs linked automatically, and linked or proposed. Introducing
a new matcher version stays a code change: a new `MatchWeights` set, `MatchPlanner::VERSION` and the docs.

## Statistics in the pilot

Descriptive only, for the own hospital: coverage, encounters by arrival route and link status, MTS
categories and diagrams, process times recomputed from timestamps (median, IQR), disposition and
care level. No cross-hospital aggregates.

### PZC ↔ MTS comparisons (linkage bias)

Decided in [ADR 033](../../02-architecture/decisions/033-learning-plausibility-table.md#bias-in-pzc--mts-analyses)
and the [ADR 030 amendment](../../02-architecture/decisions/030-encounter-allocation-matching.md#amendment-matcher-120)
(#1156). Since matcher `1.2.0`, pass 2 adds a bonus when the allocation's PZC and the visit's MTS diagram are
plausible together. A link the bonus made agrees in PZC and diagram because it was chosen for that agreement, so
linked data shows more agreement between IVENA and the ED triage than the true pairs have. Pass-1 links (EMS
arrivals) never use the diagram.

**Where the flag comes from:** `encounter_match.plausibility_decisive`, written by the matcher in pass 2: the pair
would not have reached its status (automatic link ≥ 0.90, proposal ≥ 0.50) with the bonus removed from every
candidate of the allocation in that pass ([matching](#matching)). Pass-1 pairs, pairs of matcher versions before
`1.2.0`, runs without an active snapshot and pairs created in the review are never decisive. A decision keeps the
flag: an automatic link confirmed later still carries it. `plausibility_snapshot_id` names the snapshot that scored
the pair.

**Today:** no figure compares an IVENA attribute (PZC, indication, urgency) with the visit's MTS triage. The
[hospital overview](#hospital-overview) counts MTS categories and diagrams over all visits, linked or not; the
[case card](#case-card-hospital-course) and the [match review](#match-review) show single pairs. Nothing is excluded
from them, and their numbers do not change with #1156. The overview's IVENA link tab shows how many automatic links
the bonus decided ("of which decided by the plausibility bonus"), so the size of the affected group is visible.

**Rule for any analysis that compares PZC, indication or urgency with the MTS diagram, indicator or category on
linked data** (the indicator and category follow from the diagram, so the selection reaches them too; decided in
the review of #1156, 2026-11-14):

| Aspect | Rule |
|---|---|
| Population | Effective links (`auto_accepted`, `confirmed`) of the hospital and period. Automatic links with `plausibility_decisive` are excluded. Confirmed links stay in, whatever their flag: a person decided them (ADR 033, "manual decisions are unaffected"). |
| Separate view | Instead of leaving them out, the excluded links may be shown as a group of their own beside the rest, never added to it. |
| Count | The figure states how many links it excluded (or shows in the separate group) and their share of the effective links, with the small-count rule of the page (1–4 shown as `< 5`). |
| Numerator and denominator | Both come from the remaining links; agreement shares never use the excluded links as denominator. |
| Missing or unknown values | Pairs without PZC or without diagram get bonus 0 and are never decisive, so they are not excluded; they count as "not documented" on their side as usual. |
| Period | Unchanged: the analysis' own period rule; the flag belongs to the pair, not to a period. |
| Comparison groups | Only within one hospital, as every encounter figure in the pilot; the bonus-decided group is a comparison group only in the separate view. |

The learning job (#1155) leaves the same links out of its training data, so the table does not learn from links it
decided (ADR 033). Whether the bonus causes wrong links is judged in the
[match validation](#match-validation) ("by plausibility snapshot").

### Hospital overview

Implemented in #1055. Statistics page `/statistics/encounter-analytics` ("Encounter data (beta)" in the
statistics sub-navigation, title "Analysis" / "Auswertung"; `EncounterAnalyticsController` in `Statistics/UI`),
also linked from My Hospitals ("Encounter data" on each card whose hospital is in `AggregateHospitalScope`). It
uses the statistics building blocks: the scope and period picker (`Statistics:AnalysisContext`) in
`AnalysisContextScopeMode::AssignedHospital`, the statistics filter (`StatisticsFilterValueResolver`) and
period window (`StatisticsPeriodResolver`), and the statistics page layout. The picker offers exactly the
hospitals of `AggregateHospitalScope` (`EncounterAttribute::VIEW_AGGREGATES`: beta role + any encounter
grant + hospital permission `STATISTICS`; administrators every hospital with stored visits) and no "my
hospitals" aggregate; any other scope in the URL (public, state, cohort, another hospital) is redirected
to `scope=hospital&hospital=<id>` of an allowed hospital. Users without such a hospital see a notice;
without the beta role the page is forbidden. Content tabs (`?tab=`): overview (four KPI cards: visits, admitted, median arrival → physician, median length of stay in the ED; left two thirds: visits over time by arrival route, data coverage of the period and of all stored data,
disposition, care level; right third: arrival route, MTS categories, top 10 presentations), process times (box plots and table), IVENA link (route × link) and distributions (full
tables). The page carries no explanatory texts; the definitions are documented here. Every figure belongs to exactly one hospital; the page never adds up
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
| Arrival route × IVENA link | Visits per route (`ems`, `self`, `unknown` from `arrival_route`) and link: linked automatically (`auto_accepted`), linked after confirmation (`confirmed`), linked in total (both), not linked | Visits of the row (route), last row all visits | Route `unknown` = `arrival_mode` `other` or empty. Not linked = no effective link: no pair, only an open proposal, or a rejected pair. Automatic links count as linked; their origin is a column of its own. Column "of which decided by the plausibility bonus" (#1156): visits whose automatic link has `plausibility_decisive`, as a share of the row's automatic links (confirmed links never count); small counts as everywhere. It only shows the share; the other columns are unchanged. |
| MTS categories | Visits per initial `mts_category`, in MTS order red … blue | All visits of the period | "Not documented" row for visits without category. Retriage not counted. |
| Top presentations (MTS diagrams) | The 10 most frequent initial `mts_diagram` codes (ties by code), then "other diagrams" (sum of the rest) | All visits of the period | "Not documented" row. Names from the MTS catalog (`MtsNames`); unknown codes shown as code. |
| Process times | Median, Q1 and Q3 of the valid durations (PostgreSQL `percentile_cont`, linear interpolation), counted visits | – | Per interval: "time missing" (one of the two timestamps empty), "negative" (end before start), "over 24 h" (more than `ProcessInterval::MAX_PLAUSIBLE_MINUTES` = 1440). They are excluded from median and IQR and shown as counts. Median and IQR need at least 5 valid durations. |
| KPIs (overview tab, four cards) | visits; admitted to this hospital (disposition `admitted`) of all visits; median arrival → first physician and median arrival → ED discharge, each with IQR | as stated | a suppressed count shows `< 5` instead of a share; medians need 5 valid durations |
| Visits over time | visits per bucket and arrival route with the statistics time axis (`TimeSeriesGrainResolver`, `TimeSeriesAxisFiller`, [statistics-filter-and-scope.md](../statistics/statistics-filter-and-scope.md#periods)): days for a month period, months otherwise; empty buckets as 0; the current month is not drawn until complete; open periods start at the first month with visits | – | buckets with 1–4 visits per route are gaps in the chart |
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

- **Catalog in the database.** Implemented in #1082 as decided in
  [ADR 032](../../02-architecture/decisions/032-mts-catalog-as-maintained-data.md). Tables of the Encounter
  context: `encounter_mts_diagram` and `encounter_mts_indicator` (stable code, German name, optional English
  name, source `orbis_export` / `mts_edition` / `manual` / `first_seen`, status `curated` / `provisional` /
  `merged`, first seen), `encounter_mts_alias` (kind, alias code, label, target code) and
  `encounter_mts_diagram_indicator` (assignments as definitions). The migration seeds the content of
  `mts-de.json` (40 diagrams, 70 indicators, `curated`, `orbis_export`) and the English diagram names that were
  translations before; the values are carried in the migration. `encounter` keeps storing plain codes without
  a foreign key, and the import keeps storing the source code unchanged, so every stored code stays valid.
  Development fixtures do not purge these tables.
- **Resolution.** `MtsCatalog` reads the tables (`MtsCatalogQuery`) and resolves a code in one step: an active
  entry (`curated` or `provisional`) to itself, a merged entry or other alias code to its active target; a code
  that resolves to nothing is shown as a code. `diagrams()` and `indicators()` list active entries only. The
  glossary sums the counts of a code and its aliases (`codesResolvingTo()`), and a diagram page called with an
  alias code shows its target. `MtsCatalog::code()` derives codes exactly as the tool's `catalogCode()`.
- **Maintenance.** Administrators (`ROLE_ADMIN`) curate the catalog in the back office, Admin → Data → MTS
  catalog (EasyAdmin pages of the Encounter context under `/admin/encounter/mts-*`), through
  `MtsCatalogCuration`; every change is audited (`encounter.mts_catalog.*` intents):
  - Diagrams and indicators: German and English name, source, status `curated` / `provisional`. The code is
    derived from the German name on creation and never changes; entries are not deleted.
  - Creating an entry or alias whose code already exists is refused on the form: as a duplicate when the
    label is the same (ignoring case), as a **code collision** naming the existing label when it differs.
    Codes are unique per kind across entries and aliases.
  - Aliases: kind and label are set on creation (the alias code is derived from the label), the target is an
    active entry of the same kind and can be changed. The alias of a merge cannot be edited or deleted.
  - Merge (detail page of a provisional entry): it becomes `merged` and an alias from its code to the chosen
    active entry is created, in one transaction. Refused while aliases point to the entry, it has
    assignments or curated rules of the [plausibility table](#plausibility-table) name it. Undo removes the alias and makes the entry `provisional` again. Encounter rows are never
    touched.
  - Diagram–indicator assignments between active entries, created and removed.
- **Provisional entries (#1157).** The import creates an entry for every MTS code of a stored visit (initial
  triage and retriage, diagram and indicator) that resolves to nothing: no entry of the kind with that code, in
  any status, and no alias code. Status `provisional`, source `first_seen`, the code as German name (the
  exchange file carries codes, not labels), `first_seen_at` the time of the import; which hospital sent it is
  not stored. Known visits, rejected rows and rejected files create nothing; a failed import rolls the entries
  back with its commit. `MtsCatalogFirstSeenWriter` inserts with `ON CONFLICT (code) DO NOTHING` in code order,
  so parallel imports of the same code create one entry and never fail, and writes one audit entry per created
  row (`create`, intent `encounter.mts_catalog.first_seen_created` with kind, code and import id). They appear in the
  back office lists (Admin → Data → MTS catalog) with status `provisional` and their first-seen time, to name,
  merge or curate.
- **Backfill.** `php bin/console app:encounter:mts-catalog:backfill-first-seen` creates the same entries for
  codes already stored in `encounter` (first seen = creation time of the oldest encounter with the code);
  safe to run again. Run it once when releasing #1157.
- **Catalog for the tool (#1157).** `GET /data-prep/mts-catalog.json` (public, read only) serves the curated
  entries and the aliases to them (provisional entries stay off the public endpoint until a person curates them); the tool keeps `src/Encounter/Application/Catalog/mts-de.json` as offline
  fallback and writes the code of the source label, also for an alias
  ([data-preparation-tool.md](data-preparation-tool.md#mts-catalog)). Export and import of the curated catalog
  to production is a later follow-up.
- **Categories:** `MtsCategory` with the target time from the first assessment contact to the first
  physician contact (`physicianContactTargetMinutes()`: red 0, orange 10, yellow 30, green 90, blue
  120 minutes), colour and name in EN and DE.
- **Names only.** No discriminator texts and no flowcharts (copyright). The German name is the source.
  English: an entry shows its English name from the catalog when one is set, with the German name below.
  The seed has the well-known English chart names of 39 of 40 diagrams (`Generelle Indikatoren` keeps its
  German name) and no English indicator names, as there is no generally known English name for most of them
  and a translation of our own would invent terms. Administrators can add English names in the back office.
- **Visibility.** Definitions are shown to every participant (`^/explore` rule). Counts are shown only
  to users with `VIEW_CASES` or `VIEW_AGGREGATES` on a hospital (`EncounterVoter`), summed over exactly
  those hospitals (`EncounterCountScopeResolver`); administrators with the beta role count all
  hospitals, as ADR 028 gives them every action. Other hospitals are never counted.
- **What is counted.** Encounters by the initial triage (`mts_category`, `mts_diagram`,
  `mts_indicator`), all key generations, no period filter; retriage values are not counted. Population:
  every stored encounter of the hospitals in scope; encounters without a value are not counted for that
  code. A diagram page counts all encounters with the diagram (total) and per indicator; encounters of
  the diagram without an indicator are one extra row.
- **Small cells.** Counts from 1 to 4 are shown as `< 5` (`MtsCount`, threshold
  `CatalogPrivacyPolicy::MIN_ALLOCATIONS`, ADR 012); 0 is shown as a dash.
- **Diagram page.** Lists the indicators assigned to the diagram in the catalog (diagram–indicator
  assignments between active entries, definitions, #1157) to every participant, without counts. Users with
  encounter access also see the indicators seen with the diagram in the encounters they may see, all rows
  with counts; seen indicators that are not assigned are marked "not assigned in the catalog" when the diagram
  has assignments, and seen codes that resolve to nothing are marked "not in the catalog". Filter on name and
  code (`?q=`). Without assignments and without encounter access the page explains that the observed list
  needs encounter access: listing all catalog indicators would suggest pairs that the MTS does not have, and
  pairs from other hospitals' data are not shared. Counts and small-cell suppression are unchanged.

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

## Tools section

Since #1128 the interactive encounter workflows are tools ([ADR 031](../../02-architecture/decisions/031-tools-section-and-tool-registry.md)),
listed by `EncounterToolProvider` for `ROLE_ENCOUNTER_BETA` with a beta badge:

| Tool | Route | Path |
|---|---|---|
| Encounter match review | `app_tools_encounter_review` | `/tools/encounter-review` (hospital list), `/tools/encounter-review/{id}/…` |
| Encounter match validation | `app_tools_encounter_review` with `mode=validation` | the same hospital list, linking to `/tools/encounter-review/{id}/validation` |
| Data preparation tool | `app_tools_data_prep` | `/tools/data-prep`, `/tools/data-prep/key`, `/tools/data-prep/local-map`, `/tools/data-prep/download` |
| Plausibility table (also needs `ROLE_ENCOUNTER_CATALOG_REVIEWER`, [above](#plausibility-table)) | `app_tools_encounter_plausibility*` | `/tools/encounter-plausibility`, `…/areas/new`, `…/areas/{code}/edit`, `…/areas/{code}/delete`, `…/snapshots/{id}`, `…/snapshots/{id}/{activate,retire,delete}` |

There is no encounter entry in the main navigation anymore: descriptive figures are in Statistics
(`/statistics/encounter-analytics`), the MTS glossary and the case card in Explore, the upload in Import and
grants in the hospital edit pages. `EncounterMyHospitalActionProvider` adds "Encounter data" (link to the
analysis of that hospital) to the My Hospitals cards of the hospitals in `AggregateHospitalScope`; a
hospital with an encounter grant but outside My Hospitals (no hospital permission) gets no button.

Until v0.4-beta every old path under `/encounter…` and its route name (`app_encounter_index`,
`app_encounter_review_*`, `app_encounter_validation*`, `app_encounter_data_prep*` except `_latest`)
answers with a permanent redirect (308, query string kept) to its tool route
(`LegacyEncounterRedirectController`, removal in #1160). `/data-prep/latest.json`
(`app_encounter_data_prep_latest`) is called by the downloaded tool and stays where it is.

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
| #1130 | Tool landing page and local map editor |
| #1136 | MTS plausibility (umbrella): #1152 ADRs, #1082 catalog in the database, #1153 plausibility table, #1154 matcher `1.2.0`, #1155 learning job, #1156 analyses, #1157 catalog distribution |
