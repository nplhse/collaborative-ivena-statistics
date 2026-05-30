# Batch import requeue

Re-dispatch allocation import jobs for all existing imports (or a filtered subset)
via Symfony Messenger. The command only queues jobs; actual CSV processing happens
asynchronously in `ImportAllocationsMessageHandler` (or synchronously in `test` env).

Each dispatch uses the import creator (`Import.createdBy`) as the audit user.

## Prerequisites

- In production: Messenger worker consuming `async_priority_high`

## Commands

### Single import (existing)

```bash
php bin/console app:import:allocations 42
```

Exit codes: `0` = dispatched, `1` = import/creator not found or dispatch error, `2` = invalid arguments.

### Batch requeue

Dry-run (no DB checkpoints, no messages):

```bash
php bin/console app:import:requeue-all --dry-run
```

Filtered runs:

```bash
php bin/console app:import:requeue-all --from-id=10 --limit=5
php bin/console app:import:requeue-all --only-id=42
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
./scripts/import/requeue-all-until-done.sh
```

## Checkpoints

Progress is stored in `import_batch_run` and `import_batch_run_item`:

- Before each dispatch: item status `running` (persisted)
- After success: `queued`
- On dispatch error: `dispatch_failed` (batch continues)
- On SIGINT/SIGTERM: `interrupted`

Item status `queued` means the message was handed to Messenger, not that the CSV import finished.

## Reimport cleanup

When an import is processed again (`runCount > 0`), `ImportAllocationsMessageHandler` deletes all data from the previous run before reading the CSV again:

- `allocation_stats_projection` rows for that import
- `import_reject` rows and the reject CSV file on disk
- `assessment` records linked to previous allocations
- `allocation` and `mci_case` rows for that import

The `import` record and source CSV file are kept; only import-scoped result data is removed. Shared reference data (specialities, departments, indication catalog entries, etc.) is not deleted.

## Related

- Handler: `src/Import/Application/MessageHandler/ImportAllocationsMessageHandler.php`
- Messenger routing: `config/packages/messenger.yaml`
