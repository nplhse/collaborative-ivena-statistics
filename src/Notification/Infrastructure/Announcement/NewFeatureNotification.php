<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Announcement;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Introduces a new feature to every user. The parameter `feature` selects the text, so later features
 * can be announced with the same type; users switch these announcements off like any other type.
 * The introduction of the notification system itself is published by a migration at deploy time.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: NewFeatureNotification::TYPE,
    category: NotificationCategory::Announcements,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
)]
final class NewFeatureNotification extends AppNotification
{
    public const string TYPE = 'announcement.new_feature';
}
