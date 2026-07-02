# Import workflow

## Overview

Imports process CSV data asynchronously via Symfony Messenger.
Upload/dispatch is separated from processing in the worker.

## What data is imported?

- Allocation records
- MCI/MANV-related rows
- Rejected rows are recorded as rejects

Source: CSV files uploaded through the import UI.

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

## Test locally

```bash
php bin/console app:import:allocations <IMPORT_ID>
php bin/console app:import:requeue-all --dry-run
php bin/console app:import:requeue-all --resume
```

Useful tests:
- `tests/Import/Integration/...`
- `tests/Import/Functional/Command/RequeueAllImportsCommandTest.php`

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
