<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A blog post an administrator sent as an announcement (#1486). On by default on every channel, so important news
 * reaches everyone; users can switch it off like any other type.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: PostAnnouncementNotification::TYPE,
    category: NotificationCategory::Announcements,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast, NotificationChannel::Email],
)]
final class PostAnnouncementNotification extends AppNotification
{
    public const string TYPE = 'announcement.post';
}
