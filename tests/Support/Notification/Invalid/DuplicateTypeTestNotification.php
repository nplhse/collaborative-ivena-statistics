<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification\Invalid;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

#[NotificationDefinition(
    type: 'test.in_app',
    category: NotificationCategory::Announcements,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp],
)]
final class DuplicateTypeTestNotification extends AppNotification
{
}
