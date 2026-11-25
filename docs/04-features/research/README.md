# Data extract requests

**Audience:** Developers working on the Research context.

**Status:** in development on the integration branch `feature/data-requests` (#1635). Decisions:
[ADR 045](../../02-architecture/decisions/045-data-extract-requests.md). Rules for extracts: #1540.

Research projects ask the Board for an extract from the database. The request, the Board's vote, the admin-written
queries, the release and the export all happen in the application. This page describes what exists so far and is
extended with every step of #1635.

## Pages

| Route | Path | Who |
|---|---|---|
| `app_data_requests` | `/requests/data` | participants: every submitted request and their own drafts |
| `app_data_requests_new` | `/requests/data/new` | participants: start a request, saved as a draft |
| `app_data_requests_show` | `/requests/data/{publicId}` | `DataRequestVoter::VIEW` |
| `app_data_requests_edit` | `/requests/data/{publicId}/edit` | the applicant, while the current version is not submitted |
| `app_data_requests_transition` | `POST /requests/data/{publicId}/{submit\|withdraw}` | the applicant |
| `app_data_requests_review` | `POST /requests/data/{publicId}/review/{open\|return\|vote\|exclude\|resolve}` | Board members and administrators, per voter attribute |
| `app_data_requests_query` | `POST /requests/data/{publicId}/queries/{add\|revise\|dry-run}` | `DATA_REQUEST_MANAGE_QUERIES` |
| `app_data_requests_compare` | `/requests/data/{publicId}/compare` | `DATA_REQUEST_VIEW` |
| `app_data_requests_comment` | `POST /requests/data/{publicId}/comments` | `DATA_REQUEST_COMMENT` |
| `app_data_requests_release` | `POST /requests/data/{publicId}/releases` | `DATA_REQUEST_RELEASE` |
| `app_data_requests_export` | `POST /requests/data/{publicId}/releases/{number}/{start\|retry}` | start: the recipient of an active release; retry: administrators |
| `app_data_requests_export_download` | `/requests/data/{publicId}/releases/{number}/download` | the recipient and administrators, for seven days |
| `app_my_requests` | `/requests` | participants: "My requests" with hospital assignment requests and data extract requests |

The pages sit under "My requests", the shared roof for every kind of request (`/requests`, user menu). They are
not a tool: a data extract request is a request to the Board like a hospital assignment request is one to the
administrators. The breadcrumb starts at "My requests"; the list of all requests is linked from its section.

## Request and versions

`DataRequest` carries the status and the times (created, first submission, closing); `DataRequestVersion` carries
what the applicant entered, in three groups:

| Group | Fields | Who sees it |
|---|---|---|
| Visible | title, summary, institution, enrichment planned (yes/no) | every participant once submitted |
| Export scope | purpose, period, population, variables, granularity, single cases (yes/no), recipients, details of the enrichment | every participant except recipients and enrichment details |
| Internal | description, data needed and why, responsible persons, conflicts of interest, ethics vote, optional planned end, deletion and publication | the applicant, Board members and administrators |

Participants without access to the internal fields see for each one whether it was given ("Given, not public" or
"Not given"). A version can change until it is submitted; after that it is frozen. After the formal check returned the
request or the Board asked for a revision, the applicant's first change starts the next version, later changes edit
it until it is submitted. The approved version, with its export scope, is the Board's decision
(`DataRequest::approvedVersion`).

**Comparing versions:** `/requests/data/{publicId}/compare` (`app_data_requests_compare`, optional `from` and `to`
version numbers) compares two versions field by field, by default the latest with the one before
(`VersionComparison`, line diff with `sebastian/diff`). Only changed fields are listed. Readers without the internal
details compare only the fields they can see; only the applicant sees a version that is not submitted yet. The basic
information links to the page, and each "submitted version N" event in the timeline links to the changes from N-1.

## Status

The `data_request` state machine (`config/packages/workflow.yaml`) has the places and transitions of ADR 045.
`DataRequestService` handles the applicant's steps, `DataRequestReviewService` the Board's and administrators' steps
and the deadlines; both apply the transitions and record the times.

| Status | Meaning | Next |
|---|---|---|
| `draft` | only the applicant sees it | `submitted`, `withdrawn` |
| `submitted` | waits for the formal check | `in_review` (vote opened), `returned`, `withdrawn` |
| `returned` | the formal check handed it back with a message | `submitted` (next version), `withdrawn` |
| `in_review` | a voting round is open | `approved`, `rejected`, `revision_requested`, `stalled`, `withdrawn` |
| `revision_requested` | the Board asks for a revision, one vote period to submit it | `in_review` (next version, new round), `expired`, `withdrawn` |
| `stalled` | a veto stopped it; only an administrator resolves it | `rejected`, `in_review` (restart), `revision_requested`, `withdrawn` |
| `approved` | approved and closed | `withdrawn` |
| `rejected`, `expired`, `withdrawn` | final | – |

A withdrawal ends the request for good and an open round with it (outcome `cancelled`). Later steps also revoke open
releases and cancel running exports.

## Formal check

A Board member or an administrator who is neither the applicant nor excluded checks a submitted request: complete,
export scope unambiguous, answerable with the data. "Accept and open the vote" opens the first round; "Return" needs a
message and hands the request back. The next version of a returned request goes to the formal check again.

## Voting rounds

`DataRequestRound` records the version voted on, the kind (`initial`, `after_revision`, `restart`), the deadline and the
ids of the eligible members: every enabled, verified account with `ROLE_BOARD_MEMBER` when the round opens, without the
applicant and without excluded members. A round cannot open without an eligible member.

- **Deadline:** `app.research.vote_period_days` (default 14) from the opening; the same period applies to a requested
  revision.
- **Votes:** approve, needs revision (a conditional approval), reject, veto (reason required). Members can change their
  vote until the round ends. `DataRequestVote` keeps the reason; only Board members see single votes, everyone who
  can see the request sees counts and the outcome. Administrators see the reason of a veto they have to resolve.
- **Rules:** `RoundEvaluator`, a pure function with hand-countable tests (`RoundEvaluatorTest`):
  - quorum two thirds of the eligible members, rounded up; missed at the deadline → `rejected` (outcome `quorum_missed`);
  - without enrichment: approvals more than half of the votes cast → approved; approvals plus revisions more than half
    → revision requested; otherwise rejected;
  - with enrichment: every vote an approval → approved; at least one revision and no rejection → revision requested;
    otherwise rejected;
  - a veto ends the round at once and stops the request (`stalled`).
- **End of a round:** at the deadline, or earlier once every eligible member voted. A round after a submitted revision
  is evaluated only at its deadline. The scheduler job `research_data_request_deadlines` (every 15 minutes) closes due
  rounds and lets revisions expire that were not submitted in time.
- **Revision:** the submitted revision goes straight into a new round without a formal check. Approvals of the previous
  round carry over for members who are still eligible (marked "carried over"); all other votes start empty.
- **Veto:** an administrator closes the request, restarts the vote (new round, no votes) or asks for a revision (message
  required).

## Exclusions

In the actions card, "Declare own conflict of interest" opens a dialog in which a Board member gives the reason;
administrators also have "Exclude a Board member" with a dialog to choose the member (`DataRequestExclusion`, one per
member and request). The exclusion cannot be undone. An excluded member leaves an open round together with their vote,
does not count for the quorum, cannot vote and cannot do the formal check. The round may be complete afterwards.

## Timeline and comments

The request page shows a timeline like a GitHub issue: events and comments in the order they happened.
`DataRequestEvent` rows are added by the services (submitted, returned, vote opened, voted, round ended, member
excluded, veto resolved, expired, withdrawn); `DataRequestComment` holds comments, nested once, with the version that
was current when it was written. `DataRequestTimeline` mixes both for one reader; within the same second a vote comes
first, then the comment with its reason, then the end of the round.

Every entry has one of three visibility levels (`Visibility`):

| Level | Who reads it | Examples |
|---|---|---|
| `internal` | Board members | votes, their reasons, exclusions |
| `private` | Board members and the applicant | the return message, the reason for "Needs revision", veto resolution |
| `public` | everyone who may see the request | submissions, opened and ended rounds, normal comments |

- **Readers:** administrators read every level, as do Board members who are neither the applicant nor excluded. The
  applicant reads private and public entries, everyone else public ones. An excluded member reads public entries only.
- **Writers:** administrators and active Board members write every level, the applicant private and public comments,
  other participants nothing. The form always shows the levels the writer may choose; public is preselected.
- **Replies:** a reply never reaches more readers than the comment it answers; a broader choice is narrowed. The
  visibility is fixed once posted, and comments are not edited.
- **Vote reasons:** a reason is always and only a comment on the vote: internal, or private for "Needs revision", so
  the applicant reads what to change. Votes themselves no longer store a reason.

## Export queries

After the approval an administrator writes one or more export queries (`DataRequestQuery`, versions in
`DataRequestQueryVersion`). The name becomes the CSV file name; each change of the SQL is a new version with its
SHA-256. Everyone who may see the request reads the SQL (formatted with `doctrine/sql-formatter`) and its plain-language
description; check results, dry runs and the forms are for administrators. The timeline records each saved version
(`query_saved`, public).

- **Allowlist check** (`QueryGuard`, rules in `PlanInspector`, lists in `ExportAllowlist`), on every save and before a
  dry run, on the export connection in a read-only transaction; the query itself does not run:
  - one SELECT or WITH statement, no denied function (functions that run SQL of their own, read files or settings,
    or reach other databases);
  - every table from `EXPLAIN (VERBOSE, FORMAT JSON)` on the allowlist (allocations and their reference tables);
  - no blocked column anywhere in the plan, not even in a filter: free text, case ID hash, import, public IDs,
    hospital name, address, coordinates, beds, participation dates and timestamps, references to user accounts,
    review comments;
  - hospital and allocation IDs only to join and count, never as a result column;
  - the reserved column: `_hospital_ids` (`array_agg(DISTINCT a.hospital_id)` in the same grouping) for aggregated
    queries, `_hospital_id` for single cases.
  With a single table Postgres prints bare column names; they count as that table's columns. String literals are
  ignored. The check is a plausibility check; #1636 makes the limit technical.
- **Dry run** (`QueryDryRunner`): read-only `REPEATABLE READ` transaction, 30 s statement timeout, at most 50,000 rows.
  It reports the result columns, the first rows, the hospitals involved and the groups with fewer than three
  contributing hospitals (`HospitalMinimum`); the reserved column never leaves the runner. The summary is stored with
  the version.

### Export connection

`RESEARCH_DATABASE_URL` configures the Doctrine connection `research`. Empty (the default) keeps checks, dry runs and
exports disabled; nothing falls back to the application user. In production it is the hosting's read-only user, on
Uberspace `<account>_ro` with the password from `~/.pgpass`. In the test environment it points to the test database,
and DAMA shares the test transaction with it (`connection_keys` in `dama_doctrine_test_bundle.yaml`); a dedicated
read-only role for local development and CI follows in the next step.

## Release and export run

Once at least one query has a checked current version, an administrator releases the request
(`DataRequestRelease`). The administrator chooses the queries; by default every query that passes the check. The
release binds the applicant as recipient, the current version of each chosen query and, for chosen single-case
queries, the administrator's confirmation that timestamps are at most hourly and ages only in groups. Queries left out
stay with the request and are named in the release and its timeline entry, so it shows they were left out on purpose.
The usual case: the dry run of one query shows groups below the minimum; the administrator agrees with the applicant
in a private comment to leave it out (or to change it) and releases the others. It is valid for three
months; a new release is possible once the previous one is neither active nor has an export that is queued, running
or still downloadable. Expiry, revocation and conditions follow in the next steps.

The recipient starts the export once. `DataRequestRelease::startExport()` creates the release's only
`DataRequestExportRun` (unique key on the release) and `RunDataExportMessage` goes to `async_priority_low`. The worker
(`RunDataExportMessageHandler`) takes over only a queued run (optimistic lock), so a redelivered message or a second
worker does nothing.

- **Run** (`ExportRunner`): all query versions in one read-only `REPEATABLE READ` transaction on the export connection,
  so every file sees the same data; per query a 120 s statement timeout, at most 1,000,000 rows and 200 MB. Each query
  becomes one CSV file (UTF-8, header row, comma); the reserved hospital column is counted and dropped before a row is
  written, and the IDs are not kept.
- **Minimum:** an aggregated file with a group of fewer than three hospitals blocks the run (`blocked`); nothing is
  provided, and the administrator sees the first such groups without IDs. A changed query needs a new version and a
  new release.
- **Failure:** a technical error or an exceeded limit ends in `failed` with the error for administrators; they may queue
  the same run again.
- **Archive** (`ExportStorage`, `ExportPackage`): `data-request-<number>-release-<n>/` with the CSV files,
  `manifest.json` (request number and URL, data state, rows, hospitals and SHA-256 per file) and `LIESMICH.txt` or
  `README.txt` in the recipient's language (title and version, query descriptions, creation date, latest finished
  import as data state, hospitals per file, the minimum, the pseudonymisation note and a citation). Neither names a
  person. The archive lies in `var/research/exports` under a random name, is served only by the download route, and is
  downloadable for seven days by the recipient and by administrators; each download is recorded
  (`DataRequestExportDownload`). Removing the file after the period follows with the cleanup job.
- **Internal record** (on the run, administrators only): settings (isolation, timeout, limits, minimum), application
  and allowlist version (`ExportAllowlist::VERSION`), time and duration, the finished imports at the time of the run,
  the query versions with their SQL checksum, per file rows, hospitals, SHA-256 and the number of groups per count of
  hospitals, the archive's SHA-256 and size, and the recipient's user name.

Timeline: `released`, `export_requested` and `export_provided` are private (Board, applicant, administrators);
`export_blocked`, `export_failed` and `export_retried` are internal.

## Permissions

`DataRequestVoter` decides what the pages offer; the services check the rules again.

| Attribute | Granted to |
|---|---|
| `DATA_REQUEST_VIEW` | the applicant; every participant once the request was submitted |
| `DATA_REQUEST_VIEW_INTERNAL` | the applicant; Board members and administrators once submitted |
| `DATA_REQUEST_EDIT` | the applicant while the status is `draft`, `returned` or `revision_requested` |
| `DATA_REQUEST_SUBMIT` | the applicant, for a version that is not submitted yet, where the state machine allows it |
| `DATA_REQUEST_WITHDRAW` | the applicant, from every non-final status |
| `DATA_REQUEST_CHECK` | Board members and administrators who are neither the applicant nor excluded, while `submitted` |
| `DATA_REQUEST_VOTE` | eligible, not excluded Board members while a round is open |
| `DATA_REQUEST_VIEW_VOTES` | Board members who are neither the applicant nor excluded, and administrators |
| `DATA_REQUEST_EXCLUDE_SELF` | Board members except the applicant, on a submitted, not final request |
| `DATA_REQUEST_RECORD_EXCLUSION`, `DATA_REQUEST_RESOLVE` | administrators; resolving only while `stalled` |
| `DATA_REQUEST_COMMENT` | whoever may write at least one level (see above), on a submitted request |
| `DATA_REQUEST_MANAGE_QUERIES` | administrators, once the request is approved |
| `DATA_REQUEST_RELEASE` | administrators, once every query passes the check and no other release is open |

## Audit

Every entity is audited. The services record the intents `research.data_request.drafted`, `.updated`, `.submitted`,
`.withdrawn`, `.commented`, `.round_opened`, `.returned`, `.voted`, `.vetoed`, `.member_excluded`, `.veto_resolved`, `.round_closed`,
`.expired`, `.query_added`, `.query_revised`, `.query_checked`, `.query_dry_run`, `.released`, `.export_requested`,
`.export_started`, `.export_finished`, `.export_retried` and `.export_downloaded`. A request, its votes and rounds stay when an account is deleted (the user reference becomes null);
an exclusion is deleted with the member's account.
