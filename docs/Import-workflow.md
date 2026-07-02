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

Reimports intentionally keep the source file; only result data from the previous run is cleared. See [Import-batch-requeue.md](Import-batch-requeue.md).

File paths are resolved and removed through `ImportFileStorage` (Symfony `Filesystem::remove()`). Failed deletions are logged as `import.file.delete_failed`.

## Test locally

```bash
php bin/console app:import:allocations <IMPORT_ID>
php bin/console app:import:requeue-all --dry-run
php bin/console app:import:requeue-all --resume
```

Useful tests:
- `tests/Import/Integration/...`
- `tests/Import/Functional/Command/RequeueAllImportsCommandTest.php`
- `tests/Import/Functional/Controller/NewImportControllerTest.php` (upload validation, Excel rejection)
- `tests/Import/Unit/Service/ImportUploadGuardTest.php`
- `tests/Import/Integration/Validator/Constraints/ImportSourceFileValidatorTest.php`

## Related documentation

- Requeue: [Import-batch-requeue.md](Import-batch-requeue.md)
- Reject analysis: [Import-reject-analysis.md](Import-reject-analysis.md)
- Statistics projection: [Statistics-projection-materialized-views.md](Statistics-projection-materialized-views.md)
- Operations diagnosis: [Troubleshooting.md](Troubleshooting.md)
