# ADR 034: Performance monitoring in Sentry, usage analytics without APM

**Status:** accepted

## Context

Usage analytics ([usage-analytics.md](../../04-features/analytics/usage-analytics.md)) also measured
performance: every tracked request stored its duration, the number of database queries and their time,
a DBAL middleware counted the queries, and the daily aggregation summed durations and error counts per
area. An admin page showed averages, p95 by area, the slowest routes and "prioritisation hints".

That answered "which page is slow" but not "why": no query text, no breakdown inside a request, nothing
for background jobs. Sentry was already integrated for errors, logs and the job runs of
[ADR 018](018-job-run-monitoring.md) / [ADR 019](019-messenger-monitoring.md). Issue #951 (with #586)
asked to move request performance there as well, without sending unnecessary data or an excessive
number of spans.

The project ships Sentry as an **optional** integration: operators decide whether they use it and
manage their own plan and quota. Telemetry still has to stay proportionate.

## Decision

- **Request performance lives in Sentry.** Request transactions are named by route, Live Component
  requests by component and action; tags `request_id`, `route`, `bounded_context` and the numeric
  user id (error reports always, performance traces only with the performance consent) ([observability-sentry.md](../../05-operations/observability-sentry.md)).
- **Few, meaningful spans.** Automatic Doctrine spans (execute only, no prepare spans), one
  `view.render` span per top-level render instead of one per template and block, cache calls as a
  summary in the trace data instead of single spans, and manual spans (`SentrySpanTracer`) only for
  logical blocks where a trace leaves a question open (`analysis.explorer.run`). Health checks and
  notification polling are never sampled.
- **Privacy by default.** No query strings, no request bodies, no SQL parameters, span and trace data
  through an allowlist, the user id on performance traces only with a separate consent option that is
  offered only while Sentry is used (#1200).
- **Usage analytics keeps counting, but no longer measures.** Request counts, counted accesses,
  sessions, areas and routes stay; duration, query count and time, the daily sums, the error count per
  area, the query counter and the admin Performance page are removed. The migration drops the columns
  and their stored history.

## Alternatives

- **Keep the in-app measurement next to Sentry.** Two sources for the same question, both to maintain;
  the in-app numbers cannot explain a slow request. Rejected.
- **Keep the in-app measurement as the fallback for operators without Sentry.** Would keep the query
  counter on every request and the aggregation columns for a view few operators use. Operators without
  Sentry can still use the Symfony profiler locally or their own APM. Rejected.
- **Another APM or tracing platform (OpenTelemetry collector, self-hosted).** More infrastructure for a
  small project; Sentry is already in place for errors, logs and job runs. Not now.
- **Sentry with the default instrumentation.** 150–460 spans per request (mostly Twig and cache), full
  URLs with query strings. Rejected for volume and privacy.

## Consequences

- Operators who want performance data need Sentry and a sampling rate; the volume per request and
  month is documented for them ([observability-sentry.md](../../05-operations/observability-sentry.md#volume)).
- The stored performance history is gone after the migration; trends start again in Sentry.
- Sentry retention and query window depend on the operator's plan; long-term job history stays in
  `job_run`.
- New manual spans follow the rules in [observability-sentry.md](../../05-operations/observability-sentry.md#request-spans):
  one span per logical block, stable op names, counts, labels and numeric ids only, every data key on
  the scrubber allowlist.
- Slow paths found with Sentry become ordinary issues (the first ones: #1193–#1197).
