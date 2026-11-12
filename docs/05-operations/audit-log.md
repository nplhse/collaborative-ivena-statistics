# Audit log

What the audit log (`audit_log`) records, who writes it, how entries belong together and who can read
them. The audit log is a record of individual events: entries are only appended, never changed
or merged by the application. It is supplementary and not an operational source (ADR 016).

Related: [audit-log-maintenance.md](audit-log-maintenance.md) (purge of historical import noise),
[observability-sentry.md](observability-sentry.md), [ADR 025](../02-architecture/decisions/025-audit-log-correlation-and-timeline-grouping.md)
(proposed: correlation and grouping for the audit timeline, issue #955).

## Fields

Entity: [`AuditEntry`](../../src/Shared/Infrastructure/Audit/Entity/AuditEntry.php).

| Column | Content | Notes |
|--------|---------|-------|
| `id` | identity | the only reliable order between entries with the same timestamp |
| `occurred_at` | time of the write, from `ClockInterface` | `TIMESTAMP(0)`: seconds only, all entries of one flush share it |
| `request_id` | correlation id of the request, command run or message (see [Correlation](#correlation)) | up to 128 characters |
| `actor_id` | user who caused the change | `NULL` for anonymous, CLI and system activity, and after the user was deleted (`ON DELETE SET NULL`) |
| `origin` | `http`, `cli` or `messenger` | where the code ran, not who triggered it |
| `action` | `create`, `update`, `delete`, `export`, `download`, `impersonate`, `impersonate_exit` | |
| `entity_class` | FQCN of the affected entity, or the pseudo class `data_export` | |
| `entity_id` | id of the affected entity, or the exporter key for exports | `NULL` when the entity has no id |
| `changes` | JSON `{field: {old, new}}` | empty for `delete` and for the explicit loggers |
| `metadata` | JSON, see below | `NULL` when nothing applies |
| `intent` | copy of `metadata.intent` | for filtering; `NULL` without intent |
| `outcome` | `success`, `blocked` or `failed` | only where the entry states it: exports (`blocked`, `success`) and the `import.run.failed` intent; otherwise `NULL` |
| `request_id_kind` | `generated` or `legacy` | `legacy` for all entries written before ADR 025, whose HTTP request id may have come from a client |
| `session_ref` | random reference of the authenticated session | `NULL` without a logged-in session and for all entries written before ADR 025 |

Indexes: `request_id`, `(entity_class, entity_id)`, `actor_id`, `(occurred_at, id)`,
`(actor_id, occurred_at, id)`, `intent`, `session_ref`.

`intent` and `outcome` are set when an entry is written ([`AuditEntry`](../../src/Shared/Infrastructure/Audit/Entity/AuditEntry.php)
`intentFrom()` / `outcomeFrom()`). For older entries, `app:audit:backfill-derived` copies them from
the stored metadata with the same rules; see [Backfill of older entries](#backfill-of-older-entries).

### Metadata keys

| Key | Written by | Content |
|-----|------------|---------|
| `intent` | Doctrine subscriber, download logger | name of the business action, for example `user.settings.password_changed`, `import.run.failed` |
| `intent_metadata` | Doctrine subscriber | free-form context of the intent, for example `hospital_id`, `section`, or `reason` (exception message) for `import.run.failed` |
| `clientIp`, `userAgent` | all producers in HTTP requests except exports | personal data; client IP depends on `SYMFONY_TRUSTED_PROXIES` |
| `upstreamRequestId` | all producers | well-formed `X-Request-Id` the client or a proxy sent; for tracing only |
| `exporterKey`, `hospitalIds`, `filters`, `rowCount`, `blocked`, `success` | export logger | the only recorded outcome besides the `import.run.*` intents |
| `import_id` | download logger | |
| `impersonatorUserId`, `impersonatorUsername`, `targetUserId`, `targetUsername` | impersonation | |

## Producers

| Producer | Writes | When |
|----------|--------|------|
| [`AuditingDoctrineSubscriber`](../../src/Shared/Infrastructure/Audit/Doctrine/AuditingDoctrineSubscriber.php) | `create` / `update` / `delete` for entities marked `#[Audited]` | on every flush |
| [`ExportAuditLogger`](../../src/Shared/Application/Export/ExportAuditLogger.php) | `export` | each data export, also blocked ones |
| [`ImportSourceDownloadAuditLogger`](../../src/Import/Application/Service/ImportSourceDownloadAuditLogger.php) | `download` | successful download of an import source file |
| [`SwitchUserSubscriber`](../../src/User/Infrastructure/Security/SwitchUserSubscriber.php) | `impersonate`, `impersonate_exit` | an admin starts or ends impersonation |

Rules of the Doctrine subscriber:

- Audited entities live in the Allocation, Content, Import, Shared and User contexts; search for
  `#[Audited]` for the current list.
- `createdAt`, `updatedAt`, `createdBy`, `updatedBy` and fields with `#[AuditIgnore]` are skipped.
  Fields with `#[AuditSensitive]` (the password hash) are stored as `********`.
- `create` stores a snapshot of all fields and to-one associations, `update` only changed fields,
  `delete` no fields.
- The intent comes from `AuditContext::beginIntent()` around the flush. An intent alone writes
  nothing; without an audited change there is no entry.
- Allocation imports suppress bulk entities (`ImportRunSuppressedAuditClasses`), see
  [audit-log-maintenance.md](audit-log-maintenance.md).

Not recorded: logins, logouts, failed logins, page views and read access other than exports and
source file downloads.

## Correlation

[`AuditContext`](../../src/Shared/Infrastructure/Audit/AuditContext.php) holds the request id, origin,
actor, session reference, intent, client IP, user agent and upstream request id of the current unit
of work ([ADR 025](../02-architecture/decisions/025-audit-log-correlation-and-timeline-grouping.md)).

| Unit | Request id | Session reference | Actor |
|------|------------|-------------------|-------|
| HTTP request | always a new UUIDv7. Sent back in the `X-Request-Id` response header and set as Sentry tag `request_id`. A well-formed incoming `X-Request-Id` (`[a-zA-Z0-9._-]{1,128}`) is kept as `upstreamRequestId` and Sentry tag `upstream_request_id`. | of the logged-in session | logged-in user; during impersonation the original admin |
| Messenger message | the request id of the code that dispatched it (`RequestIdStamp`), otherwise a new UUIDv7 | of the dispatching session (`SessionRefStamp`) | the dispatching user (`ActorStamp`) |
| Console command | one UUIDv7 for the whole run | none | none unless the command sets one |

### Session reference

[`AuditSessionRefSubscriber`](../../src/Shared/Infrastructure/Audit/Http/AuditSessionRefSubscriber.php)
keeps a random UUIDv4 in the attribute `_audit_session_ref` of the server-side session. It is not the
session id, it is never sent to the browser, and knowing it gives no access.

- A login starts a new reference, unless the same user authenticates again in the same session
  (password confirmation).
- Logout ends the session and with it the reference. Impersonation keeps the admin's reference.
- A session that was logged in before the reference existed gets one on its next request.
- Anonymous visitors get none; the subscriber never starts a session.
- Logins and logouts are not audit events, so a session shows from its first audited entry on.

Consequences:

- Entries of one HTTP request share a request id, and so do the asynchronous jobs it dispatched.
  Those jobs run later (seconds to minutes) with origin `messenger`.
- A long console command (for example a backfill) writes all its entries under one request id.
- Entries with `request_id_kind = legacy` may share an HTTP request id that a client sent, so they
  are not reliable request groups.
- The explicit export and download loggers fall back to a 32-character hex id when no request id is
  set; this happens only outside HTTP and Messenger.
- Older entries have no session reference; their sessions can only be estimated from actor and time.

## Access and sensitive data

- Only `ROLE_ADMIN` can read the audit log (EasyAdmin **Audit log**, `AuditLogCrudController`).
- `clientIp`, `userAgent`, usernames and the export filters are personal data. Exception messages in
  `intent_metadata.reason` may contain technical details.
- Session cookies, session ids and authentication tokens are not stored.
- Entries are not deleted automatically; the only deletion is the one-time purge in
  [audit-log-maintenance.md](audit-log-maintenance.md).

## Backfill of older entries

`app:audit:backfill-derived` copies `intent` and `outcome` of entries written before ADR 025 from their
stored metadata. It previews by default and writes only with `--apply`. It touches only empty
columns, so a second run changes nothing. It never sets `session_ref` and changes no other field.

```bash
php bin/console app:audit:backfill-derived
php bin/console app:audit:backfill-derived --apply
```

`request_id_kind = legacy` needs no command: the migration sets it as column default for existing rows.

## Known gaps

1. Request groups can contain asynchronous jobs far away in time.
2. `occurred_at` has second precision; the order inside a second is given only by `id`.
3. Entries written before ADR 025 have no session reference and may have a client-sent request id.
4. An outcome is recorded only for exports and failed import runs.
