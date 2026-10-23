<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

#[NotificationDefinition(
    type: 'test.mandatory_in_app',
    category: NotificationCategory::Account,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    mandatoryChannels: [NotificationChannel::InApp],
)]
final class MandatoryInAppTestNotification extends AppNotification
{
}
