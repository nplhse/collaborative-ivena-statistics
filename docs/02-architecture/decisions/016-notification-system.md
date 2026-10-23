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
- An import is processed in several commits (`flush` + `clear` per chunk). The data of the previous run is removed only when the next run starts (`cleanupPreviousRun`).
- Processing errors inside a run are caught: the import is marked `FAILED`, `runCount` is incremented and the message ends without retry. Errors outside that block (cleanup of previous data, database failures) propagate, and Messenger retries the whole message from the beginning.
- The only history of individual runs is the audit log (`import.run.*` intents). It is not meant as an operational source.
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

Business facts that notifications are built from (import runs, milestones, batch outcomes) belong to the producing context, not to `Notification`.

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
    type: 'import.completed',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    priority: NotificationPriority::Normal,
    configurable: true,
    mandatoryChannels: [],
    accessScope: NotificationAccessScope::Hospital,
    deduplicationTtl: 'P7D',
)]
final class ImportCompletedNotification extends AppNotification
{
    // translation keys, email template, action route
}
```

Definitions are collected at container compile time (attribute autoconfiguration + compiler pass) into a `NotificationRegistry`. Type identifiers are dotted strings. Categories, channels, priorities and access scopes are enums; `NotificationPriority` maps onto Notifier's importance levels. A notification class lives in the context that owns the event (e.g. `Import\Infrastructure\Notification\…`). The attribute and base class live in `Notification\Application`. The enums live in `Notification\Domain`, because the entities use them; producing contexts may use `Notification_Application` and these enums.

A compiler pass **rejects invalid definitions** and fails the container build when:

- a type name is used twice,
- a default or mandatory channel is not in `availableChannels`,
- toast is available or default without in-app,
- a non-configurable type has no default channel,
- the deduplication TTL is not longer than the reconciliation window (see Recovery).

`NotificationChannel::Toast` is a user-facing option, not a Notifier channel. It is a property of the in-app instance.

### One publishing port

```php
interface NotificationPublisherInterface
{
    public function publish(NotificationRequest $request): void;
}
```

`NotificationRequest` carries the recipients (user ids or a role-based selector), the type, translation parameters, an optional action (route name + parameters), the access-scope reference (e.g. hospital id), metadata and a **deduplication key**. Producing contexts keep their own application events. Their subscribers translate them into a request and call the port, exactly like `UserActivityRecorderInterface` (ADR 014). Notifications and Activity entries are produced side by side and never depend on each other.

The publisher opens its own transaction, or joins the caller's transaction if one is active. Joining is used only where this ADR names it explicitly (batch completion).

### Delivery semantics

#### Publishing

Per recipient, the publisher runs **one database transaction** on the default connection:

1. Resolve the effective channels (see Preferences), using the recipient's permissions at this moment. If none remain, stop.
2. Claim the deduplication key (see Persistence). If the key is held by an earlier, unexpired publication, go to step 4.
3. If the key was claimed:
   - insert the instance (visible in the inbox if in-app is resolved, with a toast window if toast is resolved) and link it from the deduplication record,
   - insert one delivery row per external channel with status `pending`,
   - dispatch one `DeliverNotificationMessage(deliveryId)` per delivery,
   - update the recipient's inbox state (see Polling).

   Because the Doctrine transport writes to `messenger_messages` on the same connection, the instance, its deliveries and their messages are committed together or not at all.
4. If the key is held, nothing new is created. If the deduplication record still points to an instance, the publisher re-dispatches that instance's deliveries that are still `pending` and older than a short grace period (5 minutes). A duplicate prevents a second *creation*. It never prevents an unfinished delivery from being resumed.

The transaction must stay on `doctrine://default`. Moving Messenger to another transport (Redis, AMQP) would break the atomicity and requires revisiting this ADR.

#### Delivery

`DeliverNotificationMessage` is handled asynchronously on `async_priority_low`. Processing one delivery is **serialized by a row lock** on the delivery:

1. Start a transaction and load the delivery with `SELECT … FOR UPDATE`. This lock excludes every other process handling the same delivery id.
2. If its status is no longer `pending`, commit and stop.
3. Call Notifier with exactly that channel. For email, `EmailChannel` dispatches `SendEmailMessage` to `async_mail` on the same connection, with a correlation header `X-Notification-Delivery-Id`.
4. Set the status to `queued` in the same transaction.
5. Commit. The lock is released only with the commit.

A database row lock is used rather than Symfony Lock, because it needs no shared lock store and cannot be released before the transaction ends. It does not replace the transaction; it is part of it.

A subscriber modelled on `MonthlyReminderDeliverySubscriber` then sets `sent` on `SentMessageEvent` and `failed` on `WorkerMessageFailedEvent` when no retry follows. If `DeliverNotificationMessage` itself fails for good (retries exhausted), the same mechanism sets the delivery to `failed` with the reason code `delivery_job_failed`.

Delivery statuses have distinct meanings:

| Status | Meaning |
|--------|---------|
| `pending` | The delivery job is stored but not yet handed to the channel |
| `queued` | Handed to the channel's queue (for email: `SendEmailMessage` stored) |
| `sent` | Accepted by the channel's transport (for email: the SMTP server). This does not prove the mail reached the inbox |
| `failed` | Given up for good: permanent error or retries exhausted. Stores a reason code, not the raw error |

Reconciliation re-dispatches only `pending` deliveries. **`failed` deliveries are never restarted automatically.** Delivery failures never change the business result of the event; an import stays completed even if its mail fails.

#### Gap between business change and publishing

As a rule, events are dispatched after the business transaction has committed, and the publisher runs its own transaction. A crash in between loses the publish call. This is handled per workflow:

- **Import runs, milestones and batches** are reconciled from their durable business records (see Recovery).
- **All other types** (participation, admin notifications, reminders) accept this loss consciously. The window is a few milliseconds, and an outbox for these cases would cost more than it saves.

**Exception: batch completion.** Closing a batch and publishing its summary happen in one transaction: the transaction that sets the last item to a terminal state (see Batch re-imports). The publisher joins it. This is the only place where publishing is part of the business transaction.

#### Recovery

An hourly Scheduler task (`app:notification:reconcile`) does two things:

- It re-dispatches `pending` deliveries older than 15 minutes.
- It re-publishes the outcomes of the last **24 hours** from durable business records: terminal `ImportRun` records, recorded milestones and finished batches. It never reads the audit log. Deduplication turns already-published outcomes into no-ops.

The deduplication TTL (default 7 days) is always longer than this 24-hour window, which the registry enforces.

"Recovered within about an hour" assumes a working scheduler and worker, and an outcome inside the 24-hour window. After a longer outage, an operator runs an explicit catch-up over a chosen time range (`app:notification:reconcile --from=… --to=…`). The range may not reach further back than the shortest deduplication TTL of the affected types, because older keys may already be gone and re-publishing would create duplicates.

#### Guarantees

| Aspect | Guarantee |
|--------|-----------|
| Instance per deduplication key, within its TTL | at most once |
| Instance and its delivery jobs | created atomically |
| Delivery job processing | serialized per delivery; retried; resumed while `pending`; never restarted after `failed` |
| Email | Stored delivery jobs are processed with retries. Acceptance by the SMTP server is not guaranteed when permanent errors occur. Rare duplicate emails remain possible after transport acceptance and before message acknowledgement |
| Toast | best effort, claimed at most once |
| Import runs, milestones, batches → notification | recovered by reconciliation within about an hour, inside the 24-hour window |
| Other types → notification | lost if the process crashes between commit and publish; accepted |

### Persistence

PostgreSQL tables in the `Notification` context:

**`notification`** (instance / inbox entry)

- recipient, type, category, priority, `created_at`, `read_at`, `removed_at`
- `in_app_visible` (false when the user only gets external channels)
- **translation keys and parameters**, not rendered text, so the text follows the reader's current locale
- action as **route name + parameters**, not a URL. The URL is generated at render time, and opening it goes through normal authorization.
- access-scope reference (e.g. `hospital_id`) and small JSON metadata (reference type and id only)
- toast fields: `toast_pending_until`, `toast_claimed_at`
- index `(recipient_id, created_at DESC, id DESC)` for the inbox
- partial index `(recipient_id) WHERE read_at IS NULL AND removed_at IS NULL AND in_app_visible`
- partial index `(recipient_id, toast_pending_until) WHERE toast_claimed_at IS NULL`

**`notification_delivery`**: instance, channel, status, attempt count, reason code, `created_at`, `status_changed_at`. Unique `(notification_id, channel)`.

**`notification_deduplication`**: recipient, key, `expires_at`, nullable `notification_id` (`ON DELETE SET NULL`). Unique `(recipient_id, key)`.

- It is separate from the instance. Deleting an inbox entry does not remove the duplicate protection, and the protection does not keep inbox entries alive.
- The link to the instance lets a duplicate publication find pending deliveries while the instance exists.
- **Expired keys are taken over explicitly.** The claim is one statement: `INSERT … ON CONFLICT (recipient_id, key) DO UPDATE SET expires_at = :new, notification_id = NULL WHERE notification_deduplication.expires_at < now() RETURNING id`. A returned row means the key was claimed (new or expired). No row means an unexpired holder exists. `expires_at` alone never resolves a unique-key conflict.

**`notification_recipient_state`**: one row per recipient with `version` and `unread_count`. Both are updated in the same transaction as every change that is visible in the inbox or badge.

### Retention and deduplication periods

Three separate periods:

| What | Period |
|------|--------|
| Read inbox entry | deleted at the latest 30 days after `read_at` |
| Inbox entry removed by the user | hidden at once (`removed_at`) |
| Unread inbox entry | deleted 90 days after creation |
| Instance without inbox entry, and delivery rows | deleted 30 days after the last delivery reached `sent` or `failed` |
| Deduplication key | per definition (`deduplicationTtl`), default 7 days. Always longer than the 24-hour reconciliation window |

Hiding and deleting are separate:

- Removing an entry from the inbox only hides it. Outstanding deliveries are **not** cancelled or deleted.
- An instance is physically deleted only when its visible-retention rule applies **and** all its deliveries have reached `sent` or `failed` **and** their 30-day retention has passed.

A daily Scheduler task performs the cleanup. It also recounts `unread_count` (see Access).

### Data minimization and access

As much as necessary, as little as possible:

- Titles, messages, metadata and emails never contain raw data: no import rows, no allocation or patient-related values, no file contents, no exception messages.
- They contain only stable identifiers and display names that the recipient is allowed to see (e.g. import name, hospital name, counts).
- Failure reasons are passed as **reason codes** mapped to translated texts, never as free-form strings. The current `ImportFailed` admin mail forwards `$e->getMessage()`; this is replaced during migration.

**Permission changes are handled deliberately simply.** Each definition declares an access scope (`none`, `hospital`, `role`). Current permissions are checked whenever notifications are rendered in the inbox, dropdown or toast (hospital permissions per ADR 002, roles):

- **Hospital-scoped** entries are **redacted** after access is revoked. They get a generic title ("Notification about a hospital you no longer have access to"), no names, no counts and no action link.
- **Role-scoped** entries are **hidden** after the required role is revoked.

Further rules:

- External delivery uses the recipient's permissions at publication time. Already scheduled emails are not cancelled when permissions change later. This is an accepted limitation, and no cancellation state exists for it.
- Permission changes are not pushed into `unread_count` and `version` immediately. A temporary badge discrepancy is accepted until the daily cleanup recounts `unread_count` with the same visibility rules as the inbox (redacted entries count, hidden entries do not). When the count changes, the recount increments `version` in the same transaction.

### Toast delivery and active sessions

Toasts are delivered **only to active sessions**. A browser context is active when a tab of the user:

- is visible (Page Visibility API), and
- had user interaction (pointer, keyboard, scroll) within the last 10 minutes. The time of the last interaction is kept in `localStorage`, so it survives page navigation.

When an instance includes toast, `toast_pending_until = created_at + 5 minutes`. A poll from an active context claims pending toasts with one conditional update (`… SET toast_claimed_at = now() WHERE toast_claimed_at IS NULL AND toast_pending_until > now() … RETURNING`). Tabs of the same browser coordinate via `BroadcastChannel`, so a toast is shown once.

A claim means "handed to a browser", not "seen". If the response is lost, the toast is gone and the notification only stays in the inbox. This best-effort semantics is intentional for short-lived toasts.

### Polling and initial rendering

Real-time delivery uses short polling at **30–60 s** (randomized jitter), not Mercure / SSE.

**One fetching logic.** Initial page rendering and the polling endpoint use the same `NotificationSnapshotProvider`. It returns the current `version`, `unread_count` and, on request, claimable toasts.

**Initial rendering counts as the first poll.**

- Every normal page renders `unread_count` and `version` server-side.
- The server cannot know whether the tab is visible and recently used. The initial page therefore never claims toasts.
- Once the browser has confirmed visibility and recent interaction, it performs an **immediate initial poll** that checks for toasts. A toast created shortly before the page load is therefore not lost because the page already carries the newest version.

**Subsequent polls.** The client sends its last `version`:

- **Badge:** `version` unchanged means no badge update. This is a primary-key lookup.
- **Toasts:** checked **independently of the version**, with a separate indexed lookup on unclaimed toasts of the recipient. Equal versions never suppress an existing, unclaimed toast.
- If neither changed, the response is `204 No Content`. Otherwise it contains the new version, `unread_count` and the claimed toasts.

The newest notification id alone is not a usable change signal: it does not change when another tab marks everything as read or an entry is removed. Keeping the common case to two cheap indexed lookups is an implementation goal, not a guarantee.

**Load.**

- **Only active contexts poll.** Hidden tabs and tabs without interaction for 10 minutes stop. They resume immediately on visibility or interaction.
- **Page loads reset the timer**, because the page already carries the current badge.
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

The settings page *Notifications* shows categories with per-channel controls and an expandable list of individual types. It uses the existing Tabler form components. Each control is a checkbox that shows whether the channel is used (on / off); users are not asked about defaults. Storage still distinguishes a missing override from an explicit value:

- An untouched checkbox keeps what is stored, so types without their own setting keep following their category.
- A changed checkbox stores an override, or deletes it when the new value equals what applies without one (for a type: the category override, else its default; for a category: the common default of its types).
- Changing a category never overwrites types that were set individually.

Mandatory channels are shown as ticked, disabled checkboxes with an explanation; disabled checkboxes are not part of the form, so the server never accepts a value for them.

### Workflows

#### Participation activated (`participation.activated`)

- Email is **mandatory**; in-app is the default, with a link to the dashboard.
- Activation is always an admin action (grant link or EasyAdmin). Each activation is a new event with its own deduplication key. If an admin activates a user again after revocation, the user gets the welcome mail again. Two welcome mails in that case are accepted.
- The direct `TransactionalMailer::sendParticipationWelcomeEmail` call is removed in the same change, so the mail cannot be sent twice through two paths.

#### Import runs (`import.completed`, `import.failed`)

A **run** is one deliberate processing request: an upload, `StartImportCommand`, or one item of a requeue batch.

**Durable run record.** Every run is persisted as an `ImportRun` in the `Import` context. It is the operational source for asynchronous processing and reconciliation; the audit log stays supplementary. An `ImportRun` stores:

- `runId` (UUID), import reference, initiator (nullable user) and optional batch-item reference;
- status (`Queued`, `Running`, `Completed`, `Partial`, `Failed`, `Superseded`), `created_at`, `started_at`, `finished_at`;
- outcome data needed to rebuild its notifications, limited to counts (total, ok, rejected), stable references and a reason code.

`Import::currentRunId` points to the current run. Starting a new run adds a record and never overwrites earlier runs. Run records are kept for the lifetime of the import and deleted with it; this always covers the reconciliation window. Rebuilding a notification never depends on audit-log entries.

**Run lifecycle.**

- The message carries the `runId`. **Automatic Messenger retries keep it**, because they redeliver the same message. A deliberately started repetition always creates a new run.
- **`Failed` means a finished run, not a failed attempt.** Errors are classified:
  - Non-retryable errors (invalid or missing file, validation, rejection ratio exceeded) end the run as `Failed` with a reason code.
  - Retryable errors (lost database connection, deadlock, lock timeout) are rethrown and leave the run open. If retries are exhausted (`WorkerMessageFailedEvent` without retry), the run ends as `Failed` with the reason code `retries_exhausted`.
- **Exactly one terminal state per run.** The transition to `Completed`, `Partial`, `Failed` or `Superseded` is a conditional update on the `ImportRun` (`status` not yet terminal). Only the process that wins this update dispatches the outcome event. `Import::runCount` is incremented in the same transaction, so it counts finished runs, not attempts.
- Completed and partial runs use the type `import.completed`, failed runs `import.failed`, so users can set preferences for both separately. Both share the deduplication key `import.run_finished:{runId}`, which does **not** contain the status. A run therefore yields at most one notification, whatever its outcome.

**Superseded runs.**

- When a new run is registered for an import whose current run is not finished, the previous run is set to `Superseded` **in the same transaction** that sets `currentRunId` to the new run. If the previous run belongs to a batch item, that item becomes `Superseded` in the same transaction.
- A message of a superseded run is acknowledged without processing.
- The terminal-state update alone does not protect the data. Therefore **every commit of processing results** (each chunk, the rejects and the final evaluation) first locks the import row and checks that `currentRunId` still equals the message's `runId`. If not, the chunk is rolled back and processing stops. This check and the registration of a new run use the same row lock, so they are serialized.
- Chunks that a superseded run committed before the new run was registered are removed by the new run's existing cleanup of previous data, which runs at its start.
- Superseded runs produce no notification.

**Recipients.**

- For a normal run: the user who created the import (`Import::createdBy`), plus the hospital owner if that is a different user.
- For a run started by an admin: **only that admin**. A console run without `--notify-user` sends no user notification.
- Runs that belong to a batch send no notification of their own (see below).

#### First successful import (`user.first_import`)

The first successful import is a **durable business fact**, not something derived later:

- Table `user_import_milestone` in the `Import` context: `user_id`, `milestone_type`, reference to the qualifying `ImportRun` (`ON DELETE SET NULL`), `achieved_at`. Unique `(user_id, milestone_type)`.
- The milestone is recorded **in the same transaction** as the run's successful terminal transition (`Completed` or `Partial`), with `ON CONFLICT DO NOTHING`. Only its initial creation establishes it. Later runs, re-imports, deleted imports and deleted notification instances never establish it again.
- Only a newly created milestone dispatches its event. The deduplication key `user.first_import:{milestoneId}` is derived from the milestone.
- Reconciliation re-publishes milestones achieved within the 24-hour window, which is shorter than the deduplication TTL.

The notification is published next to the existing `FIRST_IMPORT` activity entry (ADR 014), without depending on it.

#### Batch re-imports (`import.batch_finished`)

Batch states, the suppression of per-import notifications and the summary are introduced **together**.

- Each `ImportBatchRunItem` references its `ImportRun`. Its terminal processing states mirror the run: `Completed`, `Partial`, `Failed`, `Superseded`. An item is updated only by the run it references.
- **`Partial` is kept as its own item outcome.** It is not merged into `Completed` or `Failed`.
- `ImportBatchRun` gets `dispatchClosedAt` and `processingFinishedAt`. The existing status `Finished` keeps its meaning ("all dispatched"); `dispatchClosedAt` is set at the same moment. An interrupted dispatch phase is not closed until it is resumed and finished.
- **All four terminal item states count as finished** for batch completion.
- **Completion check.** In the transaction that sets an item to a terminal state, the handler locks the batch row (`SELECT … FOR UPDATE`) and checks the following. If all hold, it sets `processingFinishedAt` and publishes the summary **in the same transaction** (the documented exception above):
  - `dispatchClosedAt` is set,
  - no item is still open,
  - `processingFinishedAt` is still empty.

  The row lock serializes concurrent "last" items, so a completion cannot be lost between two workers. The deduplication key `import.batch_finished:{batchRunId}` additionally protects against a duplicate summary.
- The orchestrator runs the same check after closing the dispatch phase, for batches whose items all finished before the dispatch phase ended.
- Reconciliation runs the check for every batch with `dispatchClosedAt` set and `processingFinishedAt` empty, and re-publishes summaries of batches finished within the 24-hour window.
- **Summary counts:** completed, partial, failed and superseded items, each reported separately, plus a link to the batch run. The summary goes to the initiator only.

#### Monthly reminder (`engagement.monthly_reminder`, `engagement.monthly_reminder_manual`)

- `Engagement` keeps deciding *when* a reminder is due and *what* it contains, and keeps `monthly_reminder_dispatch` with its lock.
- There is still **one reminder per hospital**. Scheduled reminders use the deduplication key `engagement.monthly_reminder:{hospitalId}:{period}`.
- Default channel: email; optional in-app with a link to the report.
- `receivesMonthlySubmissionReminder = false` is migrated to a type override. The column is dropped in a later release.
- Reminders sent manually by an admin use the separate, non-configurable type `engagement.monthly_reminder_manual` with mandatory email. This keeps today's behaviour (admin sends despite opt-out) declarative instead of a bypass flag.
- Every manual action has its own **request id**, generated when the admin form is rendered and submitted with it. Repeating the same submission (double click, retry) keeps the id. A deliberate second reminder is a new action with a new id. Key: `engagement.monthly_reminder_manual:{hospitalId}:{requestId}`. The existing guard against resending within 5 minutes stays as a business rule in `Engagement`.

#### Admin notifications (`admin.user_registered`, `admin.import_failed`, `admin.feedback_received`)

- They move into the same system, with access scope `role`. Their recipients are resolved from roles (e.g. `ROLE_ADMIN` + `ROLE_FEEDBACK_RECIPIENT`) by a recipient resolver in `User`. Each admin gets their own instance, so admins also see these in their inbox.
- `admin.import_failed` is published only for runs that ended as `Failed`, never for superseded runs.
- `admin.user_registered` and `admin.import_failed` go to `ROLE_ADMIN` + `ROLE_RECEIVES_NOTIFICATION`, `admin.feedback_received` to `ROLE_ADMIN` + `ROLE_FEEDBACK_RECIPIENT`, as the former mails did. Default channels are in-app and email; no channel is mandatory, so each admin can turn the email off.
- `AdminNotificationSender`, `AdminNotification*` and the feedback admin mailer are removed once migrated.

### Scope boundaries

Email verification, password reset, account recovery and any other authentication-critical mail stay outside the system. They keep using `TransactionalMailer` and never depend on preferences.

### Overload protection

- Defaults are conservative: routine events in-app only; successful background work in-app + toast; important failures may add email; recurring information uses one channel.
- Deduplication keys are deterministic per business fact.
- Bulk operations publish one summary instead of one notification per item.

### Transition for queued import messages

Import messages dispatched before the deploy have no `runId`. `runCount` is not a usable substitute, because it is not tied to a single run. The transition is explicit:

1. Before deploying the import-run change, wait until the import queues are empty (`messenger:stats` shows no pending `async_priority_high` messages), then stop the worker and deploy.
2. A message without `runId` that still arrives is processed with the old behaviour, creates no `ImportRun`, publishes **no** notification and logs `notification.import_run.legacy_message`.

### Rollout

Separate pull requests into `feature/notification-system`, within this one architecture:

1. Core: context, attribute, registry with validation, inbox, deduplication and recipient-state tables (including deduplication takeover), publisher with transaction, preference resolver (overrides are stored from step 7 on), Notifier configuration.
2. In-app UI: bell, dropdown, overview, read state, removal, render-time permission checks, retention and daily recount.
3. Import runs, in two pull requests:
   - **3a** Import-run lifecycle, without notifications: durable `ImportRun` history, `currentRunId`, error classification and terminal `Failed`, `Superseded`, current-run check on every commit.
   - **3b** Import notifications: `user_import_milestone`, `import.completed` / `import.failed`, `user.first_import`, reconciliation and catch-up command; `participation.activated` in-app.
4. Batch processing semantics, together: item states including `Superseded` and `Partial`, suppression of per-import notifications, completion check in one transaction, summary counts, batch reconciliation.
5. Toast component, activity detection, recipient version, shared snapshot logic, immediate initial poll and polling endpoint.
6. Email delivery: delivery table and row lock, status tracking, final-failure handling, pending-delivery reconciliation; migrate the participation welcome mail.
7. Preferences, in two pull requests:
   - **7a** Preference storage (`user_notification_preference`), resolution with stored overrides in the publisher, settings page *Notifications*. The settings page lives in the `Notification` context, which owns the preferences, and keeps the existing monthly-reminder opt-out until 7b.
   - **7b** (after step 6) Migrate the monthly reminder and its opt-out.
8. Migrate admin notifications and the feedback admin mail.

## Consequences

**Positive:**

- One place to add a notification type; features only publish requests
- Channel mechanics, email delivery and future channels come from Symfony Notifier
- Defaults live in code and apply to every user without data migrations
- Instances and their delivery jobs are atomic; unfinished deliveries are resumed, not dropped; one delivery is never processed twice at the same time
- Delivery semantics are explicit: which losses and duplicates can occur is documented, not hidden
- In-app history survives offline periods and mail failures
- No new infrastructure on the hosting side (no Mercure hub, no extra daemon, no lock store)
- Import runs get a durable history and a defined lifecycle: one terminal state per run, and superseded runs cannot overwrite data of newer runs
- The first successful import is a durable fact instead of a derivation
- Batch re-imports get a real "finished" signal for the first time

**Negative:**

- Atomic publishing depends on Messenger staying on `doctrine://default`
- Emails can, rarely, be sent twice; notifications of non-reconciled types can, rarely, be lost
- Recovery depends on a working scheduler; outages longer than 24 hours need a manual catch-up, limited by the deduplication TTL
- Toasts appear with polling latency (up to about a minute) and are best effort
- Polling adds a small, steady request load per active tab
- Badge counts can lag behind permission changes until the daily recount
- More tables (instance, delivery, deduplication, recipient state, import run, milestone) and a context with Deptrac layers to maintain
- The import handler changes substantially: error classification, retryable errors no longer end a run immediately, and every chunk commit takes a row lock on the import
- Import messages, `Import`, `ImportBatchRun` and `ImportBatchRunItem` change shape, and the deploy needs drained import queues
- Batch completion publishes inside the business transaction, an intentional exception to the after-commit rule
- Two systems coexist during migration (direct mails and notifications); each migration PR must remove the old path
- Text is rendered at read time, so changing a translation key changes old notifications too

## Alternatives

- **Fully custom channel layer without Notifier**: rejected; it would reimplement channel dispatch and email/Messenger integration that Notifier already provides, and block easy chat/SMS/push channels later.
- **Calling Notifier directly from the publisher**: rejected; a crash between storing the instance and sending would lose the delivery while deduplication blocks the retry.
- **Symfony Lock for delivery processing**: rejected; it needs a lock store shared by all workers and can be released before the transaction ends. The row lock has neither problem.
- **Notifier's global `channel_policy` for channel selection**: rejected; it is importance-based and global, while channels must follow per-type definitions and per-user preferences.
- **A general outbox for all application events**: rejected for now; reconciliation from durable business records covers imports, milestones and batches, where losses matter most, at much lower cost.
- **Audit log as source for reconciliation**: rejected; it is supplementary and not designed as an operational record.
- **Deriving the first import from import data**: rejected; deleted imports and re-imports can change the answer. A recorded milestone cannot.
- **Mercure / SSE**: rejected for now; needs an additional hub process on Uberspace and authorization for private topics. Can replace polling later behind the same version and claim model.
- **Newest notification id as change signal**: rejected; it misses read, read-all, removal and claims from other tabs or devices.
- **Deduplication key on the instance row**: rejected; it ties the duplicate protection to the inbox retention.
- **Cancelling scheduled emails on permission changes**: rejected; it needs an extra cancellation state for a rare case.
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
