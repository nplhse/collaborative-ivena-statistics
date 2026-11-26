<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A new comment (ADR 045): on a request the Board member or administrator took part in, or on the own request of the
 * applicant, publicly or privately. Parameter `own`: 1 for the applicant.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Research,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    translationDomain: 'research', position: 4,
)]
final class CommentedNotification extends AppNotification
{
    public const string TYPE = 'research.commented';
}
