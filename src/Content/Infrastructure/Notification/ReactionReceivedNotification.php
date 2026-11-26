<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Reactions to a user's post, comment or activity, grouped per target (#973). In-app only: it offers neither email
 * nor toast, so reactions can never interrupt anyone.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: ReactionReceivedNotification::TYPE,
    category: NotificationCategory::Community,
    defaultChannels: [NotificationChannel::InApp],
    availableChannels: [NotificationChannel::InApp],
)]
final class ReactionReceivedNotification extends AppNotification
{
    public const string TYPE = 'content.reaction_received';

    /** Replaces the names in an entry once one of the people named in it has deleted their account. */
    public const array WITHOUT_NAMES = ['actors' => 'none', 'firstActor' => null, 'secondActor' => null, 'others' => 0, 'actorIds' => null];
}
