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
"Not given"). A version can change until it is submitted; after that it is frozen. Later steps add a new version for
each revision. The approved version, with its export scope, is the Board's decision.

## Status

The `data_request` state machine (`config/packages/workflow.yaml`) has the places and transitions of ADR 045. This
step uses `submit` (draft → submitted) and `withdraw` (from every non-final place). The formal check, the voting
rounds and the veto follow in the next steps of #1635. `DataRequestService` applies the transitions and records the
times; `DataRequestVoter` decides what the pages offer:

| Attribute | Granted to |
|---|---|
| `DATA_REQUEST_VIEW` | the applicant; every participant once the request was submitted |
| `DATA_REQUEST_VIEW_INTERNAL` | the applicant; Board members and administrators once submitted |
| `DATA_REQUEST_EDIT` | the applicant while the status is editable and the current version is not submitted |
| `DATA_REQUEST_SUBMIT`, `DATA_REQUEST_WITHDRAW` | the applicant, where the state machine allows it |

A withdrawal ends the request for good. Later steps also end a running vote, revoke open releases and cancel running
exports.

## Audit

Both entities are audited. The service records the intents `research.data_request.drafted`, `.updated`,
`.submitted` and `.withdrawn`. A request stays when the applicant's account is deleted (`requester` becomes null).
