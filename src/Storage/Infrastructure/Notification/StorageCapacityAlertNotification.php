<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPriority;

/**
 * The storage capacity level changed (ADR 017, "Notifications"). One type for rising and falling levels,
 * so administrators configure storage alerts once; the email is {@see StorageEmailCustomizer}.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    priority: NotificationPriority::High,
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class StorageCapacityAlertNotification extends AppNotification
{
    public const string TYPE = 'storage.capacity_alert';
}
