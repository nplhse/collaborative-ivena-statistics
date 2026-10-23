<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Notification;

use App\Engagement\Application\MonthlyReminderNotificationType;
use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * The scheduled monthly submission reminder to a hospital's owner (ADR 016). Email by default;
 * users may switch it off or add the in-app entry on the notification settings page.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: MonthlyReminderNotificationType::TYPE,
    category: NotificationCategory::Reports,
    defaultChannels: [NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    accessScope: NotificationAccessScope::Hospital,
)]
final class MonthlyReminderNotification extends AppNotification
{
    public const string TYPE = MonthlyReminderNotificationType::TYPE;
}
