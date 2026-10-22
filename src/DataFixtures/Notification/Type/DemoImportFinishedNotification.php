<?php

declare(strict_types=1);

namespace App\DataFixtures\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Dev-only demo type for app:notification:demo. A normal entry with a link.
 */
#[NotificationDefinition(
    type: 'demo.import_finished',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
)]
final class DemoImportFinishedNotification extends AppNotification
{
}
