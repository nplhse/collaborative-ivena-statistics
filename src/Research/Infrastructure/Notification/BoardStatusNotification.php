<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * How requests go on, for Board members (ADR 045): a veto stopped a request, or the applicant withdrew it. Parameter
 * `kind`: vetoed, withdrawn.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(type: self::TYPE, category: NotificationCategory::Research, defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email], availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast], accessScope: NotificationAccessScope::Role, translationDomain: 'research', requiredRole: 'ROLE_BOARD_MEMBER', position: 3)]
final class BoardStatusNotification extends AppNotification
{
    public const string TYPE = 'research.board_status';
}
