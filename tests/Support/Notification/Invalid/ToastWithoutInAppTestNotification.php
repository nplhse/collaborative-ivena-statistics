<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification\Invalid;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

#[NotificationDefinition(
    type: 'test.toast_without_in_app',
    category: NotificationCategory::Announcements,
    defaultChannels: [NotificationChannel::Toast],
    availableChannels: [NotificationChannel::Toast],
)]
final class ToastWithoutInAppTestNotification extends AppNotification
{
}
