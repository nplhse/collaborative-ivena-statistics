# Notifications

Personal notifications for users: a persistent in-app inbox, toasts for active sessions and, later, email. The architecture is decided in [ADR 016](../../02-architecture/decisions/016-notification-system.md); this page describes what exists today and how to use it.

## Status

| Part | State |
|------|-------|
| Definitions, registry, validation | available |
| Publisher with deduplication and recipient state | available |
| Channel resolution (defaults, mandatory channels, overrides) | available |
| In-app UI (bell, dropdown, overview, read/remove) | available |
| Retention and daily recount | available |
| Toasts and polling | available |
| Import notifications incl. batch summary | available |
| Email delivery | available |
| Preferences (storage and settings page) | available |
| Monthly reminder migration and its opt-out | planned (step 7b) |

## Notification types

| Type | Category | Default channels | Recipients | Published when |
|------|----------|------------------|------------|----------------|
| `import.completed` | Imports | in-app, toast | upload: creator and hospital owner; console run: its initiator; batch runs: nobody (see `import.batch_finished`) | an import run ends as completed or partial |
| `import.failed` | Imports | in-app, toast | as above | an import run ends as failed (text from the reason code) |
| `import.batch_finished` | Imports | in-app, toast | the batch's `--notify-user` (admins only; hidden without `ROLE_ADMIN`) | the last item of a batch re-import finished ([details](../import/batch-requeue.md#completion-and-summary)) |
| `user.first_import` | Imports | in-app, toast | the import's creator | the creator's first successful import is recorded |
| `participation.activated` | Account | in-app, toast, email (mandatory: the welcome mail) | the activated user | an administrator activates participation |

Import types are hospital-scoped (redacted after access is revoked) and link to the import. One run yields at most one import notification (deduplication key `import.run_finished:{runId}`); superseded runs and runs from messages queued before import runs existed yield none.

The first-import milestone is stored in `user_import_milestone` (Import context) in the same transaction as the run's end and is never recorded twice. The migration marks users who already had a successful import, so existing users are not congratulated after the deploy.

## Email delivery

When a type resolves the email channel for a recipient, the publisher stores one row in `notification_delivery` (`pending`) and dispatches `DeliverNotificationMessage` (`async_priority_low`) in the same transaction. Types that resolve only email get an instance outside the inbox (`in_app_visible = false`) and do not touch the badge.

- `DeliverNotificationMessageHandler` locks the delivery (`SELECT … FOR UPDATE`), stops unless it is still `pending`, builds the email and hands it to Symfony Notifier with exactly the email channel. Notifier stores a `SendEmailMessage` on `async_mail`; the delivery becomes `queued` in the same transaction.
- `NotificationDeliveryStatusSubscriber` sets `sent` when the mail transport accepted the email and `failed` when the email (`transport_failed`) or the delivery job (`delivery_job_failed`) is given up. Recipients without a usable address or with a disabled account fail with `recipient_unavailable`. Failed deliveries are never restarted; they never change the business result.
- The email is built by `NotificationEmailFactory` in the recipient's locale: by default the generic `@Notification/email/notification.html.twig` with title, message and an absolute link to the entry. A context can give a type its own mail with a `NotificationEmailCustomizerInterface` (e.g. the participation welcome mail in User). The header `X-Notification-Delivery-Id` connects the mail events with the delivery.
- Publishing the same deduplication key again creates nothing new but dispatches deliveries of that instance that are still `pending` after 5 minutes. The hourly reconciliation dispatches deliveries that are still `pending` after 15 minutes.
- Local: in `dev`, `DeliverNotificationMessage` and `SendEmailMessage` run synchronously in the request that publishes the notification, so emails reach Mailpit without a worker. Start the app with the Symfony CLI (`symfony serve`, `symfony console`), which provides `MAILER_DSN` for Mailpit; plain `bin/console` uses `null://null` from `.env`. In production both stay asynchronous.

## Recovery

Notifications are published after the business transaction commits. If the process dies in between, the hourly job `ReconcileNotificationsMessage` (minute 17) re-publishes the outcomes of the last 24 hours from durable records; deduplication skips those that were delivered. Producers take part through `NotificationReconcilerInterface` (Import: finished `import_run` rows, recorded milestones and finished batches; backfilled milestones without a run are never announced; batches that were closed but never completed are completed).

After a longer outage, reconcile a chosen range by hand. It may not reach further back than the shortest deduplication period (7 days by default):

```bash
php bin/console app:notification:reconcile --from="2026-10-20 08:00" --to="2026-10-21 08:00"
```

Without options the command covers the last 24 hours, like the job.

## Adding a notification type

1. Create a class in the context that owns the event, e.g. `src/Import/Infrastructure/Notification/`, that extends `AppNotification`.
2. Declare the type with `#[NotificationDefinition]`:

```php
#[NotificationDefinition(
    type: 'import.run_finished',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    accessScope: NotificationAccessScope::Hospital,
)]
final class ImportRunFinishedNotification extends AppNotification
{
}
```

3. Add the translations `{type}.title`, `{type}.message` and `{type}.label` (name on the settings page) to the translation domain of the definition (default: `notification`).
4. Publish from a subscriber of the context's own application event through `NotificationPublisherInterface`:

```php
$this->notificationPublisher->publish(new NotificationRequest(
    type: 'import.run_finished',
    recipientUserIds: [$userId],
    deduplicationKey: sprintf('import.run_finished:%s', $runId),
    parameters: ['importName' => $importName],
    action: new NotificationAction('app_import_show', ['id' => $importId]),
    scopeReferenceId: $hospitalId,
));
```

The class is found automatically: `NotificationDefinitionPass` collects every class with the attribute at container compile time and fails the build when a definition is invalid (duplicate type, default channel outside the available channels, toast without in-app, deduplication TTL not longer than 24 hours, …).

## Rules for producers

- **Deduplication key:** deterministic per business fact. Publishing the same key for the same recipient again within the TTL (default 7 days) creates nothing.
- **No raw data:** parameters and metadata contain only stable identifiers, display names the recipient may see, counts and reason codes. Never exception messages, import rows or file contents.
- **Hospital scope:** types with `accessScope: Hospital` need `scopeReferenceId` (the hospital id).
- **Role scope:** types with `accessScope: Role` declare `requiredRole` (e.g. `ROLE_ADMIN`) in the definition.
- **Errors:** invalid requests throw. Storage errors for a single recipient are logged as `notification.publish_failed` and never thrown, so they cannot change the outcome of the caller.

## Inbox

- **Bell:** `NotificationBell` in the header shows an unread dot from `notification_recipient_state` (one primary-key lookup); the count is in its label. Its dropdown loads lazily from `/notifications/dropdown` and shows the unread count, the **5 newest unread** entries with their relative time (fewer if fewer are unread) and a link to the overview with the total number of entries.
- **Overview:** `/notifications` lists entries newest first, **25 per page**, with the tabs *All* and *Unread* (`?filter=unread`). "Older" and "Newer" page with keyset cursors on `created_at, id` (`?before=` / `?after=`); filter and page are kept after every action. A notice below the list explains the retention periods.
- **Single entries:** open (`GET /notifications/{id}/open` marks the entry as read and redirects to its stored route), mark as read, remove.
- **Action bar** above the list, one place for all other actions:
  - without a selection: *mark all as read* and *remove all* (confirmation dialog). With the *Unread* filter active, *remove all* only removes unread entries; read ones stay.
  - with a selection (one by one or all on the page): *mark as read*, *remove* (confirmation) and *clear selection* (`POST /notifications/bulk`, at most 100 ids per request; ids of other users are ignored).
  - the switch between both states is the Stimulus controller `notification-bulk`.
- The POST actions are CSRF-protected and only accept same-site return paths.
- **Ownership:** every query is scoped to the current user; entries of other users are not found (404).

### Toasts and polling

Types with the toast channel get a 5-minute toast window when they are published (`toast_pending_until`). The bell keeps itself current by short polling; toasts are shown only to **active** browser contexts.

- **Active** means the tab is visible and the user interacted (pointer, keyboard, scroll) within the last 10 minutes. The time of the last interaction is kept in `localStorage` (`notification:last-interaction`), so it survives navigation and is shared by the user's tabs.
- **Initial render:** every page renders `version` and the unread dot through `NotificationSnapshotProvider` and never claims toasts. Once the context is active, the Stimulus controller `notification-poll` polls immediately, then every 30–60 s (random jitter). Inactive tabs stop and resume on interaction or visibility. Page loads within 10 s of the last poll of any tab reuse it.
- **Endpoint:** `GET /notifications/poll?version=N` claims pending toasts independently of the version (`toast_claimed_at`, one conditional update, at most once per toast) and answers `204 No Content` when the version is unchanged and no toast was claimed. Otherwise the JSON carries `version`, `unreadCount`, `badgeLabel`, up to 3 toasts and a summary of further ones (`more`). Toast texts follow the inbox visibility rules: hidden entries are left out, redacted ones keep their generic text.
- **Load:** the endpoint sends `Cache-Control: no-store`, is skipped by request analytics and Sentry tracing, and is limited to 30 requests per minute and user (`notification_poll`, `429` with `Retry-After`).
- **Tabs:** results are shared via `BroadcastChannel` (`app-notifications`), so other tabs update their badge without polling. When the version changes, a dropdown that was already opened reloads.
- **Display:** the Stimulus controller `notification-toasts` shows toasts bottom right (`#notification-toasts`, kept across Turbo visits). Severity follows the priority (low: secondary, normal: primary, high: warning, urgent: danger). High and urgent toasts use `role="alert"` and stay until closed; the others hide after 10 s unless hovered or focused. A claim means "handed to a browser", not "seen": a lost response leaves the entry only in the inbox.

### Visibility after permission changes

Permissions are checked whenever entries are rendered:

| Access scope | Viewer lost access | Shown as |
|--------------|--------------------|----------|
| `none` | – | normal entry |
| `hospital` | no `View` permission on the hospital any more | generic text without names, counts or link |
| `role` | the definition's `requiredRole` is not reachable any more | hidden |

Hospital access comes from `NotificationHospitalAccessInterface`, implemented in Allocation with `HospitalPermissionAccess`. Badge counts may lag behind permission changes until the daily recount.

### Retention

`CleanUpNotificationsMessage` runs daily at 02:45 (scheduler):

- deletes read entries 30 days after they were read, unread entries 90 days after creation, removed entries and instances without inbox entry, but only when all their deliveries reached `sent` or `failed` at least 30 days ago (removing an entry never cancels its email);
- deletes expired deduplication keys;
- recounts `unread_count` per recipient with the visibility rules above and increments `version` where it changed.

## Preferences

Users choose how they want to be notified on **Settings → Notifications** (`/settings/notifications`, `NotificationSettingsController` in the Notification context; the page uses the settings layout of the User context).

- **Layout:** one table per category with a column per channel (in-app, email, toast; only channels some offered type has). The first row sets the whole category; *Set n notifications individually* expands the rows of its types. Categories with type overrides start expanded.
- **Three states per control:** *Default (on/off)* stores nothing and shows what applies without an override; *On* and *Off* store an override. For a type, the default is the category override or else the type's default; for a category it is the types' common default, or *per notification* when they differ.
- **Fixed cells:** mandatory channels show *Always*; channels of non-configurable types show their fixed state; channels a type does not offer show a dash. A category control covers only the configurable types that offer the channel and do not make it mandatory.
- **Access:** role-scoped types (e.g. `import.batch_finished`) are listed only for users with the required role (role hierarchy included). The form only accepts controls it rendered, so other types cannot be configured by a crafted request.
- **Monthly reminder:** the existing opt-out (`User::receivesMonthlySubmissionReminder`) is shown on the same page and saved with the same button until it is migrated to a type override (step 7b).

Resolution when publishing, per channel: type override → category override → definition default; overrides of non-configurable types are ignored, mandatory channels are always added, and toast is dropped without in-app (`NotificationChannelResolver`). The publisher reads the overrides through `NotificationPreferenceStoreInterface` (`DbalNotificationPreferenceStore`).

Email can already be chosen for types that offer it; it is delivered once email delivery (step 6) is available.

## Trying it locally

Real entries come from imports (upload a file and let the worker process it) and from activating participation. For a quick look without that, the `dev` command `app:notification:demo` creates demo entries for a user through the real publisher:

```bash
php bin/console app:notification:demo <username-or-email> --count=30
```

- `--count` normal entries with a link (default 3); more than 25 show the paging of the overview.
- One hospital-scoped entry about a hospital the user cannot access, so it is shown redacted (skipped when the user can access every hospital, e.g. admins).
- One admin-only entry, hidden for users without `ROLE_ADMIN`.
- `--clear` removes all notifications of the user first.

The command and its demo types live in `src/DataFixtures/Notification/` and exist only in `dev` and `test`.

## Storage

| Table | Purpose |
|-------|---------|
| `notification` | Inbox entry per recipient (translation keys and parameters, action route, access scope and required role, toast window) |
| `notification_deduplication` | Duplicate protection per recipient and key, independent of the inbox entry |
| `notification_recipient_state` | `version` and `unread_count` per recipient for badge and polling |
| `notification_delivery` | One external delivery (email) per instance and channel with status, attempts and reason code |
| `user_notification_preference` | Preference overrides only: `(user, scope = category or type, key, channel, enabled)`, unique per user, scope, key and channel; deleted with the user |

The publisher writes these tables with DBAL inside one transaction per recipient (a savepoint when the caller already has a transaction open), so it never flushes pending entity changes of the caller.

## Related documentation

- [ADR 016](../../02-architecture/decisions/016-notification-system.md)
- [../../02-architecture/extension-points.md](../../02-architecture/extension-points.md)
