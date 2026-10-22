<?php

declare(strict_types=1);

namespace App\DataFixtures\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Dev-only demo type for app:notification:demo. Hospital-scoped, so it is redacted for viewers without access.
 */
#[NotificationDefinition(
    type: 'demo.hospital',
    category: NotificationCategory::Imports,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp],
    accessScope: NotificationAccessScope::Hospital,
)]
final class DemoHospitalNotification extends AppNotification
{
}
