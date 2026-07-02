# Import workflow

## Overview

Imports process CSV data asynchronously via Symfony Messenger.
Upload/dispatch is separated from processing in the worker.

## What data is imported?

- Allocation records
- MCI/MANV-related rows
- Rejected rows are recorded as rejects

Source: CSV or plain-text files (`.csv`, `.txt`) uploaded through the import UI.

Excel files (`.xls`, `.xlsx`) are **not** supported. The importer reads rows via `SplCsvRowReader`; users must export spreadsheet data as CSV first.

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

## Technical flow

1. Create import in the UI (file + context)
2. Store file under `var/imports/...`
3. `app:import:allocations <IMPORT_ID>` or dispatcher sends a message
4. `ImportAllocationsMessageHandler` processes the file in the worker
5. On success, emit `ImportCompleted`
6. Trigger downstream statistics rebuild

## Core components

- `ImportAllocationsCommand`
- `ImportAllocationsDispatcher`
- `ImportAllocationsMessageHandler`
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
- Missing file / invalid preconditions mark the import as failed
- Row-level business errors become rejects
- Critical errors are logged as import failures

## Deletion cleanup

When an import is deleted (UI or admin), `ImportDeletionService` removes:

- The import database record
- Related allocations, assessments, MCI cases, and projection rows
- Batch-run history entries for that import
- The uploaded source file under `var/imports/...`
- The reject CSV file, if one was written during processing

Reimports intentionally keep the source file; only result data from the previous run is cleared. See [batch-requeue.md](batch-requeue.md).

File paths are resolved and removed through `ImportFileStorage` (Symfony `Filesystem::remove()`). Failed deletions are logged as `import.file.delete_failed`.

## Source file download (admin)

Administrators can download the original uploaded CSV from the import detail page (`/import/{id}`). The file is served through `DownloadImportSourceFileController` at `/import/{id}/source-file` — not via a public path under `var/imports/`.

- Access requires `ROLE_ADMIN` and `ImportVoter::DOWNLOAD_SOURCE`
- The download link is shown only when the voter grants access
- Missing or deleted source files return HTTP 404
- Downloads are recorded with audit intent `import.source_file.downloaded`

## Test locally

```bash
php bin/console app:import:allocations <IMPORT_ID>
php bin/console app:import:requeue-all --dry-run
php bin/console app:import:requeue-all --resume
```

Useful tests:
- `tests/Import/Integration/...`
- `tests/Import/Functional/Command/RequeueAllImportsCommandTest.php`
- `tests/Import/Functional/Controller/DownloadImportSourceFileControllerTest.php` (admin source file download)
- `tests/Import/Unit/Service/ImportUploadGuardTest.php`
- `tests/Import/Integration/Validator/Constraints/ImportSourceFileValidatorTest.php`

## Reject writer configuration

Reject persistence is configured via `app.import.reject_writer` in `config/packages/app.yaml`:

| Value | Behaviour |
|-------|-----------|
| `db` | Rejects stored in database (default in production) |
| `csv` | Rejects written to `app.import.csv_reject_dir` (default `var/import_rejects`) |

See [../../02-architecture/decisions/005-reject-writer-strategy.md](../../02-architecture/decisions/005-reject-writer-strategy.md).

## Related documentation

- Requeue: [batch-requeue.md](batch-requeue.md)
- Reject analysis: [reject-analysis.md](reject-analysis.md)
- Statistics projection: [../statistics/projection-and-materialized-views.md](../statistics/projection-and-materialized-views.md)
- Operations diagnosis: [../../05-operations/troubleshooting.md](../../05-operations/troubleshooting.md)
