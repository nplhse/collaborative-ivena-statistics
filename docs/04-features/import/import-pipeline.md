# Import workflow

## Overview

Imports process CSV data asynchronously via Symfony Messenger.
Upload/dispatch is separated from processing in the worker.

## What data is imported?

- Allocation records
- MCI/MANV-related rows
- Closure intervals, when the upload is a closure list ([closure-import.md](closure-import.md))
- Encounters (ED visits) from canonical `ed_visit` files (`ImportType::ENCOUNTER`), with the encounter beta
  role and grant `UPLOAD` ([encounter README](../encounter/README.md#import))
- Rejected rows are recorded as rejects

Source: CSV or plain-text files (`.csv`, `.txt`) uploaded through the import UI.

Excel files (`.xls`, `.xlsx`) are **not** supported. The importer reads rows via `SplCsvRowReader`; users must export spreadsheet data as CSV first.

## Who may upload

The upload form (`/import/new`) is only offered to users who can submit it (#1351). `ImportUploadTargets` lists, per import type, the hospitals the user may upload to; the form offers only those hospitals and types, the type field shows only the types allowed for the chosen hospital (Stimulus controller `import-type-fields`), and the controller checks the submitted combination again. A participant without any upload right is sent to the import list with a notice.

**Upload authority** (#1565, key points A1/A2): before the first upload for a hospital the person confirms "I am
authorised to upload data for this hospital." The checkbox appears only for a selected hospital without a stored
confirmation (option attribute `data-needs-upload-authority`, shown by `import-type-fields`; without JavaScript it is
always shown) and the controller refuses the upload without it. The confirmation is stored in the same flush as the
import as a `TermsAcceptance` of kind `upload_authority` with the hospital and the terms version in force
(`UploadAuthority` in `Allocation/Application/Hospital/UploadAuthority`, called by Import because Import may not use
User). It is asked again per hospital after `APP_TERMS_VERSION` is raised; while the variable is empty any stored
confirmation counts. Administrators upload as operators and are never asked. It applies to every import type; the
encounter checkbox of #1563 still comes on every encounter upload.

Links to the form use the same source: the import list and the dashboard ask `IMPORT_CREATE` (any type); the empty states of the allocation list, the Analysis Explorer and the reports, and the onboarding step "Start your first import", ask `ImportUploadTargets::canUpload($user, ImportType::ALLOCATION)` because they offer an allocation import; the data preparation guide asks for `ImportType::ENCOUNTER`. My Hospitals and the hospital detail page show the upload link per hospital with `HospitalPermission::Import`, which is the same rule for allocation imports. The welcome mail and the monthly reminder link the form only for hospital owners, who always have that permission. Rules: [permission-model.md](../../02-architecture/permission-model.md#voters).

## Upload validation

Rejected uploads are caught **before** dispatch to the worker. Validation runs in three layers:

1. **Form constraint** — `ImportSourceFile` on the file field in `ImportCreateType` (Symfony Validator).
2. **Controller guard** — `NewImportController::rejectUnsupportedImportFile()` adds a field error when the form is submitted but not yet fully validated (defensive duplicate of the guard logic).
3. **Upload service** — `FileUploader::resolveExtension()` rejects Excel extensions and guessed Excel types as a last line of defence.

Shared rules live in `ImportAllowedFileTypes` and `ImportUploadGuard`:

| Check | Result |
|---|---|
| Extension `.xls` / `.xlsx` | Rejected with `validation.import.excel_rejected` |
| Extension other than `.csv` / `.txt` | Rejected with `validation.import.file_extensions` |
| Allowed extension but content MIME not in `EXTENSION_MIME_MAP` | Rejected with `validation.import.file_mime_types` |
| Spreadsheet content MIME on a `.csv` file | Rejected as Excel (`validation.import.excel_rejected`) |

The file input uses `accept=".csv,.txt"`. Windows may report CSV exports as `application/vnd.ms-excel`; that MIME is mapped to `.csv` when the extension is allowed.

User-facing messages are in the `validators` domain (`validation.import.*`); help text on the form uses `label.import.helpFile` in the `import` domain.

## Background upload

The file field of the upload form is a drop area around the native file input (#977). Selecting or dropping a file uploads it at once, while the user fills in the rest of the form; the submission then sends only a token. Without JavaScript the form is the plain file upload it was before.

1. **Upload:** the Stimulus controller `import-upload` posts the file to `POST /import/upload` (`app_import_upload`, `PendingImportUploadController`). The endpoint needs `ROLE_PARTICIPANT`, `IMPORT_CREATE`, the CSRF token `import_upload` and the rate limit `import_upload` (60 per hour per user and IP). It applies the same file checks as the form (`ImportSourceFile`, `ImportUploadGuard`, `app.imports_max_size`) and answers `201` with `{token, name, size}`, or `422` with the message. No import is created.
2. **Storage:** `PendingImportUploadStore` keeps the file outside `public/` under `var/imports/pending/<user id>/<token>.upload`, with `<token>.json` next to it (client name, MIME type, size, time). The token is 128 random bits and is only looked up in the folder of the current user, so it cannot reach another user's file or an arbitrary path. Storage measurement counts the folder as imports.
3. **Submission:** the hidden field `uploadToken` carries the token. A `PRE_SUBMIT` listener of `ImportCreateType` puts the stored file into the file field as an `UploadedFile`, so every constraint, the controller's permission and type checks, the extension hooks and the move into the import storage run exactly as for a direct upload. A file sent with the form wins over a token; a foreign or expired token leaves the file field empty. Moving the file consumes the token: a second submission with the same token finds no file and gets `validation.import.upload_consumed` instead of a second import.
4. **Form errors:** when another field is invalid, token and file stay; the form shows "Already uploaded: <name>" and the user does not upload again.
5. **Client behaviour:** a newer selection aborts the older request and ignores its late answer; the replaced upload is deleted (`replaces`), "Remove" deletes it (`POST /import/upload/{token}/discard`). Submitting during the transfer shows a waiting note and submits when the upload is done; the submit button is disabled after the first submission. A failed upload keeps the file in the input (so the plain submission still works) and offers "Try again" unless the server rejected the file.
6. **Cleanup:** the hourly scheduler job `import_pending_upload_purge` deletes pending files older than 24 hours (`PendingImportUploadStore::RETENTION_HOURS`, by file time), metric `deleted_files` ([messenger-monitoring.md](../../05-operations/messenger-monitoring.md#scheduler-jobs-in-the-run-log)).

Automatic detection of the file format and preselection of the import type are not part of this; see #1005.

## Import options and the target hospital override

The upload form has an **Options** section below the file field, right above the submit button (#1352). It lists only the options the current user can use in the current environment and is missing when there is none. A quiet toggle with a chevron opens it, like an accordion; it starts collapsed and opens on its own when an option is set or has a validation error. The fields of other contexts' import types (for example the encounter fields of `ed_visit`) keep their own fieldset.

Options are registered in one place, `ImportCreateType::OPTIONS`: an unmapped checkbox with label, help and a voter attribute. An option the voter does not grant is not added to the form, so a posted value is an extra field and the form is invalid; the controller asks the same attribute before it uses the value.

The first option is **Override target hospital** (#878). It lets a real allocation or closure export be loaded into a fictional demo hospital and is recorded on the import (`hospital_override`, `hospital_override_source_label`).

- Available in `dev` and `demo` (`ImportHospitalOverride`), never in `prod` or `test`; tests switch it on by replacing that service.
- Right: `ImportCreateVoter::OPTION_HOSPITAL_OVERRIDE`, granted to everyone who may upload to the selected hospital (without subject: to at least one hospital). Form and controller use it; the asynchronous closure handler has no user and only checks the environment.
- Allocations are always stored on the selected hospital; the file's `Krankenhaus-Kurzname` is not compared, so the flag only records the intent.
- Closures: the flag lets a single file hospital name that matches another catalog hospital pass (details: [closure-import.md](closure-import.md#target-hospital-override-dev-and-demo)).
- Permissions, validation, duplicate handling and reference resolution do not change. Outside `dev` and `demo` the option does not exist and a stored flag has no effect.
- These imports are real operational data, not synthetic data; the synthetic data generator never replaces them. In the demo the Options section warns that such an import contradicts the "all data is synthetic" banner ([ADR 023](../../02-architecture/decisions/023-demo-environment.md#real-exports-through-the-target-hospital-override)).

## Technical flow

1. Create import in the UI (file + context; the file may arrive earlier as a [background upload](#background-upload))
2. Store file under `var/imports/...`
3. Register a new [import run](import-runs.md) (`ImportRun`, status `queued`) and point `Import::currentRunId` to it
4. The UI, or `app:import:start <IMPORT_ID>` for the import's type, dispatches the matching message with the run id
5. `ImportAllocationsMessageHandler` / `ImportClosuresMessageHandler` / `ImportEncountersMessageHandler` (Encounter context) processes the file in the worker; every commit checks that the run is still current
6. The run ends exactly once; the winner emits `ImportCompleted` or `ImportFailed` (with `runId`)
7. Trigger downstream statistics rebuild

## Core components

- `StartImportCommand`
- `ImportStartDispatcher`
- `ImportAllocationsDispatcher`
- `ImportClosuresDispatcher`
- `ImportEncountersDispatcher` (Encounter context, registered through the `import.workflow_dispatcher`
  [extension point](../../02-architecture/extension-points.md#import-workflow-dispatchers))
- `ImportAllocationsMessageHandler`
- `ImportClosuresMessageHandler`
- `ImportRunLifecycle`, `ImportRunCommitGuard`, `ImportRunErrorClassifier` (see [import-runs.md](import-runs.md))
- `AllocationImporter`
- `RuleBasedRowTypeDetector`
- `ImportRequeueBatchOrchestrator`
- `ImportAllowedFileTypes`, `ImportUploadGuard`, `ImportSourceFile` / `ImportSourceFileValidator` (upload validation)
- `FileUploader` (store file under `var/imports/...`)

## Audit during import

`ImportAllocationsMessageHandler::__invoke()` suppresses per-row Doctrine audit entries for bulk import entities via `ImportRunSuppressedAuditClasses`:

- `Allocation`
- `Assessment`
- `IndicationRaw`
- `ImportReject`
- `MciCase`

The `Import` entity itself remains audited (status updates and run intents such as `import.run.started` / `import.run.finished`).

Assessments are created when a CSV row has valid ABCD values (`AllocationAssessmentResolver`). Empty placeholders (`A-`, `B-`, …) skip assessment creation entirely.

To clean up historical import-generated `Assessment` `create` audit entries, see [../../05-operations/audit-log-maintenance.md](../../05-operations/audit-log-maintenance.md).

## Order and error handling

- Previous run data may be cleaned up before a re-run
- Missing file / invalid preconditions end the run as `failed` with a reason code and mark the import as failed
- Row-level business errors become rejects
- Non-retryable errors end the run as `failed`; retryable database errors (connection loss, deadlock, lock timeout) are rethrown so Messenger retries the run. Details: [import-runs.md](import-runs.md#errors)

## Deletion cleanup

When an import is deleted (UI, admin or a [bulk deletion](bulk-actions.md#delete-selected-imports) in the worker), `ImportDeletionService` removes:

- The import database record and its `import_run` history (database cascade)
- Related allocations, assessments, MCI cases, and projection rows
- Rows other contexts stored for the import, through `ImportDataRemoverInterface` implementations
  ([extension point](../../02-architecture/extension-points.md#import-data-removers)); today the encounters the import
  created ([encounter README](../encounter/README.md#model)) and the encounter links of its allocations or
  visits ([matching](../encounter/README.md#matching))
- Batch-run history entries for that import
- The uploaded source file under `var/imports/...`
- The reject CSV file, if one was written during processing

A data remover can refuse the deletion (`ImportDeletionRefusedException`), today when records of other
imports are attached to the encounters the import created
([encounter README](../encounter/README.md#import)). Nothing is removed then: the import, its data and its
files stay, and the reason is shown (import page and administration) or the import is skipped (bulk
deletion).

Reimports intentionally keep the source file; only result data from the previous run is cleared. See [batch-requeue.md](batch-requeue.md).

File paths are resolved and removed through `ImportFileStorage` (Symfony `Filesystem::remove()`). Failed deletions are logged as `import.file.delete_failed`.

## Reprocessing

An existing import can be processed again from its stored source file with **Reprocess import** on the detail page, or with `app:import:start`. Both register a new run; the handler removes the data of the previous run first. Details: [import-runs.md](import-runs.md#reprocessing-from-the-detail-page)

> **Hospitals with encounter data:** reprocessing or requeueing an allocation import removes the IVENA
> links and the manual review decisions of its allocations. The matcher links the new allocations again
> automatically; manual decisions have to be made again
> ([ADR 030](../../02-architecture/decisions/030-encounter-allocation-matching.md#runs)). The pages and
> commands do not warn about this, because Import does not depend on Encounter.

## Data coverage

At the end of each run the import stores which days its records cover, shown on the detail page with potential gaps. Details: [data-coverage.md](data-coverage.md)

## Source file download

Administrators and the owner of the import's hospital can download the original uploaded file from the import detail page (`/import/{id}`), for example to keep a copy before the hospital leaves the platform (#1569). The file is served through `DownloadImportSourceFileController` at `/import/{id}/source-file` — not via a public path under `var/imports/`.

- Access requires `ImportVoter::DOWNLOAD_SOURCE`. For allocation and closure imports it is granted to `ROLE_ADMIN` and to the hospital owner. It is not granted to the creator of the import or to other users with grants, because source files hold the IVENA free text and the case number in clear. For encounter imports it is granted to users with the encounter grant `UPLOAD` (or `ROLE_ADMIN`) while the file is kept ([encounter README](../encounter/README.md#upload-file-retention)).
- The download link is shown only when the voter grants access and the file is still stored; otherwise the card says that the original file is no longer stored.
- Requesting the download of a missing file redirects to the import detail page with the same message.
- Downloads are recorded with audit intent `import.source_file.downloaded`.

## Test locally

```bash
php bin/console app:import:start <IMPORT_ID>
php bin/console app:import:requeue-all --dry-run
php bin/console app:import:requeue-all --resume
```

Useful tests:
- `tests/Import/Integration/...`
- `tests/Import/Functional/Command/StartImportCommandTest.php`
- `tests/Import/Functional/Command/RequeueAllImportsCommandTest.php`
- `tests/Import/Unit/Application/Service/ImportStartDispatcherTest.php`
- `tests/Import/Functional/Controller/DownloadImportSourceFileControllerTest.php` (source file download)
- `tests/Import/Unit/Service/ImportUploadGuardTest.php`
- `tests/Import/Integration/Validator/Constraints/ImportSourceFileValidatorTest.php`

## Reject writer configuration

Reject persistence is configured via `app.import.reject_writer` in `config/packages/app.yaml`:

| Value | Behaviour |
|-------|-----------|
| `db` | Rejects stored in database (default in production) |
| `csv` | Rejects written to `app.import.csv_reject_dir` (default `var/import_rejects`) |

See [../../02-architecture/decisions/005-reject-writer-strategy.md](../../02-architecture/decisions/005-reject-writer-strategy.md).

Encounter imports ignore this setting: they always store their row rejects in the database, with line
number, field, error code and profile version only, never the row or a cell value (ADR 026).

## Encounter imports

`ImportType::ENCOUNTER` reuses the import runs (`ImportRunLifecycle`, `ImportRunCommitGuard`), the
previous-run cleanup, deletion (`ImportDeletionService` with `EncounterImportDataRemover`) and the data coverage
of the other types, and the import pages. The Encounter context contributes through two extension points:
its workflow dispatcher (`import.workflow_dispatcher`) and `EncounterImportTypeExtension`
(`import.type_extension`): the type in the upload form with its extra fields and checks, access for
`ImportVoter` and `ListImportsQuery` by the encounter grants, and a section on the detail page. Message
(`ImportEncountersMessage`) and handler belong to the Encounter context. Encounter upload files are
deleted after a retention period by a daily job of the Encounter context; files of other import types
are kept until their import is deleted. Details:
[encounter README](../encounter/README.md#import).

## Related documentation

- Import runs: [import-runs.md](import-runs.md)
- Data coverage: [data-coverage.md](data-coverage.md)
- Requeue: [batch-requeue.md](batch-requeue.md)
- Reject analysis: [reject-analysis.md](reject-analysis.md)
- Indication corruption repair: [repair-indication-corruption.md](repair-indication-corruption.md)
- Statistics projection: [../statistics/projection-and-materialized-views.md](../statistics/projection-and-materialized-views.md)
- Operations diagnosis: [../../05-operations/troubleshooting.md](../../05-operations/troubleshooting.md)
