<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A bulk deletion from the import list finished without failures (#720).
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'import.bulk_deleted',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
)]
final class ImportBulkDeletedNotification extends AppNotification
{
}
