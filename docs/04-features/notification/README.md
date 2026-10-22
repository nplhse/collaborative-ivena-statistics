# Notifications

Personal notifications for users: a persistent in-app inbox, toasts for active sessions and, later, email. The architecture is decided in [ADR 016](../../02-architecture/decisions/016-notification-system.md); this page describes what exists today and how to use it.

## Status

| Part | State |
|------|-------|
| Definitions, registry, validation | available |
| Publisher with deduplication and recipient state | available |
| Channel resolution (defaults, mandatory channels, overrides) | available; overrides are not stored yet |
| In-app UI (bell, dropdown, overview, read/remove) | available |
| Retention and daily recount | available |
| Toasts and polling | planned (step 5) |
| Email delivery | planned (step 6) |
| Preferences UI | planned (step 7) |

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

3. Add the translations `{type}.title` and `{type}.message` to the translation domain of the definition (default: `notification`).
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

- **Bell:** `NotificationBell` in the header shows an unread dot from `notification_recipient_state` (one primary-key lookup); the count is in its label. Its dropdown loads lazily from `/notifications/dropdown` and shows the unread count and the **5 newest** entries.
- **Overview:** `/notifications` lists entries newest first, **25 per page**, with the tabs *All* and *Unread* (`?filter=unread`). "Older" and "Newer" page with keyset cursors on `created_at, id` (`?before=` / `?after=`); filter and page are kept after every action. A notice below the list explains the retention periods.
- **Actions:** open (`GET /notifications/{id}/open` marks the entry as read and redirects to its stored route), mark as read, mark all as read, remove. The POST actions are CSRF-protected and only accept same-site return paths.
- **Ownership:** every query is scoped to the current user; entries of other users are not found (404).

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

- deletes read entries 30 days after they were read, unread entries 90 days after creation, and removed entries;
- deletes expired deduplication keys;
- recounts `unread_count` per recipient with the visibility rules above and increments `version` where it changed.

## Trying it locally

No production type publishes notifications yet. In `dev`, the command `app:notification:demo` creates demo entries for a user through the real publisher:

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

The publisher writes these tables with DBAL inside one transaction per recipient (a savepoint when the caller already has a transaction open), so it never flushes pending entity changes of the caller.

## Related documentation

- [ADR 016](../../02-architecture/decisions/016-notification-system.md)
- [../../02-architecture/extension-points.md](../../02-architecture/extension-points.md)
