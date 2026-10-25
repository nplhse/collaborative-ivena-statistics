<?php

declare(strict_types=1);

namespace App\Feedback\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;

/**
 * Someone sent feedback (ADR 016, "Admin notifications"). Goes to the administrators who receive
 * feedback; the email is {@see AdminFeedbackEmailCustomizer}.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class AdminFeedbackReceivedNotification extends AppNotification
{
    public const string TYPE = 'admin.feedback_received';
}
