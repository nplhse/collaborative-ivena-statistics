<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Weekly count of feedback that went into quarantine (issue #964). Opt-in: without default channels an
 * administrator only receives it after switching it on. It carries counts only, never message content.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class AdminFeedbackQuarantineSummaryNotification extends AppNotification
{
    public const string TYPE = 'admin.feedback_quarantine_summary';
}
