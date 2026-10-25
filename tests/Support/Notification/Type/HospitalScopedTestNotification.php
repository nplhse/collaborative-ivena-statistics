<?php

declare(strict_types=1);

namespace App\Tests\Support\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

#[NotificationDefinition(
    type: 'test.hospital_scoped',
    category: NotificationCategory::Announcements,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp],
    accessScope: NotificationAccessScope::Hospital,
)]
final class HospitalScopedTestNotification extends AppNotification
{
}
