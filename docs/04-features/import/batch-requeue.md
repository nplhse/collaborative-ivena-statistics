# Batch import requeue

Re-dispatch allocation import jobs for all existing imports (or a filtered subset)
via Symfony Messenger. The command only queues jobs; actual CSV processing happens
asynchronously in `ImportAllocationsMessageHandler` (or synchronously in `test` env).

Each dispatch uses the import creator (`Import.createdBy`) as the audit user and registers a new [import run](import-runs.md). An unfinished earlier run of the same import becomes `superseded`.
`app:import:requeue-all` dispatches allocation jobs only. A closure import in that batch is marked dispatch-failed. Requeue it with `app:import:start`.

## Prerequisites

- In production: Messenger worker consuming `async_priority_high`

## Commands

### Single import

```bash
php bin/console app:import:start 42
```

`app:import:start` loads the import and dispatches the workflow for its type (`Allocation` or `Closure`).

Both commands accept `--notify-user=<username>`. The user is stored as the run initiator; without the option, the run has no initiator. An unknown username exits with `2`.

Exit codes: `0` = dispatched, `1` = import/creator not found, missing type, or dispatch error, `2` = invalid arguments.

### Batch requeue

Dry-run (no DB checkpoints, no messages):

```bash
php bin/console app:import:requeue-all --dry-run
```

Filtered runs:

```bash
php bin/console app:import:requeue-all --from-id=10 --limit=5
php bin/console app:import:requeue-all --only-id=42
php bin/console app:import:requeue-all --only-ids=42,43,44
```

Resume after interruption (OOM, SIGTERM, server restart):

```bash
php bin/console app:import:requeue-all --resume
php bin/console app:import:requeue-all --run-id=3
```

### Exit codes (`app:import:requeue-all`)

| Code | Meaning |
|------|---------|
| `0` | All planned imports dispatched |
| `1` | At least one dispatch failed; run finished cleanly |
| `2` | Critical: signal interrupt, max retries exceeded, invalid options |

### Automatic restart loop

Use the wrapper script to retry on exit `1` until all imports are queued.
Exit `2` stops the loop (e.g. permanent dispatch failure or max retries):

```bash
export MAX_RETRIES_PER_IMPORT=3
./bin/requeue-all-until-done
```

## Checkpoints

Progress is stored in `import_batch_run` and `import_batch_run_item`:

- Before each dispatch: item status `running` (persisted)
- After success: `queued`, set in the same transaction that registers the item's run (`import_run_id`)
- On dispatch error: `dispatch_failed` (batch continues)
- On SIGINT/SIGTERM the batch stops after the current item and becomes `interrupted`; `--resume` continues with the next import. (Items of earlier batches may still carry the item status `interrupted`.)
- When the item's run ends: `completed`, `partial` or `failed`, mirroring the run. Only the run an item references updates it.
- When a newer run is registered for the import before this item's run finished: `superseded`

Item status `queued` means the message was handed to Messenger, not that the CSV import finished.

## Completion and summary

When every item is dispatched, the batch gets the status `finished` and `dispatch_closed_at`. Processing goes on in the worker. As soon as no item is `pending`, `running`, `queued` or `interrupted` any more, the batch gets `processing_finished_at` and the user given with `--notify-user` (or the user who started a [bulk reprocess](bulk-actions.md)) receives one summary: `import.batch_finished` when nothing failed, otherwise `import.batch_failed` (high priority). It counts completed, partial, failed (including `dispatch_failed` and `skipped`) and superseded items and links to the import list filtered to the batch (`/import?batch=<id>`); the admin area still shows the batch under *Import batch runs*. Runs of a batch send no notification of their own.

- The check locks the batch row and runs in the transaction that ends an item's run (or registers a newer run for its import), and once more when the dispatch phase closes. The summary is published in that transaction (ADR 016).
- Batches that are `interrupted` or `failed` (max retries) do not close their dispatch phase and get no summary until a resumed run finishes them.
- The hourly notification reconciliation completes batches that were closed but never completed and publishes lost summaries of the last 24 hours again.
- Batches finished before this feature were marked as processed by the migration; nobody gets a summary for them.

Bulk reprocess batches from the import list (`options.source = ui`) close their dispatch phase right away and are never continued by `--resume`.

## Reimport cleanup

When an import is processed again (`runCount > 0`), or a run is redelivered after a retryable error, `ImportAllocationsMessageHandler` deletes all data from the previous attempt before reading the CSV again:

- `allocation_stats_projection` rows for that import
- `import_reject` rows and the reject CSV file on disk
- `allocation` and `mci_case` rows for that import
- `assessment` records that were linked to those allocations (IDs collected before allocations are removed)

The `import` record and source CSV file are kept; only import-scoped result data is removed. Shared reference data (specialities, departments, indication catalog entries, etc.) is not deleted.

## Related

- Handler: `src/Import/Application/MessageHandler/ImportAllocationsMessageHandler.php`
- Messenger routing: `config/packages/messenger.yaml`
