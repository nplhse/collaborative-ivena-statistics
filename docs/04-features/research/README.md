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
| `app_data_requests_comment` | `POST /requests/data/{publicId}/comments` | `DATA_REQUEST_COMMENT` |
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

## Comments

`DataRequestComment` holds the discussion on a submitted request, nested like blog comments, with the version that
was current when it was written.

- **Who writes:** the applicant, Board members and administrators. Other participants read the public comments.
- **Private comments:** written and read only by Board members who are neither the applicant nor excluded;
  administrators without the Board role do not see them. A reply to a private comment is always private, a public
  comment may get a private reply. The form always shows who will read the comment; the visibility cannot change
  after posting, and comments are not edited.
- **Vote reasons:** a reason stays on the vote (Board only) unless the member chooses "also as a public comment" or
  "also as a private comment" when voting; the comment then refers to the vote.

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
| `DATA_REQUEST_VIEW_VOTES` | Board members except the applicant |
| `DATA_REQUEST_EXCLUDE_SELF` | Board members except the applicant, on a submitted, not final request |
| `DATA_REQUEST_RECORD_EXCLUSION`, `DATA_REQUEST_RESOLVE` | administrators; resolving only while `stalled` |
| `DATA_REQUEST_COMMENT` | the applicant, Board members and administrators, on a submitted request |
| `DATA_REQUEST_VIEW_PRIVATE_COMMENTS` | Board members who are neither the applicant nor excluded |

## Audit

Every entity is audited. The services record the intents `research.data_request.drafted`, `.updated`, `.submitted`,
`.withdrawn`, `.commented`, `.commented_privately`, `.round_opened`, `.returned`, `.voted`, `.vetoed`, `.member_excluded`, `.veto_resolved`, `.round_closed`
and `.expired`. A request, its votes and rounds stay when an account is deleted (the user reference becomes null);
an exclusion is deleted with the member's account.
