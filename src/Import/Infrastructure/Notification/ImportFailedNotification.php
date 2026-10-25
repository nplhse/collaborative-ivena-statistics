<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPriority;

/**
 * An import run failed for good (ADR 016, step 3b).
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'import.failed',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    priority: NotificationPriority::High,
    accessScope: NotificationAccessScope::Hospital,
    toastGroup: 'import.run_outcome',
    toastGroupRoute: 'app_import_index',
)]
final class ImportFailedNotification extends AppNotification
{
}
