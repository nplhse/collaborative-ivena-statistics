# Import runs

**Audience:** Developers and operators who start, requeue or debug imports.

An **import run** is one deliberate processing request for an import: an upload, a reprocess from the import detail page, one `app:import:start` call, or one item of an `app:import:requeue-all` batch. Every run is stored as an `ImportRun` row (`import_run` table) in the `Import` context. Background and rules: [ADR 016](../../02-architecture/decisions/016-notification-system.md#import-runs-importrun_finished).

## What a run stores

| Field | Meaning |
|-------|---------|
| `id` | Run id (UUID v7). Carried by the Messenger message |
| `import_id` | The import. Runs are deleted with it (`ON DELETE CASCADE`) |
| `trigger` | Where the run came from: `upload` (UI upload), `reprocess` ("Reprocess import" on the detail page), `console` (`app:import:start`), `batch_requeue` (one item of `app:import:requeue-all`) |
| `initiated_by_id` | User who deliberately started the run: the uploading user, the user who clicked "Reprocess import", the `--notify-user` of a console or batch run, or `NULL` (console run without `--notify-user`) |
| `status` | `queued`, `running`, `completed`, `partial`, `failed`, `superseded` |
| `created_at`, `started_at`, `finished_at` | Registration, first processing attempt, terminal transition |
| `rows_total`, `rows_ok`, `rows_rejected` | Outcome counts of a completed, partial or failed evaluation |
| `reason_code` | Why a run failed, e.g. `file_not_found`, `rejection_ratio_exceeded`, `retries_exhausted`. Never an exception message |

`Import::currentRunId` points to the run whose results the import currently accepts. Older runs are kept as history; a new run never overwrites one.

A requeue batch item (`import_batch_run_item.import_run_id`) references the run registered for it.

`trigger` and `initiated_by_id` together decide who is told about the outcome (ADR 016): an `upload` run notifies the import's creator and the hospital owner; a `reprocess` run notifies only the user who started it; a `console` run notifies only its initiator (nobody without `--notify-user`); `batch_requeue` runs send no notification of their own, they are summarized per batch ([batch-requeue.md](batch-requeue.md#completion-and-summary)). See [../notification/README.md](../notification/README.md#notification-types). A run that reaches the creator's first successful import also records the milestone in `user_import_milestone`.

## Lifecycle

```
queued ──▶ running ──▶ completed | partial | failed
   │          │
   └──────────┴──▶ superseded   (a newer run was registered)
```

1. **Register** (`ImportRunLifecycle::register()`): upload (`NewImportController`), `ImportAllocationsDispatcher` / `ImportClosuresDispatcher` (used by `app:import:start` and `app:import:requeue-all`). In one transaction, under a row lock on the import:
   - the previous run, if it is still `queued` or `running`, becomes `superseded` (and its batch item too, which may finish its batch),
   - a new `queued` run is created and `Import::currentRunId` points to it,
   - the run's message is dispatched.

   Messenger stores messages on the same connection (`doctrine://default`), so the run and its message are committed together. If dispatching fails, the whole registration is rolled back: no `queued` run without a message is left behind, and the previous run stays current.
2. **Start** (`ImportRunLifecycle::start()`): the handler claims the message. It is acknowledged **without processing** when its run is not the import's current run any more, or when the run already finished (redelivery).
3. **Process**: every commit of processing results (the cleanup of previous data, each chunk flush, the rejects, the final evaluation) goes through `ImportRunCommitGuard`. It locks the import row and checks `currentRunId` first. If a newer run was registered, the chunk is rolled back and processing stops.
4. **End**: exactly one terminal transition per run, as a conditional update from `queued`/`running`. Only the process that wins it commits the import result, increments `Import::runCount` and dispatches `ImportCompleted` / `ImportFailed` (both carry `runId`). `runCount` therefore counts finished runs (including superseded ones), not attempts.

## Errors

| Error | Examples | Result |
|-------|----------|--------|
| Precondition | missing creator, hospital or import permission, wrong type, missing or invalid file path, file not found | Run `failed` with a reason code, import `FAILED` |
| Validation | `ImportException` (e.g. closure list for another hospital) | Run `failed` with `invalid_file` |
| Evaluation | no rows, rejection ratio ≥ 35 % | Run `failed` with `no_rows` / `rejection_ratio_exceeded` |
| Other non-retryable error | bug, constraint violation | Run `failed` with `processing_error` |
| **Retryable** | lost database connection, deadlock, lock timeout (DBAL `RetryableException`, `ConnectionException`) | Rethrown; the run **stays open** and Messenger retries the same message (same run) |
| Retries exhausted | `WorkerMessageFailedEvent` without retry | `ImportRunRetriesExhaustedSubscriber` ends the run `failed` with `retries_exhausted` |

Retryable errors no longer mark the import `FAILED` immediately. While Messenger retries, the import stays `Running`.

A redelivered message (run already `running`) first removes the chunks that the failed attempt committed, using the same cleanup as a reimport. The cleanup deletes rows inside a guarded commit, so a superseded run never deletes data of a newer run; the materialized view refresh and the reject file deletion follow after the commit.

## Reprocessing from the detail page

The import detail page (`/import/{id}`) has a **Reprocess import** button next to *Delete import*. After a confirmation dialog, `ReprocessImportController` (`POST /import/{id}/reprocess`, CSRF token `import_reprocess_<id>`) calls `ImportStartDispatcher` with trigger `reprocess` and the current user as initiator, then redirects to the processing page. It uses the same workflow as `app:import:start`, so every import type is supported without choosing a workflow.

The button is shown only when `ImportVoter::REPROCESS` grants it:

- the user may delete the import (admin, hospital owner, or the creator while they can access the hospital), and
- the creator may still process it (`ImportCreatorPermissionCheck`: import permission for the hospital, and `ROLE_CLOSURE_BETA` for closure imports; the message handlers use the same check), and
- the stored source file still exists.

While the import is `Running` the button is disabled, and a request that arrives anyway is rejected with a flash message. A `Pending` import can be reprocessed; the new run supersedes the queued one.

The workflow dispatchers set the import creator as audit user while they register the run and restore the previous token afterwards, so the logged-in user stays signed in.

## Starting runs from the console

```bash
php bin/console app:import:start 42 --notify-user=admin
php bin/console app:import:requeue-all --only-ids=42,43 --notify-user=admin
```

`--notify-user=<username>` stores that user as run initiator. Without it, a console run has no initiator. An unknown username exits with `2` before anything is dispatched.

## Legacy messages and deployment

Messages queued before this change carry no run id. Such a message is processed with the old behaviour, creates no `ImportRun` and logs `notification.import_run.legacy_message`.

Before deploying, drain the import queue: wait until `php bin/console messenger:stats` shows no pending `async_priority_high` messages, stop the worker, deploy, start the worker.

## Log events

| Event | Meaning |
|-------|---------|
| `import.run.queued` | Run registered |
| `import.run.superseded` | Previous unfinished run replaced by a new one |
| `import.run.stale_message` | Message of a non-current run acknowledged without processing |
| `import.run.already_finished` | Redelivered message of a finished run, or lost terminal transition |
| `import.run.superseded_during_processing` | Commit refused because a newer run was registered |
| `import.run.retryable_error` | Error rethrown for a Messenger retry |
| `import.run.failed` | Run ended as failed (with `reason`) |
| `notification.import_run.legacy_message` | Message without run id |

## Related

- [import-pipeline.md](import-pipeline.md)
- [batch-requeue.md](batch-requeue.md)
- Code: `src/Import/Application/Service/ImportRunLifecycle.php`, `ImportRunCommitGuard.php`, `ImportRunErrorClassifier.php`
