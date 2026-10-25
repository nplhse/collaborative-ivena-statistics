# Bulk actions on the import list

The import list (`/import`) can reprocess or delete several imports at once (#720). It uses the generic DataTable bulk selection ([twig-components.md](../../03-development/twig-components.md#bulk-actions)).

## Selection

- Checkboxes appear only on rows the user may change: the same rule as deleting a single import (`ImportVoter::DELETE`: admin, hospital owner, or the creator while they can access the hospital). Viewing an import is not enough. Without any such row, the list has no selection column.
- *Select all* covers the current page only. Paging, sorting, searching or filtering reloads the list and clears the selection.
- While rows are selected, a toolbar shows the count, the actions and *Clear selection*.
- `POST /import/bulk` (`BulkImportActionController`, CSRF token `import_bulk`, at most 100 ids) checks every id again; the page is not trusted. The form submits outside the list's Turbo frame so the result flash is shown.

## Reprocess selected imports

The confirmation names the current count ("Reprocess these 7 imports?"). `ImportBulkReprocessService` then decides per import:

| Outcome | When |
|---------|------|
| skipped: without permission | the user may not change it, or the id does not exist (not told apart) |
| skipped: already queued or processing | status `Pending` or `Running`; it is not queued again |
| skipped: without source file | the stored file is gone |
| skipped: creator may no longer import | the run is processed as the creator, who lost the import permission or the closure beta role |
| skipped: could not be queued | the dispatch failed; the other imports are still queued |
| queued | everything else, allocation and closure imports alike |

The queued imports form one `ImportBatchRun` (`options.source = ui`, `initiatedByUserId`, `importIds`) with one item per import. Each item is dispatched through `ImportStartDispatcher`, like the single *Reprocess import* action, with run trigger `bulk_reprocess`. Such runs send no notification of their own. The dispatch phase is always closed at the end, so the batch can complete and is never continued by `app:import:requeue-all --resume`.

A flash reports queued and skipped imports with their reasons, then the list opens filtered to the batch (`/import?batch=<id>`). For the user who started the batch, and for admins, an alert above the table shows the progress ("Reprocessing 10 imports: 4 completed, 1 failed, 5 open") and later the result. Other users only see that the list is filtered.

When the last run ends, the initiator gets one summary ([batch-requeue.md](batch-requeue.md#completion-and-summary)): `import.batch_finished` ("10 imports processed successfully") or, if any run failed, `import.batch_failed` with high priority ("8 of 10 imports completed, 2 require attention"). Both link to the import list filtered to the batch.

Unlike the single action, bulk reprocessing skips `Pending` imports. The single action may supersede a queued run to recover a stuck import.

## Delete selected imports

The confirmation names the current count ("Really delete these 7 imports?", singular "Really delete this import?") and lists what is removed with the imports: allocations or closure intervals, MCI cases, projections, rejected rows and source files; statistics and the closure analysis are recalculated.

1. **Request** (`ImportBulkDeletionService`): skips imports the user may not delete (`ImportVoter::DELETE`, also unknown ids) and running imports, then queues one `DeleteImportsMessage` (operation UUID, initiator, import ids) on `async_priority_low`. The flash says how many imports are being deleted and why others were skipped. The rows stay on the list until the worker is done.
2. **Worker** (`DeleteImportsMessageHandler`): checks every import again. Gone imports count as deleted, running ones and ones the user may no longer delete (the audit middleware authenticates the initiator for the message) are skipped. Each import is deleted through `ImportDeletionService`, the same path as a single deletion, but with the overview materialized view refresh deferred; one refresh runs at the end if any deleted import removed a hospital from the projection. A failing import is logged and does not stop the others.
3. **Summary** to the initiator: `import.bulk_deleted` ("7 imports deleted") or, with failures, `import.bulk_delete_failed` (high priority, "5 of 7 imports deleted, 2 could not be deleted"). Both link to the import list. The deduplication key contains the operation id, so a Messenger retry sends no second summary.

There is no durable record of a bulk deletion: a lost summary is not reconciled (the deletion itself is complete either way). Pending imports may be deleted; their queued run then finds no import and ends without effect.

## Toasts for several imports

Independently started imports keep one notification each in the inbox. When several of their toasts arrive in the same poll, they are shown as one toast ("5 imports processed, 1 failed") that links to the import list ([notification README](../notification/README.md#toasts-and-polling)). Bulk reprocessing and bulk deletion send one summary anyway.
