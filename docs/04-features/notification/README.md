# Notifications

Personal notifications for users: a persistent in-app inbox, toasts for active sessions and, later, email. The architecture is decided in [ADR 016](../../02-architecture/decisions/016-notification-system.md); this page describes what exists today and how to use it.

## Status

| Part | State |
|------|-------|
| Definitions, registry, validation | available |
| Publisher with deduplication and recipient state | available |
| Channel resolution (defaults, mandatory channels, overrides) | available; overrides are not stored yet |
| In-app UI (bell, inbox) | planned (step 2) |
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
- **Errors:** invalid requests throw. Storage errors for a single recipient are logged as `notification.publish_failed` and never thrown, so they cannot change the outcome of the caller.

## Storage

| Table | Purpose |
|-------|---------|
| `notification` | Inbox entry per recipient (translation keys and parameters, action route, toast window) |
| `notification_deduplication` | Duplicate protection per recipient and key, independent of the inbox entry |
| `notification_recipient_state` | `version` and `unread_count` per recipient for badge and polling |

The publisher writes these tables with DBAL inside one transaction per recipient (a savepoint when the caller already has a transaction open), so it never flushes pending entity changes of the caller.

## Related documentation

- [ADR 016](../../02-architecture/decisions/016-notification-system.md)
- [../../02-architecture/extension-points.md](../../02-architecture/extension-points.md)
