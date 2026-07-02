# ADR 005: Reject writer strategy (db vs. csv)

**Status:** accepted

## Context

Import rejects (rows that fail validation) must be persisted for operator review and transformer planning. Different environments have different needs: production prefers queryable DB storage; local development may prefer CSV files for quick inspection.

## Decision

Make reject persistence configurable via `app.import.reject_writer` in `config/packages/app.yaml`. Allowed values: `db` (default in production config) or `csv` (writes to `app.import.csv_reject_dir`, default `var/import_rejects`).

## Consequences

**Positive:**

- Operators can query rejects in production via SQL or `app:import:analyze-rejects`
- Developers can inspect CSV rejects without database tools
- Single import pipeline code path with pluggable writer

**Negative:**

- Two code paths to test
- CSV rejects on disk require backup consideration

## Alternatives

- **DB only** — rejected; harder local debugging workflow
- **CSV only** — rejected; production analysis needs aggregation queries

## References

- [../../04-features/import/import-pipeline.md](../../04-features/import/import-pipeline.md)
- [../../06-reference/configuration.md](../../06-reference/configuration.md)
