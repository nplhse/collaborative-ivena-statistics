<?php

declare(strict_types=1);

namespace App\DataFixtures\Notification\Type;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Dev-only demo type for app:notification:demo. Role-scoped, so it is hidden for viewers without ROLE_ADMIN.
 */
#[NotificationDefinition(
    type: 'demo.admin_only',
    category: NotificationCategory::Administration,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp],
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class DemoAdminNotification extends AppNotification
{
}
