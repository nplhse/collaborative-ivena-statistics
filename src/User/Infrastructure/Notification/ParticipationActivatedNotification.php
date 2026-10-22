<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * An administrator activated the user's participation (ADR 016). The welcome email stays on its
 * current path until email delivery exists (step 6), then it becomes this type's mandatory email.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: 'participation.activated',
    category: NotificationCategory::Account,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Toast],
)]
final class ParticipationActivatedNotification extends AppNotification
{
}
