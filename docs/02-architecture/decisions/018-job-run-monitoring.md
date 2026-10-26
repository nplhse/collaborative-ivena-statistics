# ADR 018: One run log and shared telemetry for long-running background jobs

**Status:** accepted

## Context

Imports and projection rebuilds run in Messenger workers (`--memory-limit=256M`, `--time-limit=3600`) and from the console. Their duration, memory and throughput are not recorded anywhere, so regressions and slow trends go unnoticed (issue #693).

The closure volume rebuild already solved this for one job (ADR 015, phase 2.0): `ClosureRebuildTelemetry` writes a row per run to `closure_rebuild_run`, opens a Sentry transaction that is sampled completely, logs one summary per run plus threshold warnings, and `SentryClosureRebuildFilter` lets only ids, counts and durations leave the system.

Three more jobs need the same: allocation import, closure import and the allocation stats projection rebuild. Copying the closure classes three times would mean four run tables, four allowlist filters and four lists of sampled ops.

`import_run` (ADR 016) already records each import run, but as a domain lifecycle: queued, running, finished, superseded; who started it; which batch it belongs to; which notification follows. It answers *what happened to the import*, not *how the run performed*.

## Decision

### One table `job_run` for every job

Every run of a long-running background job writes one row to `job_run`, at the start as `running` and at the end with its final status. A Messenger retry is a new row with the next `attempt`.

- Common values are typed columns: `job`, `triggered_by`, `status`, `attempt`, `import_id`, `import_run_id`, `hospital_id`, `started_at`, `finished_at`, `duration_ms`, `peak_memory_bytes`, `thresholds_exceeded`, `error_class`, `error_message`. They can be filtered and indexed across jobs.
- Job-specific counters live in `metrics` (`jsonb`), for example passes and partitions of a closure rebuild or rows and phase times of an import.
- Timestamps are `TIMESTAMPTZ`. Doctrine entities store UTC, the closure rebuild stored Europe/Berlin wall time; a shared table must not mix both.
- `closure_rebuild_run` is moved into `job_run` (job `closure_rebuild`) and dropped.
- `import_run` stays the domain table and is not extended. `job_run.import_run_id` links the technical record to it, so nothing is stored twice.
- The error message stays in `job_run` and is never sent to Sentry.
- Writing the row never fails the job: `JobRunRecorder` catches its own errors and logs `job_run.record_failed`.

### Shared mechanics, job-specific content

`src/Shared/Infrastructure/Monitoring/JobRun/` holds what every job needs:

- `JobRunTrace`: Sentry transaction kept off the scope (query spans stay with the surrounding worker or console transaction), span stack, duration, peak memory.
- `JobRunRecorder`: the `job_run` row, including abandoning running rows of dead processes while the job's lock is held.
- `JobRunThresholds` / `JobRunThresholdConfig`: duration and memory limits per job from `app.job_runs` in `config/packages/app.yaml`. A job without values only records its runs, so new jobs start without guessed limits and get them from measured runs.
- `JobRunSentryProfile`: what one job may send (transaction name, sampled ops, span op prefix, log prefixes, allowed keys).

Each context keeps its telemetry class (counters, spans, extra checks such as "more than one pass") and its `JobRunSentryProfile` in its own Infrastructure layer. `SentryJobRunFilter` and `SentryTracesSampler` collect all profiles through the service tag `app.job_run.sentry_profile`; a new job adds a profile and needs no change in Shared.

Application handlers reach the telemetry only through a port in their context's `Application/Contract`, so `Application` does not depend on `Infrastructure`.

## Consequences

- One query compares runs of all jobs; the ops list of runs (follow-up issue) needs one table.
- The admin list of runs (issue #755) reads `job_run` through the read-only entity `JobRun` (`src/Shared/Infrastructure/Monitoring/JobRun/Entity`). It maps the table without foreign keys and with plain strings for job, trigger and status; `JobRunRecorder` keeps writing via DBAL.
- `metrics` keys are not checked by the database. Each telemetry class documents its keys, and tests read them.
- Thresholds of the closure rebuild moved from class constants to `app.job_runs.closure_rebuild` (same values).
- `closure_rebuild_run` no longer exists. Queries on it read `job_run WHERE job = 'closure_rebuild'` and `metrics` instead (`request_count` → `requests`, `query_count` → `queries`, `full_rebuild` → `full`).
- Old rows are kept; retention of `job_run` is decided separately.

## Related

- ADR 015 (closure volume metrics, rebuild monitoring)
- ADR 016 (`import_run`)
- Issue #693
