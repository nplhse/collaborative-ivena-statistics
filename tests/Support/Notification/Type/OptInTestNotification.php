<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

#[NotificationDefinition(
    type: 'test.opt_in',
    category: NotificationCategory::Announcements,
    defaultChannels: [],
    availableChannels: [NotificationChannel::InApp],
)]
final class OptInTestNotification extends AppNotification
{
}
