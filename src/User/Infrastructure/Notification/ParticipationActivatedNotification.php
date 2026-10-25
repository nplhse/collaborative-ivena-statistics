<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * An administrator activated the user's participation (ADR 016). The email is mandatory and is the
 * welcome mail ({@see ParticipationWelcomeEmailCustomizer}).
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'participation.activated',
    category: NotificationCategory::Account,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    mandatoryChannels: [NotificationChannel::Email],
)]
final class ParticipationActivatedNotification extends AppNotification
{
}
