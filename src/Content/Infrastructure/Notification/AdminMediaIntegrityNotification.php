<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Notification;

use App\Notification\Application\Definition\AppNotification;
use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Domain\Enum\NotificationAccessScope;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Domain\Enum\NotificationPriority;

/**
 * The media integrity check found missing or damaged media files (ADR 039, ADR 016 "Admin notifications"). Stores
 * only the counts; the files are listed by `app:media:check`.
 *
 * @psalm-suppress UnusedClass Collected by NotificationDefinitionPass through #[NotificationDefinition].
 */
#[NotificationDefinition(
    type: self::TYPE,
    category: NotificationCategory::Administration,
    defaultChannels: [NotificationChannel::InApp, NotificationChannel::Email],
    availableChannels: [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast],
    priority: NotificationPriority::High,
    accessScope: NotificationAccessScope::Role,
    requiredRole: 'ROLE_ADMIN',
)]
final class AdminMediaIntegrityNotification extends AppNotification
{
    public const string TYPE = 'admin.media_integrity_problem';
}
