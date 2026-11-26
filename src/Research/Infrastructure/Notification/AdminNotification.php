<?php

declare(strict_types=1);

namespace App\Research\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Data extract work for administrators (ADR 045): a request was approved (prepare queries and release), a veto needs a
 * decision, an export run failed or was blocked. Parameter `kind`: approved, vetoed, failed, blocked.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(type: self::TYPE, category: NotificationCategory::Research, defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email], availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast], accessScope: NotificationAccessScope::Role, translationDomain: 'research', requiredRole: 'ROLE_ADMIN', position: 6)]
final class AdminNotification extends AppNotification
{
    public const string TYPE = 'research.admin';
}
