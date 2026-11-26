<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * A data extract request was submitted and waits for the formal check; to Board members. Off by default (ADR 045).
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(type: self::TYPE, category: NotificationCategory::Research, defaultChannels: [], availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast], accessScope: NotificationAccessScope::Role, translationDomain: 'research', requiredRole: 'ROLE_BOARD_MEMBER', position: 1)]
final class RequestSubmittedNotification extends AppNotification
{
    public const string TYPE = 'research.request_submitted';
}
