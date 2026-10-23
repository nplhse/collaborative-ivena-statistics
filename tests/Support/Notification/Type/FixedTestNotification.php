<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

#[NotificationDefinition(
    type: 'test.fixed',
    category: NotificationCategory::Announcements,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    configurable: false,
)]
final class FixedTestNotification extends AppNotification
{
}
