# ADR 016: Multi-channel notification system with declarative definitions and user preferences

**Status:** accepted

## Context

Users are not told in a consistent way when something relevant happens to them: an import finishes or fails, their participation is activated, a monthly reminder is due. Today this is spread over several unrelated mechanisms:

- `TransactionalMailer` sends verification, password reset and the participation welcome mail (`UserBecameParticipantNotificationSubscriber`).
- `AdminNotificationSender` (`Shared\Application\Notification\AdminNotification*`) mails role-based admin recipients on `UserRegistered` and `ImportFailed`. Feedback has its own admin mailer.
- `Engagement` sends monthly submission reminders per hospital, tracks them in `monthly_reminder_dispatch` and reads the single opt-out flag `User::receivesMonthlySubmissionReminder` (the only entry on the settings page *Notifications*). Admin-triggered reminders ignore the opt-out. `MonthlyReminderDeliverySubscriber` already tracks mail delivery through a correlation header, `SentMessageEvent` and `WorkerMessageFailedEvent`.
- The Activity Stream (ADR 014) records public, historical events. It is not addressed to a recipient and has no read state.
- `symfony/notifier` is installed but unused.

There is no in-app inbox, no toast mechanism and no place to say "this event goes to these channels by default, and this user wants it differently". Every new feature would otherwise add its own mail call. Issue #695 asks for one notification infrastructure that all contexts reuse.

Constraints:

- Production runs on Uberspace with PostgreSQL and one Messenger worker. The transport is `doctrine://default` (ADR 003), so queued messages live in the same database and on the same connection as application data. There is no Mercure hub.
- Messenger delivers at least once. A handler can run again after a crash, even if its work already succeeded.
- Import messages carry only the import id. The handler does not know who started the run, which batch it belongs to, or whether the message is outdated.
- On an exception, the import handler marks the import as `FAILED`, increments `runCount` and rethrows. Messenger then retries. A failed *attempt* is therefore stored as if it were a failed *run*.
- Re-imports are started from the console only (`StartImportCommand`, `RequeueAllImportsCommand`). An `ImportBatchRun` is set to `Finished` once all items are **dispatched**, not once they are **processed**. Nothing knows when a batch has actually completed.
- Application events are plain classes dispatched synchronously after flush (ADR 014); there is no outbox.

## Decision

### Separate bounded context `Notification`

A new top-level context `src/Notification/` with the usual Domain / Application / Infrastructure / UI layers and Deptrac layers (ADR 007, ADR 010). It owns its entities, UI and preferences, so it does not belong in `Shared` (ADR 008). It may depend on `User` (recipients, access checks) and `Shared`. Producing contexts depend only on its application port. `Notification` never depends on Import, Engagement, Allocation or other feature contexts.

### Responsibilities

1. **Definition**: what a notification type is. Static, in code, never in the database.
2. **Preference**: how one user wants to receive configurable types. Stored as overrides only.
3. **Instance**: one notification for one recipient, with its read state. This is the inbox entry.
4. **Delivery**: one durable job per external channel (e.g. email) of an instance, with its own status.

### Symfony Notifier for channel dispatch

Symfony Notifier is the delivery core. It already provides the channel abstraction, the email channel with Messenger support and transports for future channels (chat, SMS, push). The project adds only what Notifier lacks:

| Part | Source |
|------|--------|
| Channel dispatch, `EmailChannel`, future chat/SMS/push channels | Symfony Notifier |
| Email delivery via `SendEmailMessage` on `async_mail` | Symfony Notifier + Mailer (existing) |
| Definitions, registry, preferences, preference resolution | custom |
| Instances, deliveries, deduplication, read state, retention | custom |
| Toast delivery and polling | custom |

Notifier is called from the **delivery handler**, one channel per call, never directly from the publisher. Each type's notification class extends `Symfony\Component\Notifier\Notification\Notification` and implements `EmailNotificationInterface`, so `asEmailMessage()` builds a `TemplatedEmail` from the existing mail layout in the recipient's locale. Recipients are a small `UserRecipient` (user id, email, locale) implementing `EmailRecipientInterface`.

The global `channel_policy` and `admin_recipients` in `config/packages/notifier.yaml` are not used and are removed.

### Declarative definitions

Each type declares its defaults with a PHP attribute on its notification class:

```php
#[NotificationDefinition(
    type: 'import.run_finished',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    priority: NotificationPriority::Normal,
    configurable: true,
    mandatoryChannels: [],
    accessScope: NotificationAccessScope::Hospital,
    deduplicationTtl: 'P7D',
)]
final class ImportRunFinishedNotification extends AppNotification
{
    // translation keys, email template, action route
}
```

Definitions are collected at container compile time (autoconfigured tag + tagged iterator) into a `NotificationRegistry`. Type identifiers are dotted strings. Categories, channels, priorities and access scopes are enums; `NotificationPriority` maps onto Notifier's importance levels. A notification class lives in the context that owns the event (e.g. `Import\Infrastructure\Notification\…`). The attribute, base class and enums live in `Notification\Application`.

A compiler pass **rejects invalid definitions** and fails the container build when:

- a type name is used twice,
- a default or mandatory channel is not in `availableChannels`,
- toast is available or default without in-app,
- a non-configurable type has no default channel,
- the deduplication TTL is shorter than the reconciliation window (see Delivery semantics).

`NotificationChannel::Toast` is a user-facing option, not a Notifier channel. It is a property of the in-app instance.

### One publishing port

```php
interface NotificationPublisherInterface
{
    public function publish(NotificationRequest $request): void;
}
```

`NotificationRequest` carries the recipients (user ids or a role-based selector), the type, translation parameters, an optional action (route name + parameters), the access-scope reference (e.g. hospital id), metadata and a **deduplication key**. Producing contexts keep their own application events. Their subscribers translate them into a request and call the port, exactly like `UserActivityRecorderInterface` (ADR 014). Notifications and Activity entries are produced side by side and never depend on each other.

### Delivery semantics

#### Publishing

Per recipient, the publisher runs **one database transaction** on the default connection:

1. Resolve the effective channels (see Preferences). If none remain, stop.
2. Insert the deduplication key `(recipient_id, key, expires_at)` with `ON CONFLICT DO NOTHING`.
3. If the key was inserted:
   - insert the instance (visible in the inbox if in-app is resolved, with a toast window if toast is resolved),
   - insert one delivery row per external channel with status `pending`,
   - dispatch one `DeliverNotificationMessage(deliveryId)` per delivery,
   - update the recipient's inbox state (see Polling).

   Because the Doctrine transport writes to `messenger_messages` on the same connection, the instance, its deliveries and their messages are committed together or not at all.
4. If the key already existed, nothing new is created. Instead, the publisher re-dispatches deliveries of the existing instance that are still `pending` and older than a short grace period (5 minutes). A duplicate prevents a second *creation*. It never prevents an unfinished delivery from being resumed.

The transaction is wrapped explicitly in the publisher and must stay on `doctrine://default`. Moving Messenger to another transport (Redis, AMQP) would break the atomicity and requires revisiting this ADR.

#### Delivery

`DeliverNotificationMessage` is handled asynchronously on `async_priority_low`:

1. Load the delivery. If its status is not `pending`, stop (idempotent).
2. In one transaction: call Notifier with exactly that channel, then set the status to `queued`. For email, `EmailChannel` dispatches `SendEmailMessage` to `async_mail` on the same connection, with a correlation header `X-Notification-Delivery-Id`.
3. A subscriber modelled on `MonthlyReminderDeliverySubscriber` sets `sent` on `SentMessageEvent` and `failed` on `WorkerMessageFailedEvent` when no retry follows.

Delivery statuses have distinct meanings:

| Status | Meaning |
|--------|---------|
| `pending` | The delivery job is stored but not yet handed to the channel |
| `queued` | Handed to the channel's queue (for email: `SendEmailMessage` stored) |
| `sent` | Accepted by the channel's transport (for email: the SMTP server). This does not prove the mail reached the inbox |
| `failed` | Given up for good: permanent error or retries exhausted. Stores a reason code, not the raw error |

A scheduled reconciliation task (hourly) re-dispatches `pending` deliveries older than 15 minutes. Delivery failures never change the business result of the event; an import stays completed even if its mail fails.

#### Gap between business change and publishing

Events are dispatched after the business transaction has committed. A crash in between loses the publish call. This is handled per workflow:

- **Imports and batches** are reconciled. An hourly task re-publishes the outcome of every import run and batch that reached a terminal state within the last 24 hours. Deduplication turns already-published outcomes into no-ops, so lost ones are recovered within about an hour.
- **All other types** (participation, admin notifications, reminders) accept this loss consciously. The window is a few milliseconds, and an outbox for these cases would cost more than it saves.

#### Guarantees

| Aspect | Guarantee |
|--------|-----------|
| Instance per deduplication key, within its TTL | at most once |
| Instance and its delivery jobs | created atomically |
| Email | at least once after the job is stored. Rare duplicates are possible if a worker crashes after the SMTP handoff and before Messenger acknowledges the message |
| Toast | best effort, claimed at most once |
| Event → notification for imports and batches | recovered by reconciliation within about an hour |
| Event → notification for other types | lost if the process crashes between commit and publish; accepted |

### Persistence

PostgreSQL tables:

**`notification`** (instance / inbox entry)

- recipient, type, category, priority, `created_at`, `read_at`, `removed_at`
- `in_app_visible` (false when the user only gets external channels)
- **translation keys and parameters**, not rendered text, so the text follows the reader's current locale
- action as **route name + parameters**, not a URL. The URL is generated at render time, and opening it goes through normal authorization.
- access-scope reference (e.g. `hospital_id`) and small JSON metadata (reference type and id only)
- toast fields: `toast_pending_until`, `toast_claimed_at`
- index `(recipient_id, created_at DESC, id DESC)` for the inbox
- partial index `(recipient_id) WHERE read_at IS NULL AND removed_at IS NULL AND in_app_visible`
- partial index on `toast_pending_until WHERE toast_claimed_at IS NULL`

**`notification_delivery`**: instance, channel, status, attempt count, reason code, `created_at`, `status_changed_at`. Unique `(notification_id, channel)`.

**`notification_deduplication`**: recipient, key, `expires_at`. Unique `(recipient_id, key)`. It is separate from the instance so that deleting an inbox entry does not remove the duplicate protection, and the protection does not keep inbox entries alive.

**`notification_recipient_state`**: one row per recipient with `version` (bigint) and `unread_count`. Both are updated in the same transaction as every change that is visible in the inbox or badge.

### Retention and deduplication periods

Three separate periods:

| What | Period |
|------|--------|
| Read inbox entry | deleted at the latest 30 days after `read_at` |
| Inbox entry removed by the user | hidden at once (`removed_at`), deleted by the next cleanup |
| Unread inbox entry | deleted 90 days after creation |
| Instance without inbox entry, and delivery rows | deleted 30 days after the last delivery reached `sent` or `failed` |
| Deduplication key | per definition (`deduplicationTtl`), default 7 days. It must exceed the reconciliation look-back (24 h) and the longest retry span |

A daily Scheduler task performs the cleanup. It also recounts `unread_count` so that a drift cannot persist.

**Permanent milestones are not protected by deduplication.** Whether an import is the user's *first* successful import is derived from import data each time (the earliest successfully completed import of the user). Re-publishing therefore always gives the same answer, regardless of whether the deduplication key still exists. The deduplication key only covers retries and reconciliation.

### Data minimization and access

As much as necessary, as little as possible:

- Titles, messages, metadata and emails never contain raw data: no import rows, no allocation or patient-related values, no file contents, no exception messages.
- They contain only stable identifiers and display names that the recipient is allowed to see (e.g. import name, hospital name, counts).
- Failure reasons are passed as **reason codes** mapped to translated texts, never as free-form strings. The current `ImportFailed` admin mail forwards `$e->getMessage()`; this is replaced during migration.

**Revoked access.** The stored parameters of an instance stay readable even after the recipient loses access to what they refer to. Therefore, each definition declares an access scope (`none`, `hospital`, `role`). On render (inbox, dropdown, toast), the scope is checked against the viewer's current permissions (hospital permissions per ADR 002, roles):

- `hospital` without current access: the entry is shown **redacted**. It gets a generic title ("Notification about a hospital you no longer have access to"), no names, no counts and no action link.
- `role` without the role (e.g. admin notifications after losing `ROLE_ADMIN`): the entry is hidden.

Emails that were already sent cannot be recalled; this is accepted.

### Toast delivery and active sessions

Toasts are delivered **only to active sessions**. A session is active when a browser tab of the user:

- is visible (Page Visibility API), and
- had user interaction (pointer, keyboard, scroll) within the last 10 minutes.

When an instance includes toast, `toast_pending_until = created_at + 5 minutes`. A poll from an active tab claims pending toasts with one conditional update (`… SET toast_claimed_at = now() WHERE toast_claimed_at IS NULL AND toast_pending_until > now() … RETURNING`). Tabs of the same browser coordinate via `BroadcastChannel`, so a toast is shown once.

A claim means "handed to a browser", not "seen". If the response is lost, the toast is gone and the notification only stays in the inbox. This best-effort semantics is intentional for short-lived toasts.

### Polling and its cost

Real-time delivery uses short polling at **30–60 s** (randomized jitter), not Mercure / SSE.

**Change detection.** The client sends the last `version` of its `notification_recipient_state`. That version changes with every visible change: new entry, read, read all, removal, toast creation. The newest notification id alone is not enough, because it does not change when another tab marks everything as read or an entry is removed.

- `version` unchanged: `204 No Content`. This needs only a primary-key lookup.
- `version` changed: the response contains the new version, `unread_count` and claimable toasts.

Counting, change detection and toast claiming are separate operations. Keeping the common case to a single primary-key lookup is an implementation goal, not a guarantee.

**Load.**

- **Only active tabs poll.** Hidden tabs and tabs without interaction for 10 minutes stop. They resume immediately on visibility or interaction.
- **Page loads count as polls.** The header badge and version are rendered server-side on every normal page, and the timer restarts after each navigation.
- **Lean endpoint.** The route is excluded from request analytics (`AnalyticsRequestSubscriber` skip list) and from Sentry tracing. It does not write the session, sends `Cache-Control: no-store` and is limited per user with the existing rate limiter.

Expected order of magnitude: 100 users with an active tab at the same time and a 45 s interval produce about 2 short requests per second. If this ever becomes a problem, Mercure can replace polling behind the same version and claim model.

### In-app UI

- Header bell with unread badge from `notification_recipient_state`. The dropdown content loads lazily (Turbo frame), so ordinary pages do not pay for it.
- Notification overview page with keyset pagination: mark one or all as read, remove entries.
- Reusable Tabler toast component with severity, optional action button, `aria-live`, light and dark mode.
- All queries are scoped to the current user. No route accepts another user's notification id without an ownership check.

### Preferences and resolution

Table `user_notification_preference` stores **only overrides**: `(user_id, scope ∈ {category, type}, key, channel, enabled)`. No rows are created for defaults, so new types and new users need no migration.

Effective channels for one user and type:

1. Start from the definition's `availableChannels`.
2. For each channel, take the first value found in **type override → category override → definition default**. A type override always beats a category override.
3. If the type is not `configurable`, overrides are ignored.
4. Add the definition's `mandatoryChannels` regardless of preferences.
5. Drop `toast` if `in_app` is not part of the result.

A channel the user disabled is never used unless it is mandatory. Mandatory status is declared per definition, never decided by the caller.

The settings page *Notifications* shows categories with per-channel controls and an expandable list of individual types. It uses the existing Tabler form components. Each control has three states, because a missing override and an explicit "off" mean different things:

- **Default (on / off)**: no override is stored, and the effective default is shown. Choosing it deletes the override.
- **On**: override `enabled = true`.
- **Off**: override `enabled = false`.

Mandatory channels are shown as fixed.

### Workflows

#### Participation activated (`participation.activated`)

- Email is **mandatory**; in-app is the default, with a link to the dashboard.
- Activation is always an admin action (grant link or EasyAdmin). Each activation is a new event with its own deduplication key. If an admin activates a user again after revocation, the user gets the welcome mail again. Two welcome mails in that case are accepted.
- The direct `TransactionalMailer::sendParticipationWelcomeEmail` call is removed in the same change, so the mail cannot be sent twice through two paths.

#### Import runs (`import.run_finished`)

A **run** is one deliberate processing request: an upload, `StartImportCommand`, or one item of a requeue batch.

- Each run gets a `runId` (UUID) when it is dispatched. The import stores it as `currentRunId`, and the message carries it together with the optional `initiatedByUserId` and `batchRunId`.
- **Automatic Messenger retries keep the same `runId`**, because they redeliver the same message. A deliberately started repetition always gets a new `runId`.
- A message whose `runId` is not the import's `currentRunId` is outdated. It is acknowledged without processing and cannot change the state of a newer run.
- **`Failed` means a finished run, not a failed attempt.** A retryable error leaves the run open and lets Messenger retry. The run ends as `Failed` only on a non-retryable error (thrown as `UnrecoverableMessageHandlingException`) or when retries are exhausted (`WorkerMessageFailedEvent` without retry). The handler no longer marks the import as failed before rethrowing.
- **Exactly one terminal state per run.** The transition to `Completed`, `Partial` or `Failed` is a conditional update on `currentRunId = :runId AND the run is not yet finished`. Only the process that wins this update dispatches the outcome event. `runCount` is incremented in the same update, so it counts runs, not attempts.
- The deduplication key `import.run_finished:{importId}:{runId}` does **not** contain the status. A run therefore yields one notification, whatever its outcome.

Recipients:

- For a normal run: the user who created the import (`Import::createdBy`), plus the hospital owner if that is a different user.
- For a run started by an admin: **only that admin**. A console run without `--notify-user` sends no user notification.
- Runs that belong to a batch send no notification of their own (see below).

#### First successful import (`user.first_import`)

Published by the same subscriber as `import.run_finished`, when the run is the user's earliest successfully completed import according to import data. Deduplication key `user.first_import:{userId}`. It is published next to the existing `FIRST_IMPORT` activity entry, without depending on it.

#### Batch re-imports (`import.batch_finished`)

Batch states, the suppression of per-import notifications and the summary are introduced **together**.

- `ImportBatchRunItem` stores the item's `runId` and gets terminal processing states `Completed` and `Failed`. An item is updated only by a message with the matching `runId`.
- `ImportBatchRun` gets `dispatchClosedAt` and `processingFinishedAt`. The existing status `Finished` keeps its meaning ("all dispatched"); `dispatchClosedAt` is set at the same moment. An interrupted dispatch phase is not closed until it is resumed and finished.
- **Completion check.** In the transaction that sets an item to a terminal state, the handler locks the batch row (`SELECT … FOR UPDATE`) and checks the following. If all hold, it sets `processingFinishedAt` and publishes the summary in the same transaction:
  - `dispatchClosedAt` is set,
  - no item is still open,
  - `processingFinishedAt` is still empty.

  The row lock serializes concurrent "last" items, so a completion cannot be lost between two workers. The deduplication key `import.batch_finished:{batchRunId}` additionally protects against a duplicate summary.
- The orchestrator runs the same check after closing the dispatch phase, for batches whose items all finished before the dispatch phase ended.
- The hourly reconciliation runs the check for every batch with `dispatchClosedAt` set and `processingFinishedAt` empty. Items whose messages ended in the failure transport are set to `Failed` by the retries-exhausted listener, so a batch cannot wait forever on them.
- The summary (counts of completed and failed items, link to the batch run) goes to the initiator only.

#### Monthly reminder (`engagement.monthly_reminder`, `engagement.monthly_reminder_manual`)

- `Engagement` keeps deciding *when* a reminder is due and *what* it contains, and keeps `monthly_reminder_dispatch` with its lock.
- There is still **one reminder per hospital**. Scheduled reminders use the deduplication key `engagement.monthly_reminder:{hospitalId}:{period}`.
- Default channel: email; optional in-app with a link to the report.
- `receivesMonthlySubmissionReminder = false` is migrated to a type override. The column is dropped in a later release.
- Reminders sent manually by an admin use the separate, non-configurable type `engagement.monthly_reminder_manual` with mandatory email. This keeps today's behaviour (admin sends despite opt-out) declarative instead of a bypass flag.
- Every manual action has its own **request id**, generated when the admin form is rendered and submitted with it. Repeating the same submission (double click, retry) keeps the id. A deliberate second reminder is a new action with a new id. Key: `engagement.monthly_reminder_manual:{hospitalId}:{requestId}`. The existing guard against resending within 5 minutes stays as a business rule in `Engagement`.

#### Admin notifications (`admin.user_registered`, `admin.import_failed`, `admin.feedback_received`)

- They move into the same system, with access scope `role`. Their recipients are resolved from roles (e.g. `ROLE_ADMIN` + `ROLE_FEEDBACK_RECIPIENT`) by a recipient resolver in `User`. Each admin gets their own instance, so admins also see these in their inbox.
- `admin.import_failed` is published only for terminally failed runs.
- `AdminNotificationSender`, `AdminNotification*` and the feedback admin mailer are removed once migrated.

### Scope boundaries

Email verification, password reset, account recovery and any other authentication-critical mail stay outside the system. They keep using `TransactionalMailer` and never depend on preferences.

### Overload protection

- Defaults are conservative: routine events in-app only; successful background work in-app + toast; important failures may add email; recurring information uses one channel.
- Deduplication keys are deterministic per business fact.
- Bulk operations publish one summary instead of one notification per item.

### Transition for queued import messages

Import messages dispatched before the deploy have no `runId`. `runCount` is not a usable substitute, because it is not stable across retries today. The transition is explicit:

1. Before deploying the import-run change, wait until the import queues are empty (`messenger:stats` shows no pending `async_priority_high` messages), then stop the worker and deploy.
2. A message without `runId` that still arrives is processed with the old behaviour, publishes **no** notification and logs `notification.import_run.legacy_message`.

### Rollout

Separate pull requests into `feature/notification-system`, within this one architecture:

1. Core: context, attribute, registry with validation, tables, publisher with transaction and deduplication, preference resolver with defaults only, Notifier configuration.
2. In-app UI: bell, dropdown, overview, read state, removal, redaction on revoked access, retention task.
3. Import run semantics (`runId`, `currentRunId`, terminal `Failed`, outdated messages), `import.run_finished`, `user.first_import`, import reconciliation; `participation.activated` in-app.
4. Batch states, suppression of per-import notifications in batches, `import.batch_finished` and batch reconciliation, together.
5. Toast component, activity detection, recipient version and polling endpoint.
6. Email delivery with status tracking and delivery reconciliation; migrate the participation welcome mail.
7. Preferences UI; migrate the monthly reminder and its opt-out.
8. Migrate admin notifications and the feedback admin mail.

## Consequences

**Positive:**

- One place to add a notification type; features only publish requests
- Channel mechanics, email delivery and future channels come from Symfony Notifier
- Defaults live in code and apply to every user without data migrations
- Instances and their delivery jobs are atomic; unfinished deliveries are resumed, not dropped
- Delivery semantics are explicit: which losses and duplicates can occur is documented, not hidden
- In-app history survives offline periods and mail failures
- No new infrastructure on the hosting side (no Mercure hub, no extra daemon)
- Import runs get a defined lifecycle: one terminal state per run, outdated messages cannot overwrite newer runs
- Batch re-imports get a real "finished" signal for the first time

**Negative:**

- Atomic publishing depends on Messenger staying on `doctrine://default`
- Emails can, rarely, be sent twice; notifications of non-reconciled types can, rarely, be lost
- Toasts appear with polling latency (up to about a minute) and are best effort
- Polling adds a small, steady request load per active tab
- More tables (instance, delivery, deduplication, recipient state) and a context with Deptrac layers to maintain
- The import handler's failure behaviour changes: failed attempts no longer mark the import as `FAILED` immediately, so a temporarily failing import stays "processing" until retries are exhausted
- Import messages, `Import`, `ImportBatchRun` and `ImportBatchRunItem` change shape, and the deploy needs drained import queues
- Two systems coexist during migration (direct mails and notifications); each migration PR must remove the old path
- Text is rendered at read time, so changing a translation key changes old notifications too

## Alternatives

- **Fully custom channel layer without Notifier**: rejected; it would reimplement channel dispatch and email/Messenger integration that Notifier already provides, and block easy chat/SMS/push channels later.
- **Calling Notifier directly from the publisher**: rejected; a crash between storing the instance and sending would lose the delivery while deduplication blocks the retry.
- **Notifier's global `channel_policy` for channel selection**: rejected; it is importance-based and global, while channels must follow per-type definitions and per-user preferences.
- **A general outbox for all application events**: rejected for now; reconciliation covers imports and batches, where losses matter most, at much lower cost.
- **Mercure / SSE**: rejected for now; needs an additional hub process on Uberspace and authorization for private topics. Can replace polling later behind the same version and claim model.
- **Newest notification id as change signal**: rejected; it misses read, read-all, removal and claims from other tabs or devices.
- **Deduplication key on the instance row**: rejected; it ties the duplicate protection to the inbox retention.
- **Definitions in the database**: rejected; no runtime editing is needed, and code definitions are typed, reviewable and testable.
- **Storing a full preference row per user and type**: rejected; requires migrations for every new type and user.
- **Reusing the Activity Stream (`user_activity`) as inbox**: rejected; activity is public and historical, notifications are private and have read state (ADR 014).
- **Notifications inside `Shared`**: rejected; it is a feature with entities and UI, which ADR 008 keeps out of Shared.
- **One notification per import in a batch**: rejected; a full requeue would flood the inbox.
- **Bypass flag for admin-sent reminders**: rejected in favour of a separate mandatory type, so mandatory delivery stays declarative.
- **Keeping stored notification texts visible after access is revoked**: rejected; names and counts of a hospital would remain readable without permission.

## References

- [002-hospital-permission-bitmask.md](002-hospital-permission-bitmask.md)
- [003-doctrine-messenger-transport.md](003-doctrine-messenger-transport.md)
- [007-bounded-contexts-and-dependency-directions.md](007-bounded-contexts-and-dependency-directions.md)
- [008-shared-platform-and-domain-framework-coupling.md](008-shared-platform-and-domain-framework-coupling.md)
- [009-ports-repositories-query-objects-and-naming.md](009-ports-repositories-query-objects-and-naming.md)
- [010-architecture-guardrails-and-beta-scope.md](010-architecture-guardrails-and-beta-scope.md)
- [014-persisted-user-activity-projection.md](014-persisted-user-activity-projection.md)
- [../../05-operations/transactional-mail.md](../../05-operations/transactional-mail.md)
- [Symfony Notifier](https://symfony.com/doc/current/notifier.html)
- [Symfony Messenger: idempotent handlers](https://symfony.com/doc/current/messenger.html#creating-idempotent-handlers)
- Issue [#695](https://github.com/nplhse/collaborative-ivena-statistics/issues/695)
