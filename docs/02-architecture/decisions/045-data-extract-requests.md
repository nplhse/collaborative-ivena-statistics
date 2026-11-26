# ADR 045: Data extract requests with Board vote, admin SQL and time-limited export

**Status:** accepted

## Context

Research projects ask the Board for extracts from the database. The rules for this (request, decision, content, use,
retention, publication, ethics) are recorded in #1540; until #1635 the process ran outside the application. Bringing
it into the application means storing requests that change over time, a vote of a changing group of Board members,
SQL that an administrator writes and that runs against production data, and files that leave the platform.

Three forces shape the design. The Board decides on content, not on SQL, which most members cannot read. The data that
leaves must stay within what the Board approved, including the minimum of three contributing hospitals per group.
And the hosting (Uberspace 8) offers one database user plus a documented read-only user `_ro`, but no documented way
to create roles with narrower rights.

## Decision

1. **A new bounded context `Research`.** It owns requests, their versions, voting rounds, comments, queries,
   releases and export runs, with its own translation domain `research`. It reads allocations and their reference
   tables only through the export connection (point 6), never through the Allocation repositories. Hospital access
   requests stay in Allocation; the "My requests" page lists both through a small provider contract.
2. **Three objects with their own lifecycle.**
   - The **request** carries the Board's decision. Every change after submission is a new **version**; the approved
     version, including the export scope the applicant fills in as separate fields (purpose, period, population,
     variables, granularity, recipients, enrichment, single cases), is the decision. An approved request is closed;
     more data needs a new request.
   - A **release** is the time-limited right (3 months) to start one export. It binds recipient, request version,
     conditions and concrete query versions.
   - An **export run** records the execution, its result, errors and downloads. Exactly one logical run exists per
     release; parallel clicks and worker retries belong to it.
   The expiry of a release or of a run never changes the approval of the request.
3. **Symfony Workflow state machines** model the request, the release and the run. Transitions are guarded by voters
   and by the round rules; the marking is an enum column.
4. **Voting rounds** record the eligible Board members per round. Exclusions for conflicts of interest remove the vote
   and do not count for the quorum of two thirds. A round without a submitted revision ends when everyone voted or at
   the deadline (14 days, configurable); a round after a submitted revision is evaluated only at its deadline. A veto
   stops the request until an administrator closes it, restarts the vote or asks for a revision.
5. **Only administrators write SQL.** Queries are versioned, immutable once bound to a release, visible to all
   participants, and checked on save with `EXPLAIN (VERBOSE, FORMAT JSON)` against an allowlist of tables and a list
   of blocked columns kept in code. The applicant cannot pass SQL or parameters. There is no vote on the SQL text.
6. **A second DBAL connection `research`** (`RESEARCH_DATABASE_URL`) uses the read-only user on the same database.
   Without it, exports stay disabled; there is no fallback to the application user. Each run uses one read-only
   `REPEATABLE READ` transaction with a statement timeout and limits on rows and size, and runs only in the Messenger
   worker. Locally and in CI a read-only role `app_ro` exercises the same path.
7. **The minimum of contributing hospitals is checked on the actual output.** Every aggregated query returns a
   reserved column `_hospital_ids` in the same grouping as its rows; the runner checks each row, keeps the counts in
   the internal record and drops the column before writing the CSV. A group below the minimum blocks the run.
8. **The archive leaves only through the recipient.** A `.tar.gz` with CSV files, a manifest and a localised readme,
   downloadable several times for 7 days after it is provided. Downloads are events on the run. The internal record
   keeps query versions, settings, import states, row counts and checksums.

## Consequences

- The Board decides on a structured scope it has seen. The administrator works inside it and may correct SQL
  technically; a change of scope is a new request.
- **Residual risk, accepted for the first release:** the `EXPLAIN` check is a plausibility check, not an enforced
  limit. Until the rights of `_ro` are known, the design assumes it can read the whole database, including user data
  and the audit log. Two-factor authentication for administrators lowers the risk of an account takeover but not of a
  mistake in a query. #1636 makes the limit technically enforced.
- A later export on current data is a new extract; the archive is not kept beyond its download period, so a bit-exact
  restoration is not promised.
- Operators must configure `RESEARCH_DATABASE_URL` before exports work. The upgrade notes say so.
- New dependencies: `symfony/workflow` and a diff library for the version view.

## Alternatives

- **A separate extract database filled before each run.** Isolates well with a single database user, but duplicates
  data and needs a maintained list of columns to copy. Rejected for the first release; candidate in #1636.
- **A schema `research` with views in the main database.** Backups cover only `public` (ADR 038), and without a
  narrower role the schema protects nothing.
- **A role limited to export views with `GRANT`.** Not documented on Uberspace 8; checked in #1636.
- **Queries as files in the repository, executed after deployment.** Safe, but every extract would need a pull
  request and a release.
- **A second Board vote on the SQL text.** Rejected: the Board decides on the scope, and the SQL is public.
- **Falling back to the application user without `_ro`.** Rejected: the export would silently run with write rights.
