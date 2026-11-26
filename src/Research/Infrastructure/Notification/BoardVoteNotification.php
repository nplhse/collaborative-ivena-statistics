<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Votes for Board members (ADR 045): a round opened, a revision was submitted (to members whose approval was carried
 * over) or a round ends within 48 hours (to members who have not voted). Parameter `kind`: opened, revision, reminder.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(type: self::TYPE, category: NotificationCategory::Research, defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email], availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast], accessScope: NotificationAccessScope::Role, translationDomain: 'research', requiredRole: 'ROLE_BOARD_MEMBER', position: 2)]
final class BoardVoteNotification extends AppNotification
{
    public const string TYPE = 'research.board_vote';
}
