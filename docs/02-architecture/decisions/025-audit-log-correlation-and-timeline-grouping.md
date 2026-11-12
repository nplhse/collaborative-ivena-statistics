# ADR 025: Audit log correlation and timeline grouping

**Status:** proposed

## Context

The audit log ([audit-log.md](../../05-operations/audit-log.md)) is browsed through an EasyAdmin table.
Issue #955 replaces it with a timeline that stacks entries of one request and shows related activity
of a user. A review of the existing data showed:

- Over HTTP the `request_id` is taken from the client header `X-Request-Id` when it is well-formed.
  Two requests, even of different users, can therefore share an id. Grouping by it would show
  unrelated entries as one action.
- Messenger messages inherit the request id of the code that dispatched them, so a request's entries
  can be minutes apart and come from different origins.
- There is no session correlation. The analytics `session_key` cannot be used: it exists only with
  analytics consent, lives in a client cookie and serves another purpose. The Symfony session id is a
  reusable secret and must not be stored.
- `intent` and the outcome of exports exist only inside the `metadata` JSON.
- Historical entries have none of the new information, and the issue forbids inventing it.

## Decision

### Request id

- Every HTTP request gets a new UUIDv7 as `request_id`; the incoming header no longer sets it.
- A well-formed incoming `X-Request-Id` is kept as `metadata.upstreamRequestId` so a request can still
  be traced through a proxy. The response header `X-Request-Id` carries our id.
- Sentry keeps our id as tag `request_id` (the same id as in the audit log and the response header)
  and gets the upstream id, when present, as tag `upstream_request_id`.
- Messenger and console keep their current behaviour.
- A new column `request_id_kind` says whether the id is reliable: `generated` for entries written
  after this change, `legacy` for all older entries.

### Session reference

- On every successful authentication (`LoginSuccessEvent`, including remember-me) the application
  creates a random UUIDv4 and stores it as an attribute of the server-side session. It is never the
  session id, never a cookie and never sent to the client.
- Every audit entry written in that session stores it in a new nullable, indexed column
  `session_ref`. Messenger messages carry it in a stamp next to `RequestIdStamp`.
- A new login creates a new reference. Logout ends the session and with it the reference.
  Impersonation keeps the reference of the admin's session.
- The reference is not a secret: knowing it does not give access to anything.
- Anonymous requests, console commands and scheduler jobs have no reference. Logins and logouts are
  not written as audit events; a session shows from its first audited entry on.

### Grouping in the timeline

- **Request stack:** entries with the same `request_id`, only when `request_id_kind = generated`.
  Legacy ids are stacked only when all entries of the id share actor and origin, and are marked
  as legacy. Entries of the same request with origin `messenger` appear as a separate, labelled part
  ("background jobs started by this request"). Entries are never grouped by time alone.
- **Session:** entries with the same `session_ref`.
- **Inferred activity period:** for entries without `session_ref`, consecutive entries of the same
  (non-null) actor with at most 30 minutes between them. Shown only in the view filtered to one
  actor, always labelled as inferred, never stored.
- **Order:** `occurred_at DESC, id DESC` between groups and entries; inside a stack
  `occurred_at ASC, id ASC`.
- **Paging:** keyset over entries, not over groups. A stack whose request has more entries than the
  current page shows "n of m entries" and links to the full request view; it is never shown as
  complete when it is not. With filters, a stack shows the matching entries; the other entries of
  the request are shown only as context, marked as such.

### Backfill

Existing entries get only values that are copies of what they already store:

- `intent` as its own column, copied from `metadata.intent`
- `outcome` (`success`, `blocked`, `failed`) only where the entry states it: export metadata and
  the `import.run.failed` intent
- `request_id_kind = legacy`

No historical entry gets a `session_ref`, and no other field of an existing entry changes.

### Admin interface

The timeline replaces the EasyAdmin audit table, which is removed. An entry's permalink is its
detail view. The metadata substring search goes away; filters on `intent` and `outcome` replace it.

## Consequences

**Positive:**

- Stacks show only entries that belong to one request of our own making.
- Sessions are correlated without storing anything that could be used to take over a session.
- Inferred relationships are visible as such; historical entries stay as they were.
- `intent` and `outcome` become filterable and indexable.

**Negative:**

- A request id from a proxy is no longer the audit request id; correlation goes through
  `upstreamRequestId`.
- Historical entries can only be shown individually, as legacy stacks or as inferred periods.
- A session without an audited change leaves no trace; logins stay invisible in the audit log.
- Three new columns and a one-time backfill on a table that grows without retention.

## Alternatives

- **Trust `X-Request-Id` only from trusted proxies** — rejected; keeps two meanings of one column and
  depends on proxy configuration.
- **Keep the header and accept false stacks** — rejected; the issue requires reliable correlation.
- **Analytics `session_key`** — rejected; consent-bound, client-held, other purpose.
- **Hash of the Symfony session id** — rejected; derived from a secret and changes with session
  migration on login.
- **Inferred periods only, no new column** — rejected; real sessions of one user would merge when
  they are less than 30 minutes apart.
- **Audit login and logout** — rejected for now; the session reference already groups the activity,
  and logins add no change to data.
- **Backfill `session_ref` from inferred periods** — rejected; it would present a guess as evidence.
- **Group in SQL and page over groups** — rejected; expensive on a large table and unclear for
  requests whose jobs run much later.

## References

- Issue #955
- [audit-log.md](../../05-operations/audit-log.md)
- [014-persisted-user-activity-projection.md](014-persisted-user-activity-projection.md) (audit log is not the activity feed)
- [016-notification-system.md](016-notification-system.md) (audit log is supplementary)
