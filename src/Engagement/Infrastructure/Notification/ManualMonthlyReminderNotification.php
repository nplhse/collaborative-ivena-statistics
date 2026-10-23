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
 * A monthly reminder an administrator sends by hand (ADR 016). Not configurable, email is mandatory:
 * the admin sends it on purpose, also to owners who switched off the scheduled reminder.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: MonthlyReminderNotificationType::MANUAL,
    category: NotificationCategory::Reports,
    defaultChannels: [NotificationChannel::Email],
    availableChannels: [NotificationChannel::Email],
    configurable: false,
    mandatoryChannels: [NotificationChannel::Email],
    accessScope: NotificationAccessScope::Hospital,
)]
final class ManualMonthlyReminderNotification extends AppNotification
{
    public const string TYPE = MonthlyReminderNotificationType::MANUAL;
}
